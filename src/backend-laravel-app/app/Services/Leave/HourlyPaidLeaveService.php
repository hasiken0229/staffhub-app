<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\AuditLogService;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HourlyPaidLeaveService
{
    /** A snapshot is the balance at the end of effective_on. It never adds days. */
    public function balance(int $employeeId, ?string $targetDate = null, ?int $excludeRequestId = null): ?array
    {
        if (!Schema::hasTable('paid_leave_hourly_balances')) { return null; }
        $targetDate ??= now()->toDateString();
        $snapshot = DB::table('paid_leave_hourly_balances')->where('employee_id', $employeeId)
            ->where('effective_on', '<=', $targetDate)->where('expires_on', '>=', $targetDate)
            ->orderByDesc('effective_on')->first();
        if ($snapshot === null) { return null; }
        $query = DB::table('leave_requests')->where('employee_id', $employeeId)
            ->where('time_leave_type', 'PAID_HOURLY')
            ->where('start_date', '>', $snapshot->effective_on)->where('start_date', '<=', $snapshot->expires_on);
        if ($excludeRequestId !== null) { $query->where('id', '<>', $excludeRequestId); }
        $nextBaseline = DB::table('paid_leave_hourly_balances')->where('employee_id', $employeeId)
            ->where('effective_on', '>', $snapshot->effective_on)->min('effective_on');
        if ($nextBaseline !== null) { $query->where('start_date', '<=', $nextBaseline); }
        $approved = (int) (clone $query)->where('status', 'APPROVED')->sum('quantity_minutes');
        $usedAsOf = (int) (clone $query)->where('status', 'APPROVED')->where('start_date', '<=', $targetDate)->sum('quantity_minutes');
        $pending = (int) (clone $query)->whereIn('status', ['PENDING', 'RETURNED'])->sum('quantity_minutes');
        return [
            'asOf' => $targetDate,
            'usedAsOfMinutes' => $usedAsOf,
            'remainingAsOfMinutes' => (int) $snapshot->remaining_minutes - $usedAsOf,
            'futureApprovedMinutes' => $approved - $usedAsOf,
            'effectiveOn' => $snapshot->effective_on, 'expiresOn' => $snapshot->expires_on,
            'openingMinutes' => (int) $snapshot->remaining_minutes,
            'usedMinutes' => $approved, 'pendingMinutes' => $pending,
            'remainingMinutes' => (int) $snapshot->remaining_minutes - $approved,
            'totalRemainingMinutes' => $snapshot->source_total_minutes !== null ? (int) $snapshot->source_total_minutes - $approved : null,
            'availableMinutes' => min((int) $snapshot->remaining_minutes, $snapshot->source_total_minutes ?? PHP_INT_MAX) - $approved - $pending,
        ];
    }

    public function assertAvailable(int $employeeId, string $targetDate, int $minutes, ?int $excludeRequestId = null): void
    {
        $balance = $this->balance($employeeId, $targetDate, $excludeRequestId);
        if ($balance === null) {
            if (Schema::hasTable('paid_leave_hourly_balances') && DB::table('paid_leave_hourly_balances')->where('employee_id', $employeeId)->exists()) {
                throw new ApiException('HOURLY_PAID_LEAVE_NOT_CONFIGURED', '対象日の時間有給残数が未設定です。管理者に確認してください。', 422);
            }
            return; // Preserve the existing contract for staff not migrated to hourly management.
        }
        if ($targetDate <= $balance['effectiveOn']) {
            throw new ApiException('HOURLY_PAID_LEAVE_BEFORE_BASELINE', '残数基準日以前の時間有給は管理者に確認してください。', 422);
        }
        if ($minutes > $balance['availableMinutes']) {
            throw new ApiException('INSUFFICIENT_HOURLY_PAID_LEAVE', '時間有給の残数が不足しています（承認待ちを含む）。', 422);
        }
    }

    public function setBalance(array $payload, ?GenericUser $actor = null): array
    {
        return DB::transaction(function () use ($payload, $actor) {
            $employee = DB::table('employees')->where('id', $payload['employeeId'])->lockForUpdate()->first();
            if ($employee === null) { throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404); }
            if (DB::table('leave_requests')->where('employee_id', $employee->id)->where('status', 'APPROVED')
                ->where('time_leave_type', 'PAID_HOURLY')->where(fn ($q) => $q->where('quantity_days', '>', 0)->orWhereNotNull('hourly_day_minutes'))
                ->where('start_date', '>', $payload['effectiveOn'])->where('start_date', '<=', $payload['expiresOn'])->exists()) {
                throw new ApiException('HOURLY_BALANCE_CHANGED', '日数換算で承認済みの時間有給が残数基準日後にあります。二重消化を防ぐため、対象申請の取消・再申請について原本を確認してください。', 422);
            }
            $old = DB::table('paid_leave_hourly_balances')->where('employee_id', $employee->id)->where('effective_on', $payload['effectiveOn'])->first();
            DB::table('paid_leave_hourly_balances')->updateOrInsert(
                ['employee_id' => $employee->id, 'effective_on' => $payload['effectiveOn']],
                ['expires_on' => $payload['expiresOn'], 'remaining_minutes' => $payload['remainingMinutes'],
                    'source_total_minutes' => $payload['totalMinutes'],
                    'source_system' => 'ADMIN', 'note' => $payload['note'] ?? null, 'updated_at' => now(), 'created_at' => $old->created_at ?? now()]
            );
            app(AuditLogService::class)->record('ADMIN', $actor?->id, 'SET_HOURLY_PAID_LEAVE_BALANCE', 'EMPLOYEE', (string) $employee->id, ['before' => $old, 'after' => $payload]);
            return $this->balance((int) $employee->id, $payload['effectiveOn']);
        });
    }
}
