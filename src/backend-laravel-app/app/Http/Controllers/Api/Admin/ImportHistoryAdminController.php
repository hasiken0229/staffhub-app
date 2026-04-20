<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\ImportHistoryService;
use Illuminate\Http\Request;

final class ImportHistoryAdminController extends Controller
{
    public function __construct(private readonly ImportHistoryService $importHistoryService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->importHistoryService->list($request->only([
            'importType',
            'targetPeriod',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function download(int $id)
    {
        try {
            return $this->importHistoryService->download($id);
        } catch (\App\Services\ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }
}
