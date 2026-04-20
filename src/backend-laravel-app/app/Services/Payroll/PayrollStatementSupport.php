<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait PayrollStatementSupport
{
    public function statementTypeLabel(string $statementType): string
    {
        return strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細';
    }

    protected function loadVisibleStatement(int $employeeId, int $statementId): ?object
    {
        return DB::table('payroll_statements as ps')
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
            ->where('ps.employee_id', $employeeId)
            ->whereNull('ps.deleted_at')
            ->whereNotNull('ps.published_at')
            ->where('ps.published_at', '<=', now())
            ->first();
    }

    protected function streamStatement(object $statement, bool $inline)
    {
        if (!Storage::disk('local')->exists($statement->file_path)) {
            throw new ApiException('NOT_FOUND', '給与明細ファイルが見つかりません。', 404);
        }

        $headers = [
            'Content-Type' => $statement->content_type ?: 'application/pdf',
        ];

        if ($inline) {
            $headers['Content-Disposition'] = 'inline; filename="' . $statement->original_file_name . '"';
            return Storage::disk('local')->response($statement->file_path, $statement->original_file_name, $headers);
        }

        return Storage::disk('local')->download($statement->file_path, $statement->original_file_name, $headers);
    }

    protected function buildStatementDetail(object $statement, bool $isAdmin): array
    {
        $lineRows = DB::table('payroll_statement_lines')
            ->where('payroll_statement_id', $statement->id)
            ->orderByRaw("
                CASE section_type
                    WHEN 'PAY' THEN 1
                    WHEN 'DEDUCTION' THEN 2
                    WHEN 'SUMMARY' THEN 3
                    WHEN 'OTHER' THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('display_order')
            ->get();

        $lines = $lineRows->map(fn (object $row) => [
            'id' => (int) $row->id,
            'sectionType' => $row->section_type,
            'sectionLabel' => $this->sectionLabel((string) $row->section_type),
            'displayOrder' => (int) $row->display_order,
            'itemLabel' => $row->item_label,
            'amount' => (float) $row->amount,
            'formattedAmount' => number_format((float) $row->amount),
            'rawSourceKey' => $row->raw_source_key,
        ])->all();

        $sections = [
            'pay' => array_values(array_filter($lines, fn (array $line) => $line['sectionType'] === 'PAY')),
            'deduction' => array_values(array_filter($lines, fn (array $line) => $line['sectionType'] === 'DEDUCTION')),
            'summary' => array_values(array_filter($lines, fn (array $line) => $line['sectionType'] === 'SUMMARY')),
            'other' => array_values(array_filter($lines, fn (array $line) => $line['sectionType'] === 'OTHER')),
        ];

        $summaryLookup = [];
        foreach ($sections['summary'] as $line) {
            $summaryLookup[$line['itemLabel']] = (float) $line['amount'];
        }

        return [
            'id' => (int) $statement->id,
            'employeeId' => (int) $statement->employee_id,
            'employeeCode' => $statement->employee_code ?? null,
            'employeeName' => $statement->employee_name ?? null,
            'statementType' => $statement->statement_type,
            'statementTypeLabel' => $this->statementTypeLabel((string) $statement->statement_type),
            'targetYearMonth' => $statement->target_year_month,
            'definitionName' => $statement->definition_name ?? null,
            'payDate' => $statement->pay_date,
            'periodStartOn' => $statement->period_start_on,
            'periodEndOn' => $statement->period_end_on,
            'publishedAt' => $statement->published_at ? CarbonImmutable::parse($statement->published_at)->toIso8601String() : null,
            'originalFileName' => $statement->original_file_name,
            'remarks' => $statement->remarks,
            'legacyMode' => $lines === [],
            'lines' => $lines,
            'sections' => $sections,
            'grossAmount' => (float) ($summaryLookup['総支給額'] ?? 0),
            'deductionAmount' => (float) ($summaryLookup['控除額計'] ?? 0),
            'netAmount' => (float) ($summaryLookup['差引支給'] ?? $summaryLookup['振込支給'] ?? 0),
            'downloadUrl' => url(($isAdmin ? '/api/admin' : '/api') . '/payroll/statements/' . $statement->id . '/download'),
            'previewUrl' => url(($isAdmin ? '/api/admin' : '/api') . '/payroll/statements/' . $statement->id . ($isAdmin ? '/preview' : '/download')),
            'deleteAvailable' => $isAdmin,
            'importBatchId' => $statement->payroll_import_batch_id !== null ? (int) $statement->payroll_import_batch_id : null,
        ];
    }

    protected function sectionLabel(string $sectionType): string
    {
        return match (strtoupper($sectionType)) {
            'PAY' => '支給',
            'DEDUCTION' => '控除',
            'SUMMARY' => 'その他',
            'OTHER' => '備考',
            default => '明細',
        };
    }

    protected function resolveUploaderEmployeeId(GenericUser $actor, int $fallbackEmployeeId): int
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

        return $firstActive ? (int) $firstActive : $fallbackEmployeeId;
    }

    protected function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }
}
