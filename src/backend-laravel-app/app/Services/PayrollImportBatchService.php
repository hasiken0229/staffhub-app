<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

final class PayrollImportBatchService
{
    public function __construct(
        private readonly PayrollDefinitionService $definitionService,
        private readonly PayrollTemplateCatalog $catalog,
        private readonly PayrollCsvImportService $csvImportService,
        private readonly PayrollStatementService $payrollStatementService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

    public function listForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('payroll_import_batches as pib')
            ->join('payroll_data_definitions as pdd', 'pdd.id', '=', 'pib.payroll_data_definition_id')
            ->whereNull('pib.deleted_at')
            ->select([
                'pib.id',
                'pib.statement_type',
                'pib.target_year_month',
                'pib.period_start_on',
                'pib.period_end_on',
                'pib.pay_date',
                'pib.publish_date',
                'pib.source_file_name',
                'pib.processed_count',
                'pib.success_count',
                'pib.error_count',
                'pib.status',
                'pib.created_at',
                'pdd.definition_name',
            ]);

        if (!empty($filters['statementType'])) {
            $query->where('pib.statement_type', $this->catalog->normalizeStatementType((string) $filters['statementType']));
        }

        if (!empty($filters['targetYearMonth'])) {
            $query->where('pib.target_year_month', (string) $filters['targetYearMonth']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('pib.target_year_month')
            ->orderByDesc('pib.created_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapBatch($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function detailForAdmin(int $batchId, array $filters = []): array
    {
        $batch = DB::table('payroll_import_batches as pib')
            ->join('payroll_data_definitions as pdd', 'pdd.id', '=', 'pib.payroll_data_definition_id')
            ->where('pib.id', $batchId)
            ->whereNull('pib.deleted_at')
            ->select([
                'pib.*',
                'pdd.definition_name',
                'pdd.template_version',
                'pdd.field_count',
            ])
            ->first();

        if ($batch === null) {
            throw new ApiException('NOT_FOUND', '取込バッチが見つかりません。', 404);
        }

        $itemsQuery = DB::table('payroll_import_batch_items as pbi')
            ->leftJoin('payroll_statements as ps', 'ps.id', '=', 'pbi.statement_id')
            ->where('pbi.payroll_import_batch_id', $batchId)
            ->whereNull('pbi.deleted_at')
            ->select([
                'pbi.id',
                'pbi.employee_id',
                'pbi.employee_code',
                'pbi.employee_name',
                'pbi.gross_amount',
                'pbi.deduction_amount',
                'pbi.net_amount',
                'pbi.statement_id',
                'pbi.line_no',
                'ps.original_file_name',
                'ps.deleted_at as statement_deleted_at',
            ]);

        if (!empty($filters['employeeCode'])) {
            $itemsQuery->where('pbi.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['employeeName'])) {
            $itemsQuery->where('pbi.employee_name', 'like', '%' . $filters['employeeName'] . '%');
        }

        $items = $itemsQuery
            ->orderBy('pbi.employee_code')
            ->orderBy('pbi.line_no')
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'employeeId' => (int) $row->employee_id,
                'employeeCode' => $row->employee_code,
                'employeeName' => $row->employee_name,
                'grossAmount' => (float) $row->gross_amount,
                'deductionAmount' => (float) $row->deduction_amount,
                'netAmount' => (float) $row->net_amount,
                'statementId' => $row->statement_id !== null ? (int) $row->statement_id : null,
                'lineNo' => (int) $row->line_no,
                'originalFileName' => $row->original_file_name,
                'deleted' => $row->statement_deleted_at !== null,
            ])
            ->all();

        $summary = [];
        if (!empty($batch->summary_json)) {
            $summary = json_decode((string) $batch->summary_json, true) ?: [];
        }

        return [
            ...$this->mapBatch($batch),
            'templateVersion' => (int) $batch->template_version,
            'fieldCount' => (int) $batch->field_count,
            'items' => $items,
            'errors' => $summary['errors'] ?? [],
        ];
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

    public function deleteBatch(int $batchId, GenericUser $actor): array
    {
        $batch = DB::table('payroll_import_batches')
            ->where('id', $batchId)
            ->whereNull('deleted_at')
            ->first();

        if ($batch === null) {
            throw new ApiException('NOT_FOUND', '取込バッチが見つかりません。', 404);
        }

        $actorEmployeeId = $this->resolveActorEmployeeId($actor);

        DB::transaction(function () use ($batchId, $actorEmployeeId, $actor) {
            DB::table('payroll_import_batch_items')
                ->where('payroll_import_batch_id', $batchId)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('payroll_statements')
                ->where('payroll_import_batch_id', $batchId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => $actorEmployeeId,
                    'updated_at' => now(),
                ]);

            DB::table('payroll_import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'DELETED',
                    'deleted_at' => now(),
                    'deleted_by' => $actorEmployeeId,
                    'updated_at' => now(),
                ]);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAYROLL_BATCH_DELETE',
                'PAYROLL_IMPORT_BATCH',
                (string) $batchId,
                [
                    'batchId' => $batchId,
                ]
            );
        });

        return [
            'id' => $batchId,
            'deleted' => true,
        ];
    }

    public function exportBatchPdf(int $batchId)
    {
        $batch = DB::table('payroll_import_batches')
            ->where('id', $batchId)
            ->whereNull('deleted_at')
            ->first();

        if ($batch === null) {
            throw new ApiException('NOT_FOUND', '取込バッチが見つかりません。', 404);
        }

        $items = DB::table('payroll_import_batch_items as pbi')
            ->join('payroll_statements as ps', 'ps.id', '=', 'pbi.statement_id')
            ->where('pbi.payroll_import_batch_id', $batchId)
            ->whereNull('pbi.deleted_at')
            ->whereNull('ps.deleted_at')
            ->select([
                'pbi.employee_code',
                'pbi.employee_name',
                'ps.original_file_name',
                'ps.file_path',
            ])
            ->orderBy('pbi.employee_code')
            ->get();

        if ($items->isEmpty()) {
            throw new ApiException('NOT_FOUND', '出力できる明細PDFがありません。', 404);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'staffhub-payroll-');
        if ($zipPath === false) {
            throw new ApiException('INTERNAL_ERROR', 'ZIPファイルを作成できません。', 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new ApiException('INTERNAL_ERROR', 'ZIPファイルを作成できません。', 500);
        }

        foreach ($items as $item) {
            if (!Storage::disk('local')->exists($item->file_path)) {
                continue;
            }

            $entryName = $item->employee_code . '_' . $item->employee_name . '_' . $item->original_file_name;
            $zip->addFromString($entryName, (string) Storage::disk('local')->get($item->file_path));
        }

        $zip->close();

        $downloadName = strtolower((string) $batch->statement_type) . '_batch_' . $batch->target_year_month . '.zip';

        return response()->download($zipPath, $downloadName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    private function mapBatch(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'statementType' => $row->statement_type,
            'statementTypeLabel' => $this->catalog->title((string) $row->statement_type),
            'definitionName' => $row->definition_name,
            'targetYearMonth' => $row->target_year_month,
            'periodStartOn' => $row->period_start_on,
            'periodEndOn' => $row->period_end_on,
            'payDate' => $row->pay_date,
            'publishDate' => $row->publish_date,
            'sourceFileName' => $row->source_file_name,
            'processedCount' => (int) $row->processed_count,
            'successCount' => (int) $row->success_count,
            'errorCount' => (int) $row->error_count,
            'status' => $row->status,
            'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
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

    private function validateYearMonth(string $targetYearMonth): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $targetYearMonth)) {
            throw new ApiException('VALIDATION_ERROR', '対象月は YYYY-MM 形式で指定してください。', 422, [
                ['field' => 'targetYearMonth', 'message' => '対象月は YYYY-MM 形式で指定してください。'],
            ]);
        }

        return $targetYearMonth;
    }

    private function resolveActorEmployeeId(GenericUser $actor): ?int
    {
        if (strtoupper((string) $actor->role) === 'EMPLOYEE') {
            return (int) $actor->id;
        }

        $configured = (int) env('STAFFHUB_APPROVER_EMPLOYEE_ID', 0);
        if ($configured > 0 && DB::table('employees')->where('id', $configured)->exists()) {
            return $configured;
        }

        $firstActive = DB::table('employees')
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->value('id');

        return $firstActive ? (int) $firstActive : null;
    }

    private function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }
}
