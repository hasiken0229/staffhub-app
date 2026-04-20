<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use App\Services\PayrollTemplateCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PayrollImportBatchQueryService
{
    public function __construct(private readonly PayrollTemplateCatalog $catalog)
    {
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
}
