<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class PaidLeaveGrantReviewService
{
    public function preview(int $grantId, array $payload): array
    {
        $grant = DB::table('paid_leave_grants')->where('id', $grantId)->whereNull('superseded_at')->first();
        if (!$grant) { throw new ApiException('NOT_FOUND', '有効な付与が見つかりません。再読込してください。', 404); }
        $lots = $payload['lots'];
        $total = 0;
        foreach ($lots as $lot) {
            $total += (int) round($lot['days'] * 100);
            if ($lot['expiresOn'] < $grant->granted_on || (!empty($lot['originalGrantedOn']) && $lot['originalGrantedOn'] > $grant->granted_on)) {
                throw new ApiException('VALIDATION_ERROR', '失効日は移行基準日以降、原本の付与日は移行基準日以前を指定してください。', 422);
            }
        }
        if ($total !== (int) round((float) $grant->granted_days * 100)) {
            throw new ApiException('GRANT_TOTAL_MISMATCH', '内訳の合計は元の付与日数と一致させてください。残数の増減には調整を使用してください。', 422);
        }
        $grants = DB::table('paid_leave_grants')->where('employee_id', $grant->employee_id)->whereNull('superseded_at')->orderBy('granted_on')->orderBy('id')->get()->all();
        $adjustments = DB::table('paid_leave_adjustments')->where('employee_id', $grant->employee_id)->get()->all();
        $requests = LeaveLedgerService::paidRequests((int) $grant->employee_id)->where('status', 'APPROVED')->get()->all();
        $afterGrants = array_values(array_filter($grants, fn ($g) => $g->id !== $grant->id));
        $nextId = max(array_column($grants, 'id')) + 1;
        foreach ($lots as $i => $lot) {
            // The migrated residual remains effective at its original snapshot date.
            $afterGrants[] = (object) ['id' => $nextId + $i, 'granted_on' => $grant->granted_on,
                'granted_days' => $lot['days'], 'expires_on' => $lot['expiresOn'], 'note' => $grant->note];
        }
        $date = now()->toDateString();
        $calculator = app(PaidLeaveProjection::class);
        $before = $calculator->calculate($grants, $adjustments, $requests, $date);
        $after = $calculator->calculate($afterGrants, $adjustments, $requests, $date);
        return ['grantId' => $grantId, 'employeeId' => (int) $grant->employee_id, 'effectiveOn' => $grant->granted_on,
            'totalDays' => (float) $grant->granted_days, 'beforeBalance' => $before['currentBalance'], 'afterBalance' => $after['currentBalance'],
            'lots' => $lots, 'evidenceReference' => $payload['evidenceReference'],
            'fingerprint' => hash('sha256', json_encode([$grants, $adjustments, $requests, $payload['lots'], $payload['evidenceReference'], $date]))];
    }

    public function confirm(int $grantId, array $payload, GenericUser $actor): array
    {
        // Resolve immutable ownership outside the write transaction. A nonlocking read
        // before the employee lock would establish a stale MySQL REPEATABLE READ snapshot.
        $employeeId = DB::table('paid_leave_grants')->where('id', $grantId)->value('employee_id');
        if (!$employeeId) { throw new ApiException('NOT_FOUND', '付与が見つかりません。', 404); }
        return DB::transaction(function () use ($grantId, $payload, $actor, $employeeId) {
            DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            $preview = $this->preview($grantId, $payload);
            if (!hash_equals($preview['fingerprint'], $payload['fingerprint'])) {
                throw new ApiException('STALE_LEAVE_PREVIEW', '残数または付与情報が変わりました。もう一度確認してください。', 409);
            }
            $grant = DB::table('paid_leave_grants')->where('id', $grantId)->lockForUpdate()->first();
            if (!$grant || $grant->superseded_at !== null) {
                throw new ApiException('STALE_LEAVE_PREVIEW', '付与内訳はすでに更新されています。再読込してください。', 409);
            }
            DB::table('paid_leave_grants')->where('id', $grantId)->update(['superseded_at' => now(), 'updated_at' => now()]);
            $ids = [];
            foreach ($payload['lots'] as $lot) {
                $ids[] = DB::table('paid_leave_grants')->insertGetId([
                    'employee_id' => $grant->employee_id, 'granted_on' => $grant->granted_on, 'granted_days' => $lot['days'], 'used_days' => 0,
                    'expires_on' => $lot['expiresOn'], 'original_granted_on' => $lot['originalGrantedOn'] ?? null,
                    'parent_grant_id' => $grant->id, 'note' => $grant->note, 'source_system' => $grant->source_system,
                    'source_import_type' => $grant->source_import_type, 'source_file_name' => $grant->source_file_name,
                    'source_imported_at' => $grant->source_imported_at, 'expiry_reviewed_at' => now(), 'expiry_reviewed_by' => $actor->id,
                    'evidence_reference' => $payload['evidenceReference'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ledger = app(LeaveLedgerService::class);
            $ledger->recalculatePaidLeaveUsage((int) $employeeId);
            $ledger->syncPaidLeaveLedger((int) $employeeId);
            app(AuditLogService::class)->record('ADMIN', $actor->id, 'PAID_LEAVE_GRANT_REVIEWED', 'PAID_LEAVE_GRANT', (string) $grantId,
                ['before' => $grant, 'afterGrantIds' => $ids, 'preview' => $preview]);
            return $ledger->balance((int) $employeeId);
        });
    }
}
