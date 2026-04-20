<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;

final class NotificationController extends Controller
{
    public function index()
    {
        return ApiResponse::ok([], ['page' => 1, 'perPage' => 20, 'total' => 0]);
    }

    public function markRead(int $id)
    {
        return ApiResponse::ok([
            'success' => true,
            'notificationId' => $id,
        ]);
    }
}
