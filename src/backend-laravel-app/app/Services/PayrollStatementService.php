<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PayrollStatementService
{
    public function __construct(
        private readonly NoticeService $noticeService,
        private readonly PayrollTemplateCatalog $catalog,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

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

    private function streamStatement(object $statement, bool $inline)
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

    public function storeForAdmin(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        $employeeId = (int) $payload['employeeId'];
        $statementType = strtoupper((string) ($payload['statementType'] ?? 'PAYROLL'));
        $targetYearMonth = (string) $payload['targetYearMonth'];
        $publishedAt = !empty($payload['publishedAt'])
            ? CarbonImmutable::parse($payload['publishedAt'])
            : CarbonImmutable::now();

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' || $file->getMimeType() !== 'application/pdf') {
            throw new ApiException('FILE_UPLOAD_ERROR', 'PDFファイルのみアップロードできます。', 422, [
                ['field' => 'file', 'message' => 'PDFファイルのみアップロードできます。'],
            ]);
        }

        $binaryContent = (string) file_get_contents($file->getRealPath());

        $result = $this->upsertBinaryForAdmin(
            $employeeId,
            $statementType,
            $targetYearMonth,
            $publishedAt,
            $file->getClientOriginalName(),
            $file->getMimeType() ?: 'application/pdf',
            $binaryContent,
            $actor
        );

        $historyType = $statementType === 'BONUS' ? 'BONUS_PDF_UPLOAD' : 'PAYROLL_PDF_UPLOAD';
        $this->importHistoryService->storeAndRecord(
            $historyType,
            $file->getClientOriginalName(),
            $targetYearMonth,
            $statementType,
            1,
            1,
            0,
            [
                'statementId' => $result['id'],
                'employeeId' => $employeeId,
                'employeeCode' => $result['employeeCode'],
            ],
            $binaryContent,
            $file->getClientOriginalName(),
            'application/pdf',
            $actor,
        );

        return $result;
    }

    public function upsertBinaryForAdmin(
        int $employeeId,
        string $statementType,
        string $targetYearMonth,
        CarbonImmutable $publishedAt,
        string $originalFileName,
        string $contentType,
        string $binaryContent,
        GenericUser $actor,
        array $options = [],
    ): array {
        $statementType = strtoupper($statementType);
        if (!in_array($statementType, ['PAYROLL', 'BONUS'], true)) {
            throw new ApiException('VALIDATION_ERROR', '明細種別が不正です。', 422, [
                ['field' => 'statementType', 'message' => 'PAYROLL または BONUS を指定してください。'],
            ]);
        }

        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if ($employee === null) {
            throw new ApiException('NOT_FOUND', '対象職員が見つかりません。', 404);
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $targetYearMonth)) {
            throw new ApiException('VALIDATION_ERROR', '対象年月は YYYY-MM 形式で指定してください。', 422, [
                ['field' => 'targetYearMonth', 'message' => 'YYYY-MM 形式で指定してください。'],
            ]);
        }

        $uploaderEmployeeId = $this->resolveUploaderEmployeeId($actor, $employeeId);
        $storagePath = sprintf(
            'payroll/%s/%s/%s/%s_%s.pdf',
            $statementType,
            $targetYearMonth,
            $employee->employee_code,
            now()->format('YmdHis'),
            Str::random(8)
        );

        Storage::disk('local')->put($storagePath, $binaryContent);

        return DB::transaction(function () use (
            $employeeId,
            $employee,
            $statementType,
            $targetYearMonth,
            $publishedAt,
            $uploaderEmployeeId,
            $storagePath,
            $originalFileName,
            $contentType,
            $binaryContent,
            $actor,
            $options
        ) {
            $existing = DB::table('payroll_statements')
                ->where('employee_id', $employeeId)
                ->where('statement_type', $statementType)
                ->where('target_year_month', $targetYearMonth)
                ->first();

            $attributes = [
                'employee_id' => $employeeId,
                'statement_type' => $statementType,
                'target_year_month' => $targetYearMonth,
                'pay_date' => isset($options['payDate']) && $options['payDate'] !== null
                    ? CarbonImmutable::parse((string) $options['payDate'])->format('Y-m-d')
                    : null,
                'period_start_on' => isset($options['periodStartOn']) && $options['periodStartOn'] !== null
                    ? CarbonImmutable::parse((string) $options['periodStartOn'])->format('Y-m-d')
                    : null,
                'period_end_on' => isset($options['periodEndOn']) && $options['periodEndOn'] !== null
                    ? CarbonImmutable::parse((string) $options['periodEndOn'])->format('Y-m-d')
                    : null,
                'file_path' => $storagePath,
                'original_file_name' => $originalFileName,
                'file_size_bytes' => strlen($binaryContent),
                'content_type' => $contentType,
                'published_at' => $publishedAt->format('Y-m-d H:i:s'),
                'uploaded_by' => $uploaderEmployeeId,
                'payroll_data_definition_id' => $options['payrollDataDefinitionId'] ?? null,
                'payroll_import_batch_id' => $options['payrollImportBatchId'] ?? null,
                'remarks' => $options['remarks'] ?? null,
                'deleted_at' => null,
                'deleted_by' => null,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                $statementId = (int) DB::table('payroll_statements')->insertGetId([
                    ...$attributes,
                    'created_at' => now(),
                ]);
            } else {
                $statementId = (int) $existing->id;
                if (!empty($existing->file_path) && Storage::disk('local')->exists($existing->file_path)) {
                    Storage::disk('local')->delete($existing->file_path);
                }

                DB::table('payroll_statements')
                    ->where('id', $statementId)
                    ->update($attributes);
            }

            $this->noticeService->createPayrollPublicationNotice(
                $employeeId,
                $statementType,
                $targetYearMonth,
                $statementId,
                $uploaderEmployeeId,
            );

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAYROLL_STATEMENT_UPSERT',
                'PAYROLL_STATEMENT',
                (string) $statementId,
                [
                    'employeeId' => $employeeId,
                    'employeeCode' => $employee->employee_code,
                    'statementType' => $statementType,
                    'targetYearMonth' => $targetYearMonth,
                    'fileName' => $originalFileName,
                    'payDate' => $attributes['pay_date'],
                    'periodStartOn' => $attributes['period_start_on'],
                    'periodEndOn' => $attributes['period_end_on'],
                ]
            );

            return [
                'id' => $statementId,
                'employeeId' => $employeeId,
                'employeeCode' => $employee->employee_code,
                'employeeName' => $employee->name,
                'statementType' => $statementType,
                'statementTypeLabel' => $this->statementTypeLabel($statementType),
                'targetYearMonth' => $targetYearMonth,
                'payDate' => $attributes['pay_date'],
                'periodStartOn' => $attributes['period_start_on'],
                'periodEndOn' => $attributes['period_end_on'],
                'originalFileName' => $originalFileName,
                'publishedAt' => $publishedAt->toIso8601String(),
            ];
        });
    }

    public function replaceStatementLines(int $statementId, array $lines): void
    {
        DB::transaction(function () use ($statementId, $lines) {
            DB::table('payroll_statement_lines')->where('payroll_statement_id', $statementId)->delete();

            if ($lines === []) {
                return;
            }

            $records = array_map(function (array $line) use ($statementId): array {
                return [
                    'payroll_statement_id' => $statementId,
                    'section_type' => strtoupper((string) $line['sectionType']),
                    'display_order' => (int) $line['displayOrder'],
                    'item_label' => (string) $line['itemLabel'],
                    'amount' => (float) $line['amount'],
                    'raw_source_key' => $line['rawSourceKey'] ?? null,
                ];
            }, $lines);

            DB::table('payroll_statement_lines')->insert($records);
        });
    }

    public function deleteForAdmin(int $statementId, GenericUser $actor): array
    {
        $statement = DB::table('payroll_statements')
            ->where('id', $statementId)
            ->whereNull('deleted_at')
            ->first();

        if ($statement === null) {
            throw new ApiException('NOT_FOUND', '給与明細が見つかりません。', 404);
        }

        $deletedBy = $this->resolveUploaderEmployeeId($actor, (int) $statement->employee_id);

        DB::transaction(function () use ($statementId, $deletedBy, $actor) {
            DB::table('payroll_statements')
                ->where('id', $statementId)
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => $deletedBy,
                    'updated_at' => now(),
                ]);

            DB::table('payroll_import_batch_items')
                ->where('statement_id', $statementId)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAYROLL_STATEMENT_DELETE',
                'PAYROLL_STATEMENT',
                (string) $statementId,
                ['statementId' => $statementId]
            );
        });

        return [
            'id' => $statementId,
            'deleted' => true,
        ];
    }

    public function statementTypeLabel(string $statementType): string
    {
        return strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細';
    }

    private function loadVisibleStatement(int $employeeId, int $statementId): ?object
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

    private function buildStatementDetail(object $statement, bool $isAdmin): array
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

    private function sectionLabel(string $sectionType): string
    {
        return match (strtoupper($sectionType)) {
            'PAY' => '支給',
            'DEDUCTION' => '控除',
            'SUMMARY' => 'その他',
            'OTHER' => '備考',
            default => '明細',
        };
    }

    private function resolveUploaderEmployeeId(GenericUser $actor, int $fallbackEmployeeId): int
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

    private function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }
}
