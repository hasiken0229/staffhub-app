<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use App\Services\AuditLogService;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

trait PayrollImportBatchSupport
{
    protected function validateYearMonth(string $targetYearMonth): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $targetYearMonth)) {
            throw new ApiException('VALIDATION_ERROR', '対象月は YYYY-MM 形式で指定してください。', 422, [
                ['field' => 'targetYearMonth', 'message' => '対象月は YYYY-MM 形式で指定してください。'],
            ]);
        }

        return $targetYearMonth;
    }

    protected function resolveActorEmployeeId(GenericUser $actor): ?int
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

    protected function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }
}
