<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\LeaveRequestService;
use Illuminate\Http\Request;

final class LeaveAdminController extends Controller
{
    public function __construct(private readonly LeaveRequestService $leaveRequestService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->leaveRequestService->listForAdmin($request->only([
            'status',
            'employeeCode',
            'departmentName',
            'leaveTypeCode',
            'from',
            'to',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'APPROVED');
    }

    public function reject(Request $request, int $id)
    {
        return $this->decide($request, $id, 'REJECTED');
    }

    public function return(Request $request, int $id)
    {
        return $this->decide($request, $id, 'RETURNED');
    }

    public function grant(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'days' => ['required', 'numeric'],
            'grantedOn' => ['required', 'date'],
            'expiresOn' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->leaveRequestService->grantForAdmin($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function adjust(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'adjustmentType' => ['required', 'string', 'in:ADJUST_PLUS,ADJUST_MINUS'],
            'days' => ['required', 'numeric'],
            'effectiveOn' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return ApiResponse::ok($this->leaveRequestService->adjustForAdmin($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
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
}
