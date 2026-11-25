<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

final class AttendancePunchController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService) {}

    public function store(Request $request)
    {
        $payload = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
            'cardUid' => ['required', 'string', 'max:64'],
            'occurredAt' => ['required', 'date'],
            'clockReferenceAt' => ['nullable', 'date'],
            'clientSentAt' => ['nullable', 'date'],
            'captureAgeSeconds' => ['nullable', 'integer', 'between:0,3155760000'],
            'captureClockUnknown' => ['nullable', 'boolean'],
            'dedupeKey' => ['required', 'string', 'max:100'],
            'confirmShortShift' => ['sometimes', 'boolean'],
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

    public function todayReceipts(Request $request)
    {
        $credentials = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
        ]);
        try {
            return ApiResponse::ok($this->attendanceService->todayDeviceReceipts($credentials));
        } catch (ApiException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
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
            'readerStatus' => ['nullable', 'string', 'max:255'],
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

    public function cardRegistrationEmployees(Request $request)
    {
        $payload = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->listCardRegistrationEmployees($payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function assignCard(Request $request)
    {
        $payload = $request->validate([
            'deviceCode' => ['required', 'string', 'max:50'],
            'deviceSecret' => ['required', 'string', 'max:255'],
            'employeeId' => ['required', 'integer', 'min:1'],
            'cardUid' => ['required', 'string', 'max:64'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->assignCardFromDevice($payload));
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
