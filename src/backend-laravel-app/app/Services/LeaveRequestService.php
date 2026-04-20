<?php

namespace App\Services;

use App\Services\Leave\LeaveApplyService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Auth\GenericUser;

final class LeaveRequestService
{
    public function __construct(
        private readonly LeaveApplyService $applyService,
        private readonly LeaveApprovalService $approvalService,
        private readonly LeaveLedgerService $ledgerService,
    ) {
    }

    public function balance(int $employeeId): array
    {
        return $this->ledgerService->balance($employeeId);
    }

    public function listForEmployee(int $employeeId, array $filters): array
    {
        return $this->applyService->listForEmployee($employeeId, $filters);
    }

    public function createForEmployee(int $employeeId, array $payload): array
    {
        return $this->applyService->createForEmployee($employeeId, $payload);
    }

    public function detailForEmployee(int $employeeId, int $requestId): array
    {
        return $this->applyService->detailForEmployee($employeeId, $requestId);
    }

    public function detailForAdmin(int $requestId): array
    {
        return $this->approvalService->detailForAdmin($requestId);
    }

    public function ledger(int $employeeId): array
    {
        return $this->ledgerService->ledger($employeeId);
    }

    public function cancelForEmployee(int $employeeId, int $requestId, ?string $comment = null): array
    {
        return $this->applyService->cancelForEmployee($employeeId, $requestId, $comment);
    }

    public function listForAdmin(array $filters): array
    {
        return $this->approvalService->listForAdmin($filters);
    }

    public function decide(int $requestId, string $decision, ?string $comment, GenericUser $actor): array
    {
        return $this->approvalService->decide($requestId, $decision, $comment, $actor);
    }

    public function bulkDecide(array $requestIds, string $decision, ?string $comment, GenericUser $actor): array
    {
        return $this->approvalService->bulkDecide($requestIds, $decision, $comment, $actor);
    }

    public function listWorkProcedures(array $filters): array
    {
        return $this->approvalService->listWorkProcedures($filters);
    }

    public function grantForAdmin(array $payload, GenericUser $actor): array
    {
        return $this->ledgerService->grantForAdmin($payload, $actor);
    }

    public function adjustForAdmin(array $payload, GenericUser $actor): array
    {
        return $this->ledgerService->adjustForAdmin($payload, $actor);
    }
}
