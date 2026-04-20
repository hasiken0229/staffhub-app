<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\CardAssignmentService;
use Illuminate\Http\Request;

final class CardAdminController extends Controller
{
    public function __construct(private readonly CardAssignmentService $cardAssignmentService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->cardAssignmentService->list($request->only([
            'cardUid',
            'employeeCode',
            'isActive',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function assign(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'cardUid' => ['required', 'string', 'max:64'],
        ]);

        try {
            return ApiResponse::ok(
                $this->cardAssignmentService->assign((int) $payload['employeeId'], $payload['cardUid'])
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

    public function revoke(Request $request)
    {
        $payload = $request->validate([
            'cardId' => ['required', 'integer', 'min:1'],
        ]);

        return ApiResponse::ok([
            'success' => $this->cardAssignmentService->revoke((int) $payload['cardId']),
        ]);
    }
}
