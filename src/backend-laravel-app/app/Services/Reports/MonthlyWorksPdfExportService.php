<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use TCPDF;
use TCPDF_FONTS;

final class MonthlyWorksPdfExportService
{
    use ReportFormatting;

    private const DEFAULT_COMPANY_NAME = '社会福祉法人 誠心福祉会 池上わかばこども園';
    private const DEFAULT_LOCATION_NAME = '池上わかばこども園';

    public function __construct(private readonly AttendanceService $attendanceService)
    {
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
        $this->ensureTcpdfCurlConstants();

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
}
