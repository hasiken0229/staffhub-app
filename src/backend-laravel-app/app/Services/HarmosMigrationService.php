<?php

namespace App\Services;

use App\Services\Leave\LeaveLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class HarmosMigrationService
{
    private const IMPORT_TYPES = [
        'HARMOS_EMPLOYEE_CSV',
        'HARMOS_ATTENDANCE_DAILY_CSV',
        'HARMOS_ATTENDANCE_MONTHLY_CSV',
        'HARMOS_PAID_LEAVE_BALANCE_CSV',
    ];

    public function __construct(
        private readonly ImportHistoryService $importHistoryService,
        private readonly LeaveLedgerService $leaveLedgerService,
    ) {
    }

    public function preview(array $payload, UploadedFile $file): array
    {
        return $this->analyze($this->normalizeImportType($payload['importType'] ?? ''), $payload, $file, false, null);
    }

    public function import(array $payload, UploadedFile $file, ?GenericUser $actor): array
    {
        $importType = $this->normalizeImportType($payload['importType'] ?? '');
        $result = DB::transaction(fn () => $this->analyze($importType, $payload, $file, true, $actor));

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

            $result = match ($importType) {
                'HARMOS_EMPLOYEE_CSV' => $this->analyzeEmployeeRow($row, $map, $payload, $commit),
                'HARMOS_ATTENDANCE_DAILY_CSV' => $this->analyzeAttendanceDailyRow($row, $map, $payload, $file, $commit),
                'HARMOS_ATTENDANCE_MONTHLY_CSV' => $this->analyzeAttendanceMonthlyRow($row, $map, $payload),
                'HARMOS_PAID_LEAVE_BALANCE_CSV' => $this->analyzePaidLeaveBalanceRow($row, $map, $payload, $file, $commit, $actor),
            };

            $result['line'] = $line;
            if (($result['key'] ?? '') !== '') {
                if (isset($seenKeys[$result['key']])) {
                    $result['action'] = 'SKIP';
                    $result['errors'][] = 'CSV内で同じキーが重複しています。';
                    $summary['duplicateCount']++;
                }
                $seenKeys[$result['key']] = true;
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
            'dryRun' => !$commit,
            'importType' => $importType,
            'sourceFileName' => $file->getClientOriginalName(),
            'headers' => $header,
            'processedCount' => count($items),
            'successCount' => count($items) - count($errors),
            'errorCount' => count($errors),
            'summary' => $summary,
            'items' => array_slice($items, 0, 100),
            'errors' => array_slice($errors, 0, 100),
        ];
    }

    private function analyzeEmployeeRow(array $row, array $map, array $payload, bool $commit): array
    {
        $employeeCode = $this->firstCell($row, $map, ['社員番号', '従業員番号', '職員番号', '社員コード', '従業員コード']);
        $name = $this->firstCell($row, $map, ['氏名', '名前', '社員名', '従業員名']);
        if ($name === '') {
            $name = trim($this->firstCell($row, $map, ['姓']) . ' ' . $this->firstCell($row, $map, ['名']));
        }

        $errors = [];
        if ($employeeCode === '') {
            $errors[] = '職員番号を判定できません。';
        }
        if ($name === '') {
            $errors[] = '氏名を判定できません。';
        }

        $existing = $employeeCode !== '' ? DB::table('employees')->where('employee_code', $employeeCode)->first() : null;
        $action = $existing === null ? 'CREATE' : 'UPDATE';

        if ($commit && $errors === []) {
            $values = [
                'employee_code' => $employeeCode,
                'name' => $name,
                'kana' => $this->firstCell($row, $map, ['ふりがな', 'フリガナ', '氏名カナ']) ?: null,
                'department_name' => $this->firstCell($row, $map, ['所属', '部門', '部署']) ?: ($payload['defaultDepartmentName'] ?? '未設定'),
                'location_name' => $this->firstCell($row, $map, ['拠点', '勤務場所']) ?: null,
                'employment_type' => $this->normalizeEmploymentType($this->firstCell($row, $map, ['雇用形態', '雇用区分']) ?: ($payload['defaultEmploymentType'] ?? 'FULL_TIME')),
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

        $clockIn = $this->combineDateTime($targetDate, $this->firstCell($row, $map, ['出勤', '出勤時刻', '出社時刻', '始業時刻']));
        $clockOut = $this->combineDateTime($targetDate, $this->firstCell($row, $map, ['退勤', '退勤時刻', '退社時刻', '終業時刻']));
        $breakMinutes = $this->parseMinutes($this->firstCell($row, $map, ['休憩', '休憩時間', '休憩分'])) ?? 0;
        $workMinutes = $this->parseMinutes($this->firstCell($row, $map, ['実働', '実働時間', '勤務時間', '総労働時間']));
        $paidLeaveUnit = $this->parseDecimal($this->firstCell($row, $map, ['有給', '有休日数', '有休取得日数']));

        $existing = ($employee !== null && $targetDate !== null)
            ? DB::table('attendance_daily')->where('employee_id', $employee->id)->where('target_date', $targetDate)->first()
            : null;

        if ($commit && $errors === []) {
            $values = [
                'employee_id' => (int) $employee->id,
                'target_date' => $targetDate,
                'schedule_name' => $this->firstCell($row, $map, ['勤務区分', '勤務パターン']) ?: 'HARMOS移行',
                'raw_clock_in_at' => $clockIn,
                'raw_clock_out_at' => $clockOut,
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'break_minutes' => $breakMinutes,
                'work_minutes' => $workMinutes,
                'late_flag' => 0,
                'early_leave_flag' => 0,
                'absence_flag' => $this->looksTruthy($this->firstCell($row, $map, ['欠勤'])) ? 1 : 0,
                'special_leave_flag' => $this->looksTruthy($this->firstCell($row, $map, ['特休', '特別休暇'])) ? 1 : 0,
                'paid_leave_unit' => $paidLeaveUnit,
                'hour_paid_leave_minutes' => $this->parseMinutes($this->firstCell($row, $map, ['時間有給', '時間休'])) ?? 0,
                'child_care_leave_minutes' => 0,
                'nursing_care_leave_minutes' => 0,
                'remark' => trim('HARMOS移行 ' . $this->firstCell($row, $map, ['備考', 'メモ'])),
                'approval_status' => 'APPROVED',
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
            'key' => $employeeCode . ':' . $targetDate,
            'action' => $errors === [] ? ($existing === null ? 'CREATE' : 'UPDATE') : 'SKIP',
            'employeeMatched' => $employee !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $employee->name ?? null,
            'targetDate' => $targetDate,
            'detail' => sprintf('出勤 %s / 退勤 %s / 実働 %s分', $clockIn ? substr($clockIn, 11, 5) : '-', $clockOut ? substr($clockOut, 11, 5) : '-', $workMinutes ?? '-'),
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
            'key' => $employeeCode . ':' . ($payload['targetPeriod'] ?? ''),
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

        $existing = ($employee !== null)
            ? DB::table('paid_leave_grants')
                ->where('employee_id', $employee->id)
                ->where('granted_on', $migrationDate)
                ->where('source_system', 'HARMOS')
                ->first()
            : null;

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
            'key' => $employeeCode . ':' . $migrationDate,
            'action' => $errors === [] ? ($existing === null ? 'CREATE' : 'UPDATE') : 'SKIP',
            'employeeMatched' => $employee !== null,
            'employeeCode' => $employeeCode,
            'employeeName' => $employee->name ?? null,
            'targetDate' => $migrationDate,
            'detail' => '有給残数 ' . ($balance ?? '-') . ' 日',
            'errors' => $errors,
        ];
    }

    private function normalizeImportType(string $value): string
    {
        $type = strtoupper(trim($value));
        if (!in_array($type, self::IMPORT_TYPES, true)) {
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
            if (!empty($item['targetDate']) && preg_match('/^\d{4}-\d{2}/', (string) $item['targetDate'], $matches) === 1) {
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
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
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
            return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
        }
        if (is_numeric($value) && (int) $value > 20000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        try {
            return CarbonImmutable::parse(str_replace(['年', '月', '日', '/'], ['-', '-', '', '-'], $value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function combineDateTime(?string $date, string $time): ?string
    {
        $time = $this->normalizeString($time);
        if ($date === null || $time === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}/', $time, $matches) !== 1) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $matches[0]));
        $base = CarbonImmutable::parse($date)->setTime($hour % 24, $minute);
        if ($hour >= 24) {
            $base = $base->addDay();
        }

        return $base->format('Y-m-d H:i:s');
    }

    private function parseMinutes(string $value): ?int
    {
        $value = $this->normalizeString($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3}):(\d{2})$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }
        $normalized = str_replace(['時間', '分', ','], ['', '', ''], $value);
        if (is_numeric($normalized)) {
            return (int) round((float) $normalized);
        }

        return null;
    }

    private function parseDecimal(string $value): ?float
    {
        $value = str_replace(',', '', $this->normalizeString($value));
        if ($value === '' || !is_numeric($value)) {
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
        return match ($this->normalizeString($value)) {
            '常勤', '正社員', '正職員' => 'FULL_TIME',
            '非常勤', 'パート', 'アルバイト' => 'PART_TIME',
            '契約', '契約職員' => 'CONTRACT',
            '臨時' => 'TEMPORARY',
            default => $value !== '' ? $value : 'FULL_TIME',
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
