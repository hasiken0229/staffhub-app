<?php

namespace Tests\Unit;

use App\Services\Reports\AttendancePeriod;
use PHPUnit\Framework\TestCase;

final class AttendancePeriodTest extends TestCase
{
    public function test_part_time_and_dependents_cross_year_on_the_twenty_first(): void
    {
        foreach ([['PART_TIME', false], ['扶養内', false], ['FULL_TIME', true]] as [$type, $dependent]) {
            $period = AttendancePeriod::forEmployee('2027-01', $type, $dependent);
            $this->assertSame(['2026-12-21', '2027-01-20'], array_map(fn ($d) => $d->toDateString(), $period));
        }
    }

    public function test_other_employees_use_calendar_month_including_leap_year(): void
    {
        $period = AttendancePeriod::forEmployee('2028-02', 'FULL_TIME');
        $this->assertSame(['2028-02-01', '2028-02-29'], array_map(fn ($d) => $d->toDateString(), $period));
    }
}
