<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PayrollStatementEmployeeService
{
    use PayrollStatementSupport;

    public function listForEmployee(int $employeeId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $baseQuery = DB::table('payroll_statements as ps')
            ->where('ps.employee_id', $employeeId)
            ->whereNull('ps.deleted_at')
            ->whereNotNull('ps.published_at')
            ->where('ps.published_at', '<=', now());

        if (!empty($filters['yearMonth'])) {
            $baseQuery->where('ps.target_year_month', $filters['yearMonth']);
        }

        if (!empty($filters['statementType'])) {
            $baseQuery->where('ps.statement_type', strtoupper((string) $filters['statementType']));
        }

        $total = (clone $baseQuery)->distinct()->count('ps.id');
        $rows = (clone $baseQuery)
            ->leftJoin('payroll_statement_views as psv', function ($join) use ($employeeId) {
                $join->on('psv.payroll_statement_id', '=', 'ps.id')
                    ->where('psv.employee_id', '=', $employeeId);
            })
            ->select([
                'ps.id',
                'ps.statement_type',
                'ps.target_year_month',
                'ps.pay_date',
                'ps.period_start_on',
                'ps.period_end_on',
                'ps.original_file_name',
                'ps.published_at',
                'ps.remarks',
                DB::raw('COUNT(psv.id) as view_count'),
            ])
            ->groupBy(
                'ps.id',
                'ps.statement_type',
                'ps.target_year_month',
                'ps.pay_date',
                'ps.period_start_on',
                'ps.period_end_on',
                'ps.original_file_name',
                'ps.published_at',
                'ps.remarks'
            )
            ->orderByDesc('ps.target_year_month')
            ->orderByDesc('ps.statement_type')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => [
                'id' => (int) $row->id,
                'statementType' => $row->statement_type,
                'statementTypeLabel' => $this->statementTypeLabel((string) $row->statement_type),
                'targetYearMonth' => $row->target_year_month,
                'payDate' => $row->pay_date,
                'periodStartOn' => $row->period_start_on,
                'periodEndOn' => $row->period_end_on,
                'originalFileName' => $row->original_file_name,
                'publishedAt' => $row->published_at ? CarbonImmutable::parse($row->published_at)->toIso8601String() : null,
                'viewed' => ((int) $row->view_count) > 0,
                'remarks' => $row->remarks,
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function detailForEmployee(int $employeeId, int $statementId): array
    {
        $statement = $this->loadVisibleStatement($employeeId, $statementId);
        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        return $this->buildStatementDetail($statement, false);
    }

    public function downloadForEmployee(int $employeeId, int $statementId)
    {
        $statement = $this->loadVisibleStatement($employeeId, $statementId);
        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        return $this->streamStatement($statement, false);
    }

    public function markViewed(int $employeeId, int $statementId, string $viewedAt): array
    {
        $statement = $this->loadVisibleStatement($employeeId, $statementId);
        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        DB::table('payroll_statement_views')->insert([
            'payroll_statement_id' => $statementId,
            'employee_id' => $employeeId,
            'viewed_at' => CarbonImmutable::parse($viewedAt)->format('Y-m-d H:i:s'),
            'ip_address' => request()?->ip(),
            'user_agent' => Str::limit((string) request()?->userAgent(), 255, ''),
        ]);

        $this->addAudit('EMPLOYEE', $employeeId, 'PAYROLL_VIEWED', 'PAYROLL_STATEMENT', (string) $statementId, []);

        return ['success' => true];
    }
}
