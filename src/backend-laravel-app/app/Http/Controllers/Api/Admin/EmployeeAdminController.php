<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\EmployeeAdminService;
use App\Services\ImportHistoryService;
use Illuminate\Http\Request;

final class EmployeeAdminController extends Controller
{
    public function __construct(
        private readonly EmployeeAdminService $employeeAdminService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

    public function index(Request $request)
    {
        $result = $this->employeeAdminService->index($request->only([
            'employeeCode',
            'name',
            'status',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'employeeCode' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['nullable', 'string', 'max:100'],
            'departmentName' => ['nullable', 'string', 'max:100'],
            'locationName' => ['nullable', 'string', 'max:100'],
            'employmentType' => ['required', 'string', 'max:30'],
            'status' => ['required', 'string', 'max:20'],
            'joinedOn' => ['required', 'date'],
            'retiredOn' => ['nullable', 'date'],
            'loginEmail' => ['nullable', 'email', 'max:100'],
            'googleChatUserId' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            return ApiResponse::ok($this->employeeAdminService->store($payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function update(Request $request, int $id)
    {
        $payload = $request->validate([
            'employeeCode' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['nullable', 'string', 'max:100'],
            'departmentName' => ['nullable', 'string', 'max:100'],
            'locationName' => ['nullable', 'string', 'max:100'],
            'employmentType' => ['required', 'string', 'max:30'],
            'status' => ['required', 'string', 'max:20'],
            'joinedOn' => ['required', 'date'],
            'retiredOn' => ['nullable', 'date'],
            'loginEmail' => ['nullable', 'email', 'max:100'],
            'googleChatUserId' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            return ApiResponse::ok($this->employeeAdminService->update($id, $payload));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function importCsv(Request $request)
    {
        $payload = $request->validate([
            'defaultDepartmentName' => ['nullable', 'string', 'max:100'],
            'defaultEmploymentType' => ['nullable', 'string', 'max:30'],
            'defaultStatus' => ['nullable', 'string', 'max:20'],
            'defaultJoinedOn' => ['nullable', 'date'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ]);

        try {
            $result = $this->employeeAdminService->importFromCsv($payload, $request->file('file'));

            $this->importHistoryService->record(
                'EMPLOYEE_CSV',
                $request->file('file')->getClientOriginalName(),
                null,
                null,
                (int) $result['processedCount'],
                (int) ($result['createdCount'] + $result['updatedCount']),
                (int) $result['skippedCount'],
                [
                    'createdCount' => $result['createdCount'],
                    'updatedCount' => $result['updatedCount'],
                    'skippedCount' => $result['skippedCount'],
                ],
                $request->user()
            );

            return ApiResponse::ok($result);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function previewImportCsv(Request $request)
    {
        $payload = $request->validate([
            'defaultDepartmentName' => ['nullable', 'string', 'max:100'],
            'defaultEmploymentType' => ['nullable', 'string', 'max:30'],
            'defaultStatus' => ['nullable', 'string', 'max:20'],
            'defaultJoinedOn' => ['nullable', 'date'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ]);

        try {
            return ApiResponse::ok($this->employeeAdminService->previewImportFromCsv($payload, $request->file('file')));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function downloadTemplateCsv()
    {
        $rows = [
            ['社員番号', '姓', '名', 'ふりがな', '所属', '勤務場所', '雇用区分', '状態', '入職日', '退職日', 'メールアドレス', 'Google Chat ID'],
            ['101', '橋本', '良孝', 'はしもと よしたか', '保育', '本園', 'FULL_TIME', 'ACTIVE', '2024-04-01', '', 'staff@example.com', 'users/1234567890'],
        ];

        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\r\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employees_template.csv"',
        ]);
    }
}
