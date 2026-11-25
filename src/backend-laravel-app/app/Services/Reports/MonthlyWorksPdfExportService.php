<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MonthlyWorksPdfExportService
{
    use ReportFormatting;

    private const DEFAULT_COMPANY_NAME = '社会福祉法人 誠心福祉会 池上わかばこども園';

    private const DEFAULT_LOCATION_NAME = '池上わかばこども園';

    public function __construct(private readonly AttendanceService $attendanceService) {}

    public function exportMonthlyWorksPdf(int $employeeId, string $targetMonth): array
    {
        $employee = DB::table('employees as e')
            ->leftJoin('employment_type_settings as ets', 'ets.code', '=', 'e.employment_type')
            ->where('e.id', $employeeId)
            ->selectRaw(\Illuminate\Support\Facades\Schema::hasColumn('employees', 'is_dependent_within') ? 'e.is_dependent_within' : '0 as is_dependent_within')
            ->addSelect(['legacy_calendar_month' => AttendancePeriod::legacyMonthFor($targetMonth, 'e.id')])
            ->addSelect([
                'e.id', 'e.employee_code', 'e.name', 'e.department_name', 'e.employment_type', 'ets.label as employment_type_label',
            ])
            ->first([
                'e.id',
                'e.employee_code',
                'e.name',
                'e.department_name',
                'e.employment_type',
                'ets.label as employment_type_label',
            ]);

        if ($employee === null) {
            throw new ApiException('NOT_FOUND', '対象職員が見つかりません。', 404);
        }

        $calendar = $this->attendanceService->listMonthlyCalendar([
            'targetMonth' => $targetMonth,
            'periodBasis' => 'PAYROLL',
            'employeeCode' => (string) $employee->employee_code,
        ]);

        $rows = array_values(array_filter(
            $calendar['items'],
            static fn (array $item): bool => (int) ($item['employeeId'] ?? 0) === $employeeId
        ));
        [$periodStart, $periodEnd] = AttendancePeriod::forEmployee($targetMonth, (string) $employee->employment_type, (bool) $employee->is_dependent_within, $employee->legacy_calendar_month ?? null);
        $rows = $this->buildFullMonthRows($targetMonth, $rows, $periodStart, $periodEnd);
        $summary = $this->buildMonthlyWorksSummary($rows);
        $this->ensureTcpdfCurlConstants();

        $fontFamily = $this->resolveReportPdfFontFamily();
        $pdf = new MonthlyWorksReportPdf([
            'code' => (string) $employee->employee_code,
            'name' => (string) $employee->name,
            'month' => $targetMonth,
            'start' => $periodStart->format('Y/m/d'),
            'end' => $periodEnd->format('Y/m/d'),
        ], $fontFamily);
        $pdf->SetCreator('勤怠管理');
        $pdf->SetAuthor('勤怠管理');
        $pdf->SetTitle('月次勤務表');
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 19);
        $pdf->AddPage();
        $pdf->SetFont($fontFamily, '', 7.2);
        $pdf->writeHTML($this->buildMonthlyWorksPdfHtml($employee, $targetMonth, $rows, $summary, $fontFamily), true, false, true, false, '');

        return [
            'content' => $pdf->Output('', 'S'),
            'fileName' => 'works_'.$employee->employee_code.'_'.$targetMonth.'.pdf',
            'contentType' => 'application/pdf',
            'summary' => [
                'employeeId' => (int) $employee->id,
                'employeeCode' => (string) $employee->employee_code,
                'employeeName' => (string) $employee->name,
                'targetMonth' => $targetMonth,
                'periodStart' => $periodStart->toDateString(),
                'periodEnd' => $periodEnd->toDateString(),
                'calendarDays' => count($rows),
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
            '勤務時間' => 0,
            '休憩時間' => 0,
            '実働時間' => 0,
            '普通残業' => 0,
        ];
        $leaveSummary = [
            '有給休暇' => 0.0,
            '時間有給休暇' => 0,
            '子の看護等休暇' => 0,
            '介護休暇' => 0,
            '子の看護等休暇（日）' => 0.0,
            '介護休暇（日）' => 0.0,
        ];
        $workStyleCounts = [];

        foreach ($rows as $row) {
            if (! empty($row['_isBlankCalendarDay'])) {
                continue;
            }

            $actualWorkMinutes = $this->actualWorkMinutes($row);
            $workMinutes = max(0, (int) ($row['workMinutes'] ?? 0));
            $spanMinutes = $this->clockSpanMinutes($row);
            $breakMinutes = max(0, (int) ($row['breakMinutes'] ?? 0));
            $workStyleName = (string) ($row['workStyleName'] ?? '未設定');

            if ($actualWorkMinutes > 0) {
                $attendanceSummary['出勤日数']++;
            }
            if (! empty($row['absenceFlag'])) {
                $attendanceSummary['欠勤日数'] += $row['requestLeaveDays'] ?? 1;
            }
            if (! empty($row['specialLeaveFlag'])) {
                $attendanceSummary['特別休暇'] += $row['requestLeaveDays'] ?? 1;
            }
            if (($row['approvalStatus'] ?? null) === 'PENDING' && str_contains((string)($row['approvalReviewReason'] ?? ''), '原打刻')
                || ($row['clockInAt'] && ! $row['clockOutAt']) || (! $row['clockInAt'] && $row['clockOutAt'])
                || (($row['requestLeaveDays'] ?? null) === 0.5 && ($row['dayState'] ?? '') === 'MISSING')) {
                $attendanceSummary['打刻漏れ']++;
            }
            if (($row['approvalStatus'] ?? null) === 'APPROVED') {
                $attendanceSummary['承認済']++;
            }
            if (($row['approvalStatus'] ?? null) === 'RETURNED') {
                $attendanceSummary['差戻し']++;
            }

            $timeSummary['勤務時間'] += $spanMinutes ?? ($workMinutes + $breakMinutes);
            $timeSummary['休憩時間'] += $breakMinutes;
            $timeSummary['実働時間'] += array_key_exists('reportWorkMinutes', $row) ? (int) $row['reportWorkMinutes'] : $actualWorkMinutes;
            $timeSummary['普通残業'] += $this->overtimeMinutes($row, $actualWorkMinutes);

            $leave = $this->leaveQuantities($row);
            $leaveSummary['有給休暇'] += $leave['paidLeaveDays'];
            $leaveSummary['時間有給休暇'] += $leave['hourPaidLeaveMinutes'];
            $leaveSummary['子の看護等休暇'] += $leave['childCareLeaveMinutes'];
            $leaveSummary['子の看護等休暇（日）'] += $leave['childCareLeaveDays'];
            $leaveSummary['介護休暇（日）'] += $leave['nursingCareLeaveDays'];
            $leaveSummary['介護休暇'] += $leave['nursingCareLeaveMinutes'];

            $workStyleCounts[$workStyleName] ??= 0;
            $workStyleCounts[$workStyleName] += $row['requestLeaveDays'] ?? 1;
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
        $monthStart = CarbonImmutable::parse($targetMonth.'-01')->startOfMonth();
        [$periodStart, $monthEnd] = AttendancePeriod::forEmployee($targetMonth, (string) $employee->employment_type, (bool) $employee->is_dependent_within, $employee->legacy_calendar_month ?? null);
        $companyName = $this->escape((string) config('staffhub.company_name', self::DEFAULT_COMPANY_NAME));
        $locationName = $this->escape((string) config('staffhub.location_name', self::DEFAULT_LOCATION_NAME));
        $monthLabel = $this->escape($monthStart->format('Y年m月').'度（'.$periodStart->format('Y/m/d').'～'.$monthEnd->format('Y/m/d').'） 日次勤怠');
        $employeeCode = $this->escape((string) $employee->employee_code);
        $employeeName = $this->escape((string) $employee->name);
        $employmentType = $this->escape($this->employmentTypeDisplayLabel(
            $employee->employment_type ?? null,
            $employee->employment_type_label ?? null,
        ));
        $departmentName = $this->escape((string) ($employee->department_name ?? '-'));
        $targetMonthLabel = $this->escape($targetMonth);
        $resolvedFontFamily = $this->escape($fontFamily);
        $dailyWidths = $this->monthlyWorksDailyColumnWidths();
        $dateWidth = $dailyWidths['date'];
        $workStyleWidth = $dailyWidths['workStyle'];
        $clockInWidth = $dailyWidths['clockIn'];
        $clockOutWidth = $dailyWidths['clockOut'];
        $breakMinutesWidth = $dailyWidths['breakMinutes'];
        $paidLeaveWidth = $dailyWidths['paidLeave'];
        $hourPaidLeaveWidth = $dailyWidths['hourPaidLeave'];
        $overtimeWidth = $dailyWidths['overtime'];
        $actualWorkWidth = $dailyWidths['actualWork'];
        $remarkWidth = $dailyWidths['remark'];
        $childCareWidth = $dailyWidths['childCare'];
        $nursingCareWidth = $dailyWidths['nursingCare'];

        if ($rows === []) {
            $dailyRowsHtml = '<tr><td colspan="12">対象月の勤務データがありません。</td></tr>';
        } else {
            $dailyRowsHtml = implode('', array_map(function (array $row) use ($dailyWidths): string {
                $isBlankCalendarDay = ! empty($row['_isBlankCalendarDay']);
                $actualWorkMinutes = ($row['workMinutes'] ?? null) !== null ? (int) $row['workMinutes'] : null;
                $overtimeMinutes = $actualWorkMinutes !== null ? $this->overtimeMinutes($row, $actualWorkMinutes) : null;
                $className = $this->monthlyWorksPdfRowClass($row);
                $trClass = $className !== '' ? ' class="'.$className.'"' : '';
                $empty = '-';
                $leave = $this->leaveQuantities($row);

                return '<tr'.$trClass.'>'
                    .'<td width="'.$dailyWidths['date'].'" nowrap="nowrap">'.$this->escape($this->formatMonthDayWithWeekday($row['targetDate'] ?? null)).'</td>'
                    .'<td width="'.$dailyWidths['workStyle'].'" nowrap="nowrap">'.$this->escape($isBlankCalendarDay ? $empty : (string) ($row['workStyleName'] ?? '-')).'</td>'
                    .'<td width="'.$dailyWidths['clockIn'].'" nowrap="nowrap">'.$this->adoptedAndRawTime($row, 'clockInAt', 'rawClockInAt', $isBlankCalendarDay).'</td>'
                    .'<td width="'.$dailyWidths['clockOut'].'" nowrap="nowrap">'.$this->adoptedAndRawTime($row, 'clockOutAt', 'rawClockOutAt', $isBlankCalendarDay).'</td>'
                    .'<td width="'.$dailyWidths['breakMinutes'].'" class="amount" nowrap="nowrap">'.$this->escape(($isBlankCalendarDay || (empty($row['breakMinutes']) && (!empty($row['hourPaidLeaveMinutes']) || !empty($row['paidLeaveUnit'])))) ? $empty : $this->formatMinutesCell($row['breakMinutes'] ?? null)).'</td>'
                    .'<td width="'.$dailyWidths['overtime'].'" class="amount" nowrap="nowrap">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatMinutesCell($overtimeMinutes)).'</td>'
                    .'<td width="'.$dailyWidths['actualWork'].'" class="amount" nowrap="nowrap">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatMinutesCell(array_key_exists('reportWorkMinutes', $row) ? $row['reportWorkMinutes'] : $actualWorkMinutes)).'</td>'
                    .'<td width="'.$dailyWidths['paidLeave'].'" class="amount" nowrap="nowrap">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatDayUnitCell($row['paidLeaveUnit'] ?? null)).'</td>'
                    .'<td width="'.$dailyWidths['hourPaidLeave'].'" class="amount" nowrap="nowrap">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatMinutesCell($row['hourPaidLeaveMinutes'] ?? null)).'</td>'
                    .'<td width="'.$dailyWidths['childCare'].'" class="amount">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatCareLeaveQuantity($leave['childCareLeaveDays'], $leave['childCareLeaveMinutes'])).'</td>'
                    .'<td width="'.$dailyWidths['nursingCare'].'" class="amount">'.$this->escape($isBlankCalendarDay ? $empty : $this->formatCareLeaveQuantity($leave['nursingCareLeaveDays'], $leave['nursingCareLeaveMinutes'])).'</td>'
                    .'<td width="'.$dailyWidths['remark'].'">'.$this->escape($isBlankCalendarDay ? ($row['_holidayName'] ?? '-') : $this->provenanceRemark($row)).'</td>'
                    .'</tr>';
            }, $rows));
        }

        $attendanceSummaryHtml = $this->buildSummaryTableRows($summary['attendance'], false);
        $timeSummaryHtml = $this->buildSummaryTableRows($summary['time'], true);
        $leaveSummaryHtml = $this->buildSummaryTableRows($summary['leave'], true, ['有給休暇' => 'DAY', '子の看護等休暇（日）' => 'DAY', '介護休暇（日）' => 'DAY']);
        $workStyleSummaryHtml = $this->buildSummaryTableRows($summary['workStyles'], false, [], '日');

        return <<<HTML
<style>
    body, table, th, td, h1, h2 { font-family: {$resolvedFontFamily}; font-weight: normal; }
    h1 { font-size: 13px; text-align: center; margin: 0 0 4px; }
    h2 { font-size: 8px; text-align: center; margin: 0 0 6px; color: #475569; }
    table { border-collapse: collapse; }
    .report-title td { font-size: 8px; color: #475569; }
    .report-title .report-name { font-size: 13px; color: #0f172a; }
    .meta td, .meta th { border: 0.45px solid #94a3b8; padding: 2.6px 4px; font-size: 7px; }
    .meta th { background-color: #eaf0f7; color: #334155; }
    .daily { table-layout: fixed; }
    .daily td, .daily th { border: 0.35px solid #b6c2cf; padding: 2.1px 2px; font-size: 6.3px; line-height: 1.15; }
    .daily th, .summary th { background-color: #eaf0f7; color: #1e3a5f; }
    .daily tr.sunday td, .daily tr.holiday td { background-color: #fff1f2; color: #e11d48; }
    .daily tr.saturday td { background-color: #eff6ff; color: #111827; }
    .summary td, .summary th { border: 0.4px solid #b6c2cf; padding: 2.5px 3px; font-size: 6.5px; line-height: 1.25; }
    .amount { text-align: right; }
    .summary-layout > tbody > tr > td { vertical-align: top; padding-right: 3px; }
</style>
<table class="report-title" width="100%" cellpadding="0" cellspacing="0">
    <tr><td width="65%">{$companyName}</td><td width="35%" align="right" class="report-name">月次勤務表</td></tr>
</table>
<h1>{$monthLabel}</h1>
<h2>個別職員の月間勤怠・休暇・勤務時間の集計</h2>
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
            <th width="{$clockInWidth}" nowrap="nowrap">出勤時間/<br />元の打刻</th>
            <th width="{$clockOutWidth}" nowrap="nowrap">退勤時間/<br />元の打刻</th>
            <th width="{$breakMinutesWidth}" nowrap="nowrap">休憩時間</th>
            <th width="{$overtimeWidth}" nowrap="nowrap">残業</th>
            <th width="{$actualWorkWidth}" nowrap="nowrap">実働</th>
            <th width="{$paidLeaveWidth}" nowrap="nowrap">有休</th>
            <th width="{$hourPaidLeaveWidth}" nowrap="nowrap">時間有休</th>
            <th width="{$childCareWidth}">子の看護等</th>
            <th width="{$nursingCareWidth}">介護休暇</th>
            <th width="{$remarkWidth}">採用元・備考</th>
        </tr>
    </thead>
    <tbody>
        {$dailyRowsHtml}
    </tbody>
</table>
<br />
<table class="summary-layout" width="100%" cellpadding="0" cellspacing="0" nobr="true">
    <tr>
        <td width="25%">
            <table class="summary" width="100%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">出勤状況</th></tr>
                {$attendanceSummaryHtml}
            </table>
        </td>
        <td width="25%">
            <table class="summary" width="100%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">勤務時間</th></tr>
                {$timeSummaryHtml}
            </table>
        </td>
        <td width="25%">
            <table class="summary" width="100%" cellpadding="0" cellspacing="0">
                <tr><th colspan="2">休日・休暇取得</th></tr>
                {$leaveSummaryHtml}
            </table>
        </td>
        <td width="25%">
            <table class="summary" width="100%" cellpadding="0" cellspacing="0">
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
                $formattedValue = (string) $value.$defaultSuffix;
            } else {
                $formattedValue = (string) $value;
            }

            $rows[] = '<tr><td width="65%">'.$this->escape((string) $label).'</td><td width="35%" class="amount">'.$this->escape($formattedValue).'</td></tr>';
        }

        return implode('', $rows);
    }

    private function buildFullMonthRows(string $targetMonth, array $rows, ?CarbonImmutable $periodStart = null, ?CarbonImmutable $periodEnd = null): array
    {
        try {
            $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $targetMonth.'-01')->startOfDay();
        } catch (\Throwable) {
            return $rows;
        }

        $rowByDate = [];
        foreach ($rows as $row) {
            if (! empty($row['targetDate'])) {
                $rowByDate[(string) $row['targetDate']] = $row;
            }
        }

        $periodStart ??= $monthStart;
        $periodEnd ??= $monthStart->endOfMonth()->startOfDay();
        $fullRows = [];
        for ($date = $periodStart; $date->lessThanOrEqualTo($periodEnd); $date = $date->addDay()) {
            $dateKey = $date->toDateString();
            $row = $rowByDate[$dateKey] ?? [
                'targetDate' => $dateKey,
                '_isBlankCalendarDay' => true,
            ];
            if (empty($row['id'])) { $row['_isBlankCalendarDay'] = true; }
            $row['_dayOfWeek'] = $date->dayOfWeek;
            $row['_holidayName'] = JapaneseHolidayCalendar::name($dateKey);
            $fullRows[] = $row;
        }

        return $fullRows;
    }

    private function monthlyWorksPdfRowClass(array $row): string
    {
        if (! empty($row['_holidayName'])) {
            return 'holiday';
        }

        $dayOfWeek = $row['_dayOfWeek'] ?? null;
        if ($dayOfWeek === 0) {
            return 'sunday';
        }
        if ($dayOfWeek === 6) {
            return 'saturday';
        }

        return '';
    }

    private function monthlyWorksDailyColumnWidths(): array
    {
        return [
            'date' => '6%',
            'workStyle' => '13%',
            'clockIn' => '8%',
            'clockOut' => '8%',
            'breakMinutes' => '7%',
            'overtime' => '7%',
            'actualWork' => '8%',
            'paidLeave' => '5%',
            'hourPaidLeave' => '6%',
            'childCare' => '6%',
            'nursingCare' => '6%',
            'remark' => '20%',
        ];
    }

    private function adoptedAndRawTime(array $row, string $adoptedKey, string $rawKey, bool $blank): string
    {
        if ($blank) return '-';
        if (empty($row[$adoptedKey]) && empty($row[$rawKey])) return '-';
        return $this->escape($this->formatTimeOnly($row[$adoptedKey] ?? null) ?: '-')
            .'<br /><span style="color:#58677b;font-size:6pt">'.$this->escape($this->formatTimeOnly($row[$rawKey] ?? null) ?: '-').'</span>';
    }

    private function provenanceRemark(array $row): string
    {
        $label = match ($row['timeSource'] ?? '') {
            'MANUAL' => '手動修正', 'SCHEDULE' => '予定補完', 'RULE' => '勤務ルール適用',
            'IMPORTED' => ($row['sourceSystem'] ?? '外部').'取込', default => '',
        };
        return trim(($label ? '['.$label.'] ' : '').($row['remark'] ?? '' )) ?: '-';
    }

    private function employmentTypeDisplayLabel(?string $code, ?string $masterLabel): string
    {
        $label = trim((string) $masterLabel);
        if ($label !== '') {
            return $label;
        }

        return match (trim((string) $code)) {
            'FULL_TIME' => '常勤',
            'PART_TIME' => '非常勤',
            'CONTRACT' => '契約',
            'TEMPORARY' => '臨時',
            '' => '-',
            default => (string) $code,
        };
    }
}
