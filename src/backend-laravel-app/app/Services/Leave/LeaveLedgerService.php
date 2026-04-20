<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class LeaveLedgerService extends LeaveRequestSupport
{
    public function balance(int $employeeId): array
    {
        $this->assertEmployeeExists($employeeId);
        $this->recalculatePaidLeaveUsage($employeeId);
        $this->syncPaidLeaveLedger($employeeId);

        $grants = DB::table('paid_leave_grants')
            ->where('employee_id', $employeeId)
            ->orderBy('granted_on')
            ->get();

        $items = $grants->map(fn (object $row) => [
            'id' => (int) $row->id,
            'grantedOn' => $row->granted_on,
            'grantedDays' => (float) $row->granted_days,
            'usedDays' => (float) $row->used_days,
            'expiresOn' => $row->expires_on,
            'note' => $row->note,
        ])->all();

        $adjustments = DB::table('paid_leave_adjustments')
            ->where('employee_id', $employeeId)
            ->orderBy('effective_on')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'adjustmentType' => $row->adjustment_type,
                'days' => (float) $row->days,
                'effectiveOn' => $row->effective_on,
                'note' => $row->note,
            ])
            ->all();

        $currentBalance = array_reduce($items, function (float $carry, array $item): float {
            if ($item['expiresOn'] !== null && $item['expiresOn'] < now()->toDateString()) {
                return $carry;
            }

            return $carry + max(0, (float) $item['grantedDays'] - (float) $item['usedDays']);
        }, 0.0);

        $adjustmentBalance = array_reduce($adjustments, fn (float $carry, array $item): float => $carry + (float) $item['days'], 0.0);

        return [
            'employeeId' => $employeeId,
            'currentBalance' => round($currentBalance + $adjustmentBalance, 2),
            'grants' => $items,
            'adjustments' => $adjustments,
        ];
    }


    public function ledger(int $employeeId): array
    {
        $this->assertEmployeeExists($employeeId);
        $this->recalculatePaidLeaveUsage($employeeId);
        $this->syncPaidLeaveLedger($employeeId);

        $items = DB::table('paid_leave_ledger')
            ->where('employee_id', $employeeId)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'entryType' => $row->entry_type,
                'sourceType' => $row->source_type,
                'sourceId' => $row->source_id !== null ? (int) $row->source_id : null,
                'occurredOn' => $row->occurred_on,
                'daysDelta' => (float) $row->days_delta,
                'balanceAfter' => $row->balance_after !== null ? (float) $row->balance_after : null,
                'note' => $row->note,
                'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ])
            ->all();

        return [
            'employeeId' => $employeeId,
            'currentBalance' => $this->balance($employeeId)['currentBalance'],
            'items' => $items,
        ];
    }


    public function grantForAdmin(array $payload, GenericUser $actor): array
    {
        $employeeId = (int) $payload['employeeId'];
        $this->assertEmployeeExists($employeeId);

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
    }

    public function adjustForAdmin(array $payload, GenericUser $actor): array
    {
        $employeeId = (int) $payload['employeeId'];
        $this->assertEmployeeExists($employeeId);

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
    }


    public function calculateAvailablePaidLeave(int $employeeId): float
    {
        $currentBalance = $this->balance($employeeId)['currentBalance'];

        $pendingDays = (float) DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('leave_type_code', 'PAID')
            ->whereIn('status', ['PENDING', 'RETURNED'])
            ->sum('quantity_days');

        return round($currentBalance - $pendingDays, 2);
    }


    public function recalculatePaidLeaveUsage(int $employeeId): void
    {
        $usedDays = (float) DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('leave_type_code', 'PAID')
            ->where('status', 'APPROVED')
            ->sum('quantity_days');

        $remaining = $usedDays;
        $grants = DB::table('paid_leave_grants')
            ->where('employee_id', $employeeId)
            ->orderBy('granted_on')
            ->get();

        foreach ($grants as $grant) {
            $consumed = min($remaining, (float) $grant->granted_days);

            DB::table('paid_leave_grants')
                ->where('id', $grant->id)
                ->update([
                    'used_days' => $consumed,
                    'updated_at' => now(),
                ]);

            $remaining = max(0, $remaining - (float) $grant->granted_days);
        }
    }

    public function syncPaidLeaveLedger(int $employeeId): void
    {
        $entries = [];

        $grants = DB::table('paid_leave_grants')
            ->where('employee_id', $employeeId)
            ->orderBy('granted_on')
            ->orderBy('id')
            ->get();

        foreach ($grants as $grant) {
            $entries[] = [
                'entryType' => 'GRANT',
                'sourceType' => 'PAID_LEAVE_GRANT',
                'sourceId' => (int) $grant->id,
                'occurredOn' => $grant->granted_on,
                'daysDelta' => (float) $grant->granted_days,
                'note' => $grant->note ?: '有給付与',
                'createdBy' => null,
            ];
        }

        $adjustments = DB::table('paid_leave_adjustments')
            ->where('employee_id', $employeeId)
            ->orderBy('effective_on')
            ->orderBy('id')
            ->get();

        foreach ($adjustments as $adjustment) {
            $entries[] = [
                'entryType' => $adjustment->adjustment_type,
                'sourceType' => 'PAID_LEAVE_ADJUSTMENT',
                'sourceId' => (int) $adjustment->id,
                'occurredOn' => $adjustment->effective_on,
                'daysDelta' => (float) $adjustment->days,
                'note' => $adjustment->note ?: '有給調整',
                'createdBy' => $adjustment->created_by ? (int) $adjustment->created_by : null,
            ];
        }

        $approvedRequests = DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('leave_type_code', 'PAID')
            ->where('status', 'APPROVED')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        foreach ($approvedRequests as $request) {
            $entries[] = [
                'entryType' => 'USE',
                'sourceType' => 'LEAVE_REQUEST',
                'sourceId' => (int) $request->id,
                'occurredOn' => $request->start_date,
                'daysDelta' => -1 * (float) $request->quantity_days,
                'note' => '有給申請 ' . $request->start_date . ' - ' . $request->end_date,
                'createdBy' => $request->approved_by ? (int) $request->approved_by : null,
            ];
        }

        usort($entries, function (array $left, array $right): int {
            $dateOrder = strcmp($left['occurredOn'], $right['occurredOn']);
            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            $weight = [
                'GRANT' => 1,
                'ADJUST_PLUS' => 2,
                'ADJUST_MINUS' => 3,
                'USE' => 4,
            ];

            $leftWeight = $weight[$left['entryType']] ?? 99;
            $rightWeight = $weight[$right['entryType']] ?? 99;
            if ($leftWeight !== $rightWeight) {
                return $leftWeight <=> $rightWeight;
            }

            return ($left['sourceId'] ?? 0) <=> ($right['sourceId'] ?? 0);
        });

        $balance = 0.0;
        $rows = [];
        foreach ($entries as $entry) {
            $balance = round($balance + (float) $entry['daysDelta'], 2);
            $rows[] = [
                'employee_id' => $employeeId,
                'entry_type' => $entry['entryType'],
                'source_type' => $entry['sourceType'],
                'source_id' => $entry['sourceId'],
                'occurred_on' => $entry['occurredOn'],
                'days_delta' => $entry['daysDelta'],
                'balance_after' => $balance,
                'note' => $entry['note'],
                'created_by' => $entry['createdBy'],
                'created_at' => now(),
            ];
        }

        DB::table('paid_leave_ledger')->where('employee_id', $employeeId)->delete();
        if ($rows !== []) {
            DB::table('paid_leave_ledger')->insert($rows);
        }
    }

}
