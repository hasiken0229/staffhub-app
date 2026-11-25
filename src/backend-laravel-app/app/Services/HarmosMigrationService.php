<?php

namespace App\Services;

use App\Services\Leave\LeaveLedgerService;
use App\Services\Attendance\MonthMutationLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class HarmosMigrationService
{
    private const IMPORT_TYPES = [
        'HARMOS_EMPLOYEE_CSV',
        'HARMOS_WORK_TYPE_CSV',
        'HARMOS_ATTENDANCE_DAILY_CSV',
        'HARMOS_ATTENDANCE_MONTHLY_CSV',
        'HARMOS_PAID_LEAVE_BALANCE_CSV',
    ];

    public function __construct(
        private readonly ImportHistoryService $importHistoryService,
        private readonly LeaveLedgerService $leaveLedgerService,
    ) {}

    public function preview(array $payload, UploadedFile $file): array
    {
        return $this->analyze($this->normalizeImportType($payload['importType'] ?? ''), $payload, $file, false, null);
    }

    public function import(array $payload, UploadedFile $file, ?GenericUser $actor): array
    {
        $importType = $this->normalizeImportType($payload['importType'] ?? '');
        $months = [];
        if ($importType === 'HARMOS_ATTENDANCE_DAILY_CSV') {
            $rows = $this->readCsvRows($file);
            $map = $this->buildHeaderIndexMap($rows[0] ?? []);
            foreach (array_slice($rows, 1) as $row) {
                $date = $this->parseDateValue($this->firstCell($row, $map, ['日付', '勤務日', '対象日', '年月日']));
                if ($date !== null) { $months[] = substr($date, 0, 7); }
            }
        }
        $result = DB::transaction(function () use ($months, $importType, $payload, $file, $actor) {
            MonthMutationLock::acquire($months);
            $result = $this->analyze($importType, $payload, $file, true, $actor);
        $this->importHistoryService->storeAndRecord(
            $importType,
            $file->getClientOriginalName(),
            $this->resolveTargetPeriod($result, $payload),
            null,
            (int) $result['processedCount'],
            (int) $result['successCount'],
            (int) $result['errorCount'],
            [
                'dryRun' => false,
                'summary' => $result['summary'],
                'errors' => array_slice($result['errors'], 0, 100),
            ],
            file_get_contents($file->getRealPath()) ?: '',
            $file->getClientOriginalName(),
            $file->getClientMimeType() ?: 'text/csv',
            $actor,
            now()->addMonths(6)->toIso8601String(),
        );
            return $result;
        });

        return $result;
    }

    private function analyze(string $importType, array $payload, UploadedFile $file, bool $commit, ?GenericUser $actor): array
    {
        $rows = $this->readCsvRows($file);
        if (count($rows) < 2) {
            throw new ApiException('CSV_FORMAT_ERROR', 'CSVにデータ行がありません。', 422);
        }

        $header = $rows[0];
        $map = $this->buildHeaderIndexMap($header);
        $items = [];
        $errors = [];
        $summary = [
            'createdCount' => 0,
            'updatedCount' => 0,
            'skippedCount' => 0,
            'matchedEmployeeCount' => 0,
            'unmatchedEmployeeCount' => 0,
            'duplicateCount' => 0,
        ];
        $seenKeys = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $line = $offset + 2;
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            if ($importType === 'HARMOS_WORK_TYPE_CSV'
                && $this->firstCell($row, $map, ['勤務/休日・休暇']) !== '勤務') {
                continue;
            }

            // Decide duplicates before a row analyzer is allowed to write. A SKIP
            // result must never have overwritten the first row in the same CSV.
            $key = $this->rowKey($importType, $row, $map, $payload);
            $duplicate = $key !== '' && isset($seenKeys[$key]);
            $write = $commit && !$duplicate;
            $result = match ($importType) {
                'HARMOS_EMPLOYEE_CSV' => $this->analyzeEmployeeRow($row, $map, $payload, $write),
                'HARMOS_WORK_TYPE_CSV' => $this->analyzeWorkTypeRow($row, $map, $write),
                'HARMOS_ATTENDANCE_DAILY_CSV' => $this->analyzeAttendanceDailyRow($row, $map, $payload, $file, $write),
                'HARMOS_ATTENDANCE_MONTHLY_CSV' => $this->analyzeAttendanceMonthlyRow($row, $map, $payload),
                'HARMOS_PAID_LEAVE_BALANCE_CSV' => $this->analyzePaidLeaveBalanceRow($row, $map, $payload, $file, $write, $actor),
            };

            $result['line'] = $line;
            if ($key !== '') {
                if ($duplicate) {
                    $result['action'] = 'SKIP';
                    $result['errors'][] = 'CSV内で同じキーが重複しています。';
                    $summary['duplicateCount']++;
                }
                $seenKeys[$key] = true;
            }

            if (($result['employeeMatched'] ?? false) === true) {
                $summary['matchedEmployeeCount']++;
            } elseif (array_key_exists('employeeMatched', $result)) {
                $summary['unmatchedEmployeeCount']++;
            }

            if (($result['errors'] ?? []) !== []) {
                $summary['skippedCount']++;
                foreach ($result['errors'] as $message) {
                    $errors[] = [
                        'line' => $line,
                        'employeeCode' => $result['employeeCode'] ?? null,
                        'message' => $message,
                    ];
                }
            } else {
                if (($result['action'] ?? '') === 'CREATE') {
                    $summary['createdCount']++;
                } elseif (($result['action'] ?? '') === 'UPDATE') {
                    $summary['updatedCount']++;
                }
            }

            unset($result['key'], $result['errors']);
            $items[] = $result;
        }

        return [
            'dryRun' => ! $commit,
            'importType' => $importType,
            'sourceFileName' => $file->getClientOriginalName(),
            'headers' => $header,
            'processedCount' => count($items),
            'successCount' => count($items) - $summary['skippedCount'],
            'errorCount' => $summary['skippedCount'],
            'summary' => $summary,
            'items' => array_slice($items, 0, 100),
            'errors' => array_slice($errors, 0, 100),
        ];
    }

    private function rowKey(string $importType, array $row, array $map, array $payload): string
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        if ($importType === 'HARMOS_WORK_TYPE_CSV') {
            $name = $this->firstCell($row, $map, ['勤務区分名', '表示名']);
            return $name !== '' ? 'work-type:'.$name : '';
        }
        if ($employeeCode === '') { return ''; }
        if ($importType === 'HARMOS_ATTENDANCE_DAILY_CSV') {
            $date = $this->parseDateValue($this->firstCell($row, $map, ['日付', '勤務日', '対象日', '年月日']));
            return $date !== null ? $employeeCode.':'.$date : '';
        }
        return match ($importType) {
            'HARMOS_EMPLOYEE_CSV' => $employeeCode,
            'HARMOS_ATTENDANCE_MONTHLY_CSV' => $employeeCode.':'.($payload['targetPeriod'] ?? ''),
            'HARMOS_PAID_LEAVE_BALANCE_CSV' => $employeeCode.':'.($this->parseDateValue((string) ($payload['migrationDate'] ?? '')) ?? now()->toDateString()),
        };
    }

    private function analyzeEmployeeRow(array $row, array $map, array $payload, bool $commit): array
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        $name = $this->firstCell($row, $map, ['氏名', '名前', '社員名', '従業員名']);
        if ($name === '') {
            $name = trim($this->firstCell($row, $map, ['姓']).' '.$this->firstCell($row, $map, ['名']));
        }

        $errors = [];
        if ($employeeCode === '') {
            $errors[] = '職員番号を判定できません。';
        }
        if ($name === '') {
            $errors[] = '氏名を判定できません。';
        }

        $query = DB::table('employees')->where('employee_code', $employeeCode);
        if ($commit) { $query->lockForUpdate(); }
        $existing = $employeeCode !== '' ? $query->first() : null;
        $action = $existing === null ? 'CREATE' : 'UPDATE';

        $inputType = $this->firstCell($row, $map, ['雇用形態', '雇用区分']) ?: ($payload['defaultEmploymentType'] ?? 'FULL_TIME');
        $employmentType = $this->normalizeEmploymentType($inputType);
        if ($existing !== null) {
            try {
                EmploymentTypeChangeGuard::assertCanChange($existing, $inputType);
            } catch (ApiException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($commit && $errors === []) {
            $values = [
                'employee_code' => $employeeCode,
                'name' => $name,
                'kana' => $this->firstCell($row, $map, ['ふりがな', 'フリガナ', '氏名カナ']) ?: null,
                'department_name' => $this->firstCell($row, $map, ['所属', '部門', '部署']) ?: ($payload['defaultDepartmentName'] ?? '未設定'),
                'location_name' => $this->firstCell($row, $map, ['拠点', '勤務場所']) ?: null,
                'employment_type' => $employmentType,
                ...EmploymentType::flags((string) ($existing->employment_type ?? '')),
                ...EmploymentType::flags($inputType),
                'status' => $this->normalizeStatus($this->firstCell($row, $map, ['状態', 'ステータス', '在職状況']) ?: ($payload['defaultStatus'] ?? 'ACTIVE')),
                'joined_on' => $this->parseDateValue($this->firstCell($row, $map, ['入社日', '入職日'])) ?? ($payload['defaultJoinedOn'] ?? now()->toDateString()),
                'retired_on' => $this->parseDateValue($this->firstCell($row, $map, ['退職日'])) ?: null,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                $values['created_at'] = now();
                DB::table('employees')->insert($values);
            } else {
                DB::table('employees')->where('id', $existing->id)->update($values);
            }

            $this->syncEmployeeMasterSettings($values);
        }

        return [
            'key' => $employeeCode,
            'action' => $errors === [] ? $action : 'SKIP',
            'employeeMatched' => $existing !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $name,
            'targetDate' => null,
            'detail' => $action === 'CREATE' ? '職員を新規登録' : '職員情報を更新',
            'errors' => $errors,
        ];
    }

    private function analyzeWorkTypeRow(array $row, array $map, bool $commit): array
    {
        $sourceId = $this->firstCell($row, $map, ['勤務区分ID']);
        $name = $this->firstCell($row, $map, ['勤務区分名', '表示名']);
        $startTime = $this->parseTimeValue($this->firstCell($row, $map, ['勤務時間_開始_']));
        $endTime = $this->parseTimeValue($this->firstCell($row, $map, ['勤務時間_終了_']));
        $sortOrder = $this->parseDecimal($this->firstCell($row, $map, ['並び順'])) ?? 0;
        $errors = [];
        if ($name === '') {
            $errors[] = '勤務区分名を判定できません。';
        }
        $standardDayMinutes = $this->parseMinutes($this->firstCell($row, $map, ['所定時間']), '所定時間', $errors);

        $existing = $name !== '' ? DB::table('work_type_settings')->where('name', $name)->first() : null;
        if ($commit && $errors === []) {
            $values = [
                'start_time' => $startTime,
                'end_time' => $endTime,
                'default_break_minutes' => $this->breakMinutesFromHarmosRow($row, $map),
                'standard_day_minutes' => $standardDayMinutes,
                'sort_order' => (int) $sortOrder + 1,
                'is_active' => 1,
                'updated_at' => now(),
            ];
            if ($existing === null) {
                $values['name'] = $name;
                $values['created_at'] = now();
                DB::table('work_type_settings')->insert($values);
            } else {
                DB::table('work_type_settings')->where('id', $existing->id)->update($values);
            }
        }

        return [
            'key' => 'work-type:'.($sourceId !== '' ? $sourceId : $name),
            'action' => $errors === [] ? ($existing === null ? 'CREATE' : 'UPDATE') : 'SKIP',
            'employeeCode' => null,
            'employeeName' => null,
            'targetDate' => null,
            'detail' => sprintf('%s / %s〜%s / 休憩%s分 / 所定%s分', $name ?: '-', $startTime ? substr($startTime, 0, 5) : '-', $endTime ? substr($endTime, 0, 5) : '-', $this->breakMinutesFromHarmosRow($row, $map) ?? '-', $standardDayMinutes ?? '-'),
            'errors' => $errors,
        ];
    }

    private function syncEmployeeMasterSettings(array $values): void
    {
        $now = now();
        foreach ([
            ['department_settings', 'name', $values['department_name'] ?? null],
            ['location_settings', 'name', $values['location_name'] ?? null],
        ] as [$table, $column, $name]) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            DB::table($table)->updateOrInsert(
                [$column => $name],
                ['is_active' => 1, 'updated_at' => $now],
            );
        }

        $employmentCode = (string) ($values['employment_type'] ?? '');
        if ($employmentCode !== '') {
            DB::table('employment_type_settings')->updateOrInsert(
                ['code' => $employmentCode],
                ['label' => $this->employmentTypeLabel($employmentCode), 'is_active' => 1, 'updated_at' => $now],
            );
        }
    }

    private function analyzeAttendanceDailyRow(array $row, array $map, array $payload, UploadedFile $file, bool $commit): array
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        $targetDate = $this->parseDateValue($this->firstCell($row, $map, ['日付', '勤務日', '対象日', '年月日']));
        $employee = $employeeCode !== '' ? DB::table('employees')->where('employee_code', $employeeCode)->first() : null;
        $errors = [];
        if ($employeeCode === '') {
            $errors[] = '職員番号を判定できません。';
        }
        if ($targetDate === null) {
            $errors[] = '対象日を判定できません。';
        }
        if ($employee === null) {
            $errors[] = '本アプリの職員と突合できません。';
        }

        $clockIn = $this->attendanceTimestamp($row, $map, ['出勤', '出勤時刻', '出社時刻', '始業時刻'], $targetDate, $errors);
        $clockOut = $this->attendanceTimestamp($row, $map, ['退勤', '退勤時刻', '退社時刻', '終業時刻'], $targetDate, $errors);
        $rawIn = $this->attendanceTimestamp($row, $map, ['出勤時刻_打刻_', '出勤打刻時刻', '出勤打刻'], $targetDate, $errors);
        $rawOut = $this->attendanceTimestamp($row, $map, ['退勤時刻_打刻_', '退勤打刻時刻', '退勤打刻'], $targetDate, $errors);
        if ($clockIn !== null && $clockOut !== null && $clockOut < $clockIn) {
            $errors[] = '退勤が出勤より前になっています。翌日退勤は24時以降の時刻または翌日区分で指定してください。';
        }
        $sourceApproval = $this->firstCell($row, $map, ['申請承認']);
        $approvalStatus = match ($sourceApproval) {
            '承認済み','承認済' => 'APPROVED',
            '差戻し','差戻','差し戻し' => 'RETURNED',
            default => 'PENDING',
        };
        $breakMinutes = $this->parseMinutes($this->firstCell($row, $map, ['休憩', '休憩時間', '休憩分']), '休憩', $errors, 0);
        $workMinutes = $this->parseMinutes($this->firstCell($row, $map, ['実働', '実働時間', '勤務時間', '総労働時間']), '実働', $errors);
        $paidLeaveMinutes = $this->parseMinutes($this->firstCell($row, $map, ['時間有給', '時間休']), '時間有給', $errors, 0);
        $paidLeaveUnit = $this->parseDecimal($this->firstCell($row, $map, ['有給', '有休日数', '有休取得日数']));

        $existing = ($employee !== null && $targetDate !== null)
            ? DB::table('attendance_daily')->where('employee_id', $employee->id)->where('target_date', $targetDate)->first()
            : null;

        if ($targetDate !== null && DB::table('attendance_monthly_closes')
            ->where('target_year_month', substr($targetDate, 0, 7))->where('status', 'CLOSED')->exists()) {
            $errors[] = '締め済み月のため取り込めません。締め解除後にプレビューを取り直してください。';
        }
        if ($existing !== null) {
            $hasPunch = DB::table('attendance_events')->where('employee_id', $employee->id)
                ->where('receive_status', 'ACCEPTED')
                ->whereBetween('occurred_at', [$targetDate.' 00:00:00', $targetDate.' 23:59:59'])->exists();
            $hasLeaveProjection = DB::table('attendance_leave_projections')->where('employee_id', $employee->id)
                ->where('target_date', $targetDate)->exists();
            if (($existing->source_system ?? null) !== 'HARMOS' || !empty($existing->is_manually_edited)
                || ($existing->close_status ?? 'OPEN') === 'CLOSED'
                || !empty($existing->approved_at) || !empty($existing->approved_by)
                || (str_starts_with((string) ($existing->approval_comment ?? ''), 'HARMOS原票:') && $sourceApproval === '')
                || ($existing->raw_clock_in_at !== null && !isset($map[$this->normalizeHeader('出勤時刻_打刻_')]) && !isset($map['出勤打刻時刻']) && !isset($map['出勤打刻']) && $existing->raw_clock_in_at !== $existing->clock_in_at)
                || ($existing->raw_clock_out_at !== null && !isset($map[$this->normalizeHeader('退勤時刻_打刻_')]) && !isset($map['退勤打刻時刻']) && !isset($map['退勤打刻']) && $existing->raw_clock_out_at !== $existing->clock_out_at)
                || $hasPunch || $hasLeaveProjection) {
                $errors[] = '本アプリの打刻・手修正・承認・休暇反映済みの勤怠を保護するため取り込めません。日次勤怠で内容を確認してください。';
            }
        }

        $remark = trim('HARMOS移行 '.$this->firstCell($row, $map, ['備考', 'メモ']));
        if (mb_strlen($remark) > 255) {
            $errors[] = '備考が長すぎます。HARMOS移行の接頭辞を含め255文字以内にしてください。';
        }
        if ($commit && $errors === []) {
            $values = [
                'employee_id' => (int) $employee->id,
                'target_date' => $targetDate,
                'schedule_name' => $this->firstCell($row, $map, ['勤務区分', '勤務パターン']) ?: 'HARMOS移行',
                'raw_clock_in_at' => $rawIn,
                'raw_clock_out_at' => $rawOut,
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'break_minutes' => $breakMinutes,
                'work_minutes' => $workMinutes,
                'late_flag' => 0,
                'early_leave_flag' => 0,
                'absence_flag' => $this->looksTruthy($this->firstCell($row, $map, ['欠勤'])) ? 1 : 0,
                'special_leave_flag' => $this->looksTruthy($this->firstCell($row, $map, ['特休', '特別休暇'])) ? 1 : 0,
                'paid_leave_unit' => $paidLeaveUnit,
                'hour_paid_leave_minutes' => $paidLeaveMinutes,
                'child_care_leave_minutes' => 0,
                'nursing_care_leave_minutes' => 0,
                'remark' => $remark,
                'approval_status' => $approvalStatus,
                'approval_comment' => $sourceApproval === '' ? null : 'HARMOS原票: '.$sourceApproval,
                'close_status' => 'OPEN',
                'is_manually_edited' => 0,
                'source_system' => 'HARMOS',
                'source_import_type' => 'HARMOS_ATTENDANCE_DAILY_CSV',
                'source_file_name' => $file->getClientOriginalName(),
                'source_imported_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('attendance_daily')->updateOrInsert(
                ['employee_id' => (int) $employee->id, 'target_date' => $targetDate],
                $values,
            );
        }

        return [
            'key' => $employeeCode.':'.$targetDate,
            'action' => $errors === [] ? ($existing === null ? 'CREATE' : 'UPDATE') : 'SKIP',
            'employeeMatched' => $employee !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $employee->name ?? null,
            'targetDate' => $targetDate,
            'detail' => sprintf('出勤 %s / 退勤 %s / 実働 %s分 / 休憩 %s分 / 時間有給 %s分', $clockIn ? substr($clockIn, 11, 5) : '-', $clockOut ? substr($clockOut, 11, 5) : '-', $workMinutes ?? '-', $breakMinutes ?? '-', $paidLeaveMinutes ?? '-'),
            'errors' => $errors,
        ];
    }

    private function analyzeAttendanceMonthlyRow(array $row, array $map, array $payload): array
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        $employee = $employeeCode !== '' ? DB::table('employees')->where('employee_code', $employeeCode)->first() : null;
        $errors = [];
        if ($employeeCode === '') {
            $errors[] = '職員番号を判定できません。';
        }
        if ($employee === null) {
            $errors[] = '本アプリの職員と突合できません。';
        }

        return [
            'key' => $employeeCode.':'.($payload['targetPeriod'] ?? ''),
            'action' => $errors === [] ? 'REFERENCE_ONLY' : 'SKIP',
            'employeeMatched' => $employee !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $employee->name ?? null,
            'targetDate' => $payload['targetPeriod'] ?? null,
            'detail' => '月次CSVは参照ファイルとして履歴保存します。',
            'errors' => $errors,
        ];
    }

    private function analyzePaidLeaveBalanceRow(array $row, array $map, array $payload, UploadedFile $file, bool $commit, ?GenericUser $actor): array
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        $employee = $employeeCode !== '' ? DB::table('employees')->where('employee_code', $employeeCode)->first() : null;
        $balance = $this->parseDecimal($this->firstCell($row, $map, ['残数', '残日数', '有給残数', '残り日数', '調整後残数']));
        $migrationDate = $this->parseDateValue((string) ($payload['migrationDate'] ?? '')) ?? now()->toDateString();
        $expiresOn = $this->parseDateValue($this->firstCell($row, $map, ['有効期限', '失効日', '期限']));
        $errors = [];
        if ($employeeCode === '') {
            $errors[] = '職員番号を判定できません。';
        }
        if ($employee === null) {
            $errors[] = '本アプリの職員と突合できません。';
        }
        if ($balance === null) {
            $errors[] = '有給残数を判定できません。';
        }

        if ($commit && $employee !== null) {
            // Serialize residual imports with source review and leave balance changes.
            DB::table('employees')->where('id', $employee->id)->lockForUpdate()->first();
        }

        $existing = ($employee !== null)
            ? DB::table('paid_leave_grants')
                ->where('employee_id', $employee->id)
                ->where('granted_on', $migrationDate)
                ->where('source_system', 'HARMOS')
                ->when($commit, fn ($query) => $query->lockForUpdate())
                ->first()
            : null;

        $reviewed = $employee === null ? null : DB::table('paid_leave_grants')->where('employee_id', $employee->id)
            ->where('granted_on', $migrationDate)->where('source_system', 'HARMOS')
            ->where(function ($q) { $q->whereNotNull('superseded_at')->orWhereNotNull('expiry_reviewed_at'); })
            ->when($commit, fn ($query) => $query->lockForUpdate())->first();
        if ($reviewed !== null) {
            $errors[] = '原本確認済みの付与内訳があります。移行CSVで上書きせず、有給期限の確認画面を使用してください。';
        }

        if ($commit && $errors === []) {
            $values = [
                'employee_id' => (int) $employee->id,
                'granted_on' => $migrationDate,
                'granted_days' => $balance,
                'used_days' => 0,
                'expires_on' => $expiresOn,
                'note' => 'HARMOS移行残数',
                'source_system' => 'HARMOS',
                'source_import_type' => 'HARMOS_PAID_LEAVE_BALANCE_CSV',
                'source_file_name' => $file->getClientOriginalName(),
                'source_imported_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('paid_leave_grants')->insert([...$values, 'created_at' => now()]);
            } else {
                DB::table('paid_leave_grants')->where('id', $existing->id)->update($values);
            }
            $this->leaveLedgerService->syncPaidLeaveLedger((int) $employee->id);
        }

        return [
            'key' => $employeeCode.':'.$migrationDate,
            'action' => $errors === [] ? ($existing === null ? 'CREATE' : 'UPDATE') : 'SKIP',
            'employeeMatched' => $employee !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $employee->name ?? null,
            'targetDate' => $migrationDate,
            'detail' => '有給残数 '.($balance ?? '-').' 日',
            'errors' => $errors,
        ];
    }

    private function normalizeImportType(string $value): string
    {
        $type = strtoupper(trim($value));
        if (! in_array($type, self::IMPORT_TYPES, true)) {
            throw new ApiException('VALIDATION_ERROR', 'HARMOS移行種別が不正です。', 422);
        }

        return $type;
    }

    private function resolveTargetPeriod(array $result, array $payload): ?string
    {
        $value = trim((string) ($payload['targetPeriod'] ?? $payload['migrationDate'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        foreach ($result['items'] as $item) {
            if (! empty($item['targetDate']) && preg_match('/^\d{4}-\d{2}/', (string) $item['targetDate'], $matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    private function readCsvRows(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_UPLOAD_ERROR', 'CSVファイルを読み取れません。', 422);
        }

        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map([$this, 'normalizeString'], $row);
        }
        fclose($handle);

        return $rows;
    }

    private function buildHeaderIndexMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $columnName) {
            $normalized = $this->normalizeHeader($columnName);
            $map[$normalized] ??= [];
            $map[$normalized][] = $index;
        }

        return $map;
    }

    private function firstCell(array $row, array $map, array $headers): string
    {
        foreach ($headers as $header) {
            $index = $map[$this->normalizeHeader($header)][0] ?? null;
            if ($index !== null) {
                return $this->normalizeString($row[$index] ?? '');
            }
        }

        return '';
    }

    private function normalizeHeader(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s　_\-（）()]/u', '', $this->normalizeString($value)) ?? $value);
    }

    private function normalizeString(?string $value): string
    {
        $value ??= '';
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        return trim($value);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeString($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseDateValue(string $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $value) === 1) {
            return checkdate((int) substr($value, 4, 2), (int) substr($value, 6, 2), (int) substr($value, 0, 4))
                ? substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2) : null;
        }
        if (is_numeric($value) && (int) $value > 20000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        $normalized = str_replace(['年', '月', '日', '/'], ['-', '-', '', '-'], $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|\s|T)/', $normalized, $parts) === 1
            && !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        try {
            return CarbonImmutable::parse($normalized)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function attendanceTimestamp(array $row, array $map, array $headers, ?string $date, array &$errors): ?string
    {
        foreach ($headers as $header) {
            $index = $map[$this->normalizeHeader($header)][0] ?? null;
            if ($index === null) { continue; }
            $value = $this->normalizeString($row[$index] ?? '');
            if ($value === '' || $date === null) { return null; }
            $stamp = $this->combineDateTime($date, $value);
            if ($stamp === null) { $errors[] = $header.'時刻が不正です。時刻は00:00〜47:59で指定してください。'; return null; }
            $hasOffset = in_array($index + 1, $map[$this->normalizeHeader('前日/翌日')] ?? [], true);
            $offset = $hasOffset ? trim($row[$index + 1] ?? '') : '';
            if (!in_array($offset, ['','0','1','-1'], true) || ($offset !== '' && (int) $offset !== 0 && (int) explode(':',$value)[0] >= 24)) {
                $errors[] = $header.'の前日/翌日区分が不正です。'; return null;
            }
            return CarbonImmutable::parse($stamp)->addDays((int) $offset)->format('Y-m-d H:i:s');
        }
        return null;
    }

    private function combineDateTime(?string $date, string $time): ?string
    {
        $time = $this->normalizeString($time);
        if ($date === null || $time === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', $time, $matches) !== 1 || (int) $matches[1] > 47) {
            return null;
        }

        $hour = (int) $matches[1];
        $base = CarbonImmutable::parse($date)->setTime($hour % 24, (int) $matches[2], (int) ($matches[3] ?? 0));
        if ($hour >= 24) {
            $base = $base->addDay();
        }

        return $base->format('Y-m-d H:i:s');
    }

    private function parseMinutes(string $value, string $label, array &$errors, ?int $blankValue = null): ?int
    {
        $value = trim(mb_convert_kana($value, 'as', 'UTF-8'));
        if ($value === '') {
            return $blankValue;
        }

        $minutes = null;
        if (preg_match('/^([0-9]+):([0-5][0-9])$/D', $value, $matches)) {
            $minutes = (float) $matches[1] * 60 + (int) $matches[2];
        } elseif (preg_match('/^([0-9]+)\s*時間\s*([0-5]?[0-9])\s*分$/uD', $value, $matches)) {
            $minutes = (float) $matches[1] * 60 + (int) $matches[2];
        } elseif (preg_match('/^\+?((?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]*)?|\.[0-9]+)\s*(時間|分)?$/uD', $value, $matches)) {
            $amount = (float) str_replace(',', '', $matches[1]);
            $minutes = round($amount * (($matches[2] ?? '') === '時間' ? 60 : 1));
        }
        // Imported duration columns are signed SQL INTs. Reject overflow in the preview as well as on commit.
        if ($minutes !== null && $minutes <= 2147483647) {
            return (int) $minutes;
        }

        $errors[] = $label.'の時間表記が不正です。0以上の分数、8:30、8時間、8時間30分などで指定してください。';
        return null;
    }

    private function parseTimeValue(string $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === '' || $value === '0') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function breakMinutesFromHarmosRow(array $row, array $map): ?int
    {
        $total = 0;
        $hasBreak = false;
        foreach ([1, 2] as $number) {
            $start = $this->parseTimeValue($this->firstCell($row, $map, ["休憩{$number}_開始_"]));
            $end = $this->parseTimeValue($this->firstCell($row, $map, ["休憩{$number}_終了_"]));
            if ($start === null || $end === null) {
                continue;
            }
            $startMinutes = ((int) substr($start, 0, 2) * 60) + (int) substr($start, 3, 2);
            $endMinutes = ((int) substr($end, 0, 2) * 60) + (int) substr($end, 3, 2);
            if ($endMinutes <= $startMinutes) {
                $endMinutes += 24 * 60;
            }
            $total += $endMinutes - $startMinutes;
            $hasBreak = true;
        }

        return $hasBreak ? $total : null;
    }

    private function parseDecimal(string $value): ?float
    {
        $value = str_replace(',', '', $this->normalizeString($value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function looksTruthy(string $value): bool
    {
        return in_array($this->normalizeString($value), ['1', '○', '〇', '有', 'あり', 'true', 'TRUE'], true);
    }

    private function normalizeEmploymentType(string $value): string
    {
        return EmploymentType::canonical($this->normalizeString($value) ?: 'FULL_TIME');
    }

    private function employmentTypeLabel(string $code): string
    {
        return match ($code) {
            'FULL_TIME' => '常勤',
            'PART_TIME' => '非常勤',
            'CONTRACT' => '契約',
            'TEMPORARY' => '臨時',
            default => $code,
        };
    }

    private function normalizeStatus(string $value): string
    {
        return match ($this->normalizeString($value)) {
            '在職', '勤務中', '有効' => 'ACTIVE',
            '停止', '休職' => 'INACTIVE',
            '退職', '無効' => 'RETIRED',
            default => $value !== '' ? $value : 'ACTIVE',
        };
    }
}
