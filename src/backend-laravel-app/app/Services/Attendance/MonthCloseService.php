<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\Reports\JapaneseHolidayCalendar;
use App\Services\Reports\AttendancePeriod;
use App\Services\Reports\PayrollReportExportService;
use App\Services\AuditLogService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
final class MonthCloseService extends AttendanceServiceSupport
{
    public function __construct(private readonly AttendanceErrorService $attendanceErrorService)
    {
    }
    public function monthCloseStatus(array $filters): array
    {
        $targetMonth = $this->validateTargetMonth((string) ($filters['targetMonth'] ?? now()->format('Y-m')));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(10000, (int) ($filters['perPage'] ?? 50)));
        $monthStart = CarbonImmutable::parse($targetMonth . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $employeeQuery = DB::table('employees')
            ->select(['id', 'employee_code', 'name', 'department_name', 'location_name', 'employment_type', 'joined_on', 'retired_on'])
            ->whereDate('joined_on', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('retired_on')->orWhereDate('retired_on', '>=', $monthStart->toDateString());
            });

        foreach ([
            'employeeCode' => 'employee_code',
            'employeeName' => 'name',
            'departmentName' => 'department_name',
            'locationName' => 'location_name',
            'employmentType' => 'employment_type',
        ] as $filterKey => $column) {
            if (!empty($filters[$filterKey])) {
                $employeeQuery->where($column, 'like', '%' . $filters[$filterKey] . '%');
            }
        }

        $employees = $employeeQuery->orderBy('employee_code')->get();
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
        $dailyRows = $employeeIds === []
            ? collect()
            : DB::table('attendance_daily')
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('target_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->select('*')
                ->get();

        $employeesById = $employees->keyBy('id');
        foreach ($dailyRows as $row) {
            $row->employee_code = $employeesById[$row->employee_id]->employee_code;
            $row->employment_type = $employeesById[$row->employee_id]->employment_type;
        }
        $schedules = DB::table('attendance_shift_schedules as s')
            ->leftJoin('work_type_settings as wt', 's.work_type_id', '=', 'wt.id')
            ->whereIn('s.employee_id', $employeeIds)
            ->whereBetween('s.target_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get(['s.employee_id', 's.target_date', 's.scheduled_clock_in_time', 's.scheduled_clock_out_time', 'wt.name'])
            ->keyBy(fn ($shift) => $this->monthlyCalendarKey((int) $shift->employee_id, $shift->target_date));
        $missingShifts = app(DailyApprovalReview::class)->absentDays(['from'=>$monthStart->toDateString(), 'to'=>$monthEnd->toDateString()])
            ->keyBy(fn ($s) => $this->monthlyCalendarKey($s->employee_id,$s->target_date));
        $today = CarbonImmutable::today('Asia/Tokyo')->toDateString();
        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[$this->monthlyCalendarKey((int) $row->employee_id, (string) $row->target_date)] = $row;
        }

        app(DailyApprovalReview::class)->annotate($dailyRows);
        $close = $this->monthlyCloseSummary($targetMonth, $dailyRows->where('review_status','PENDING')->count() + $missingShifts->count());
        $closeStatusFilter = !empty($filters['closeStatus']) ? strtoupper((string) $filters['closeStatus']) : null;
        if ($closeStatusFilter !== null && $closeStatusFilter !== $close['status']) {
            return ['items' => [], 'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => 0]];
        }

        $approvalStatusFilter = !empty($filters['approvalStatus']) ? strtoupper((string) $filters['approvalStatus']) : null;
        $items = [];
        foreach ($employees as $employee) {
            $start = CarbonImmutable::parse((string) $employee->joined_on)->startOfDay();
            if ($start->lessThan($monthStart)) {
                $start = $monthStart;
            }
            $end = $monthEnd;
            if (!empty($employee->retired_on)) {
                $retired = CarbonImmutable::parse((string) $employee->retired_on)->startOfDay();
                if ($retired->lessThan($end)) {
                    $end = $retired;
                }
            }

            $counts = ['unsubmitted' => 0, 'pending' => 0, 'returned' => 0, 'approved' => 0, 'daily' => 0];
            $missing = ['workday'=>0, 'future'=>0, 'holiday'=>0, 'today'=>0];
            $dayDetails = [];
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $key = $this->monthlyCalendarKey((int) $employee->id, $date->format('Y-m-d'));
                $row = $dailyMap[$key] ?? null;
                $dateKey = $date->format('Y-m-d');
                $holidayName = JapaneseHolidayCalendar::name($dateKey);
                $shift = $schedules->get($key);
                $calendar = ['notElapsed' => $dateKey >= $today, 'holidayName' => $holidayName, 'shift' => $shift ? [
                    'name' => $shift->name, 'start' => $shift->scheduled_clock_in_time, 'end' => $shift->scheduled_clock_out_time,
                ] : null];
                if ($row === null) {
                    $counts['unsubmitted']++;
                    $category = $dateKey > $today ? 'future' : ($dateKey === $today ? 'today' : (($date->isWeekend() || $holidayName !== null) ? 'holiday' : 'workday'));
                    $missing[$category]++;
                    $shiftIssue = $missingShifts->get($key);
                    if ($shiftIssue) { $counts['pending']++; }
                    $dayDetails[] = [...$calendar, 'targetDate'=>$dateKey, 'dailyId'=>null, 'state'=>strtoupper($category).'_UNREGISTERED',
                        'approvalStatus'=>$shiftIssue ? 'PENDING' : null,'approvalReviewReason'=>$shiftIssue?->review_reason];
                    continue;
                }
                $row->employee_code = $employee->employee_code;
                $row->employment_type = $employee->employment_type;
                $projected = $this->attendanceDayProjection($row);
                $dayDetails[] = [...$calendar, 'targetDate'=>$dateKey, 'dailyId'=>(int)$row->id, 'state'=>AttendanceDayState::classify($projected), 'approvalStatus'=>$row->review_status,
                    'incompleteApproved' => $row->review_status === 'APPROVED' && DailyConfirmation::issue(!empty($projected->saturday_holiday) ? $projected : $row) !== null];

                $counts['daily']++;
                $status = $row->review_status;
                if ($status === 'APPROVED') {
                    $counts['approved']++;
                } elseif ($status === 'RETURNED') {
                    $counts['returned']++;
                } elseif ($status === 'PENDING') {
                    $counts['pending']++;
                }
            }

            if ($approvalStatusFilter !== null) {
                $match = match ($approvalStatusFilter) {
                    'APPROVED' => $counts['approved'] > 0,
                    'RETURNED' => $counts['returned'] > 0,
                    'PENDING' => $counts['pending'] > 0,
                    'UNSUBMITTED' => $counts['unsubmitted'] > 0,
                    default => true,
                };
                if (!$match) {
                    continue;
                }
            }

            $items[] = [
                'employee' => [
                    'id' => (int) $employee->id,
                    'employeeCode' => $employee->employee_code,
                    'name' => $employee->name,
                    'departmentName' => $employee->department_name,
                    'locationName' => $employee->location_name,
                    'employmentType' => $employee->employment_type,
                ],
                'targetYearMonth' => $targetMonth,
                'unsubmittedCount' => $counts['unsubmitted'],
                'missingBreakdown' => $missing,
                'dayDetails' => $dayDetails,
                'pendingCount' => $counts['pending'],
                'returnedCount' => $counts['returned'],
                'approvedCount' => $counts['approved'],
                'dailyCount' => $counts['daily'],
                'closeStatus' => $close['status'],
                'closedAt' => $close['closedAt'],
                'closedByName' => $close['closedByName'],
            ];
        }

        $total = count($items);
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function monthlyCloseSummary(string $targetMonth, ?int $knownReviewCount = null): array
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);

        $close = DB::table('attendance_monthly_closes as amc')
            ->leftJoin('employees as closed_by_emp', 'closed_by_emp.id', '=', 'amc.closed_by')
            ->leftJoin('employees as reopened_by_emp', 'reopened_by_emp.id', '=', 'amc.reopened_by')
            ->where('amc.target_year_month', $targetMonth)
            ->select([
                'amc.status',
                'amc.note',
                'amc.closed_at',
                'closed_by_emp.name as closed_by_name',
                'amc.reopened_at',
                'reopened_by_emp.name as reopened_by_name',
            ])
            ->first();

        $stats = DB::table('attendance_daily');
        $this->applyTargetMonthFilter($stats, 'target_date', $targetMonth);
        $stats = $stats
            ->selectRaw('COUNT(*) as daily_count')
            ->selectRaw("SUM(CASE WHEN close_status = 'CLOSED' THEN 1 ELSE 0 END) as closed_daily_count")
            ->selectRaw("SUM(CASE WHEN close_status = 'OPEN' OR close_status IS NULL THEN 1 ELSE 0 END) as open_daily_count")
            ->first();

        $payrollBatchCount = (int) DB::table('payroll_import_batches')
            ->where('target_year_month', $targetMonth)
            ->whereNull('deleted_at')
            ->count();

        return [
            'targetYearMonth' => $targetMonth,
            'status' => $close?->status === self::MONTH_CLOSE_CLOSED ? self::MONTH_CLOSE_CLOSED : self::MONTH_CLOSE_OPEN,
            'note' => $close?->note,
            'closedAt' => !empty($close?->closed_at) ? CarbonImmutable::parse($close->closed_at)->toIso8601String() : null,
            'closedByName' => $close?->closed_by_name,
            'reopenedAt' => !empty($close?->reopened_at) ? CarbonImmutable::parse($close->reopened_at)->toIso8601String() : null,
            'reopenedByName' => $close?->reopened_by_name,
            'dailyCount' => (int) ($stats?->daily_count ?? 0),
            'closedDailyCount' => (int) ($stats?->closed_daily_count ?? 0),
            'openDailyCount' => (int) ($stats?->open_daily_count ?? 0),
            'pendingApprovalCount' => $knownReviewCount ?? app(DailyApprovalReview::class)->pending(['from'=>$targetMonth.'-01','to'=>CarbonImmutable::parse($targetMonth.'-01')->endOfMonth()->toDateString()])->count(),
            'payrollBatchCount' => $payrollBatchCount,
        ];
    }

    public function monthClosePrecheck(string $targetMonth, bool $includePayroll = true): array
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);
        $monthStart = CarbonImmutable::parse($targetMonth . '-01')->startOfMonth()->toDateString();
        $monthEnd = CarbonImmutable::parse($targetMonth . '-01')->endOfMonth()->toDateString();
        $statusRows = $this->monthCloseStatus([
            'targetMonth' => $targetMonth,
            'perPage' => 10000,
        ])['items'];

        $unsubmittedCount = array_sum(array_map(static fn (array $row): int => (int) $row['unsubmittedCount'], $statusRows));
        $pendingCount = array_sum(array_map(static fn (array $row): int => (int) $row['pendingCount'], $statusRows));
        $returnedCount = array_sum(array_map(static fn (array $row): int => (int) $row['returnedCount'], $statusRows));
        $errorsByStatus = $this->attendanceErrorService->listErrors([
            'fromMonth' => $targetMonth, 'toMonth' => $targetMonth, 'perPage' => 1,
        ])['meta']['countsByStatus'];
        $openErrorCount = $errorsByStatus['OPEN'] ?? 0;
        $inProgressErrorCount = $errorsByStatus['IN_PROGRESS'] ?? 0;
        $incompleteApproved = 0;
        foreach ($statusRows as $employee) {
            $incompleteApproved += count(array_filter(array_column($employee['dayDetails'], 'incompleteApproved')));
        }
        $pendingEditRequests = (int) DB::table('attendance_daily_edit_requests')
            ->whereIn('status', ['PENDING', 'RETURNED'])
            ->whereBetween('target_date', [$monthStart, $monthEnd])
            ->count();
        $close = $this->monthlyCloseSummary($targetMonth, $pendingCount);

        $blockers = [];
        // Future/in-progress dates are informational in error reports, but a month cannot be frozen early.
        $today = CarbonImmutable::today('Asia/Tokyo');
        if ($monthEnd >= $today->toDateString()) {
            $firstUnfinished = $today->max(CarbonImmutable::parse($monthStart, 'Asia/Tokyo'));
            $daysRemaining = 1 + (int) $firstUnfinished->diffInDays(CarbonImmutable::parse($monthEnd, 'Asia/Tokyo'));
            $blockers[] = ['code'=>'PERIOD_NOT_FINISHED', 'label'=>'対象月の本日・未到来日', 'count'=>$daysRemaining, 'message'=>'対象月の最終日が終了してから月締めしてください。'];
        }
        $pendingLeaveRequests = DB::table('leave_requests')->whereIn('status', ['PENDING', 'RETURNED'])
            ->where('start_date', '<=', $monthEnd)->where('end_date', '>=', $monthStart)->count();
        foreach ([
            ['UNSUBMITTED_DAILY', '未申請（未登録）', $unsubmittedCount, '対象月に日次勤怠が未登録の日があります。'],
            ['PENDING_APPROVAL', '承認待ち', $pendingCount, '承認待ちの日次勤怠が残っています。'],
            ['RETURNED_APPROVAL', '差戻し', $returnedCount, '差戻しの日次勤怠が残っています。'],
            ['OPEN_ERRORS', '未対応・対応中エラー', $openErrorCount + $inProgressErrorCount, '勤怠エラーの対応が完了していません。'],
            ['PENDING_DAILY_EDIT_REQUESTS', '未処理の修正申請', $pendingEditRequests, '未処理・差戻しの日次修正申請が残っています。内容を確認し、訂正または取消を済ませてください。'],
            ['INCOMPLETE_APPROVED_DAILY', '承認済みの未確定勤怠', $incompleteApproved, '採用時刻・実働を確認し、日次勤怠を確定してください。'],
            ['PENDING_LEAVE_REQUESTS', '未処理の届出・休暇申請', $pendingLeaveRequests, '未処理・差戻しの届出・休暇申請が残っています。内容を確認し、訂正または取消を済ませてください。'],
        ] as [$code, $label, $count, $message]) {
            if ((int) $count <= 0) {
                continue;
            }

            $blockers[] = [
                'code' => $code,
                'label' => $label,
                'count' => (int) $count,
                'message' => $message,
            ];
        }

        $payrollBlockers = $close['status'] === self::MONTH_CLOSE_CLOSED ? [] : $blockers;
        if ($close['status'] !== self::MONTH_CLOSE_CLOSED) {
            array_unshift($payrollBlockers, [
                'code' => 'MONTH_NOT_CLOSED',
                'label' => '月締未完了',
                'count' => 1,
                'message' => '給与連携前に対象月を月締めしてください。',
            ]);
        }

        if ($includePayroll && $close['status'] === self::MONTH_CLOSE_CLOSED) {
            $issue = app(PayrollReportExportService::class)->readinessIssue($targetMonth);
            if ($issue !== null) {
                $payrollBlockers[] = ['code' => $issue['code'], 'label' => '給与CSV出力前の確認', 'count' => 1, 'message' => $issue['message']];
            }
        }

        $payrollWarnings = [];
        if ($includePayroll) {
            $previous = CarbonImmutable::parse($monthStart)->subMonthNoOverflow();
            $priorMonth = $previous->format('Y-m');
            $periodFrom = $previous->day(21)->toDateString();
            $periodTo = $previous->endOfMonth()->toDateString();
            $recorded = DB::table('employees as e')->join('attendance_daily as ad', 'ad.employee_id', '=', 'e.id')
                ->whereBetween('ad.target_date', [$periodFrom, $periodTo])->where('ad.work_minutes', '>', 0);
            foreach (['overlap', 'gap'] as $transition) {
                $employees = clone $recorded;
                $calendarMonth = $transition === 'overlap' ? $priorMonth : $targetMonth;
                $newCutoffMonth = $transition === 'overlap' ? $targetMonth : $priorMonth;
                $codes = $employees->whereIn('e.id', AttendancePeriod::legacyPeriods($calendarMonth)->select('lp.employee_id'))
                    ->whereNotIn('e.id', AttendancePeriod::legacyPeriods($newCutoffMonth)->select('lp.employee_id'))
                    ->orderBy('e.employee_code')->distinct()->pluck('e.employee_code');
                if ($codes->isEmpty()) { continue; }
                $description = $transition === 'overlap' ? 'が両方の給与期間に含まれます' : 'がどちらの給与期間にも含まれません';
                $payrollWarnings[] = ['code' => 'CUTOFF_TRANSITION_REVIEW', 'label' => '給与期間変更の確認', 'count' => $codes->count(),
                    'message' => $periodFrom.'～'.$periodTo.'の記録済み勤怠'.$description.'。給与の出力・支払実績を照合してください。職員番号 '.$codes->implode('、')];
            }
        }
        if ((int) $close['payrollBatchCount'] > 0) {
            $payrollWarnings[] = [
                'code' => 'PAYROLL_BATCH_EXISTS',
                'label' => '給与取込済み',
                'count' => (int) $close['payrollBatchCount'],
                'message' => '対象月には既に給与取込バッチがあります。再作成時は重複や差し替え対象を確認してください。',
            ];
        }

        return [
            'targetYearMonth' => $targetMonth,
            'canClose' => $blockers === [],
            'blockers' => $blockers,
            'summary' => [
                'unsubmittedDailyCount' => $unsubmittedCount,
                'pendingApprovalCount' => $pendingCount,
                'returnedApprovalCount' => $returnedCount,
                'openErrorCount' => $openErrorCount,
                'inProgressErrorCount' => $inProgressErrorCount,
                'pendingDailyEditRequestCount' => $pendingEditRequests,
                'dailyCount' => (int) $close['dailyCount'],
                'closedDailyCount' => (int) $close['closedDailyCount'],
                'openDailyCount' => (int) $close['openDailyCount'],
                'payrollBatchCount' => (int) $close['payrollBatchCount'],
                'monthCloseStatus' => $close['status'],
            ],
            'payrollReady' => $payrollBlockers === [],
            'payrollBlockers' => $payrollBlockers,
            'payrollWarnings' => $payrollWarnings,
        ];
    }

    public function updateMonthlyClose(string $targetMonth, string $status, ?string $note, GenericUser $actor): array
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);
        $status = strtoupper($status);

        if (!in_array($status, [self::MONTH_CLOSE_OPEN, self::MONTH_CLOSE_CLOSED], true)) {
            throw new ApiException('VALIDATION_ERROR', '月締め状態が不正です。', 422, [
                ['field' => 'status', 'message' => 'OPEN または CLOSED を指定してください。'],
            ]);
        }

        $normalizedNote = $note !== null ? trim($note) : null;
        if ($normalizedNote === '') {
            $normalizedNote = null;
        }

        $operatorId = $this->resolveOperatorEmployeeId($actor, 0);

        DB::transaction(function () use ($targetMonth, $status, $normalizedNote, $actor, $operatorId) {
            MonthMutationLock::acquire([$targetMonth], false);
            if ($status === self::MONTH_CLOSE_CLOSED) {
                // Persist the same day-off projection used by screens and exports before freezing the month.
                if (!$this->isMonthClosed($targetMonth)) {
                    $days = DB::table('attendance_daily as ad')->join('employees as e', 'e.id', '=', 'ad.employee_id')
                        ->select(['ad.*', 'e.employee_code'])->whereBetween('ad.target_date', [$targetMonth.'-01', CarbonImmutable::parse($targetMonth.'-01')->endOfMonth()->toDateString()])
                        ->lockForUpdate()->get();
                    app(DailyApprovalReview::class)->annotate($days);
                    $noReviewIds = $days->where('review_status','NOT_REQUIRED')->pluck('id')->all();
                    foreach ($days as $day) {
                        if ($day->work_minutes === null && DailyApprovalReview::confirmedFullLeave($day)) {
                            DB::table('attendance_daily')->where('id',$day->id)->update(['work_minutes'=>0]);
                        }
                        $projected = SaturdayHolidayPolicy::project($day);
                        if (empty($projected->saturday_holiday)) { continue; }
                        $values = ['clock_in_at'=>null,'clock_out_at'=>null,'break_minutes'=>0,'work_minutes'=>0,'schedule_name'=>'公休','work_type_id'=>null,'remark'=>$projected->remark];
                        $oldBreaks = DB::table('attendance_daily_breaks')->where('attendance_daily_id', $day->id)->get();
                        DB::table('attendance_daily')->where('id', $day->id)->update($values);
                        $this->syncAutoBreak((int) $day->id, null);
                        $this->addAudit('SYSTEM', null, 'ATTENDANCE_SATURDAY_HOLIDAY', 'ATTENDANCE_DAILY', (string) $day->id, ['before'=>(array)$day,'after'=>$values,'breaksBefore'=>$oldBreaks]);
                    }
                }
                $precheck = $this->monthClosePrecheck($targetMonth, false);
                if (!$precheck['canClose']) {
                    throw new ApiException('VALIDATION_ERROR', '月締め前チェックで未処理項目が見つかりました。', 422, array_map(
                        static fn (array $blocker): array => [
                            'field' => 'targetMonth',
                            'message' => $blocker['label'] . ': ' . $blocker['count'] . '件',
                        ],
                        $precheck['blockers']
                    ));
                }
            }
            $existing = DB::table('attendance_monthly_closes')
                ->where('target_year_month', $targetMonth)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === $status && $existing->note === $normalizedNote) {
                return;
            }

            $now = now();
            if ($existing === null) {
                DB::table('attendance_monthly_closes')->insert([
                    'target_year_month' => $targetMonth,
                    'status' => $status,
                    'note' => $normalizedNote,
                    'closed_at' => $status === self::MONTH_CLOSE_CLOSED ? $now : null,
                    'closed_by' => $status === self::MONTH_CLOSE_CLOSED && $operatorId > 0 ? $operatorId : null,
                    'reopened_at' => $status === self::MONTH_CLOSE_OPEN ? $now : null,
                    'reopened_by' => $status === self::MONTH_CLOSE_OPEN && $operatorId > 0 ? $operatorId : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $attributes = [
                    'status' => $status,
                    'note' => $normalizedNote,
                    'updated_at' => $now,
                ];

                if ($status === self::MONTH_CLOSE_CLOSED) {
                    $attributes['closed_at'] = $now;
                    $attributes['closed_by'] = $operatorId > 0 ? $operatorId : null;
                } else {
                    DB::table('payroll_legacy_calendar_periods')->where('target_month', $targetMonth)
                        ->whereNull('reopened_at')->update(['reopened_at' => $now]);
                    $attributes['reopened_at'] = $now;
                    $attributes['reopened_by'] = $operatorId > 0 ? $operatorId : null;
                }

                DB::table('attendance_monthly_closes')
                    ->where('id', $existing->id)
                    ->update($attributes);
            }

            $dailyIds = DB::table('attendance_daily')
                ->whereBetween('target_date', [
                    CarbonImmutable::parse($targetMonth . '-01')->startOfMonth()->toDateString(),
                    CarbonImmutable::parse($targetMonth . '-01')->endOfMonth()->toDateString(),
                ])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $dailyUpdateQuery = DB::table('attendance_daily');
            $this->applyTargetMonthFilter($dailyUpdateQuery, 'target_date', $targetMonth);
            // Freeze the no-review decision at an actual close; reopening returns it to the live rules.
            if ($status === 'CLOSED' && ($existing->status ?? 'OPEN') !== 'CLOSED') {
                (clone $dailyUpdateQuery)->whereIntegerInRaw('id', $noReviewIds ?? [])->where('approval_status', 'PENDING')
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('employees as e')->whereColumn('e.id','attendance_daily.employee_id')
                        ->whereColumn('e.joined_on','<=','attendance_daily.target_date')
                        ->where(fn ($q) => $q->whereNull('e.retired_on')->orWhereColumn('e.retired_on','>=','attendance_daily.target_date')))
                    ->update(['approval_status'=>'NOT_REQUIRED']);
            } elseif ($status === 'OPEN') {
                (clone $dailyUpdateQuery)->where('approval_status','NOT_REQUIRED')->update(['approval_status'=>'PENDING']);
            }
            $dailyUpdateQuery->update([
                    'close_status' => $status,
                    'updated_at' => $now,
                ]);

            foreach ($dailyIds as $dailyId) {
                $this->recordDailyHistory(
                    $dailyId,
                    $status === self::MONTH_CLOSE_CLOSED ? 'MONTH_CLOSED' : 'MONTH_REOPENED',
                    'closeStatus',
                    $status === self::MONTH_CLOSE_CLOSED ? self::MONTH_CLOSE_OPEN : self::MONTH_CLOSE_CLOSED,
                    $status,
                    $actor,
                    $operatorId,
                    $normalizedNote
                );
            }

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                $status === self::MONTH_CLOSE_CLOSED ? 'ATTENDANCE_MONTH_CLOSE' : 'ATTENDANCE_MONTH_REOPEN',
                'ATTENDANCE_MONTH',
                $targetMonth,
                [
                    'targetYearMonth' => $targetMonth,
                    'status' => $status,
                    'note' => $normalizedNote,
                ]
            );
        });

        return $this->monthlyCloseSummary($targetMonth);
    }
}
