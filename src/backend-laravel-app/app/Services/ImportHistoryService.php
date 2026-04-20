<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportHistoryService
{
    public function record(
        string $importType,
        string $sourceFileName,
        ?string $targetPeriod,
        ?string $statementType,
        int $processedCount,
        int $successCount,
        int $errorCount,
        array $summary,
        ?GenericUser $actor = null,
        array $file = [],
    ): int {
        return (int) DB::table('import_histories')->insertGetId([
            'import_type' => strtoupper($importType),
            'source_file_name' => $sourceFileName,
            'download_file_name' => $file['downloadFileName'] ?? null,
            'target_period' => $targetPeriod,
            'statement_type' => $statementType ? strtoupper($statementType) : null,
            'processed_count' => $processedCount,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'file_path' => $file['filePath'] ?? null,
            'content_type' => $file['contentType'] ?? null,
            'expires_at' => !empty($file['expiresAt'])
                ? CarbonImmutable::parse((string) $file['expiresAt'])->format('Y-m-d H:i:s')
                : null,
            'imported_by' => $actor ? $this->resolveActorEmployeeId($actor) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function storeAndRecord(
        string $importType,
        string $sourceFileName,
        ?string $targetPeriod,
        ?string $statementType,
        int $processedCount,
        int $successCount,
        int $errorCount,
        array $summary,
        string $binaryContent,
        string $downloadFileName,
        string $contentType,
        ?GenericUser $actor = null,
        ?string $expiresAt = null,
    ): int {
        $safeFileName = trim(basename($downloadFileName)) ?: 'history_file';
        $storagePath = sprintf(
            'file-history/%s/%s_%s',
            strtolower($importType),
            now()->format('YmdHis'),
            Str::random(8) . '_' . $safeFileName
        );

        $absolutePath = $this->localStorageAbsolutePath($storagePath);
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ApiException('FILE_EXPORT_ERROR', '履歴ファイルの保存先を作成できません。', 500);
        }

        if (file_put_contents($absolutePath, $binaryContent) === false) {
            throw new ApiException('FILE_EXPORT_ERROR', '履歴ファイルを保存できません。', 500);
        }

        return $this->record(
            $importType,
            $sourceFileName,
            $targetPeriod,
            $statementType,
            $processedCount,
            $successCount,
            $errorCount,
            $summary,
            $actor,
            [
                'downloadFileName' => $downloadFileName,
                'filePath' => $storagePath,
                'contentType' => $contentType,
                'expiresAt' => $expiresAt,
            ],
        );
    }

    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('import_histories as ih')
            ->leftJoin('employees as e', 'e.id', '=', 'ih.imported_by')
            ->select([
                'ih.id',
                'ih.import_type',
                'ih.source_file_name',
                'ih.download_file_name',
                'ih.target_period',
                'ih.statement_type',
                'ih.processed_count',
                'ih.success_count',
                'ih.error_count',
                'ih.summary_json',
                'ih.file_path',
                'ih.content_type',
                'ih.expires_at',
                'ih.created_at',
                'e.employee_code',
                'e.name as imported_by_name',
            ]);

        if (!empty($filters['importType'])) {
            $query->where('ih.import_type', strtoupper((string) $filters['importType']));
        }

        if (!empty($filters['targetPeriod'])) {
            $query->where('ih.target_period', $filters['targetPeriod']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('ih.created_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(function (object $row): array {
                $summary = [];
                if (!empty($row->summary_json)) {
                    $summary = json_decode((string) $row->summary_json, true) ?: [];
                }

                return [
                    'id' => (int) $row->id,
                    'importType' => $row->import_type,
                    'sourceFileName' => $row->source_file_name,
                    'downloadFileName' => $row->download_file_name ?: $row->source_file_name,
                    'targetPeriod' => $row->target_period,
                    'statementType' => $row->statement_type,
                    'processedCount' => (int) $row->processed_count,
                    'successCount' => (int) $row->success_count,
                    'errorCount' => (int) $row->error_count,
                    'summary' => $summary,
                    'createdAt' => $row->created_at,
                    'importedByName' => $row->imported_by_name,
                    'importedByEmployeeCode' => $row->employee_code,
                    'downloadAvailable' => !empty($row->file_path)
                        && (int) $row->success_count > 0
                        && (empty($row->expires_at) || CarbonImmutable::parse((string) $row->expires_at)->isFuture()),
                    'contentType' => $row->content_type,
                    'expiresAt' => $row->expires_at
                        ? CarbonImmutable::parse((string) $row->expires_at)->toIso8601String()
                        : null,
                ];
            })->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function download(int $historyId)
    {
        $history = DB::table('import_histories')
            ->where('id', $historyId)
            ->first();

        if ($history === null) {
            throw new ApiException('NOT_FOUND', '履歴が見つかりません。', 404);
        }

        if ((int) $history->success_count <= 0 || empty($history->file_path)) {
            throw new ApiException('NOT_FOUND', '再取得できるファイルがありません。', 404);
        }

        if (!empty($history->expires_at) && CarbonImmutable::parse((string) $history->expires_at)->isPast()) {
            throw new ApiException('FORBIDDEN', 'このファイルの再取得期限は終了しました。', 403);
        }

        $absolutePath = $this->localStorageAbsolutePath((string) $history->file_path);
        if (!is_file($absolutePath)) {
            throw new ApiException('NOT_FOUND', '保存済みファイルが見つかりません。', 404);
        }

        return response()->download(
            $absolutePath,
            (string) ($history->download_file_name ?: $history->source_file_name),
            [
                'Content-Type' => $history->content_type ?: 'application/octet-stream',
            ],
        );
    }

    private function localStorageAbsolutePath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new ApiException('FILE_EXPORT_ERROR', '履歴ファイルの保存パスが不正です。', 500);
        }

        return storage_path('app/private/' . $normalized);
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
}
