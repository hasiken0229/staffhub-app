<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use App\Services\LeaveRequestService;
use App\Services\NoticeService;
use App\Services\SystemMasterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileHomeController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly NoticeService $noticeService,
        private readonly SystemMasterService $systemMasterService,
        private readonly AttendanceService $attendanceService,
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $employeeId = (int) $user->id;
        $now = now();
        $employee = ['id' => $user->id, 'employeeCode' => $user->employeeCode, 'name' => $user->name];
        $view = $request->validate(['view' => ['sometimes', 'string', 'in:today,attention,leave']])['view'] ?? null;
        if ($view === 'today' || $view === null) {
            $today = [
                'employee' => $employee,
                'checkedAt' => $now->toIso8601String(),
                'todayAttendance' => $this->attendanceService->listMonthlyCalendar([
                    'employeeId' => $employeeId, 'targetMonth' => $now->format('Y-m'), 'onlyDate' => $now->toDateString(),
                ])['items'][0] ?? null,
            ];
            if ($view === 'today') return ApiResponse::ok($today);
        }
        if ($view === 'attention') {
            return ApiResponse::ok($this->attention($employeeId));
        }
        $balance = $this->leaveRequestService->balance($employeeId);
        $pendingLeaveCount = (int) DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'PENDING')
            ->count();
        $leave = [
            'pendingLeaveCount' => $pendingLeaveCount,
            'paidLeaveBalance' => $balance['currentBalance'],
            'hourlyPaidLeaveBalance' => $balance['hourlyBalance'] ?? null,
            'leaveTypes' => $this->systemMasterService->leaveTypes(false),
        ];
        if ($view === 'leave') return ApiResponse::ok($leave);
        $latestPayroll = DB::table('payroll_statements')
            ->where('employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->first();

        return ApiResponse::ok([
            ...($today ?? []), ...$leave, ...$this->attention($employeeId),
            'pendingDailyEditRequestCount' => (int) DB::table('attendance_daily_edit_requests')->where('employee_id', $employeeId)->where('status', 'PENDING')->count(),
            'latestPayroll' => $latestPayroll ? [
                'id' => (int) $latestPayroll->id,
                'statementType' => $latestPayroll->statement_type,
                'targetYearMonth' => $latestPayroll->target_year_month,
                'originalFileName' => $latestPayroll->original_file_name,
                'publishedAt' => $latestPayroll->published_at,
                'revision' => (int) ($latestPayroll->revision ?? 1),
                'replacedAt' => $latestPayroll->replaced_at ?? null,
                'payDate' => $latestPayroll->pay_date ?? null,
            ] : null,
        ]);
    }

    private function attention(int $employeeId): array
    {
        return [
            'returnedRequestCount' => (int) DB::table('leave_requests')->where('employee_id', $employeeId)->where('status', 'RETURNED')->count()
                + (int) DB::table('attendance_daily_edit_requests')->where('employee_id', $employeeId)->where('status', 'RETURNED')->count(),
            'unreadNotificationCount' => $this->noticeService->unreadCountForEmployee($employeeId),
        ];
    }
}
