<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;

final class WorkProcedureAdminController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequestService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->leaveRequestService->listWorkProcedures($request->only([
            'status',
            'employeeCode',
            'departmentName',
            'leaveTypeCode',
            'requestCategory',
            'timeLeaveType',
            'from',
            'to',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::ok($this->leaveRequestService->detailForAdmin($id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'APPROVED');
    }

    public function bulkApprove(Request $request)
    {
        return $this->bulkDecide($request, 'APPROVED');
    }

    public function return(Request $request, int $id)
    {
        return $this->decide($request, $id, 'RETURNED');
    }

    public function bulkReturn(Request $request)
    {
        return $this->bulkDecide($request, 'RETURNED');
    }

    private function decide(Request $request, int $id, string $decision)
    {
        $payload = $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->leaveRequestService->decide($id, $decision, $payload['comment'] ?? null, $request->user())
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

    private function bulkDecide(Request $request, string $decision)
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'comment' => ['nullable', 'string'],
        ]);

        try {
            return ApiResponse::ok(
                $this->leaveRequestService->bulkDecide(
                    $payload['ids'],
                    $decision,
                    $payload['comment'] ?? null,
                    $request->user()
                )
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
