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
                ->select(['employee_id', 'target_date', 'approval_status'])
                ->get();

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[$this->monthlyCalendarKey((int) $row->employee_id, (string) $row->target_date)] = $row;
        }

        $close = $this->monthlyCloseSummary($targetMonth);
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
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $key = $this->monthlyCalendarKey((int) $employee->id, $date->format('Y-m-d'));
                $row = $dailyMap[$key] ?? null;
                if ($row === null) {
                    $counts['unsubmitted']++;
                    continue;
                }

                $counts['daily']++;
                $status = strtoupper((string) ($row->approval_status ?? 'PENDING'));
                if ($status === 'APPROVED') {
                    $counts['approved']++;
                } elseif ($status === 'RETURNED') {
                    $counts['returned']++;
                } else {
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

    public function monthlyCloseSummary(string $targetMonth): array
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
            ->selectRaw("SUM(CASE WHEN approval_status = 'PENDING' THEN 1 ELSE 0 END) as pending_approval_count")
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
            'pendingApprovalCount' => (int) ($stats?->pending_approval_count ?? 0),
            'payrollBatchCount' => $payrollBatchCount,
        ];
    }

    public function monthClosePrecheck(string $targetMonth): array
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
        $openErrorCount = (int) $this->attendanceErrorService->listErrors([
            'fromMonth' => $targetMonth,
            'toMonth' => $targetMonth,
            'handlingStatus' => 'OPEN',
            'perPage' => 1,
        ])['meta']['total'];
        $inProgressErrorCount = (int) $this->attendanceErrorService->listErrors([
            'fromMonth' => $targetMonth,
            'toMonth' => $targetMonth,
            'handlingStatus' => 'IN_PROGRESS',
            'perPage' => 1,
        ])['meta']['total'];
        $pendingEditRequests = (int) DB::table('attendance_daily_edit_requests')
            ->where('status', 'PENDING')
            ->whereBetween('target_date', [$monthStart, $monthEnd])
            ->count();
        $close = $this->monthlyCloseSummary($targetMonth);

        $blockers = [];
        foreach ([
            ['UNSUBMITTED_DAILY', '未申請（未登録）', $unsubmittedCount, '対象月に日次勤怠が未登録の日があります。'],
            ['PENDING_APPROVAL', '承認待ち', $pendingCount, '承認待ちの日次勤怠が残っています。'],
            ['RETURNED_APPROVAL', '差戻し', $returnedCount, '差戻しの日次勤怠が残っています。'],
            ['OPEN_ERRORS', '未対応・対応中エラー', $openErrorCount + $inProgressErrorCount, '勤怠エラーの対応が完了していません。'],
            ['PENDING_DAILY_EDIT_REQUESTS', '未処理の修正申請', $pendingEditRequests, '未処理の日次修正申請が残っています。'],
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

        $payrollBlockers = $blockers;
        if ($close['status'] !== self::MONTH_CLOSE_CLOSED) {
            array_unshift($payrollBlockers, [
                'code' => 'MONTH_NOT_CLOSED',
                'label' => '月締未完了',
                'count' => 1,
                'message' => '給与連携前に対象月を月締めしてください。',
            ]);
        }

        $payrollWarnings = [];
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

        if ($status === self::MONTH_CLOSE_CLOSED) {
            $precheck = $this->monthClosePrecheck($targetMonth);
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

        $operatorId = $this->resolveOperatorEmployeeId($actor, 0);

        DB::transaction(function () use ($targetMonth, $status, $normalizedNote, $actor, $operatorId) {
            $existing = DB::table('attendance_monthly_closes')
                ->where('target_year_month', $targetMonth)
                ->lockForUpdate()
                ->first();

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
