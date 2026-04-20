<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use TCPDF;
use TCPDF_FONTS;

final class ReportService
{
    private const DEFAULT_COMPANY_NAME = '社会福祉法人 誠心福祉会 池上わかばこども園';
    private const DEFAULT_LOCATION_NAME = '池上わかばこども園';

    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly LeaveRequestService $leaveRequestService,
    ) {
    }

    public function hubSummary(): array
    {
        $today = now()->toDateString();

        return [
            'todayAttendanceCount' => (int) DB::table('attendance_daily')
                ->where('target_date', $today)
                ->count(),
            'pendingAttendanceApprovalCount' => (int) DB::table('attendance_daily')
                ->where('approval_status', 'PENDING')
                ->count(),
            'pendingLeaveCount' => (int) DB::table('leave_requests')
                ->whereIn('status', ['PENDING', 'RETURNED'])
                ->count(),
            'publishedPayrollCount' => (int) DB::table('payroll_statements')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->count(),
        ];
    }

    public function todayAttendance(array $filters): array
    {
        $today = $filters['targetDate'] ?? now()->toDateString();

        $result = $this->attendanceService->listApprovals([
            'from' => $today,
            'to' => $today,
            'status' => 'ALL',
            'perPage' => 500,
        ]);

        return [
            'items' => $result['items'],
            'meta' => [
                'targetDate' => $today,
                'count' => count($result['items']),
            ],
        ];
    }

    public function attendanceApprovalHistory(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->leftJoin('employees as approver', 'approver.id', '=', 'ad.approved_by')
            ->select([
                'ad.id',
                'e.employee_code',
                'e.name as employee_name',
                'ad.target_date',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'approver.name as approver_name',
            ])
            ->whereIn('ad.approval_status', ['APPROVED', 'RETURNED']);

        if (!empty($filters['from'])) {
            $query->whereDate('ad.target_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('ad.target_date', '<=', $filters['to']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ad.approved_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => [
                'id' => (int) $row->id,
                'employeeCode' => $row->employee_code,
                'employeeName' => $row->employee_name,
                'targetDate' => $row->target_date,
                'approvalStatus' => $row->approval_status,
                'approvalComment' => $row->approval_comment,
                'approvedAt' => $row->approved_at ? CarbonImmutable::parse($row->approved_at)->toIso8601String() : null,
                'approverName' => $row->approver_name,
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function paidLeaveManagement(array $filters): array
    {
        $employeeQuery = DB::table('employees')
            ->where('status', 'ACTIVE')
            ->orderBy('employee_code');

        if (!empty($filters['employeeCode'])) {
            $employeeQuery->where('employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        $employees = $employeeQuery->get();
        $items = [];
        foreach ($employees as $employee) {
            $balance = $this->leaveRequestService->balance((int) $employee->id);
            $latestLedger = DB::table('paid_leave_ledger')
                ->where('employee_id', $employee->id)
                ->orderByDesc('occurred_on')
                ->orderByDesc('id')
                ->first();

            $items[] = [
                'employeeId' => (int) $employee->id,
                'employeeCode' => $employee->employee_code,
                'employeeName' => $employee->name,
                'departmentName' => $employee->department_name,
                'currentBalance' => $balance['currentBalance'],
                'latestEntryType' => $latestLedger?->entry_type,
                'latestOccurredOn' => $latestLedger?->occurred_on,
                'latestDaysDelta' => $latestLedger !== null ? (float) $latestLedger->days_delta : null,
            ];
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => count($items),
            ],
        ];
    }

    public function exportMonthlyAttendanceCsv(string $targetMonth): string
    {
        $grid = $this->attendanceService->listMonthlyCalendar(['targetMonth' => $targetMonth]);
        return $this->buildMonthlyAttendanceSummaryCsv($grid['items']);
    }

    public function exportDailyAttendanceCsv(array $filters): string
    {
        $result = $this->attendanceService->listApprovals([
            'from' => $filters['from'] ?? now()->toDateString(),
            'to' => $filters['to'] ?? now()->toDateString(),
            'status' => 'ALL',
            'employeeCode' => $filters['employeeCode'] ?? null,
        ]);

        return $this->buildAttendanceCsv($result['items']);
    }

    public function exportDailyAttendancePdf(string $targetMonth): string
    {
        $grid = $this->attendanceService->listDailyGrid(['targetMonth' => $targetMonth]);

        $pdf = new TCPDF();
        $pdf->SetCreator('勤怠管理');
        $pdf->SetAuthor('勤怠管理');
        $pdf->SetTitle('日次勤怠一覧');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage('L', 'A4');
        $pdf->SetFont('kozminproregular', '', 10);

        $html = '<h2>日次勤怠一覧 ' . htmlspecialchars($targetMonth, ENT_QUOTES, 'UTF-8') . '</h2>';
        $html .= '<table border="1" cellpadding="4">';
        $html .= '<thead><tr>'
            . '<th>職員番号</th><th>氏名</th><th>日付</th><th>勤務区分</th><th>原本出勤</th><th>原本退勤</th><th>補正出勤</th><th>補正退勤</th><th>休憩</th><th>勤務時間</th><th>休暇</th><th>手動補正</th><th>エラー</th><th>承認状態</th><th>コメント</th>'
            . '</tr></thead><tbody>';

        foreach ($grid['items'] as $item) {
            $note = trim(implode(' / ', array_filter([
                (string) ($item['supervisorComment'] ?? ''),
                (string) ($item['remark'] ?? ''),
            ])));
            $html .= '<tr>'
                . '<td>' . htmlspecialchars((string) $item['employeeCode'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $item['employeeName'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatDateOnly($item['targetDate'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($item['workStyleName'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatTimeOnly($item['rawClockInAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatTimeOnly($item['rawClockOutAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatTimeOnly($item['clockInAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatTimeOnly($item['clockOutAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatMinutesCell((int) ($item['breakMinutes'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatMinutesCell(($item['workMinutes'] ?? null) !== null ? (int) $item['workMinutes'] : null), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($this->formatLeaveSummary($item), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars(!empty($item['isManuallyEdited']) ? 'あり' : '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($item['alertSummary'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($item['approvalStatus'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($note !== '' ? $note : '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public function exportMonthlyPayrollCsv(string $targetMonth): array
    {
        $grid = $this->attendanceService->listMonthlyCalendar(['targetMonth' => $targetMonth]);
        $aggregates = [];

        foreach ($grid['items'] as $item) {
            $employeeKey = (string) ($item['employeeCode'] ?? '');
            if ($employeeKey === '') {
                continue;
            }

            $aggregates[$employeeKey] ??= [
                'employeeCode' => $employeeKey,
                'employeeName' => (string) ($item['employeeName'] ?? ''),
                'baseMinutes' => 0,
                'overtimeMinutes' => 0,
                'hourPaidLeaveMinutes' => 0,
                'childCareLeaveMinutes' => 0,
                'nursingCareLeaveMinutes' => 0,
            ];

            $actualWorkMinutes = $this->actualWorkMinutes($item);
            $aggregates[$employeeKey]['baseMinutes'] += $actualWorkMinutes;
            $aggregates[$employeeKey]['overtimeMinutes'] += max(0, $actualWorkMinutes - 8 * 60);
            $aggregates[$employeeKey]['hourPaidLeaveMinutes'] += max(0, (int) ($item['hourPaidLeaveMinutes'] ?? 0));
            $aggregates[$employeeKey]['childCareLeaveMinutes'] += max(0, (int) ($item['childCareLeaveMinutes'] ?? 0));
            $aggregates[$employeeKey]['nursingCareLeaveMinutes'] += max(0, (int) ($item['nursingCareLeaveMinutes'] ?? 0));
        }

        ksort($aggregates, SORT_NATURAL);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_EXPORT_ERROR', '月次勤務CSVを生成できません。', 500);
        }

        fputcsv($handle, ['個人Ｃ', '氏名', '時給１時間', '前月普通残業１', '時間有給', '子の看護等', '介護休暇']);

        $totalBaseMinutes = 0;
        $totalOvertimeMinutes = 0;

        foreach ($aggregates as $aggregate) {
            $totalBaseMinutes += (int) $aggregate['baseMinutes'];
            $totalOvertimeMinutes += (int) $aggregate['overtimeMinutes'];

            fputcsv($handle, [
                $aggregate['employeeCode'],
                $aggregate['employeeName'],
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['baseMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['overtimeMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['hourPaidLeaveMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['childCareLeaveMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['nursingCareLeaveMinutes']),
            ]);
        }

        rewind($handle);
        $utf8Csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return [
            'content' => mb_convert_encoding($utf8Csv, 'SJIS-win', 'UTF-8'),
            'fileName' => '給与用_' . now()->format('YmdHi') . '.csv',
            'contentType' => 'text/csv; charset=Shift_JIS',
            'summary' => [
                'employeeCount' => count($aggregates),
                'totalBaseMinutes' => $totalBaseMinutes,
                'totalOvertimeMinutes' => $totalOvertimeMinutes,
                'targetMonth' => $targetMonth,
            ],
        ];
    }

    public function exportMonthlyWorksPdf(int $employeeId, string $targetMonth): array
    {
        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->first([
                'id',
                'employee_code',
                'name',
                'department_name',
                'employment_type',
            ]);

        if ($employee === null) {
            throw new ApiException('NOT_FOUND', '対象職員が見つかりません。', 404);
        }

        $calendar = $this->attendanceService->listMonthlyCalendar([
            'targetMonth' => $targetMonth,
            'employeeCode' => (string) $employee->employee_code,
        ]);

        $rows = array_values(array_filter(
            $calendar['items'],
            static fn (array $item): bool => (int) ($item['employeeId'] ?? 0) === $employeeId
        ));
        $summary = $this->buildMonthlyWorksSummary($rows);

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('勤怠管理');
        $pdf->SetAuthor('勤怠管理');
        $pdf->SetTitle('月次勤務表');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 8);
        $pdf->AddPage();
        $fontFamily = $this->resolveMonthlyWorksPdfFontFamily();
        $pdf->SetFont($fontFamily, '', 7.2);
        $pdf->writeHTML($this->buildMonthlyWorksPdfHtml($employee, $targetMonth, $rows, $summary, $fontFamily), true, false, true, false, '');

        return [
            'content' => $pdf->Output('', 'S'),
            'fileName' => 'works_' . $employee->employee_code . '_' . $targetMonth . '.pdf',
            'contentType' => 'application/pdf',
            'summary' => [
                'employeeId' => (int) $employee->id,
                'employeeCode' => (string) $employee->employee_code,
                'employeeName' => (string) $employee->name,
                'targetMonth' => $targetMonth,
                'workDays' => $summary['attendance']['出勤日数'] ?? 0,
                'totalActualWorkMinutes' => $summary['time']['実働時間'] ?? 0,
            ],
        ];
    }

    private function buildAttendanceCsv(array $items, bool $includeWeekday = false): string
    {
        $header = [
            '職員番号',
            '氏名',
            '所属',
            '日付',
        ];

        if ($includeWeekday) {
            $header[] = '曜日';
        }

        $header = [
            ...$header,
            '勤務区分',
            '出勤',
            '退勤',
            '原本出勤',
            '原本退勤',
            '手動補正',
            '休憩分',
            '勤務分',
            '有給日数',
            '時間有給分',
            '子の看護等分',
            '介護休暇分',
            '承認状態',
            '所属長コメント',
            'エラー概要',
            '備考',
        ];

        $rows = [$header];

        foreach ($items as $item) {
            $row = [
                (string) ($item['employeeCode'] ?? ''),
                (string) ($item['employeeName'] ?? ''),
                (string) ($item['departmentName'] ?? ''),
                $this->formatDateOnly($item['targetDate'] ?? null),
            ];

            if ($includeWeekday) {
                $row[] = $this->formatWeekday($item['targetDate'] ?? null);
            }

            $rows[] = [
                ...$row,
                (string) ($item['workStyleName'] ?? ''),
                $this->formatTimeOnly($item['clockInAt'] ?? null),
                $this->formatTimeOnly($item['clockOutAt'] ?? null),
                $this->formatTimeOnly($item['rawClockInAt'] ?? null),
                $this->formatTimeOnly($item['rawClockOutAt'] ?? null),
                !empty($item['isManuallyEdited']) ? 'あり' : '',
                (string) ($item['breakMinutes'] ?? 0),
                (string) ($item['workMinutes'] ?? 0),
                (string) ($item['paidLeaveUnit'] ?? ''),
                (string) ($item['hourPaidLeaveMinutes'] ?? 0),
                (string) ($item['childCareLeaveMinutes'] ?? 0),
                (string) ($item['nursingCareLeaveMinutes'] ?? 0),
                (string) ($item['approvalStatus'] ?? ''),
                (string) ($item['supervisorComment'] ?? ''),
                (string) ($item['alertSummary'] ?? ''),
                (string) ($item['remark'] ?? ''),
            ];
        }

        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $escaped = array_map(function (string $value): string {
                $value = str_replace('"', '""', $value);
                return '"' . $value . '"';
            }, $row);
            $csv .= implode(',', $escaped) . "\r\n";
        }

        return $csv;
    }

    private function buildMonthlyAttendanceSummaryCsv(array $items): string
    {
        $summaryByEmployee = [];

        foreach ($items as $item) {
            $employeeCode = (string) ($item['employeeCode'] ?? '');
            if ($employeeCode === '') {
                continue;
            }

            $summaryByEmployee[$employeeCode] ??= [
                'employeeCode' => $employeeCode,
                'employeeName' => (string) ($item['employeeName'] ?? ''),
                'workMinutesTotal' => 0,
                'overtimeMinutesTotal' => 0,
                'hourPaidLeaveMinutesTotal' => 0,
                'childCareLeaveMinutesTotal' => 0,
                'nursingCareLeaveMinutesTotal' => 0,
                'manualEditedDays' => 0,
                'errorDays' => 0,
            ];

            $actualWorkMinutes = $this->actualWorkMinutes($item);
            $summaryByEmployee[$employeeCode]['workMinutesTotal'] += $actualWorkMinutes;
            $summaryByEmployee[$employeeCode]['overtimeMinutesTotal'] += max(0, $actualWorkMinutes - 8 * 60);
            $summaryByEmployee[$employeeCode]['hourPaidLeaveMinutesTotal'] += max(0, (int) ($item['hourPaidLeaveMinutes'] ?? 0));
            $summaryByEmployee[$employeeCode]['childCareLeaveMinutesTotal'] += max(0, (int) ($item['childCareLeaveMinutes'] ?? 0));
            $summaryByEmployee[$employeeCode]['nursingCareLeaveMinutesTotal'] += max(0, (int) ($item['nursingCareLeaveMinutes'] ?? 0));
            $summaryByEmployee[$employeeCode]['manualEditedDays'] += !empty($item['isManuallyEdited']) ? 1 : 0;
            $summaryByEmployee[$employeeCode]['errorDays'] += ($item['alertSummary'] ?? '-') !== '-' ? 1 : 0;
        }

        ksort($summaryByEmployee, SORT_NATURAL);

        $rows = [[
            '個人Ｃ',
            '氏名',
            '時給１時間',
            '前月普通残業１',
            '時間有給',
            '子の看護等',
            '介護休暇',
            '手動補正日数',
            'エラー日数',
        ]];

        foreach ($summaryByEmployee as $row) {
            $rows[] = [
                $row['employeeCode'],
                $row['employeeName'],
                $this->formatMinutesAsHoursAndMinutes((int) $row['workMinutesTotal']),
                $this->formatMinutesAsHoursAndMinutes((int) $row['overtimeMinutesTotal']),
                $this->formatMinutesAsHoursAndMinutes((int) $row['hourPaidLeaveMinutesTotal']),
                $this->formatMinutesAsHoursAndMinutes((int) $row['childCareLeaveMinutesTotal']),
                $this->formatMinutesAsHoursAndMinutes((int) $row['nursingCareLeaveMinutesTotal']),
                (string) $row['manualEditedDays'],
                (string) $row['errorDays'],
            ];
        }

        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $escaped = array_map(function (string $value): string {
                $value = str_replace('"', '""', $value);
                return '"' . $value . '"';
            }, $row);
            $csv .= implode(',', $escaped) . "\r\n";
        }

        return $csv;
    }

    private function buildMonthlyWorksSummary(array $rows): array
    {
        $attendanceSummary = [
            '出勤日数' => 0,
            '欠勤日数' => 0,
            '特別休暇' => 0,
            '打刻漏れ' => 0,
            '承認済' => 0,
            '差戻し' => 0,
        ];
        $timeSummary = [
            '拘束時間' => 0,
            '休憩時間' => 0,
            '実働時間' => 0,
            '普通残業' => 0,
        ];
        $leaveSummary = [
            '有給休暇' => 0.0,
            '時間有給休暇' => 0,
            '子の看護等休暇' => 0,
            '介護休暇' => 0,
        ];
        $workStyleCounts = [];

        foreach ($rows as $row) {
            $actualWorkMinutes = $this->actualWorkMinutes($row);
            $workMinutes = max(0, (int) ($row['workMinutes'] ?? 0));
            $spanMinutes = $this->clockSpanMinutes($row);
            $breakMinutes = max(0, (int) ($row['breakMinutes'] ?? 0));
            $workStyleName = (string) ($row['workStyleName'] ?? '未設定');

            if ($actualWorkMinutes > 0) {
                $attendanceSummary['出勤日数']++;
            }
            if (!empty($row['absenceFlag'])) {
                $attendanceSummary['欠勤日数']++;
            }
            if (!empty($row['specialLeaveFlag'])) {
                $attendanceSummary['特別休暇']++;
            }
            if (($row['clockInAt'] && !$row['clockOutAt']) || (!$row['clockInAt'] && $row['clockOutAt'])) {
                $attendanceSummary['打刻漏れ']++;
            }
            if (($row['approvalStatus'] ?? null) === 'APPROVED') {
                $attendanceSummary['承認済']++;
            }
            if (($row['approvalStatus'] ?? null) === 'RETURNED') {
                $attendanceSummary['差戻し']++;
            }

            $timeSummary['拘束時間'] += $spanMinutes ?? ($workMinutes + $breakMinutes);
            $timeSummary['休憩時間'] += $breakMinutes;
            $timeSummary['実働時間'] += $actualWorkMinutes;
            $timeSummary['普通残業'] += max(0, $actualWorkMinutes - 8 * 60);

            $leaveSummary['有給休暇'] += (float) ($row['paidLeaveUnit'] ?? 0);
            $leaveSummary['時間有給休暇'] += max(0, (int) ($row['hourPaidLeaveMinutes'] ?? 0));
            $leaveSummary['子の看護等休暇'] += max(0, (int) ($row['childCareLeaveMinutes'] ?? 0));
            $leaveSummary['介護休暇'] += max(0, (int) ($row['nursingCareLeaveMinutes'] ?? 0));

            $workStyleCounts[$workStyleName] ??= 0;
            $workStyleCounts[$workStyleName]++;
        }

        arsort($workStyleCounts, SORT_NUMERIC);

        return [
            'attendance' => $attendanceSummary,
            'time' => $timeSummary,
            'leave' => $leaveSummary,
            'workStyles' => $workStyleCounts,
        ];
    }

    private function buildMonthlyWorksPdfHtml(object $employee, string $targetMonth, array $rows, array $summary, string $fontFamily): string
    {
        $monthStart = CarbonImmutable::parse($targetMonth . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $companyName = $this->escape((string) config('staffhub.company_name', self::DEFAULT_COMPANY_NAME));
        $locationName = $this->escape((string) config('staffhub.location_name', self::DEFAULT_LOCATION_NAME));
        $monthLabel = $this->escape($monthStart->format('Y年m月') . '度（' . $monthStart->format('m/d') . '～' . $monthEnd->format('m/d') . '） 日次勤怠');
        $employeeCode = $this->escape((string) $employee->employee_code);
        $employeeName = $this->escape((string) $employee->name);
        $employmentType = $this->escape((string) ($employee->employment_type ?? '-'));
        $departmentName = $this->escape((string) ($employee->department_name ?? '-'));
        $targetMonthLabel = $this->escape($targetMonth);
        $resolvedFontFamily = $this->escape($fontFamily);
        $dailyWidths = $this->monthlyWorksDailyColumnWidths();
        $dateWidth = $dailyWidths['date'];
        $workStyleWidth = $dailyWidths['workStyle'];
        $clockInWidth = $dailyWidths['clockIn'];
        $clockOutWidth = $dailyWidths['clockOut'];
        $workMinutesWidth = $dailyWidths['workMinutes'];
        $breakMinutesWidth = $dailyWidths['breakMinutes'];
        $paidLeaveWidth = $dailyWidths['paidLeave'];
        $hourPaidLeaveWidth = $dailyWidths['hourPaidLeave'];
        $childCareWidth = $dailyWidths['childCare'];
        $nursingCareWidth = $dailyWidths['nursingCare'];
        $overtimeWidth = $dailyWidths['overtime'];
        $actualWorkWidth = $dailyWidths['actualWork'];
        $remarkWidth = $dailyWidths['remark'];

        if ($rows === []) {
            $dailyRowsHtml = '<tr><td colspan="13">対象月の勤務データがありません。</td></tr>';
        } else {
            $dailyRowsHtml = implode('', array_map(function (array $row) use ($dailyWidths): string {
                $actualWorkMinutes = $this->actualWorkMinutes($row);
                $overtimeMinutes = max(0, $actualWorkMinutes - 8 * 60);
                $spanMinutes = $this->clockSpanMinutes($row);

                return '<tr>'
                    . '<td width="' . $dailyWidths['date'] . '" nowrap="nowrap">' . $this->escape($this->formatMonthDayWithWeekday($row['targetDate'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['workStyle'] . '" nowrap="nowrap">' . $this->escape((string) ($row['workStyleName'] ?? '-')) . '</td>'
                    . '<td width="' . $dailyWidths['clockIn'] . '" nowrap="nowrap">' . $this->escape($this->formatTimeOnly($row['clockInAt'] ?? null) ?: '-') . '</td>'
                    . '<td width="' . $dailyWidths['clockOut'] . '" nowrap="nowrap">' . $this->escape($this->formatTimeOnly($row['clockOutAt'] ?? null) ?: '-') . '</td>'
                    . '<td width="' . $dailyWidths['workMinutes'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($spanMinutes)) . '</td>'
                    . '<td width="' . $dailyWidths['breakMinutes'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($row['breakMinutes'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['paidLeave'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatDayUnitCell($row['paidLeaveUnit'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['hourPaidLeave'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($row['hourPaidLeaveMinutes'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['childCare'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($row['childCareLeaveMinutes'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['nursingCare'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($row['nursingCareLeaveMinutes'] ?? null)) . '</td>'
                    . '<td width="' . $dailyWidths['overtime'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($overtimeMinutes)) . '</td>'
                    . '<td width="' . $dailyWidths['actualWork'] . '" class="amount" nowrap="nowrap">' . $this->escape($this->formatMinutesCell($actualWorkMinutes)) . '</td>'
                    . '<td width="' . $dailyWidths['remark'] . '">' . $this->escape((string) ($row['remark'] ?? '-')) . '</td>'
                    . '</tr>';
            }, $rows));
        }

        $attendanceSummaryHtml = $this->buildSummaryTableRows($summary['attendance'], false);
        $timeSummaryHtml = $this->buildSummaryTableRows($summary['time'], true);
        $leaveSummaryHtml = $this->buildSummaryTableRows($summary['leave'], true, ['有給休暇' => 'DAY']);
        $workStyleSummaryHtml = $this->buildSummaryTableRows($summary['workStyles'], false, [], '日');

        return <<<HTML
<style>
    body, table, th, td, h1, h2 { font-family: {$resolvedFontFamily}; font-weight: normal; }
    h1 { font-size: 16px; text-align: center; margin: 0 0 4px; }
    h2 { font-size: 11px; text-align: center; margin: 0 0 8px; }
    table { border-collapse: collapse; }
    .meta td, .meta th { border: 1px solid #666; padding: 3px 5px; font-size: 7px; }
    .daily { table-layout: fixed; }
    .daily td, .daily th { border: 1px solid #777; padding: 1px 2px; font-size: 5.7px; line-height: 1.25; }
    .daily th, .summary th { background-color: #f1f5f9; }
    .summary td, .summary th { border: 1px solid #777; padding: 3px 5px; font-size: 7px; }
    .amount { text-align: right; }
    .summary-wrap td { vertical-align: top; }
</style>
<h1>{$companyName}</h1>
<h2>{$monthLabel}</h2>
<table class="meta" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <th width="12%">社員番号</th>
        <td width="18%">{$employeeCode}</td>
        <th width="12%">氏名</th>
        <td width="18%">{$employeeName}</td>
        <th width="12%">雇用形態</th>
        <td width="28%">{$employmentType}</td>
    </tr>
    <tr>
        <th>部門名</th>
        <td>{$departmentName}</td>
        <th>拠点名</th>
        <td>{$locationName}</td>
        <th>対象月</th>
        <td>{$targetMonthLabel}</td>
    </tr>
</table>
<br />
<table class="daily" width="100%" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th width="{$dateWidth}" nowrap="nowrap">日付</th>
            <th width="{$workStyleWidth}" nowrap="nowrap">勤務区分</th>
            <th width="{$clockInWidth}" nowrap="nowrap">出勤時刻</th>
            <th width="{$clockOutWidth}" nowrap="nowrap">退勤時刻</th>
            <th width="{$workMinutesWidth}" nowrap="nowrap">拘束時間</th>
            <th width="{$breakMinutesWidth}" nowrap="nowrap">休憩時間</th>
            <th width="{$paidLeaveWidth}" nowrap="nowrap">休暇</th>
            <th width="{$hourPaidLeaveWidth}" nowrap="nowrap">時間有給</th>
            <th width="{$childCareWidth}" nowrap="nowrap">子の看護等</th>
            <th width="{$nursingCareWidth}" nowrap="nowrap">介護休暇</th>
            <th width="{$overtimeWidth}" nowrap="nowrap">残業時間</th>
            <th width="{$actualWorkWidth}" nowrap="nowrap">実働時間</th>
            <th width="{$remarkWidth}">備考</th>
        </tr>
    </thead>
    <tbody>
        {$dailyRowsHtml}
    </tbody>
</table>
<br />
<table class="summary-wrap" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="50%">
            <table class="summary" width="98%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">出勤状況</th></tr>
                {$attendanceSummaryHtml}
            </table>
        </td>
        <td width="50%">
            <table class="summary" width="98%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">勤務時間</th></tr>
                {$timeSummaryHtml}
            </table>
        </td>
    </tr>
    <tr>
        <td width="50%">
            <table class="summary" width="98%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">休日・休暇取得</th></tr>
                {$leaveSummaryHtml}
            </table>
        </td>
        <td width="50%">
            <table class="summary" width="98%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">勤務区分</th></tr>
                {$workStyleSummaryHtml}
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function buildSummaryTableRows(array $items, bool $minutesAsTime = false, array $dayKeys = [], string $defaultSuffix = ''): string
    {
        if ($items === []) {
            return '<tr><td colspan="2">該当なし</td></tr>';
        }

        $rows = [];
        foreach ($items as $label => $value) {
            if (isset($dayKeys[$label]) && $dayKeys[$label] === 'DAY') {
                $formattedValue = $this->formatDayCount((float) $value);
            } elseif ($minutesAsTime) {
                $formattedValue = $this->formatMinutesCell((int) $value);
            } elseif ($defaultSuffix !== '') {
                $formattedValue = (string) $value . $defaultSuffix;
            } else {
                $formattedValue = (string) $value;
            }

            $rows[] = '<tr><td width="65%">' . $this->escape((string) $label) . '</td><td width="35%" class="amount">' . $this->escape($formattedValue) . '</td></tr>';
        }

        return implode('', $rows);
    }

    private function monthlyWorksDailyColumnWidths(): array
    {
        return [
            'date' => '8%',
            'workStyle' => '10%',
            'clockIn' => '7%',
            'clockOut' => '7%',
            'workMinutes' => '7%',
            'breakMinutes' => '6%',
            'paidLeave' => '5%',
            'hourPaidLeave' => '6%',
            'childCare' => '8%',
            'nursingCare' => '6%',
            'overtime' => '7%',
            'actualWork' => '7%',
            'remark' => '16%',
        ];
    }

    private function resolveMonthlyWorksPdfFontFamily(): string
    {
        static $fontFamily = null;

        if (is_string($fontFamily) && $fontFamily !== '') {
            return $fontFamily;
        }

        $configuredFontPath = $this->resolvePdfFontPath((string) config('staffhub.pdf_font_path', ''));
        $candidates = array_filter(array_unique([
            $configuredFontPath,
            storage_path('app/fonts/BIZUDGothic-Regular.ttf'),
            storage_path('app/fonts/BIZUDGothic-Regular.otf'),
            resource_path('fonts/BIZUDGothic-Regular.ttf'),
            resource_path('fonts/BIZUDGothic-Regular.otf'),
            public_path('fonts/BIZUDGothic-Regular.ttf'),
            public_path('fonts/BIZUDGothic-Regular.otf'),
        ]));

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || !is_file($candidate)) {
                continue;
            }

            try {
                $registered = TCPDF_FONTS::addTTFfont($candidate);
                if (is_string($registered) && $registered !== '') {
                    $fontFamily = $registered;
                    return $fontFamily;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $fontFamily = 'kozminproregular';
        return $fontFamily;
    }

    private function resolvePdfFontPath(string $value): string
    {
        $path = trim($value);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function actualWorkMinutes(array $row): int
    {
        if (($row['workMinutes'] ?? null) !== null) {
            return max(0, (int) $row['workMinutes']);
        }

        $spanMinutes = $this->clockSpanMinutes($row);
        if ($spanMinutes === null) {
            return 0;
        }

        $breakMinutes = max(0, (int) ($row['breakMinutes'] ?? 0));

        return max(0, $spanMinutes - $breakMinutes);
    }

    private function clockSpanMinutes(array $row): ?int
    {
        if (empty($row['clockInAt']) || empty($row['clockOutAt'])) {
            return null;
        }

        try {
            return max(0, CarbonImmutable::parse((string) $row['clockOutAt'])->diffInMinutes(CarbonImmutable::parse((string) $row['clockInAt']), true));
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatMinutesCell(?int $minutes): string
    {
        if ($minutes === null) {
            return '-';
        }

        return $this->formatMinutesAsHoursAndMinutes($minutes);
    }

    private function formatDayUnitCell(mixed $days): string
    {
        if ($days === null) {
            return '-';
        }

        $value = (float) $days;
        if (abs($value) < 0.0001) {
            return '-';
        }

        return $this->formatDayCount($value);
    }

    private function formatLeaveSummary(array $row): string
    {
        $parts = [];

        if (($row['paidLeaveUnit'] ?? null) !== null && (float) $row['paidLeaveUnit'] > 0) {
            $parts[] = '有給' . $this->formatDayCount((float) $row['paidLeaveUnit']);
        }

        foreach ([
            'hourPaidLeaveMinutes' => '時間有給',
            'childCareLeaveMinutes' => '子の看護等',
            'nursingCareLeaveMinutes' => '介護休暇',
        ] as $key => $label) {
            $minutes = max(0, (int) ($row[$key] ?? 0));
            if ($minutes > 0) {
                $parts[] = $label . $this->formatMinutesAsHoursAndMinutes($minutes);
            }
        }

        return $parts === [] ? '-' : implode(' / ', $parts);
    }

    private function formatDayCount(float $days): string
    {
        $formatted = rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
        return ($formatted === '' ? '0' : $formatted) . '日';
    }

    private function formatMinutesAsHoursAndMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remain = $minutes % 60;

        return $hours . '時間' . str_pad((string) $remain, 2, '0', STR_PAD_LEFT) . '分';
    }

    private function formatMonthDayWithWeekday(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $date = CarbonImmutable::parse($value);
            return $date->format('m/d') . '(' . $this->formatWeekday($date->toDateString()) . ')';
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatWeekday(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return ['日', '月', '火', '水', '木', '金', '土'][CarbonImmutable::parse($value)->dayOfWeek];
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatDateOnly(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('Y/n/j');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatTimeOnly(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
