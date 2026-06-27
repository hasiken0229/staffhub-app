<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use Illuminate\Support\Facades\DB;
use ZipArchive;

final class PayrollImportBatchArchiveService
{
    public function exportBatchPdf(int $batchId): array
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

        $entries = [];
        foreach ($items as $item) {
            $sourcePath = storage_path('app/private/' . ltrim((string) $item->file_path, '/\\'));
            if (!is_file($sourcePath)) {
                continue;
            }

            $entryName = $item->employee_code . '_' . $item->employee_name . '_' . $item->original_file_name;
            $entryContent = file_get_contents($sourcePath);
            if ($entryContent !== false) {
                $entries[] = [
                    'name' => $entryName,
                    'content' => $entryContent,
                ];
            }
        }

        if ($entries === []) {
            throw new ApiException('NOT_FOUND', '出力できる明細PDFがありません。', 404);
        }

        $downloadName = strtolower((string) $batch->statement_type) . '_batch_' . $batch->target_year_month . '.zip';
        $content = class_exists(ZipArchive::class)
            ? $this->buildZipWithExtension($entries)
            : $this->buildStoredZip($entries);

        return [
            'content' => $content,
            'fileName' => $downloadName,
            'contentType' => 'application/zip',
            'summary' => [
                'batchId' => (int) $batch->id,
                'statementType' => (string) $batch->statement_type,
                'targetYearMonth' => (string) $batch->target_year_month,
                'entryCount' => count($entries),
            ],
        ];
    }

    private function buildZipWithExtension(array $entries): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'staffhub-payroll-');
        if ($zipPath === false) {
            throw new ApiException('INTERNAL_ERROR', 'ZIPファイルを作成できません。', 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new ApiException('INTERNAL_ERROR', 'ZIPファイルを作成できません。', 500);
        }

        foreach ($entries as $entry) {
            $zip->addFromString((string) $entry['name'], (string) $entry['content']);
        }
        $zip->close();

        $content = file_get_contents($zipPath);
        @unlink($zipPath);
        if ($content === false) {
            throw new ApiException('INTERNAL_ERROR', 'ZIPファイルを読み取れません。', 500);
        }

        return $content;
    }

    private function buildStoredZip(array $entries): string
    {
        $localFiles = '';
        $centralDirectory = '';
        $offset = 0;

        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', (string) $entry['name']);
            $content = (string) $entry['content'];
            $crc = crc32($content);
            $size = strlen($content);
            $nameLength = strlen($name);

            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0) . $name;
            $localFiles .= $localHeader . $content;

            $centralDirectory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset,
            ) . $name;
            $offset += strlen($localHeader) + $size;
        }

        return $localFiles
            . $centralDirectory
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($centralDirectory), strlen($localFiles), 0);
    }
}
