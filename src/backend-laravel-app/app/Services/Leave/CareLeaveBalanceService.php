<?php

namespace App\Services\Leave;

use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CareLeaveBalanceService
{
    public static function entitlementDays(int $count): int { return $count === 0 ? 0 : ($count === 1 ? 5 : 10); }

    public static function project(int $opening, int $used, int $future, int $dayMinutes): array
    {
        $remaining = $opening - $used;
        return ['remainingMinutes' => $remaining, 'remainingDays' => round($remaining / $dayMinutes, 4),
            'futureReservedMinutes' => $future, 'availableMinutes' => $remaining - $future];
    }

    public function list(int $year, string $asOf): array
    {
        $start = sprintf('%04d-04-01', $year); $end = sprintf('%04d-03-31', $year + 1);
        $settings = DB::table('care_leave_balances')->where('fiscal_year', $year)->get()->keyBy(fn ($s) => $s->employee_id.':'.$s->category);
        $daily = DB::table('attendance_daily')->whereBetween('target_date', [$start, $end])->get()->groupBy('employee_id');
        $result = [];
        foreach (DB::table('employees')->orderBy('employee_code')->get() as $employee) {
            foreach (['CHILD', 'NURSING'] as $category) {
                $s = $settings->get($employee->id.':'.$category);
                $used = 0; $future = 0; $recordedDays = 0; $recordedMinutes = 0;
                foreach ($daily->get($employee->id, collect()) as $row) {
                    // Approved leave requests already update attendance_daily: never add them a second time.
                    $minutes = (int) ($category === 'CHILD' ? $row->child_care_leave_minutes : $row->nursing_care_leave_minutes);
                    $name = $category === 'CHILD' ? '子の看護' : '介護休暇';
                    $days = !$row->clock_in_at && !$row->clock_out_at && !$minutes && str_contains((string) $row->schedule_name, $name) ? 1 : 0;
                    if ($row->target_date <= $asOf) { $recordedDays += $days; $recordedMinutes += $minutes; }
                    if (!$s || $row->target_date <= $s->effective_on) { continue; }
                    $quantity = $days * $s->day_minutes + $minutes;
                    if ($row->target_date <= $asOf) { $used += $quantity; } else { $future += $quantity; }
                }
                $configured = $s && $s->effective_on <= $asOf;
                $result[] = ['employeeId' => $employee->id, 'employeeCode' => $employee->employee_code,
                    'employeeName' => $employee->name, 'category' => $category, 'fiscalYear' => $year, 'expiresOn' => $end,
                    'asOf' => $asOf, 'configured' => (bool) $configured, 'eligibleCount' => $s?->eligible_count,
                    'entitlementDays' => $s ? self::entitlementDays($s->eligible_count) : null,
                    'dayMinutes' => $s?->day_minutes, 'effectiveOn' => $s?->effective_on, 'openingMinutes' => $s?->opening_minutes,
                    'note' => $s?->note, 'recordedDays' => $recordedDays, 'recordedMinutes' => $recordedMinutes, 'usedSinceBaselineMinutes' => $used,
                ] + ($configured ? self::project($s->opening_minutes, $used, $future, $s->day_minutes) :
                    ['remainingMinutes' => null, 'remainingDays' => null, 'futureReservedMinutes' => null, 'availableMinutes' => null]);
            }
        }
        return $result;
    }

    public function save(array $p, $actor): void
    {
        $year = $p['fiscalYear'];
        $start = CarbonImmutable::create($year, 4, 1)->subDay()->toDateString();
        $end = sprintf('%04d-03-31', $year + 1);
        if ($p['effectiveOn'] < $start || $p['effectiveOn'] > $end || $p['openingMinutes'] > self::entitlementDays($p['eligibleCount']) * $p['dayMinutes']) {
            throw ValidationException::withMessages(['openingMinutes' => '基準日は年度開始前日～年度末、残数は年間上限以内で設定してください。']);
        }
        DB::transaction(function () use ($p, $actor) {
            DB::table('employees')->where('id', $p['employeeId'])->lockForUpdate()->first();
            $key = ['employee_id' => $p['employeeId'], 'category' => $p['category'], 'fiscal_year' => $p['fiscalYear']];
            $old = DB::table('care_leave_balances')->where($key)->first();
            DB::table('care_leave_balances')->updateOrInsert($key, ['eligible_count' => $p['eligibleCount'], 'day_minutes' => $p['dayMinutes'],
                'effective_on' => $p['effectiveOn'], 'opening_minutes' => $p['openingMinutes'], 'note' => $p['note'],
                'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditLogService::class)->record('ADMIN', $actor?->id, 'SET_CARE_LEAVE_BALANCE', 'EMPLOYEE', (string) $p['employeeId'], ['before' => $old, 'after' => $p]);
        });
    }
}
