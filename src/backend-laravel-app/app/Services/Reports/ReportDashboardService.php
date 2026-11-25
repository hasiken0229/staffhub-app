<?php

namespace App\Services\Reports;

use App\Services\AttendanceService;
use App\Services\Attendance\DailyApprovalReview;
use App\Services\LeaveRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReportDashboardService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly LeaveRequestService $leaveRequestService,
    ) {
    }

    public function hubSummary(): array
    {
        $today = now()->toDateString();

        return [
            'checkedAt' => now()->toIso8601String(),
            'todayAttendanceCount' => (int) DB::table('attendance_daily')
                ->where('target_date', $today)
                ->count(),
            'pendingAttendanceApprovalCount' => app(DailyApprovalReview::class)->pending([], false)->count(),
            'pendingLeaveCount' => (int) DB::table('leave_requests')
                ->whereIn('status', ['PENDING', 'RETURNED'])
                ->count(),
            'pendingDailyEditRequestCount' => (int) DB::table('attendance_daily_edit_requests')->where('status', 'PENDING')->count(),
            'publishedPayrollCount' => (int) DB::table('payroll_statements')
                ->whereNull('deleted_at')
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
            $latestLedger = $balance['latestEntry'];

            $items[] = [
                'employeeId' => (int) $employee->id,
                'employeeCode' => $employee->employee_code,
                'employeeName' => $employee->name,
                'departmentName' => $employee->department_name,
                'currentBalance' => $balance['currentBalance'],
                'futureApprovedDays' => $balance['futureApprovedDays'],
                'expiryNeedsReview' => count(array_filter($balance['grants'], fn (array $grant): bool => $grant['expiryNeedsReview'])) > 0,
                'hourlyBalance' => $balance['hourlyBalance'] ?? null,
                'latestEntryType' => $latestLedger['entryType'] ?? null,
                'latestOccurredOn' => $latestLedger['occurredOn'] ?? null,
                'latestDaysDelta' => $latestLedger['daysDelta'] ?? null,
            ];
        }

        return [
            'items' => $items,
            'meta' => [
                'total' => count($items),
            ],
        ];
    }
}
