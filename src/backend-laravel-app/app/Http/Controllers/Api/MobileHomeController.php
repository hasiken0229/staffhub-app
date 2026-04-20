<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
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
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $employeeId = (int) $user->id;
        $balance = $this->leaveRequestService->balance($employeeId);
        $pendingLeaveCount = (int) DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'PENDING')
            ->count();
        $unreadNotificationCount = $this->noticeService->unreadCountForEmployee($employeeId);
        $latestPayroll = DB::table('payroll_statements')
            ->where('employee_id', $employeeId)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->first();

        return ApiResponse::ok([
            'employee' => [
                'id' => $user?->id,
                'employeeCode' => $user?->employeeCode,
                'name' => $user?->name,
            ],
            'pendingLeaveCount' => $pendingLeaveCount,
            'paidLeaveBalance' => $balance['currentBalance'],
            'unreadNotificationCount' => $unreadNotificationCount,
            'leaveTypes' => $this->systemMasterService->leaveTypes(),
            'latestPayroll' => $latestPayroll ? [
                'id' => (int) $latestPayroll->id,
                'statementType' => $latestPayroll->statement_type,
                'targetYearMonth' => $latestPayroll->target_year_month,
                'originalFileName' => $latestPayroll->original_file_name,
                'publishedAt' => $latestPayroll->published_at,
            ] : null,
        ]);
    }
}
