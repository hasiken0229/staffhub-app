<?php

namespace App\Services;

use App\Services\Payroll\PayrollImportBatchArchiveService;
use App\Services\Payroll\PayrollImportBatchDeletionService;
use App\Services\Payroll\PayrollImportBatchImportService;
use App\Services\Payroll\PayrollImportBatchQueryService;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;

final class PayrollImportBatchService
{
    public function __construct(
        private readonly PayrollImportBatchQueryService $queryService,
        private readonly PayrollImportBatchImportService $importService,
        private readonly PayrollImportBatchDeletionService $deletionService,
        private readonly PayrollImportBatchArchiveService $archiveService,
    ) {
    }

    public function listForAdmin(array $filters): array
    {
        return $this->queryService->listForAdmin($filters);
    }

    public function detailForAdmin(int $batchId, array $filters = []): array
    {
        return $this->queryService->detailForAdmin($batchId, $filters);
    }

    public function importLegacyCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        return $this->importService->importLegacyCsv($payload, $file, $actor);
    }

    public function previewLegacyCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        return $this->importService->previewLegacyCsv($payload, $file, $actor);
    }

    public function importFromCsv(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        return $this->importService->importFromCsv($payload, $file, $actor);
    }

    public function deleteBatch(int $batchId, GenericUser $actor): array
    {
        return $this->deletionService->deleteBatch($batchId, $actor);
    }

    public function exportBatchPdf(int $batchId): array
    {
        return $this->archiveService->exportBatchPdf($batchId);
    }
}
