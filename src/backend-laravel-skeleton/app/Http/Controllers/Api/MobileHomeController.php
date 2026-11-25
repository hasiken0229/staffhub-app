<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;

final class MobileHomeController extends Controller
{
    public function show()
    {
        // TODO: ログイン職員のホーム情報を返す
        return ApiResponse::ok([
            'employee' => [
                'id' => 1,
                'employeeCode' => 'E0001',
                'name' => '見本姓K 職員J',
            ],
            'pendingLeaveCount' => 0,
            'paidLeaveBalance' => 0,
            'unreadNotificationCount' => 0,
            'latestPayroll' => null,
        ]);
    }
}
