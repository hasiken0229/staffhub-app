<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

final class AttendancePunchController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
            'cardUid' => ['required', 'string', 'max:64'],
            'occurredAt' => ['required', 'date'],
            'dedupeKey' => ['required', 'string', 'max:100'],
            'appVersion' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->punch($payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function heartbeat(Request $request)
    {
        $payload = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
            'appVersion' => ['nullable', 'string', 'max:30'],
            'lastSeenAt' => ['required', 'date'],
            'pendingOfflineCount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->heartbeat($payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }
}
