<?php

namespace App\Services\Payroll;

use App\Services\ApiException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class PayrollImportBatchDeletionService
{
    use PayrollImportBatchSupport;

    public function deleteBatch(int $batchId, GenericUser $actor): array
    {
        $batch = DB::table('payroll_import_batches')
            ->where('id', $batchId)
            ->whereNull('deleted_at')
            ->first();

        if ($batch === null) {
            throw new ApiException('NOT_FOUND', '取込バッチが見つかりません。', 404);
        }

        $actorEmployeeId = $this->resolveActorEmployeeId($actor);

        DB::transaction(function () use ($batchId, $actorEmployeeId, $actor) {
            DB::table('payroll_import_batch_items')
                ->where('payroll_import_batch_id', $batchId)
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('payroll_statements')
                ->where('payroll_import_batch_id', $batchId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => $actorEmployeeId,
                    'updated_at' => now(),
                ]);

            DB::table('payroll_import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'DELETED',
                    'deleted_at' => now(),
                    'deleted_by' => $actorEmployeeId,
                    'updated_at' => now(),
                ]);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAYROLL_BATCH_DELETE',
                'PAYROLL_IMPORT_BATCH',
                (string) $batchId,
                [
                    'batchId' => $batchId,
                ]
            );
        });

        return [
            'id' => $batchId,
            'deleted' => true,
        ];
    }
}
