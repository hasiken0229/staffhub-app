<?php

use App\Http\Controllers\Api\Admin\AttendanceAdminController;
use App\Http\Controllers\Api\Admin\AuditLogAdminController;
use App\Http\Controllers\Api\Admin\CardAdminController;
use App\Http\Controllers\Api\Admin\EmployeeAdminController;
use App\Http\Controllers\Api\Admin\ImportHistoryAdminController;
use App\Http\Controllers\Api\Admin\LeaveAdminController;
use App\Http\Controllers\Api\Admin\NoticeAdminController;
use App\Http\Controllers\Api\Admin\PayrollAdminController;
use App\Http\Controllers\Api\Admin\ReportAdminController;
use App\Http\Controllers\Api\Admin\SystemMasterAdminController;
use App\Http\Controllers\Api\Admin\WorkProcedureAdminController;
use App\Http\Controllers\Api\AttendanceDailyEditRequestController;
use App\Http\Controllers\Api\AttendancePunchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\MobileHomeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayrollController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);

Route::prefix('attendance')->group(function () {
    Route::post('/punch', [AttendancePunchController::class, 'store']);
    Route::post('/devices/heartbeat', [AttendancePunchController::class, 'heartbeat']);
});

Route::middleware('auth.api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth.api', 'employee'])->group(function () {
    Route::get('/mobile/home', [MobileHomeController::class, 'show']);

    Route::prefix('leave')->group(function () {
        Route::get('/balance', [LeaveController::class, 'balance']);
        Route::get('/ledger', [LeaveController::class, 'ledger']);
        Route::get('/requests', [LeaveController::class, 'index']);
        Route::post('/requests', [LeaveController::class, 'store']);
        Route::get('/requests/{id}', [LeaveController::class, 'show']);
        Route::post('/requests/{id}/cancel', [LeaveController::class, 'cancel']);
    });

    Route::prefix('attendance')->group(function () {
        Route::get('/daily-edit-requests', [AttendanceDailyEditRequestController::class, 'index']);
        Route::post('/daily-edit-requests', [AttendanceDailyEditRequestController::class, 'store']);
    });

    Route::prefix('payroll')->group(function () {
        Route::get('/statements', [PayrollController::class, 'index']);
        Route::get('/statements/{id}', [PayrollController::class, 'show']);
        Route::get('/statements/{id}/download', [PayrollController::class, 'download']);
        Route::post('/statements/{id}/viewed', [PayrollController::class, 'markViewed']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
    });
});

Route::middleware(['auth.api', 'admin'])->prefix('admin')->group(function () {
    Route::get('/employees', [EmployeeAdminController::class, 'index']);
    Route::get('/employees/template-csv', [EmployeeAdminController::class, 'downloadTemplateCsv']);
    Route::post('/employees', [EmployeeAdminController::class, 'store']);
    Route::put('/employees/{id}', [EmployeeAdminController::class, 'update']);
    Route::post('/employees/import-csv', [EmployeeAdminController::class, 'importCsv']);

    Route::get('/cards', [CardAdminController::class, 'index']);
    Route::post('/cards/assign', [CardAdminController::class, 'assign']);
    Route::post('/cards/revoke', [CardAdminController::class, 'revoke']);

    Route::get('/attendance/events', [AttendanceAdminController::class, 'events']);
    Route::get('/attendance/daily', [AttendanceAdminController::class, 'daily']);
    Route::get('/attendance/daily-grid', [AttendanceAdminController::class, 'dailyGrid']);
    Route::get('/attendance/daily/{id}', [AttendanceAdminController::class, 'show']);
    Route::patch('/attendance/daily/{id}', [AttendanceAdminController::class, 'updateDaily']);
    Route::delete('/attendance/daily/{id}/manual-edit', [AttendanceAdminController::class, 'resetDailyManualEdit']);
    Route::get('/attendance/daily/{id}/histories', [AttendanceAdminController::class, 'histories']);
    Route::get('/attendance/month-close', [AttendanceAdminController::class, 'monthClose']);
    Route::post('/attendance/month-close', [AttendanceAdminController::class, 'updateMonthClose']);
    Route::get('/attendance/month-close/precheck', [AttendanceAdminController::class, 'monthClosePrecheck']);
    Route::get('/attendance/month-close-status', [AttendanceAdminController::class, 'monthCloseStatus']);
    Route::get('/attendance/daily-edit-requests', [AttendanceAdminController::class, 'dailyEditRequests']);
    Route::post('/attendance/daily-edit-requests/{id}/approve', [AttendanceAdminController::class, 'approveDailyEditRequest']);
    Route::post('/attendance/daily-edit-requests/{id}/return', [AttendanceAdminController::class, 'returnDailyEditRequest']);
    Route::get('/attendance/errors', [AttendanceAdminController::class, 'errors']);
    Route::post('/attendance/errors/resolve', [AttendanceAdminController::class, 'resolveError']);
    Route::get('/attendance/approvals', [AttendanceAdminController::class, 'approvals']);
    Route::post('/attendance/approvals/{id}/approve', [AttendanceAdminController::class, 'approve']);
    Route::post('/attendance/approvals/bulk-approve', [AttendanceAdminController::class, 'bulkApprove']);
    Route::post('/attendance/approvals/{id}/return', [AttendanceAdminController::class, 'return']);
    Route::post('/attendance/approvals/bulk-return', [AttendanceAdminController::class, 'bulkReturn']);

    Route::get('/leave/requests', [LeaveAdminController::class, 'index']);
    Route::post('/leave/requests/{id}/approve', [LeaveAdminController::class, 'approve']);
    Route::post('/leave/requests/{id}/reject', [LeaveAdminController::class, 'reject']);
    Route::post('/leave/requests/{id}/return', [LeaveAdminController::class, 'return']);
    Route::post('/leave/grants', [LeaveAdminController::class, 'grant']);
    Route::post('/leave/adjustments', [LeaveAdminController::class, 'adjust']);

    Route::get('/work-procedures', [WorkProcedureAdminController::class, 'index']);
    Route::get('/work-procedures/{id}', [WorkProcedureAdminController::class, 'show']);
    Route::post('/work-procedures/{id}/approve', [WorkProcedureAdminController::class, 'approve']);
    Route::post('/work-procedures/bulk-approve', [WorkProcedureAdminController::class, 'bulkApprove']);
    Route::post('/work-procedures/{id}/return', [WorkProcedureAdminController::class, 'return']);
    Route::post('/work-procedures/bulk-return', [WorkProcedureAdminController::class, 'bulkReturn']);

    Route::get('/payroll/statements', [PayrollAdminController::class, 'index']);
    Route::get('/payroll/statements/{id}', [PayrollAdminController::class, 'show']);
    Route::delete('/payroll/statements/{id}', [PayrollAdminController::class, 'destroy']);
    Route::get('/payroll/statements/{id}/download', [PayrollAdminController::class, 'download']);
    Route::get('/payroll/statements/{id}/preview', [PayrollAdminController::class, 'preview']);
    Route::get('/payroll/definitions', [PayrollAdminController::class, 'definitions']);
    Route::post('/payroll/definitions', [PayrollAdminController::class, 'saveDefinition']);
    Route::get('/payroll/definitions/template-csv', [PayrollAdminController::class, 'downloadDefinitionTemplateCsv']);
    Route::get('/payroll/import-batches', [PayrollAdminController::class, 'importBatches']);
    Route::post('/payroll/import-batches', [PayrollAdminController::class, 'storeImportBatch']);
    Route::get('/payroll/import-batches/{id}', [PayrollAdminController::class, 'showImportBatch']);
    Route::delete('/payroll/import-batches/{id}', [PayrollAdminController::class, 'deleteImportBatch']);
    Route::get('/payroll/import-batches/{id}/export-pdf', [PayrollAdminController::class, 'exportImportBatchPdf']);
    Route::get('/payroll/template-csv', [PayrollAdminController::class, 'downloadTemplateCsv']);
    Route::post('/payroll/statements', [PayrollAdminController::class, 'store']);
    Route::post('/payroll/import-csv', [PayrollAdminController::class, 'importCsv']);

    Route::get('/system-masters', [SystemMasterAdminController::class, 'index']);
    Route::post('/system-masters/departments', [SystemMasterAdminController::class, 'storeDepartment']);
    Route::post('/system-masters/locations', [SystemMasterAdminController::class, 'storeLocation']);
    Route::post('/system-masters/employment-types', [SystemMasterAdminController::class, 'storeEmploymentType']);
    Route::post('/system-masters/work-types', [SystemMasterAdminController::class, 'storeWorkType']);
    Route::post('/system-masters/request-types', [SystemMasterAdminController::class, 'storeRequestType']);
    Route::post('/system-masters/leave-types', [SystemMasterAdminController::class, 'storeLeaveType']);
    Route::post('/system-masters/paid-leave-settings', [SystemMasterAdminController::class, 'storePaidLeaveSetting']);
    Route::post('/system-masters/attendance-alerts', [SystemMasterAdminController::class, 'storeAttendanceAlert']);
    Route::post('/system-masters/attendance-error-rules', [SystemMasterAdminController::class, 'storeAttendanceErrorRule']);
    Route::post('/system-masters/daily-fields', [SystemMasterAdminController::class, 'storeDailyField']);

    Route::get('/reports/hub', [ReportAdminController::class, 'hub']);
    Route::get('/reports/today-attendance', [ReportAdminController::class, 'todayAttendance']);
    Route::get('/reports/attendance-approvals', [ReportAdminController::class, 'attendanceApprovals']);
    Route::get('/reports/paid-leave', [ReportAdminController::class, 'paidLeave']);
    Route::get('/reports/monthly-csv', [ReportAdminController::class, 'monthlyCsv']);
    Route::get('/reports/daily-csv', [ReportAdminController::class, 'dailyCsv']);
    Route::get('/reports/daily-pdf', [ReportAdminController::class, 'dailyPdf']);
    Route::get('/reports/monthly-payroll-csv', [ReportAdminController::class, 'monthlyPayrollCsv']);
    Route::get('/reports/monthly-works-pdf', [ReportAdminController::class, 'monthlyWorksPdf']);

    Route::get('/import-history', [ImportHistoryAdminController::class, 'index']);
    Route::get('/files/history', [ImportHistoryAdminController::class, 'index']);
    Route::get('/files/history/{id}/download', [ImportHistoryAdminController::class, 'download']);
    Route::get('/notices', [NoticeAdminController::class, 'index']);
    Route::post('/notices', [NoticeAdminController::class, 'store']);

    Route::get('/audit-logs', [AuditLogAdminController::class, 'index']);
});
