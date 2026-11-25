<?php

namespace App\Services\Reports;

use App\Services\AttendanceService;
use TCPDF;

final class AttendanceReportExportService
{
    use ReportFormatting;

    public function __construct(private readonly AttendanceService $attendanceService) {}

    public function exportMonthlyAttendanceCsv(string $targetMonth, array $filters = [], ?int &$rowCount = null): string
    {
        $grid = $this->attendanceService->listMonthlyCalendar([...$filters, 'targetMonth' => $targetMonth, 'periodBasis' => 'PAYROLL']);

        return $this->buildMonthlyAttendanceSummaryCsv($grid['items'], $rowCount);
    }

    public function exportDailyAttendanceCsv(array $filters, ?int &$rowCount = null): string
    {
        $query = [
            'from' => $filters['from'] ?? now()->toDateString(),
            'to' => $filters['to'] ?? now()->toDateString(),
            'status' => 'ALL',
            'employeeCode' => $filters['employeeCode'] ?? null,
            'departmentName' => $filters['departmentName'] ?? null,
            'perPage' => 1000,
        ];
        $items = [];
        for ($page = 1; ; $page++) {
            $result = $this->attendanceService->listApprovals([...$query, 'page' => $page]);
            $items = [...$items, ...$result['items']];
            if ($result['items'] === [] || count($items) >= $result['meta']['total']) { break; }
        }

        $rowCount = count($items);
        return $this->buildAttendanceCsv($items);
    }

    public function exportDailyAttendancePdf(string $targetMonth, array $filters = []): string
    {
        $grid = $this->attendanceService->listDailyGrid([...$filters, 'targetMonth' => $targetMonth]);
        $this->ensureTcpdfCurlConstants();

        $pdf = new TCPDF;
        $pdf->SetCreator('勤怠管理');
        $pdf->SetAuthor('勤怠管理');
        $pdf->SetTitle('日次勤怠一覧');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage('L', 'A4');
        $fontFamily = $this->resolveReportPdfFontFamily();
        $pdf->SetFont($fontFamily, '', 8);

        $html = '<style>body, table, th, td, h2 { font-family: '.htmlspecialchars($fontFamily, ENT_QUOTES, 'UTF-8').'; }</style>';
        $html .= '<h2>日次勤怠一覧 '.htmlspecialchars($targetMonth, ENT_QUOTES, 'UTF-8').'</h2>';
        $html .= '<p>休憩・勤務時間は 時間:分 で表示しています。</p>';
        $html .= '<table border="1" cellpadding="3" width="100%">';
        $html .= '<thead><tr>'
            .'<th width="5%">職員番号</th><th width="7%">氏名</th><th width="8%">日付</th><th width="6%">勤務区分</th><th width="6%">原本出勤</th><th width="6%">原本退勤</th><th width="6%">補正出勤</th><th width="6%">補正退勤</th><th width="6%">休憩</th><th width="7%">勤務時間</th><th width="6%">休暇</th><th width="5%">手動補正</th><th width="11%">エラー</th><th width="6%">承認状態</th><th width="9%">コメント</th>'
            .'</tr></thead><tbody>';
        $duration = static fn (?int $minutes): string => $minutes === null ? '-' : sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);

        foreach ($grid['items'] as $item) {
            $note = trim(implode(' / ', array_filter([
                (string) ($item['supervisorComment'] ?? ''),
                (string) ($item['remark'] ?? ''),
            ])));
            $approvalLabel = match ($item['approvalStatus'] ?? null) {
                'PENDING' => '要確認', 'NOT_REQUIRED' => '承認不要', 'NOT_DUE' => '勤務中・予定', 'APPROVED' => '承認済み', 'REJECTED', 'RETURNED' => '差戻し', default => '未確認',
            };
            $html .= '<tr nobr="true">'
                .'<td width="5%">'.htmlspecialchars((string) $item['employeeCode'], ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="7%">'.htmlspecialchars((string) $item['employeeName'], ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="8%">'.htmlspecialchars($this->formatDateOnly($item['targetDate'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars((string) ($item['workStyleName'] ?? '-'), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($this->formatTimeOnly($item['rawClockInAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($this->formatTimeOnly($item['rawClockOutAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($this->formatTimeOnly($item['clockInAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($this->formatTimeOnly($item['clockOutAt'] ?? null) ?: '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($duration((int) ($item['breakMinutes'] ?? 0)), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="7%">'.htmlspecialchars($duration(($item['workMinutes'] ?? null) !== null ? (int) $item['workMinutes'] : null), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.htmlspecialchars($this->formatLeaveSummary($item), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="5%">'.htmlspecialchars(! empty($item['isManuallyEdited']) ? 'あり' : '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="11%">'.htmlspecialchars((string) ($item['alertSummary'] ?? '-'), ENT_QUOTES, 'UTF-8').'</td>'
                .'<td width="6%">'.$approvalLabel.'</td>'
                .'<td width="9%">'.htmlspecialchars($note !== '' ? $note : '-', ENT_QUOTES, 'UTF-8').'</td>'
                .'</tr>';
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
            '子の看護等日数',
            '介護休暇日数',
            '特休日数',
            '欠勤日数',
        ];

        $rows = [$header];

        foreach ($items as $item) {
            $leave = $this->leaveQuantities($item);
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
                ! empty($item['isManuallyEdited']) ? 'あり' : '',
                (string) ($item['breakMinutes'] ?? 0),
                (string) ($item['workMinutes'] ?? ''),
                (string) ($item['paidLeaveUnit'] ?? ''),
                (string) $leave['hourPaidLeaveMinutes'],
                (string) $leave['childCareLeaveMinutes'],
                (string) $leave['nursingCareLeaveMinutes'],
                (string) ($item['approvalStatus'] ?? ''),
                (string) ($item['supervisorComment'] ?? ''),
                (string) ($item['alertSummary'] ?? ''),
                (string) ($item['remark'] ?? ''),
                (string) $leave['childCareLeaveDays'],
                (string) $leave['nursingCareLeaveDays'],
                (string) (!empty($item['specialLeaveFlag']) ? ($item['requestLeaveDays'] ?? 1) : 0),
                (string) (!empty($item['absenceFlag']) ? ($item['requestLeaveDays'] ?? 1) : 0),
            ];
        }

        return $this->buildUtf8Csv($rows, ['職員番号', '氏名', '所属', '勤務区分', '所属長コメント', 'エラー概要', '備考']);
    }

    private function buildMonthlyAttendanceSummaryCsv(array $items, ?int &$rowCount): string
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
                'childCareLeaveDaysTotal' => 0,
                'nursingCareLeaveDaysTotal' => 0,
                'specialLeaveDays' => 0,
                'absenceDays' => 0,
                'manualEditedDays' => 0,
                'errorDays' => 0,
            ];

            $actualWorkMinutes = $this->actualWorkMinutes($item);
            $summaryByEmployee[$employeeCode]['workMinutesTotal'] += $actualWorkMinutes;
            $summaryByEmployee[$employeeCode]['overtimeMinutesTotal'] += $this->overtimeMinutes($item, $actualWorkMinutes);
            foreach ($this->leaveQuantities($item) as $key => $value) {
                if ($key !== 'paidLeaveDays') {
                    $summaryByEmployee[$employeeCode][$key.'Total'] += $value;
                }
            }
            $summaryByEmployee[$employeeCode]['specialLeaveDays'] += !empty($item['specialLeaveFlag']) ? ($item['requestLeaveDays'] ?? 1) : 0;
            $summaryByEmployee[$employeeCode]['absenceDays'] += !empty($item['absenceFlag']) ? ($item['requestLeaveDays'] ?? 1) : 0;
            $summaryByEmployee[$employeeCode]['manualEditedDays'] += ! empty($item['isManuallyEdited']) ? 1 : 0;
            $summaryByEmployee[$employeeCode]['errorDays'] += ($item['alertSummary'] ?? '-') !== '-' ? 1 : 0;
        }

        $rowCount = count($summaryByEmployee);
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
            '子の看護等日数',
            '介護休暇日数',
            '特休日数',
            '欠勤日数',
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
                (string) $row['childCareLeaveDaysTotal'],
                (string) $row['nursingCareLeaveDaysTotal'],
                (string) $row['specialLeaveDays'],
                (string) $row['absenceDays'],
            ];
        }

        return $this->buildUtf8Csv($rows, ['個人Ｃ', '氏名']);
    }
}
