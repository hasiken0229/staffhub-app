<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\Attendance\ConfirmedAttendancePolicy;
use App\Services\Attendance\DailyConfirmation;
use App\Services\Attendance\MonthMutationLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PayrollReportExportService
{
    use ReportFormatting;

    public function exportMonthlyPayrollCsv(string $targetMonth): array
    {
        AttendancePeriod::forEmployee($targetMonth, 'FULL_TIME');
        return DB::transaction(fn () => $this->exportConfirmedPayrollCsv($targetMonth));
    }

    public function readinessIssue(string $targetMonth): ?array
    {
        try {
            DB::transaction(fn () => $this->confirmedData($targetMonth));
        } catch (ApiException $error) {
            if ($error->errorCode !== 'PAYROLL_NOT_READY') { throw $error; }
            return $error->details[0];
        }
        return null;
    }

    private function confirmedData(string $targetMonth): array
    {
        $monthStart = CarbonImmutable::parse($targetMonth.'-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $partTimeStart = $monthStart->subMonthNoOverflow()->day(21);
        $employees = DB::table('employees')
            ->select(['id', 'employee_code', 'name', 'employment_type', 'joined_on', 'retired_on'])
            ->addSelect(Schema::hasColumn('employees', 'is_dependent_within') ? 'is_dependent_within' : DB::raw('0 as is_dependent_within'))
            ->addSelect(['legacy_calendar_month' => AttendancePeriod::legacyMonthFor($targetMonth, 'employees.id')])
            ->whereDate('joined_on', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($partTimeStart) {
                $query
                    ->whereNull('retired_on')
                    ->orWhereDate('retired_on', '>=', $partTimeStart->toDateString());
            })
            ->orderBy('employee_code')
            ->get();
        $aggregates = [];

        foreach ($employees as $employee) {
            [$periodStart, $periodEnd] = AttendancePeriod::forEmployee($targetMonth, (string) $employee->employment_type, (bool) $employee->is_dependent_within, $employee->legacy_calendar_month ?? null);
            // Only the dates on which this employee belonged to the period are
            // relevant; joining after the payroll cutoff is not a zero-hour row.
            $periodStart = $periodStart->max(CarbonImmutable::parse($employee->joined_on)->startOfDay());
            if ($employee->retired_on !== null) {
                $periodEnd = $periodEnd->min(CarbonImmutable::parse($employee->retired_on)->startOfDay());
            }
            if ($periodStart->gt($periodEnd)) {
                continue;
            }
            $employeeKey = (string) $employee->employee_code;
            $aggregates[(int) $employee->id] = [
                'employeeCode' => $employeeKey,
                'employeeName' => (string) $employee->name,
                'employmentType' => (string) $employee->employment_type,
                'periodStart' => $periodStart->toDateString(),
                'periodEnd' => $periodEnd->toDateString(),
                'baseMinutes' => 0,
                'overtimeMinutes' => 0,
                'hasAttendance' => false,
            ];
        }

        $requiredMonths = [$targetMonth];
        foreach ($aggregates as $aggregate) {
            $requiredMonths = array_merge($requiredMonths, MonthMutationLock::months($aggregate['periodStart'], $aggregate['periodEnd']));
        }
        $requiredMonths = array_values(array_unique($requiredMonths));
        sort($requiredMonths, SORT_STRING);
        // Match the month-first order used by attendance writers. Do not create
        // missing close rows or mutate attendance as a side effect of export.
        $closedMonths = DB::table('attendance_monthly_closes')
            ->whereIn('target_year_month', $requiredMonths)->orderBy('target_year_month')
            ->lockForUpdate()->get()->where('status', 'CLOSED')->pluck('target_year_month')->all();
        foreach ($requiredMonths as $month) {
            if (!in_array($month, $closedMonths, true)) {
                $this->notReady('MONTH_NOT_CLOSED', $month.' の月締めが完了していません。', ['targetMonth' => $month]);
            }
        }

        $attendanceRows = DB::table('attendance_daily')
            ->select([
                'attendance_daily.*',
                'employee_id',
                'target_date',
                'clock_in_at as clockInAt',
                'clock_out_at as clockOutAt',
                'break_minutes as breakMinutes',
                'work_minutes as workMinutes',
            ])
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereBetween('target_date', [$partTimeStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('target_date')
            ->sharedLock()
            ->get();

        $byEmployee = $attendanceRows->groupBy('employee_id');
        foreach ($aggregates as $employeeId => $aggregate) {
            $this->assertConfirmedPeriod($employeeId, $aggregate, $byEmployee->get($employeeId, collect()));
        }

        return [$aggregates, $attendanceRows];
    }

    private function exportConfirmedPayrollCsv(string $targetMonth): array
    {
        [$aggregates, $attendanceRows] = $this->confirmedData($targetMonth);
        foreach ($attendanceRows as $row) {
            $employeeId = (int) $row->employee_id;
            $aggregate = $aggregates[$employeeId] ?? null;
            if ($aggregate === null || $row->target_date < $aggregate['periodStart'] || $row->target_date > $aggregate['periodEnd']) {
                continue;
            }

            // Use the reviewed, frozen value. A preview projection or missing
            // value must never silently become paid zero minutes.
            $actualWorkMinutes = (int) $row->work_minutes;
            $aggregates[$employeeId]['baseMinutes'] += $actualWorkMinutes;
            $confirmedBreak = \App\Services\Attendance\BreakReviewPolicy::confirmation($row);
            $restored = ConfirmedAttendancePolicy::hrmosSinglePunchResult($row);
            $aggregates[$employeeId]['overtimeMinutes'] += $this->overtimeMinutes([
                'employmentType' => $aggregate['employmentType'], 'targetDate' => $row->target_date,
                'overtimeMinutesOverride' => $confirmedBreak['overtimeMinutes'] ?? $restored['overtimeMinutes'] ?? null,
            ], $actualWorkMinutes);
            $aggregates[$employeeId]['hasAttendance'] = true;
        }

        uasort($aggregates, fn (array $a, array $b) => strnatcmp($a['employeeCode'], $b['employeeCode']));

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_EXPORT_ERROR', '月次勤務CSVを生成できません。', 500);
        }

        fputcsv($handle, ['個人Ｃ', '氏名', '時給１時間', '前月普通残業１']);

        $totalBaseMinutes = 0;
        $totalOvertimeMinutes = 0;

        foreach ($aggregates as $aggregate) {
            $totalBaseMinutes += (int) $aggregate['baseMinutes'];
            $totalOvertimeMinutes += (int) $aggregate['overtimeMinutes'];

            fputcsv($handle, [
                $aggregate['employeeCode'],
                $aggregate['employeeName'],
                $aggregate['hasAttendance'] ? $this->formatMinutesAsHoursAndMinutes((int) $aggregate['baseMinutes']) : '',
                $aggregate['hasAttendance'] ? $this->formatMinutesAsHoursAndMinutes((int) $aggregate['overtimeMinutes']) : '',
            ]);
        }

        rewind($handle);
        $utf8Csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return [
            'content' => mb_convert_encoding($utf8Csv, 'SJIS-win', 'UTF-8'),
            'fileName' => '給与用_'.now()->format('YmdHi').'.csv',
            'contentType' => 'text/csv; charset=Shift_JIS',
            'summary' => [
                'employeeCount' => count($aggregates),
                'totalBaseMinutes' => $totalBaseMinutes,
                'totalOvertimeMinutes' => $totalOvertimeMinutes,
                'targetMonth' => $targetMonth,
            ],
        ];
    }

    private function assertConfirmedPeriod(int $employeeId, array $period, Collection $rows): void
    {
        $from = $period['periodStart'];
        $to = $period['periodEnd'];
        $dates = [];
        foreach ($rows as $row) {
            if ($row->target_date < $from || $row->target_date > $to) {
                continue;
            }
            $date = (string) $row->target_date;
            $dates[$date] = true;
            $context = ['employeeId' => $employeeId, 'employeeCode' => $period['employeeCode'], 'targetDate' => $date];
            if (!in_array($row->approval_status, ['APPROVED','NOT_REQUIRED'], true) || $row->close_status !== 'CLOSED') {
                $this->notReady('DAILY_NOT_CONFIRMED', $date.' の勤怠の承認・月締めが完了していません。', $context);
            }
            $issue = DailyConfirmation::issue($row);
            if ($issue === 'MISSING_WORK_MINUTES') {
                $this->notReady('MISSING_WORK_MINUTES', $date.' の勤務時間が未確定です。', $context);
            }
            if ($issue !== null) {
                $this->notReady('INCOMPLETE_PUNCHES', $date.' の出退勤が未確定です。予定補完を確認・承認してから出力してください。', $context);
            }
        }

        // A closed month is the authority for calendar completeness. Do not infer
        // workdays from weekends/holidays here, but reject known orphan punches.
        $eventDates = DB::table('attendance_events')->where('employee_id', $employeeId)
            ->where('receive_status', 'ACCEPTED')->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->sharedLock()
            ->pluck('occurred_at');
        foreach ($eventDates as $occurredAt) {
            $date = substr((string) $occurredAt, 0, 10);
            if (!isset($dates[$date])) {
                $this->notReady('MISSING_DAILY', $date.' の打刻に対応する日次勤怠がありません。', ['employeeId' => $employeeId, 'targetDate' => $date]);
            }
        }
        $pendingEdits = DB::table('attendance_daily_edit_requests')->where('employee_id', $employeeId)
            ->whereBetween('target_date', [$from, $to])->whereIn('status', ['PENDING', 'RETURNED'])->sharedLock()->first(['id']);
        $pendingLeave = DB::table('leave_requests')->where('employee_id', $employeeId)
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->whereIn('status', ['PENDING', 'RETURNED'])->sharedLock()->first(['id']);
        if ($pendingEdits || $pendingLeave) {
            $this->notReady('PENDING_REQUESTS', '給与対象期間に未処理・差戻しの申請があります。', ['employeeId' => $employeeId, 'periodStart' => $from, 'periodEnd' => $to]);
        }
    }

    private function notReady(string $reason, string $message, array $context): never
    {
        throw new ApiException('PAYROLL_NOT_READY', '給与用CSVを出力できません。'.$message, 422, [
            array_merge(['field' => 'targetMonth', 'code' => $reason, 'message' => $message], $context),
        ]);
    }
}
