<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\SystemMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemMasterAdminController extends Controller
{
    public function __construct(
        private readonly SystemMasterService $systemMasterService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->systemMasterService->overview(),
            'meta' => [],
        ]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeDepartment($request->all()));
    }

    public function storeLocation(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeLocation($request->all()));
    }

    public function storeEmploymentType(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeEmploymentType($request->all()));
    }

    public function storeWorkType(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeWorkType($request->all()));
    }

    public function storeRequestType(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeRequestType($request->all()));
    }

    public function storeLeaveType(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeLeaveType($request->all()));
    }

    public function storePaidLeaveSetting(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storePaidLeaveSetting($request->all()));
    }

    public function storeAttendanceAlert(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeAttendanceAlert($request->all()));
    }

    public function storeAttendanceErrorRule(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeAttendanceErrorRule($request->all()));
    }

    public function storeDailyField(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->systemMasterService->storeDailyField($request->all()));
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'data' => $callback(),
                'meta' => [],
            ]);
        } catch (ApiException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                    'details' => $exception->getDetails(),
                ],
            ], $exception->getStatusCode());
        }
    }
}
