<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

final class AttendanceDailyEditRequestController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    public function index(Request $request)
    {
        try {
            return ApiResponse::ok($this->attendanceService->listDailyEditRequestsForEmployee(
                (int) $request->user()->id,
                $request->only(['status'])
            ));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'targetDate' => ['required', 'date'],
            'workTypeId' => ['nullable', 'integer'],
            'clockInTime' => ['nullable', 'string', 'max:5'],
            'clockInNextDay' => ['nullable', 'boolean'],
            'clockOutTime' => ['nullable', 'string', 'max:5'],
            'clockOutNextDay' => ['nullable', 'boolean'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.startTime' => ['nullable', 'string', 'max:5'],
            'breaks.*.startNextDay' => ['nullable', 'boolean'],
            'breaks.*.endTime' => ['nullable', 'string', 'max:5'],
            'breaks.*.endNextDay' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string'],
            'employeeComment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok($this->attendanceService->createDailyEditRequest((int) $request->user()->id, $payload));
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
