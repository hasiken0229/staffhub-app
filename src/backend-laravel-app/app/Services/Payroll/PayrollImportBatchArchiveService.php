<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

final class PayrollImportBatchArchiveService
{
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
}
