<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;

final class LeaveController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequestService)
    {
    }

    public function balance(Request $request)
    {
        return ApiResponse::ok($this->leaveRequestService->balance((int) $request->user()->id));
    }

    public function index(Request $request)
    {
        $result = $this->leaveRequestService->listForEmployee((int) $request->user()->id, $request->only([
            'status',
            'from',
            'to',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function ledger(Request $request)
    {
        try {
            return ApiResponse::ok($this->leaveRequestService->ledger((int) $request->user()->id));
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
            'requestCategory' => ['nullable', 'string', 'in:LEAVE,TIME_LEAVE'],
            'leaveTypeCode' => ['nullable', 'string', 'max:20'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'dayUnit' => ['nullable', 'string', 'max:10'],
            'halfDayType' => ['nullable', 'string', 'max:10'],
            'timeLeaveType' => ['nullable', 'string', 'in:PAID_HOURLY,CHILD_CARE_HOURLY,NURSING_CARE_HOURLY'],
            'targetDate' => ['nullable', 'date'],
            'startTime' => ['nullable', 'string', 'max:5'],
            'endTime' => ['nullable', 'string', 'max:5'],
            'quantityMinutes' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok($this->leaveRequestService->createForEmployee((int) $request->user()->id, $payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->leaveRequestService->detailForEmployee((int) $request->user()->id, $id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function cancel(Request $request, int $id)
    {
        $payload = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->leaveRequestService->cancelForEmployee((int) $request->user()->id, $id, $payload['comment'] ?? null)
            );
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
