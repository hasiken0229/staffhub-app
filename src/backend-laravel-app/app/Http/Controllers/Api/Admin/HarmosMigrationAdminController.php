<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\HarmosMigrationService;
use Illuminate\Http\Request;

final class HarmosMigrationAdminController extends Controller
{
    public function __construct(private readonly HarmosMigrationService $harmosMigrationService)
    {
    }

    public function preview(Request $request)
    {
        $payload = $request->validate([
            'importType' => ['required', 'string'],
            'targetPeriod' => ['nullable', 'string', 'max:20'],
            'migrationDate' => ['nullable', 'date'],
            'defaultDepartmentName' => ['nullable', 'string', 'max:100'],
            'defaultEmploymentType' => ['nullable', 'string', 'max:30'],
            'defaultStatus' => ['nullable', 'string', 'max:20'],
            'defaultJoinedOn' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        try {
            return ApiResponse::ok($this->harmosMigrationService->preview($payload, $request->file('file')));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function import(Request $request)
    {
        $payload = $request->validate([
            'importType' => ['required', 'string'],
            'targetPeriod' => ['nullable', 'string', 'max:20'],
            'migrationDate' => ['nullable', 'date'],
            'defaultDepartmentName' => ['nullable', 'string', 'max:100'],
            'defaultEmploymentType' => ['nullable', 'string', 'max:30'],
            'defaultStatus' => ['nullable', 'string', 'max:20'],
            'defaultJoinedOn' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        try {
            return ApiResponse::ok($this->harmosMigrationService->import($payload, $request->file('file'), $request->user()));
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
