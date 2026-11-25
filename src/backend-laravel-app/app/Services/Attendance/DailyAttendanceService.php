<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\AuditLogService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
final class DailyAttendanceService extends AttendanceServiceSupport
{
    public function listEvents(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('attendance_events as ae')
            ->leftJoin('employees as e', 'e.id', '=', 'ae.employee_id')
            ->join('attendance_devices as d', 'd.id', '=', 'ae.device_id')
            ->select([
                'ae.id',
                'ae.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'ae.device_id',
                'd.device_code',
                'd.name as device_name',
                'ae.card_uid',
                'ae.occurred_at',
                'ae.event_type',
                'ae.receive_status',
                'ae.rejection_reason',
                'ae.offline_saved',
            ]);

        if (!empty($filters['from'])) {
            $query->whereDate('ae.occurred_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('ae.occurred_at', '<=', $filters['to']);
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['receiveStatus'])) {
            $query->where('ae.receive_status', strtoupper((string) $filters['receiveStatus']));
        }

        if (!empty($filters['deviceCode'])) {
            $query->where('d.device_code', $filters['deviceCode']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ae.occurred_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapEventRow($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function listDaily(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.employment_type',
                'e.department_name',
                'e.location_name',
                'ad.target_date',
                'ad.schedule_name',
                'ad.work_type_id',
                'ad.harmos_total_work_minutes', 'ad.harmos_leave_summary', 'ad.source_system', 'ad.source_import_type', 'ad.approved_by',
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.break_overtime_review',
                'ad.absence_flag',
                'ad.special_leave_flag', 'ad.request_leave_name', 'ad.request_leave_days',
                'ad.paid_leave_unit',
                'ad.hour_paid_leave_minutes',
                'ad.child_care_leave_minutes',
                'ad.nursing_care_leave_minutes',
                'ad.remark',
                'ad.supervisor_comment',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'ad.close_status',
                'ad.manual_edited_at',
            ]);

        if (!empty($filters['targetMonth'])) {
            $this->applyTargetMonthFilter($query, 'ad.target_date', (string) $filters['targetMonth']);
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['departmentName'])) {
            $query->where('e.department_name', 'like', '%' . $filters['departmentName'] . '%');
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ad.target_date')
            ->orderBy('e.employee_code')
            ->forPage($page, $perPage)
            ->get();
        app(DailyApprovalReview::class)->annotate($rows);
        $this->preloadDailyBreaks($rows);
        $alertSettings = $this->resolveAttendanceAlertSettings();
        $shortIntervalAlertMap = $this->buildShortIntervalAlertMap($rows, $alertSettings);

        return [
            'items' => $rows->map(fn (object $row) => $this->mapDailyRow($row, $this->buildAlertsForRow($row, $shortIntervalAlertMap, $alertSettings)))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function listDailyGrid(array $filters): array
    {
        if (($filters['periodBasis'] ?? null) === 'PAYROLL') {
            $calendar = $this->listMonthlyCalendar($filters);
            $calendar['items'] = array_values(array_filter($calendar['items'], static fn (array $row): bool => !empty($row['id'])));
            $calendar['meta']['count'] = count($calendar['items']);
            return $calendar;
        }
        $targetMonth = !empty($filters['targetMonth']) ? (string) $filters['targetMonth'] : now()->format('Y-m');
        $employeeCode = $filters['employeeCode'] ?? null;
        $departmentName = $filters['departmentName'] ?? null;

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.employment_type',
                'e.department_name',
                'e.location_name',
                'ad.target_date',
                'ad.schedule_name',
                'ad.work_type_id',
                'ad.harmos_total_work_minutes', 'ad.harmos_leave_summary', 'ad.source_system', 'ad.source_import_type', 'ad.approved_by',
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.break_overtime_review',
                'ad.absence_flag',
                'ad.special_leave_flag', 'ad.request_leave_name', 'ad.request_leave_days',
                'ad.paid_leave_unit',
                'ad.hour_paid_leave_minutes',
                'ad.child_care_leave_minutes',
                'ad.nursing_care_leave_minutes',
                'ad.remark',
                'ad.supervisor_comment',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'ad.close_status',
                'ad.manual_edited_at',
            ]);

        $this->applyTargetMonthFilter($query, 'ad.target_date', $targetMonth);

        if (!empty($employeeCode)) {
            $query->where('e.employee_code', 'like', '%' . $employeeCode . '%');
        }

        if (!empty($departmentName)) {
            $query->where('e.department_name', 'like', '%' . $departmentName . '%');
        }

        $rows = $query
            ->orderBy('e.employee_code')
            ->orderBy('ad.target_date')
            ->get();
        app(DailyApprovalReview::class)->annotate($rows);
        $this->preloadDailyBreaks($rows);
        $alertSettings = $this->resolveAttendanceAlertSettings();
        $shortIntervalAlertMap = $this->buildShortIntervalAlertMap($rows, $alertSettings);

        return [
            'items' => $rows->map(fn (object $row) => $this->mapGridRow($row, $this->buildAlertsForRow($row, $shortIntervalAlertMap, $alertSettings)))->all(),
            'meta' => [
                'targetMonth' => $targetMonth,
                'count' => $rows->count(),
            ],
        ];
    }

    public function listMonthlyCalendar(array $filters): array
    {
        $targetMonth = !empty($filters['targetMonth']) ? (string) $filters['targetMonth'] : now()->format('Y-m');
        $employeeId = isset($filters['employeeId']) ? (int) $filters['employeeId'] : null;
        $employeeCode = $filters['employeeCode'] ?? null;
        $departmentName = $filters['departmentName'] ?? null;
        $monthStart = CarbonImmutable::parse($targetMonth . '-01')->startOfDay();
        $monthEnd = $monthStart->endOfMonth();
        $payrollPeriod = ($filters['periodBasis'] ?? null) === 'PAYROLL';
        if (isset($filters['onlyDate'])) {
            $monthStart = CarbonImmutable::parse($filters['onlyDate'])->startOfDay();
            $monthEnd = $monthStart->endOfDay();
        }
        $queryStart = $payrollPeriod ? $monthStart->subMonthNoOverflow()->day(21) : $monthStart;

        $employeeQuery = DB::table('employees')
            ->select([
                'id as employee_id',
                'employee_code',
                'name as employee_name',
                'department_name',
                'location_name',
                'employment_type',
                'joined_on',
                'retired_on',
            ])
            ->addSelect(\Illuminate\Support\Facades\Schema::hasColumn('employees', 'is_dependent_within') ? 'is_dependent_within' : DB::raw('0 as is_dependent_within'))
            ->addSelect(['legacy_calendar_month' => \App\Services\Reports\AttendancePeriod::legacyMonthFor($targetMonth, 'employees.id')])
            ->whereDate('joined_on', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($queryStart) {
                $query
                    ->whereNull('retired_on')
                    ->orWhereDate('retired_on', '>=', $queryStart->toDateString());
            });

        if ($employeeId !== null && $employeeId > 0) {
            $employeeQuery->where('id', $employeeId);
        }

        if (!empty($employeeCode)) {
            $employeeQuery->where('employee_code', 'like', '%' . $employeeCode . '%');
        }

        if (!empty($departmentName)) {
            $employeeQuery->where('department_name', 'like', '%' . $departmentName . '%');
        }

        $employees = $employeeQuery
            ->orderBy('employee_code')
            ->get();

        if ($employees->isEmpty()) {
            return [
                'items' => [],
                'meta' => [
                    'targetMonth' => $targetMonth,
                    'count' => 0,
                ],
            ];
        }

        $employeeIds = $employees
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.employment_type',
                'e.department_name',
                'e.location_name',
                'ad.target_date',
                'ad.schedule_name',
                'ad.work_type_id',
                'ad.harmos_total_work_minutes', 'ad.harmos_leave_summary', 'ad.source_system', 'ad.source_import_type', 'ad.approved_by',
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.break_overtime_review',
                'ad.absence_flag',
                'ad.special_leave_flag', 'ad.request_leave_name', 'ad.request_leave_days',
                'ad.paid_leave_unit',
                'ad.hour_paid_leave_minutes',
                'ad.child_care_leave_minutes',
                'ad.nursing_care_leave_minutes',
                'ad.remark',
                'ad.supervisor_comment',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'ad.close_status',
                'ad.manual_edited_at',
            ])
            ->addSelect('ad.paid_leave_credit_minutes')
            ->whereBetween('ad.target_date', [$queryStart->toDateString(), $monthEnd->toDateString()])
            ->whereIn('ad.employee_id', $employeeIds)
            ->orderBy('e.employee_code')
            ->orderBy('ad.target_date')
            ->get();
        foreach ($rows as $row) {
            $birthday = str_contains((string) ($row->request_leave_name ?: $row->schedule_name), '誕生日休暇');
            $row->report_day_units = (float) $row->paid_leave_unit;
            $row->report_day_credit = (int) $row->paid_leave_credit_minutes;
            $row->report_hourly_minutes = (int) $row->hour_paid_leave_minutes + (int) $row->child_care_leave_minutes + (int) $row->nursing_care_leave_minutes;
            if ($birthday && !$row->report_day_units) {
                $row->report_day_units = (float) ($row->request_leave_days ?? 1);
                // HRMOS totals include both the hours worked and the birthday leave credit.
                if (!$row->request_leave_name && !$row->is_manually_edited && $row->source_system === 'HARMOS' && $row->harmos_total_work_minutes !== null) {
                    $row->report_day_credit = max(0, $row->harmos_total_work_minutes - (int) $row->work_minutes - $row->report_hourly_minutes);
                }
            }
        }
        $uncreditedLeave = $rows->filter(fn ($row) => $row->report_day_units > 0 && !$row->report_day_credit);
        $dutyMinutes = $employmentMinutes = $priorCredits = collect();
        if ($uncreditedLeave->isNotEmpty()) {
            $dutyMinutes = DB::table('work_type_settings')->pluck('standard_day_minutes', 'id');
            $employmentMinutes = DB::table('employment_type_settings')->pluck('standard_day_minutes', 'code');
            $priorCredits = DB::table('attendance_daily')
                ->whereIn('employee_id', $uncreditedLeave->pluck('employee_id')->unique()->values())
                ->where('target_date', '<=', $uncreditedLeave->max('target_date'))
                ->where('paid_leave_unit', 1)->where('paid_leave_credit_minutes', '>', 0)
                ->orderByDesc('target_date')->get(['employee_id', 'target_date', 'paid_leave_credit_minutes'])
                ->groupBy('employee_id');
        }
        app(DailyApprovalReview::class)->annotate($rows);
        $this->preloadDailyBreaks($rows);
        $alertSettings = $this->resolveAttendanceAlertSettings();
        $shortIntervalAlertMap = $this->buildShortIntervalAlertMap($rows, $alertSettings);

        $missingShifts = app(DailyApprovalReview::class)->absentDays(['from'=>$queryStart->toDateString(), 'to'=>$monthEnd->toDateString(), 'employeeId'=>$employeeId], includeOptionalShifts: true)
            ->keyBy(fn ($s) => $this->monthlyCalendarKey($s->employee_id,$s->target_date));
        $rowsByKey = [];
        foreach ($rows as $row) {
            $rowsByKey[$this->monthlyCalendarKey((int) $row->employee_id, (string) $row->target_date)] = $row;
        }

        $items = [];
        foreach ($employees as $employee) {
            [$periodStart, $periodEnd] = $payrollPeriod
                ? \App\Services\Reports\AttendancePeriod::forEmployee($targetMonth, (string) $employee->employment_type, (bool) $employee->is_dependent_within, $employee->legacy_calendar_month ?? null)
                : [$monthStart, $monthEnd];
            $employmentStart = CarbonImmutable::parse((string) $employee->joined_on)->startOfDay();
            if ($employmentStart->lessThan($periodStart)) {
                $employmentStart = $periodStart;
            }

            $employmentEnd = $periodEnd;
            if (!empty($employee->retired_on)) {
                $retiredOn = CarbonImmutable::parse((string) $employee->retired_on)->startOfDay();
                if ($retiredOn->lessThan($employmentEnd)) {
                    $employmentEnd = $retiredOn;
                }
            }

            if ($employmentEnd->lessThan($employmentStart)) {
                continue;
            }

            for ($date = $employmentStart; $date->lessThanOrEqualTo($employmentEnd); $date = $date->addDay()) {
                $key = $this->monthlyCalendarKey((int) $employee->employee_id, $date->toDateString());
                $row = $rowsByKey[$key] ?? null;

                $item = $row !== null
                    ? $this->mapGridRow($row, $this->buildAlertsForRow($row, $shortIntervalAlertMap, $alertSettings))
                    : $this->buildMissingCalendarRow($employee, $date);
                if ($row !== null && ($row->report_day_units > 0 || $row->report_hourly_minutes > 0)) {
                    $units = $row->report_day_units;
                    $credit = $units > 0 ? $row->report_day_credit : 0;
                    if ($units > 0 && !$credit) {
                        $personalMinutes = match ((string) $employee->employee_code) {
                            '970017' => 360, '970019' => 405, default => null,
                        };
                        $priorCredit = $priorCredits->get($row->employee_id)?->firstWhere('target_date', '<=', $row->target_date);
                        $standard = $dutyMinutes->get($row->work_type_id) ?: ($personalMinutes ?? $priorCredit?->paid_leave_credit_minutes ?? $employmentMinutes->get($row->employment_type));
                        if (!$standard && $row->employment_type === 'FULL_TIME') {
                            $standard = 480;
                        }
                        $credit = $standard ? (int) round($units * $standard) : null;
                    }
                    $worked = $item['workMinutes'] ?? null;
                    $hourly = $row->report_hourly_minutes;
                    $item['reportWorkMinutes'] = $credit === null || ($worked === null && $units < 1 && (!$hourly || $item['rawClockInAt'] || $item['rawClockOutAt']))
                        ? null : (int) $worked + $credit + $hourly;
                }
                if ($row === null && ($shift = $missingShifts->get($key))) {
                    $item = $this->missingScheduledItem($shift);
                }
                if ($payrollPeriod) {
                    $item['periodStart'] = $periodStart->toDateString();
                    $item['periodEnd'] = $periodEnd->toDateString();
                }
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'targetMonth' => $targetMonth,
                'count' => count($items),
            ],
        ];
    }

    public function detail(int $dailyId): array
    {
        $row = $this->dailyDetailQuery()
            ->where('ad.id', $dailyId)
            ->first();

        if ($row === null) {
            throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
        }

        return $this->mapDailyDetailRow($row);
    }
}
