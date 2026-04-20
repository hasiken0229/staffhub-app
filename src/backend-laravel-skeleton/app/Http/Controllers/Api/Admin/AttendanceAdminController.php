<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

final class AttendanceAdminController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    public function events(Request $request)
    {
        $result = $this->attendanceService->listEvents($request->only([
            'from',
            'to',
            'employeeCode',
            'receiveStatus',
            'deviceCode',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function daily(Request $request)
    {
        $result = $this->attendanceService->listDaily($request->only([
            'targetMonth',
            'employeeCode',
            'departmentName',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }
}
