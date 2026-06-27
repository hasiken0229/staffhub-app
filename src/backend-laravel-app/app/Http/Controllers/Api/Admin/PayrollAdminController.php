<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\ImportHistoryService;
use App\Services\PayrollDefinitionService;
use App\Services\PayrollImportBatchService;
use App\Services\PayrollStatementService;
use Illuminate\Http\Request;

final class PayrollAdminController extends Controller
{
    public function __construct(
        private readonly PayrollStatementService $payrollStatementService,
        private readonly PayrollImportBatchService $payrollImportBatchService,
        private readonly PayrollDefinitionService $payrollDefinitionService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

    public function index(Request $request)
    {
        $result = $this->payrollStatementService->listForAdmin($request->only([
            'employeeCode',
            'targetYearMonth',
            'statementType',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function show(int $id)
    {
        try {
            return ApiResponse::ok($this->payrollStatementService->detailForAdmin($id));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->payrollStatementService->deleteForAdmin($id, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function download(int $id)
    {
        try {
            return $this->payrollStatementService->downloadForAdmin($id, false);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function preview(int $id)
    {
        try {
            return $this->payrollStatementService->downloadForAdmin($id, true);
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
            'employeeId' => ['required', 'integer', 'min:1'],
            'statementType' => ['nullable', 'string', 'in:PAYROLL,BONUS'],
            'targetYearMonth' => ['required', 'string', 'size:7'],
            'publishedAt' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            return ApiResponse::ok($this->payrollStatementService->storeForAdmin($payload, $request->file('file'), $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function definitions(Request $request)
    {
        return ApiResponse::ok($this->payrollDefinitionService->list($request->only([
            'statementType',
            'activeOnly',
        ])));
    }

    public function saveDefinition(Request $request)
    {
        $payload = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'statementType' => ['required', 'string', 'in:PAYROLL,BONUS'],
            'definitionName' => ['required', 'string', 'max:100'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        try {
            return ApiResponse::ok($this->payrollDefinitionService->save($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function downloadDefinitionTemplateCsv(Request $request)
    {
        try {
            $template = $this->payrollDefinitionService->downloadTemplate((string) $request->query('statementType', 'PAYROLL'));

            return response($template['csv'], 200, [
                'Content-Type' => $template['contentType'],
                'Content-Disposition' => 'attachment; filename="' . $template['fileName'] . '"',
            ]);
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function importBatches(Request $request)
    {
        $result = $this->payrollImportBatchService->listForAdmin($request->only([
            'statementType',
            'targetYearMonth',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function showImportBatch(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->payrollImportBatchService->detailForAdmin($id, $request->only([
                'employeeCode',
                'employeeName',
            ])));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function storeImportBatch(Request $request)
    {
        $payload = $request->validate([
            'definitionId' => ['nullable', 'integer', 'min:1'],
            'statementType' => ['required', 'string', 'in:PAYROLL,BONUS'],
            'targetYearMonth' => ['required', 'string', 'size:7'],
            'periodStartOn' => ['required', 'date'],
            'periodEndOn' => ['required', 'date'],
            'payDate' => ['required', 'date'],
            'publishDate' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ]);

        try {
            return ApiResponse::ok($this->payrollImportBatchService->importFromCsv($payload, $request->file('file'), $request->user()));
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
            'statementType' => ['required', 'string', 'in:PAYROLL,BONUS'],
            'targetYearMonth' => ['required', 'string', 'size:7'],
            'publishedAt' => ['nullable', 'date'],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ]);

        try {
            return ApiResponse::ok($this->payrollImportBatchService->previewLegacyCsv($payload, $request->file('file'), $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function deleteImportBatch(Request $request, int $id)
    {
        try {
            return ApiResponse::ok($this->payrollImportBatchService->deleteBatch($id, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function exportImportBatchPdf(Request $request, int $id)
    {
        try {
            $export = $this->payrollImportBatchService->exportBatchPdf($id);
            $summary = $export['summary'];
            $statementType = strtoupper((string) ($summary['statementType'] ?? 'PAYROLL'));
            $importType = $statementType === 'BONUS' ? 'BONUS_BATCH_ZIP' : 'PAYROLL_BATCH_ZIP';

            $this->importHistoryService->storeAndRecord(
                $importType,
                $export['fileName'],
                (string) ($summary['targetYearMonth'] ?? ''),
                $statementType,
                (int) ($summary['entryCount'] ?? 1),
                (int) ($summary['entryCount'] ?? 1),
                0,
                $summary,
                $export['content'],
                $export['fileName'],
                $export['contentType'],
                $request->user(),
            );

            return response($export['content'], 200, [
                'Content-Type' => $export['contentType'],
                'Content-Disposition' => 'attachment; filename="' . $export['fileName'] . '"',
            ]);
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
            'statementType' => ['required', 'string', 'in:PAYROLL,BONUS'],
            'targetYearMonth' => ['required', 'string', 'size:7'],
            'publishedAt' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            return ApiResponse::ok($this->payrollImportBatchService->importLegacyCsv($payload, $request->file('file'), $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function downloadTemplateCsv(Request $request)
    {
        return $this->downloadDefinitionTemplateCsv($request);
    }
}
