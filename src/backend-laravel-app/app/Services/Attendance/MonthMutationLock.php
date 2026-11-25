<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** All attendance writers acquire month rows before employee/request/daily rows. */
final class MonthMutationLock
{
    public static function months(string $start, ?string $end = null): array
    {
        $date = CarbonImmutable::parse($start)->startOfMonth();
        $last = CarbonImmutable::parse($end ?? $start)->startOfMonth();
        $months = [];
        while ($date->lte($last)) {
            $months[] = $date->format('Y-m');
            $date = $date->addMonth();
        }
        return $months;
    }

    public static function acquire(array $months, bool $requireOpen = true): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MonthMutationLock requires a database transaction.');
        }
        $months = array_values(array_unique($months));
        sort($months, SORT_STRING);
        foreach ($months as $month) {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new ApiException('VALIDATION_ERROR', '対象年月が不正です。', 422);
            }
            // A duplicate INSERT IGNORE can retain a shared lock before the
            // exclusive read below. Concurrent lock upgrades can then deadlock.
            // A no-op upsert acquires the exclusive duplicate-row lock first;
            // never update an existing month's status, metadata or timestamps.
            DB::table('attendance_monthly_closes')->upsert([[
                'target_year_month' => $month, 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]], ['target_year_month'], ['target_year_month']);
            $row = DB::table('attendance_monthly_closes')
                ->where('target_year_month', $month)->lockForUpdate()->first();
            if ($requireOpen && $row->status === 'CLOSED') {
                throw new ApiException('CLOSED_PERIOD', '締め済み月は更新できません。締め解除後に再度実行してください。', 422);
            }
        }
    }

    /** Resolve immutable dates before the write transaction to avoid stale MVCC snapshots. */
    public static function forRecord(string $table, int $id, string $start = 'target_date', ?string $end = null): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        return $row === null ? [] : self::months((string) $row->{$start}, $end ? (string) $row->{$end} : null);
    }
}
