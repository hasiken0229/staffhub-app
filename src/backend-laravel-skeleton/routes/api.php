<?php

use App\Http\Controllers\Api\Admin\AttendanceAdminController;
use App\Http\Controllers\Api\Admin\CardAdminController;
use App\Http\Controllers\Api\Admin\EmployeeAdminController;
use App\Http\Controllers\Api\Admin\LeaveAdminController;
use App\Http\Controllers\Api\Admin\PayrollAdminController;
use App\Http\Controllers\Api\Admin\AuditLogAdminController;
use App\Http\Controllers\Api\AttendancePunchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\MobileHomeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayrollController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('attendance')->group(function () {
    Route::post('/punch', [AttendancePunchController::class, 'store']);
    Route::post('/devices/heartbeat', [AttendancePunchController::class, 'heartbeat']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/mobile/home', [MobileHomeController::class, 'show']);

    Route::prefix('leave')->group(function () {
        Route::get('/balance', [LeaveController::class, 'balance']);
        Route::get('/requests', [LeaveController::class, 'index']);
        Route::post('/requests', [LeaveController::class, 'store']);
        Route::get('/requests/{id}', [LeaveController::class, 'show']);
    });

    Route::prefix('payroll')->group(function () {
        Route::get('/statements', [PayrollController::class, 'index']);
        Route::get('/statements/{id}', [PayrollController::class, 'show']);
        Route::post('/statements/{id}/viewed', [PayrollController::class, 'markViewed']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markRead']);
    });
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/employees', [EmployeeAdminController::class, 'index']);
    Route::post('/employees', [EmployeeAdminController::class, 'store']);
    Route::put('/employees/{id}', [EmployeeAdminController::class, 'update']);

    Route::get('/cards', [CardAdminController::class, 'index']);
    Route::post('/cards/assign', [CardAdminController::class, 'assign']);
    Route::post('/cards/revoke', [CardAdminController::class, 'revoke']);

    Route::get('/attendance/events', [AttendanceAdminController::class, 'events']);
    Route::get('/attendance/daily', [AttendanceAdminController::class, 'daily']);

    Route::get('/leave/requests', [LeaveAdminController::class, 'index']);
    Route::post('/leave/requests/{id}/approve', [LeaveAdminController::class, 'approve']);
    Route::post('/leave/requests/{id}/reject', [LeaveAdminController::class, 'reject']);
    Route::post('/leave/requests/{id}/return', [LeaveAdminController::class, 'return']);

    Route::get('/payroll/statements', [PayrollAdminController::class, 'index']);
    Route::post('/payroll/statements', [PayrollAdminController::class, 'store']);

    Route::get('/audit-logs', [AuditLogAdminController::class, 'index']);
});
