<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\PayrollStatementService;
use Illuminate\Http\Request;

final class PayrollController extends Controller
{
    public function __construct(private readonly PayrollStatementService $payrollStatementService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->payrollStatementService->listForEmployee((int) $request->user()->id, $request->only([
            'yearMonth',
            'statementType',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function show(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->payrollStatementService->detailForEmployee((int) $request->user()->id, $id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function download(Request $request, int $id)
    {
        try {
            return $this->payrollStatementService->downloadForEmployee((int) $request->user()->id, $id);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function markViewed(Request $request, int $id)
    {
        $request->validate([
            'viewedAt' => ['required', 'date'],
        ]);

        try {
            return ApiResponse::ok(
                $this->payrollStatementService->markViewed((int) $request->user()->id, $id, $request->input('viewedAt'))
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
