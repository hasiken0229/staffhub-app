<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use App\Services\ImportHistoryService;
use App\Services\NoticeService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PayrollStatementStorageService
{
    use PayrollStatementSupport;

    public function __construct(
        private readonly NoticeService $noticeService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
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
}
