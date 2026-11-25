<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class LeaveLedgerService extends LeaveRequestSupport
{
    public static function paidRequests(int $employeeId): \Illuminate\Database\Query\Builder
    {
        return DB::table('leave_requests')->where('employee_id', $employeeId)
            ->where(fn ($query) => $query->where('balance_source', 'PAID')
                ->orWhere(fn ($legacy) => $legacy->whereNull('balance_source')->where('leave_type_code', 'PAID')));
    }

    private function projection(int $employeeId, ?string $asOf = null, bool $currentRead = false): array
    {
        $asOf ??= now()->toDateString();
        return app(PaidLeaveProjection::class)->calculate(
            DB::table('paid_leave_grants')->where('employee_id', $employeeId)->whereNull('superseded_at')->orderBy('granted_on')->orderBy('id')
                ->when($currentRead, fn ($query) => $query->lockForUpdate())->get()->all(),
            DB::table('paid_leave_adjustments')->where('employee_id', $employeeId)
                ->when($currentRead, fn ($query) => $query->lockForUpdate())->get()->all(),
            self::paidRequests($employeeId)->where('status', 'APPROVED')
                ->when($currentRead, fn ($query) => $query->lockForUpdate())->get()->all(),
            $asOf,
        );
    }

    public function balance(int $employeeId, ?string $asOf = null): array
    {
        $this->assertEmployeeExists($employeeId);
        $state = $this->projection($employeeId, $asOf);
        $grants = DB::table('paid_leave_grants')->where('employee_id', $employeeId)->whereNull('superseded_at')->orderBy('granted_on')->orderBy('id')->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id, 'grantedOn' => $row->granted_on, 'grantedDays' => (float) $row->granted_days,
                'usedDays' => $state['usedByGrant'][$row->id], 'adjustedDays' => $state['adjustedByGrant'][$row->id], 'remainingDays' => $state['remainingByGrant'][$row->id],
                'expiresOn' => $row->expires_on, 'note' => $row->note,
                'sourceSystem' => $row->source_system, 'originalGrantedOn' => $row->original_granted_on,
                'evidenceReference' => $row->evidence_reference, 'expiryReviewedAt' => $row->expiry_reviewed_at,
                'expiryNeedsReview' => $row->expires_on === null && ($row->source_system ?? null) === 'HARMOS',
            ])->all();
        $adjustments = DB::table('paid_leave_adjustments')->where('employee_id', $employeeId)->orderBy('effective_on')->orderBy('id')->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id, 'adjustmentType' => $row->adjustment_type, 'days' => (float) $row->days,
                'effectiveOn' => $row->effective_on, 'note' => $row->note,
            ])->all();
        return [
            'employeeId' => $employeeId, 'asOf' => $state['asOf'], 'currentBalance' => $state['currentBalance'],
            'futureApprovedDays' => $state['futureApprovedDays'],
            'availableDays' => $this->availableFromState($employeeId, $state),
            'latestEntry' => $state['items'] === [] ? null : $state['items'][array_key_last($state['items'])],
            'hourlyBalance' => app(HourlyPaidLeaveService::class)->balance($employeeId, $state['asOf']),
            'hourlyManagementConfigured' => DB::table('paid_leave_hourly_balances')->where('employee_id', $employeeId)->exists(),
            'grants' => $grants, 'adjustments' => $adjustments,
        ];
    }

    public function ledger(int $employeeId): array
    {
        $this->assertEmployeeExists($employeeId);
        $state = $this->projection($employeeId);
        return ['employeeId' => $employeeId, 'asOf' => $state['asOf'], 'currentBalance' => $state['currentBalance'], 'items' => $state['items']];
    }

    public function grantForAdmin(array $payload, GenericUser $actor): array
    {
        return DB::transaction(function () use ($payload, $actor) {
            $employeeId = (int) $payload['employeeId'];
            if (!DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first()) {
                throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404);
            }

            $days = round((float) $payload['days'], 2);
            if ($days <= 0) {
                throw new ApiException('VALIDATION_ERROR', '付与日数は 0 より大きい値を指定してください。', 422, [
                    ['field' => 'days', 'message' => '0 より大きい値を指定してください。'],
                ]);
            }

            $id = (int) DB::table('paid_leave_grants')->insertGetId([
                'employee_id' => $employeeId,
                'granted_on' => $payload['grantedOn'],
                'granted_days' => $days,
                'used_days' => 0,
                'expires_on' => $payload['expiresOn'] ?? null,
                'note' => $payload['note'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncPaidLeaveLedger($employeeId);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAID_LEAVE_GRANTED',
                'PAID_LEAVE_GRANT',
                (string) $id,
                [
                    'employeeId' => $employeeId,
                    'days' => $days,
                    'grantedOn' => $payload['grantedOn'],
                ]
            );

            return [
                'id' => $id,
                'employeeId' => $employeeId,
                'days' => $days,
                'grantedOn' => $payload['grantedOn'],
                'expiresOn' => $payload['expiresOn'] ?? null,
                'note' => $payload['note'] ?? null,
            ];
        });
    }

    public function adjustForAdmin(array $payload, GenericUser $actor): array
    {
        return DB::transaction(function () use ($payload, $actor) {
            $employeeId = (int) $payload['employeeId'];
            if (!DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first()) {
                throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404);
            }

            $adjustmentType = strtoupper((string) $payload['adjustmentType']);
            if (!in_array($adjustmentType, ['ADJUST_PLUS', 'ADJUST_MINUS'], true)) {
                throw new ApiException('VALIDATION_ERROR', '調整種別が不正です。', 422, [
                    ['field' => 'adjustmentType', 'message' => 'ADJUST_PLUS または ADJUST_MINUS を指定してください。'],
                ]);
            }

            $rawDays = round((float) $payload['days'], 2);
            if ($rawDays <= 0) {
                throw new ApiException('VALIDATION_ERROR', '調整日数は 0 より大きい値を指定してください。', 422, [
                    ['field' => 'days', 'message' => '0 より大きい値を指定してください。'],
                ]);
            }

            $days = $adjustmentType === 'ADJUST_MINUS' ? -1 * abs($rawDays) : abs($rawDays);

            $id = (int) DB::table('paid_leave_adjustments')->insertGetId([
                'employee_id' => $employeeId,
                'adjustment_type' => $adjustmentType,
                'days' => $days,
                'effective_on' => $payload['effectiveOn'],
                'note' => $payload['note'] ?? null,
                'created_by' => $this->resolveOperatorEmployeeId($actor, $employeeId),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncPaidLeaveLedger($employeeId);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'PAID_LEAVE_ADJUSTED',
                'PAID_LEAVE_ADJUSTMENT',
                (string) $id,
                [
                    'employeeId' => $employeeId,
                    'adjustmentType' => $adjustmentType,
                    'days' => $days,
                    'effectiveOn' => $payload['effectiveOn'],
                ]
            );

            return [
                'id' => $id,
                'employeeId' => $employeeId,
                'adjustmentType' => $adjustmentType,
                'days' => $days,
                'effectiveOn' => $payload['effectiveOn'],
                'note' => $payload['note'] ?? null,
            ];
        });
    }

    public function calculateAvailablePaidLeave(int $employeeId): float
    {
        return $this->availableFromState($employeeId, $this->projection($employeeId));
    }

    private function availableFromState(int $employeeId, array $state): float
    {
        return app(PaidLeaveReservationProjection::class)->availableAtDate(...[
            ...$this->reservationInputs($employeeId), $state['asOf'],
        ]);
    }

    public function assertPaidLeaveAvailable(int $employeeId, string $start, string $end, float $days, ?int $excludeRequestId = null, ?array $dates = null, ?int $hourlyBasis = null, ?int $minutes = null): void
    {
        $insufficientOn = app(PaidLeaveReservationProjection::class)->insufficientOn(...[
            ...$this->reservationInputs($employeeId, $excludeRequestId), $start, $end, $days, $dates, $hourlyBasis, $minutes,
        ]);
        if ($insufficientOn !== null) {
            throw new ApiException('INSUFFICIENT_PAID_LEAVE', '有給残数が不足しています。申請日・失効日・承認待ちを含む予約分を確認してください。', 422, [
                ['field'=>'leaveTypeCode', 'message'=>$insufficientOn.' 時点で有給残数が不足します。'],
            ]);
        }
    }

    private function reservationInputs(int $employeeId, ?int $excludeRequestId = null): array
    {
        $locked = DB::transactionLevel() > 0;
        return [
            DB::table('paid_leave_grants')->where('employee_id', $employeeId)->whereNull('superseded_at')->orderBy('id')
                ->when($locked, fn ($q)=>$q->lockForUpdate())->get()->all(),
            DB::table('paid_leave_adjustments')->where('employee_id', $employeeId)->orderBy('id')
                ->when($locked, fn ($q)=>$q->lockForUpdate())->get()->all(),
            self::paidRequests($employeeId)
                ->whereIn('status', ['APPROVED', 'PENDING', 'RETURNED'])->orderBy('id')
                ->when($excludeRequestId !== null, fn ($q)=>$q->where('id', '<>', $excludeRequestId))
                ->when($locked, fn ($q)=>$q->lockForUpdate())->get()->all(),
        ];
    }


    public function recalculatePaidLeaveUsage(int $employeeId): void
    {
        $state = $this->projection($employeeId, null, DB::transactionLevel() > 0);
        foreach ($state['usedByGrant'] as $id => $used) {
            DB::table('paid_leave_grants')->where('id', $id)->where('employee_id', $employeeId)
                ->update(['used_days' => $used, 'updated_at' => now()]);
        }
    }

    public function syncPaidLeaveLedger(int $employeeId): void
    {
        $state = $this->projection($employeeId, null, DB::transactionLevel() > 0);
        $rows = [];
        $previousBalance = 0.0;
        foreach ($state['items'] as $entry) {
            // The persisted historical ledger uses hundredths; allocate the rounding
            // remainder across entries from the exact cumulative balance.
            $storedBalance = round($entry['balanceAfter'], 2);
            $rows[] = [
            'employee_id' => $employeeId, 'entry_type' => $entry['entryType'], 'source_type' => $entry['sourceType'],
            'source_id' => $entry['sourceId'], 'occurred_on' => $entry['occurredOn'], 'days_delta' => round($storedBalance - $previousBalance, 2),
            'balance_after' => $storedBalance, 'note' => $entry['note'], 'created_by' => $entry['createdBy'], 'created_at' => now(),
            ];
            $previousBalance = $storedBalance;
        }
        DB::transaction(function () use ($employeeId, $rows): void {
            DB::table('paid_leave_ledger')->where('employee_id', $employeeId)->delete();
            if ($rows !== []) { DB::table('paid_leave_ledger')->insert($rows); }
        });
    }
}
