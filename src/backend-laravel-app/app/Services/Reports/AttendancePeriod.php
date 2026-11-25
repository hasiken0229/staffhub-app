<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\EmploymentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AttendancePeriod
{
    public static function legacyPeriods(string $month): Builder
    {
        return DB::table('payroll_legacy_calendar_periods as lp')
            ->join('attendance_monthly_closes as mc', 'mc.target_year_month', '=', 'lp.target_month')
            ->where('mc.status', 'CLOSED')->where('lp.target_month', $month)->whereNull('lp.reopened_at');
    }

    public static function legacyMonthFor(string $month, string $employeeIdColumn): Builder
    {
        return self::legacyPeriods($month)->whereColumn('lp.employee_id', $employeeIdColumn)->select('lp.target_month');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public static function forEmployee(string $month, string $employmentType, bool $dependentWithin = false, ?string $legacyCalendarMonth = null): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new ApiException('VALIDATION_ERROR', '対象月は YYYY-MM 形式で指定してください。', 422);
        }
        $start = CarbonImmutable::parse($month.'-01')->startOfDay();
        if ((EmploymentType::canonical($employmentType) === 'PART_TIME' || $employmentType === '扶養内' || $dependentWithin)
            && $month !== $legacyCalendarMonth) {
            return [$start->subMonthNoOverflow()->day(21), $start->day(20)];
        }

        return [$start, $start->endOfMonth()->startOfDay()];
    }
}
