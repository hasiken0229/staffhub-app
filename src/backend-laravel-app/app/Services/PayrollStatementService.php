<?php

namespace App\Services;

use App\Services\Payroll\PayrollStatementAdminQueryService;
use App\Services\Payroll\PayrollStatementEmployeeService;
use App\Services\Payroll\PayrollStatementStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;

final class PayrollStatementService
{
    public function __construct(
        private readonly PayrollStatementEmployeeService $employeeService,
        private readonly PayrollStatementAdminQueryService $adminQueryService,
        private readonly PayrollStatementStorageService $storageService,
    ) {
    }

    public function listForEmployee(int $employeeId, array $filters): array
    {
        return $this->employeeService->listForEmployee($employeeId, $filters);
    }

    public function detailForEmployee(int $employeeId, int $statementId): array
    {
        return $this->employeeService->detailForEmployee($employeeId, $statementId);
    }

    public function downloadForEmployee(int $employeeId, int $statementId)
    {
        return $this->employeeService->downloadForEmployee($employeeId, $statementId);
    }

    public function markViewed(int $employeeId, int $statementId, string $viewedAt): array
    {
        return $this->employeeService->markViewed($employeeId, $statementId, $viewedAt);
    }

    public function downloadForAdmin(int $statementId, bool $inline = false)
    {
        return $this->adminQueryService->downloadForAdmin($statementId, $inline);
    }

    public function detailForAdmin(int $statementId): array
    {
        return $this->adminQueryService->detailForAdmin($statementId);
    }

    public function listForAdmin(array $filters): array
    {
        return $this->adminQueryService->listForAdmin($filters);
    }

    public function storeForAdmin(array $payload, UploadedFile $file, GenericUser $actor): array
    {
        return $this->storageService->storeForAdmin($payload, $file, $actor);
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
        return $this->storageService->upsertBinaryForAdmin(
            $employeeId,
            $statementType,
            $targetYearMonth,
            $publishedAt,
            $originalFileName,
            $contentType,
            $binaryContent,
            $actor,
            $options,
        );
    }

    public function replaceStatementLines(int $statementId, array $lines): void
    {
        $this->storageService->replaceStatementLines($statementId, $lines);
    }

    public function deleteForAdmin(int $statementId, GenericUser $actor): array
    {
        return $this->storageService->deleteForAdmin($statementId, $actor);
    }

    public function statementTypeLabel(string $statementType): string
    {
        return strtoupper($statementType) === 'BONUS' ? '賞与明細' : '給与明細';
    }
}
