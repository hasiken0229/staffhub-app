<?php

namespace App\Services\Reports;

use App\Services\AttendanceService;
use TCPDF;

final class AttendanceReportExportService
{
    use ReportFormatting;

    public function __construct(private readonly AttendanceService $attendanceService)
    {
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
        $this->ensureTcpdfCurlConstants();

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

        return $this->buildUtf8Csv($rows);
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

        return $this->buildUtf8Csv($rows);
    }
}
