<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use App\Services\ImportHistoryService;
use App\Services\PayrollCsvImportService;
use App\Services\PayrollDefinitionService;
use App\Services\PayrollStatementService;
use App\Services\PayrollTemplateCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class PayrollImportBatchImportService
{
    use PayrollImportBatchSupport;

    public function __construct(
        private readonly PayrollDefinitionService $definitionService,
        private readonly PayrollTemplateCatalog $catalog,
        private readonly PayrollCsvImportService $csvImportService,
        private readonly PayrollStatementService $payrollStatementService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

    public function importLegacyCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        $statementType = $this->catalog->normalizeStatementType((string) ($payload['statementType'] ?? 'PAYROLL'));
        $targetYearMonth = $this->validateYearMonth((string) ($payload['targetYearMonth'] ?? ''));
        $publishAt = !empty($payload['publishedAt'])
            ? CarbonImmutable::parse((string) $payload['publishedAt'])
            : CarbonImmutable::now();

        $periodStart = CarbonImmutable::parse($targetYearMonth . '-01');
        $periodEnd = $periodStart->endOfMonth();

        return $this->importFromCsv([
            'statementType' => $statementType,
            'targetYearMonth' => $targetYearMonth,
            'periodStartOn' => $periodStart->format('Y-m-d'),
            'periodEndOn' => $periodEnd->format('Y-m-d'),
            'payDate' => $publishAt->format('Y-m-d'),
            'publishDate' => $publishAt->format('Y-m-d'),
        ], $file, $actor);
    }

    public function previewLegacyCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        $statementType = $this->catalog->normalizeStatementType((string) ($payload['statementType'] ?? 'PAYROLL'));
        $targetYearMonth = $this->validateYearMonth((string) ($payload['targetYearMonth'] ?? ''));
        $publishAt = !empty($payload['publishedAt'])
            ? CarbonImmutable::parse((string) $payload['publishedAt'])
            : CarbonImmutable::now();

        $periodStart = CarbonImmutable::parse($targetYearMonth . '-01');
        $periodEnd = $periodStart->endOfMonth();

        return $this->previewFromCsv([
            'statementType' => $statementType,
            'targetYearMonth' => $targetYearMonth,
            'periodStartOn' => $periodStart->format('Y-m-d'),
            'periodEndOn' => $periodEnd->format('Y-m-d'),
            'payDate' => $publishAt->format('Y-m-d'),
            'publishDate' => $publishAt->format('Y-m-d'),
        ], $file);
    }

    public function previewFromCsv(array $payload, UploadedFile $file): array
    {
        $statementType = $this->resolveStatementType($payload);
        $definition = $this->definitionService->resolveDefinition(
            isset($payload['definitionId']) ? (int) $payload['definitionId'] : null,
            $statementType
        );

        $targetYearMonth = $this->validateYearMonth((string) $payload['targetYearMonth']);
        $periodStartOn = CarbonImmutable::parse((string) $payload['periodStartOn']);
        $periodEndOn = CarbonImmutable::parse((string) $payload['periodEndOn']);
        $payDate = CarbonImmutable::parse((string) $payload['payDate']);
        $publishDate = CarbonImmutable::parse((string) $payload['publishDate']);

        $expectedHeaders = [];
        if (!empty($definition->sample_headers_json)) {
            $expectedHeaders = json_decode((string) $definition->sample_headers_json, true) ?: [];
        }
        if ($expectedHeaders === []) {
            $expectedHeaders = $this->catalog->headers($statementType);
        }

        $prepared = $this->csvImportService->prepareRows($file, $statementType, $expectedHeaders);
        $results = [];
        $errors = [];
        $seenEmployeeCodes = [];

        foreach (array_slice($prepared['rows'], 1) as $lineNumber => $row) {
            $actualLineNo = $lineNumber + 2;
            if ($this->csvImportService->rowIsEmpty($row)) {
                continue;
            }

            $employeeCode = $this->csvImportService->employeeCode($row, $prepared['headerIndexMap']);
            if ($employeeCode === '') {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => null,
                    'message' => '社員番号が空です。',
                ];
                continue;
            }

            if (isset($seenEmployeeCodes[$employeeCode])) {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => $employeeCode,
                    'message' => 'CSV内で社員番号が重複しています。',
                ];
                continue;
            }
            $seenEmployeeCodes[$employeeCode] = true;

            $employee = DB::table('employees')
                ->where('employee_code', $employeeCode)
                ->where('status', '!=', 'RETIRED')
                ->first();

            if ($employee === null) {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => $employeeCode,
                    'message' => 'employees に該当職員が存在しません。',
                ];
                continue;
            }

            try {
                $statement = $this->csvImportService->buildStatementPayload(
                    $statementType,
                    $targetYearMonth,
                    $row,
                    $prepared['headerIndexMap'],
                    $employee,
                    null
                );

                $results[] = [
                    'line' => $actualLineNo,
                    'employeeId' => (int) $employee->id,
                    'employeeCode' => $employeeCode,
                    'employeeName' => (string) $employee->name,
                    'grossAmount' => $statement['grossAmount'],
                    'deductionAmount' => $statement['deductionAmount'],
                    'netAmount' => $statement['netAmount'],
                ];
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => $employeeCode,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return [
            'dryRun' => true,
            'statementType' => $statementType,
            'statementTypeLabel' => $this->catalog->title($statementType),
            'definitionId' => (int) $definition->id,
            'definitionName' => $definition->definition_name,
            'targetYearMonth' => $targetYearMonth,
            'periodStartOn' => $periodStartOn->toDateString(),
            'periodEndOn' => $periodEndOn->toDateString(),
            'payDate' => $payDate->toDateString(),
            'publishDate' => $publishDate->toDateString(),
            'processedCount' => count($results) + count($errors),
            'importedCount' => count($results),
            'errorCount' => count($errors),
            'items' => $results,
            'errors' => $errors,
        ];
    }

    public function importFromCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        $statementType = $this->resolveStatementType($payload);
        $definition = $this->definitionService->resolveDefinition(
            isset($payload['definitionId']) ? (int) $payload['definitionId'] : null,
            $statementType
        );

        $targetYearMonth = $this->validateYearMonth((string) $payload['targetYearMonth']);
        $periodStartOn = CarbonImmutable::parse((string) $payload['periodStartOn']);
        $periodEndOn = CarbonImmutable::parse((string) $payload['periodEndOn']);
        $payDate = CarbonImmutable::parse((string) $payload['payDate']);
        $publishDate = CarbonImmutable::parse((string) $payload['publishDate']);
        $remarks = !empty($payload['remarks']) ? trim((string) $payload['remarks']) : null;
        $actorEmployeeId = $this->resolveActorEmployeeId($actor);

        if ($periodEndOn->lt($periodStartOn)) {
            throw new ApiException('VALIDATION_ERROR', '対象期間の終了日は開始日以降にしてください。', 422, [
                ['field' => 'periodEndOn', 'message' => '対象期間の終了日は開始日以降にしてください。'],
            ]);
        }

        $expectedHeaders = [];
        if (!empty($definition->sample_headers_json)) {
            $expectedHeaders = json_decode((string) $definition->sample_headers_json, true) ?: [];
        }
        if ($expectedHeaders === []) {
            $expectedHeaders = $this->catalog->headers($statementType);
        }

        $prepared = $this->csvImportService->prepareRows($file, $statementType, $expectedHeaders);

        $batchId = (int) DB::table('payroll_import_batches')->insertGetId([
            'statement_type' => $statementType,
            'payroll_data_definition_id' => (int) $definition->id,
            'target_year_month' => $targetYearMonth,
            'period_start_on' => $periodStartOn->format('Y-m-d'),
            'period_end_on' => $periodEndOn->format('Y-m-d'),
            'pay_date' => $payDate->format('Y-m-d'),
            'publish_date' => $publishDate->format('Y-m-d'),
            'source_file_name' => $file->getClientOriginalName(),
            'processed_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'status' => 'PROCESSING',
            'summary_json' => null,
            'created_by' => $actorEmployeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $results = [];
        $errors = [];

        foreach (array_slice($prepared['rows'], 1) as $lineNumber => $row) {
            $actualLineNo = $lineNumber + 2;
            if ($this->csvImportService->rowIsEmpty($row)) {
                continue;
            }

            $employeeCode = $this->csvImportService->employeeCode($row, $prepared['headerIndexMap']);
            if ($employeeCode === '') {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => null,
                    'message' => '社員番号が空です。',
                ];
                continue;
            }

            $employee = DB::table('employees')
                ->where('employee_code', $employeeCode)
                ->where('status', '!=', 'RETIRED')
                ->first();

            if ($employee === null) {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => $employeeCode,
                    'message' => 'employees に該当職員が存在しません。',
                ];
                continue;
            }

            try {
                $statement = $this->csvImportService->buildStatementPayload(
                    $statementType,
                    $targetYearMonth,
                    $row,
                    $prepared['headerIndexMap'],
                    $employee,
                    $remarks
                );
                $pdfBinary = $this->csvImportService->renderPdf($statement);
                $result = $this->payrollStatementService->upsertBinaryForAdmin(
                    (int) $employee->id,
                    $statementType,
                    $targetYearMonth,
                    $publishDate->startOfDay(),
                    $this->csvImportService->makeOriginalFileName($statementType, $targetYearMonth, $employeeCode),
                    'application/pdf',
                    $pdfBinary,
                    $actor,
                    [
                        'payDate' => $payDate,
                        'periodStartOn' => $periodStartOn,
                        'periodEndOn' => $periodEndOn,
                        'payrollDataDefinitionId' => (int) $definition->id,
                        'payrollImportBatchId' => $batchId,
                        'remarks' => $remarks,
                    ]
                );

                $lines = $this->catalog->buildLines($statementType, $statement);
                $this->payrollStatementService->replaceStatementLines((int) $result['id'], $lines);

                DB::table('payroll_import_batch_items')->insert([
                    'payroll_import_batch_id' => $batchId,
                    'employee_id' => (int) $employee->id,
                    'employee_code' => $employeeCode,
                    'employee_name' => (string) $employee->name,
                    'gross_amount' => $statement['grossAmount'],
                    'deduction_amount' => $statement['deductionAmount'],
                    'net_amount' => $statement['netAmount'],
                    'statement_id' => (int) $result['id'],
                    'line_no' => $actualLineNo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $results[] = [
                    'line' => $actualLineNo,
                    'employeeId' => (int) $employee->id,
                    'employeeCode' => $employeeCode,
                    'employeeName' => (string) $employee->name,
                    'statementId' => (int) $result['id'],
                ];
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'line' => $actualLineNo,
                    'employeeCode' => $employeeCode,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        DB::table('payroll_import_batches')
            ->where('id', $batchId)
            ->update([
                'processed_count' => count($results) + count($errors),
                'success_count' => count($results),
                'error_count' => count($errors),
                'status' => count($errors) > 0 ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED',
                'summary_json' => json_encode([
                    'items' => $results,
                    'errors' => $errors,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $historySummary = [
            'batchId' => $batchId,
            'definitionId' => (int) $definition->id,
            'items' => $results,
            'errors' => $errors,
        ];
        $historyType = $statementType === 'BONUS' ? 'BONUS_CSV' : 'PAYROLL_CSV';
        $fileContent = file_get_contents($file->getRealPath());

        if (count($results) > 0 && $fileContent !== false) {
            $this->importHistoryService->storeAndRecord(
                $historyType,
                $file->getClientOriginalName(),
                $targetYearMonth,
                $statementType,
                count($results) + count($errors),
                count($results),
                count($errors),
                $historySummary,
                $fileContent,
                $file->getClientOriginalName(),
                'text/csv; charset=Shift_JIS',
                $actor,
            );
        } else {
            $this->importHistoryService->record(
                $historyType,
                $file->getClientOriginalName(),
                $targetYearMonth,
                $statementType,
                count($results) + count($errors),
                count($results),
                count($errors),
                $historySummary,
                $actor,
            );
        }

        return [
            'batchId' => $batchId,
            'statementType' => $statementType,
            'statementTypeLabel' => $this->catalog->title($statementType),
            'definitionId' => (int) $definition->id,
            'definitionName' => $definition->definition_name,
            'targetYearMonth' => $targetYearMonth,
            'periodStartOn' => $periodStartOn->toDateString(),
            'periodEndOn' => $periodEndOn->toDateString(),
            'payDate' => $payDate->toDateString(),
            'publishDate' => $publishDate->toDateString(),
            'processedCount' => count($results) + count($errors),
            'importedCount' => count($results),
            'errorCount' => count($errors),
            'items' => $results,
            'errors' => $errors,
        ];
    }

    private function resolveStatementType(array $payload): string
    {
        if (!empty($payload['statementType'])) {
            return $this->catalog->normalizeStatementType((string) $payload['statementType']);
        }

        if (!empty($payload['definitionId'])) {
            $definition = DB::table('payroll_data_definitions')->where('id', (int) $payload['definitionId'])->first();
            if ($definition === null) {
                throw new ApiException('NOT_FOUND', 'データ定義が見つかりません。', 404);
            }

            return $this->catalog->normalizeStatementType((string) $definition->statement_type);
        }

        throw new ApiException('VALIDATION_ERROR', '明細種別を指定してください。', 422, [
            ['field' => 'statementType', 'message' => '明細種別を指定してください。'],
        ]);
    }
}
