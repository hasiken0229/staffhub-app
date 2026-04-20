<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PayrollStatementAdminQueryService
{
    use PayrollStatementSupport;

    public function downloadForAdmin(int $statementId, bool $inline = false)
    {
        $statement = DB::table('payroll_statements')
            ->where('id', $statementId)
            ->whereNull('deleted_at')
            ->first();
        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        return $this->streamStatement($statement, $inline);
    }

    public function detailForAdmin(int $statementId): array
    {
        $statement = DB::table('payroll_statements as ps')
            ->join('employees as e', 'e.id', '=', 'ps.employee_id')
            ->leftJoin('payroll_data_definitions as pdd', 'pdd.id', '=', 'ps.payroll_data_definition_id')
            ->leftJoin('payroll_import_batches as pib', 'pib.id', '=', 'ps.payroll_import_batch_id')
            ->select([
                'ps.*',
                'e.employee_code',
                'e.name as employee_name',
                'pdd.definition_name',
                'pib.publish_date as batch_publish_date',
            ])
            ->where('ps.id', $statementId)
            ->whereNull('ps.deleted_at')
            ->first();

        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        return $this->buildStatementDetail($statement, true);
    }

    public function listForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $baseQuery = DB::table('payroll_statements as ps')
            ->join('employees as e', 'e.id', '=', 'ps.employee_id')
            ->whereNull('ps.deleted_at');

        if (!empty($filters['employeeCode'])) {
            $baseQuery->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['targetYearMonth'])) {
            $baseQuery->where('ps.target_year_month', $filters['targetYearMonth']);
        }

        if (!empty($filters['statementType'])) {
            $baseQuery->where('ps.statement_type', strtoupper((string) $filters['statementType']));
        }

        $total = (clone $baseQuery)->distinct()->count('ps.id');
        $rows = (clone $baseQuery)
            ->leftJoin('payroll_statement_views as psv', 'psv.payroll_statement_id', '=', 'ps.id')
            ->leftJoin('payroll_data_definitions as pdd', 'pdd.id', '=', 'ps.payroll_data_definition_id')
            ->select([
                'ps.id',
                'ps.employee_id',
                'ps.statement_type',
                'e.employee_code',
                'e.name as employee_name',
                'ps.target_year_month',
                'ps.pay_date',
                'ps.period_start_on',
                'ps.period_end_on',
                'ps.original_file_name',
                'ps.file_size_bytes',
                'ps.published_at',
                'ps.payroll_import_batch_id',
                'pdd.definition_name',
                DB::raw('COUNT(psv.id) as view_count'),
                DB::raw('MAX(psv.viewed_at) as last_viewed_at'),
            ])
            ->groupBy(
                'ps.id',
                'ps.employee_id',
                'ps.statement_type',
                'e.employee_code',
                'e.name',
                'ps.target_year_month',
                'ps.pay_date',
                'ps.period_start_on',
                'ps.period_end_on',
                'ps.original_file_name',
                'ps.file_size_bytes',
                'ps.published_at',
                'ps.payroll_import_batch_id',
                'pdd.definition_name'
            )
            ->orderByDesc('ps.target_year_month')
            ->orderByDesc('ps.statement_type')
            ->orderBy('e.employee_code')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => [
                'id' => (int) $row->id,
                'employeeId' => (int) $row->employee_id,
                'employeeCode' => $row->employee_code,
                'employeeName' => $row->employee_name,
                'statementType' => $row->statement_type,
                'statementTypeLabel' => $this->statementTypeLabel((string) $row->statement_type),
                'targetYearMonth' => $row->target_year_month,
                'payDate' => $row->pay_date,
                'periodStartOn' => $row->period_start_on,
                'periodEndOn' => $row->period_end_on,
                'definitionName' => $row->definition_name,
                'importBatchId' => $row->payroll_import_batch_id !== null ? (int) $row->payroll_import_batch_id : null,
                'originalFileName' => $row->original_file_name,
                'fileSizeBytes' => $row->file_size_bytes ? (int) $row->file_size_bytes : null,
                'publishedAt' => $row->published_at ? CarbonImmutable::parse($row->published_at)->toIso8601String() : null,
                'viewCount' => (int) $row->view_count,
                'lastViewedAt' => $row->last_viewed_at ? CarbonImmutable::parse($row->last_viewed_at)->toIso8601String() : null,
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }
}
