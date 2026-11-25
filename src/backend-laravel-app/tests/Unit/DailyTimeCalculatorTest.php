<?php

namespace Tests\Unit;

use App\Services\ApiException;
use App\Services\Attendance\DailyTimeCalculator;
use App\Services\Attendance\AttendanceSharedUtilities;
use Carbon\CarbonImmutable as Date;
use PHPUnit\Framework\TestCase;

final class DailyTimeCalculatorTest extends TestCase
{
    public function test_seconds_policy_is_explicit_and_missing_is_not_zero(): void
    {
        $this->assertSame(605, DailyTimeCalculator::actual(Date::parse('2026-09-04 07:40:55'), Date::parse('2026-09-04 18:46:23'), 60));
        $this->assertNull(DailyTimeCalculator::actual(Date::parse('2026-09-03 07:39:01'), null, 0));
        $this->assertSame(0, DailyTimeCalculator::actual(Date::parse('2026-09-03 09:00'), Date::parse('2026-09-03 09:00'), 0));
        $this->assertNull(DailyTimeCalculator::actual(Date::parse('2026-09-03 18:00'), Date::parse('2026-09-03 09:00'), 45));
    }

    public function test_explicit_next_day_is_valid(): void
    {
        $start = Date::parse('2026-12-31 22:00');
        $end = Date::parse('2027-01-01 06:00');
        DailyTimeCalculator::validateManualTimes($start, $end, []);
        $this->assertSame(435, DailyTimeCalculator::actual($start, $end, 45));
    }

    public function test_reverse_times_are_rejected_on_edit(): void
    {
        $this->expectException(ApiException::class);
        DailyTimeCalculator::validateManualTimes(Date::parse('2026-09-03 18:00'), Date::parse('2026-09-03 09:00'), []);
    }

    public function test_overlapping_breaks_are_rejected(): void
    {
        $this->expectException(ApiException::class);
        DailyTimeCalculator::validateManualTimes(Date::parse('2026-09-03 09:00'), Date::parse('2026-09-03 18:00'), [
            ['startAt' => Date::parse('2026-09-03 12:00'), 'endAt' => Date::parse('2026-09-03 13:00')],
            ['startAt' => Date::parse('2026-09-03 12:30'), 'endAt' => Date::parse('2026-09-03 13:30')],
        ]);
    }

    public function test_break_outside_shift_is_rejected(): void
    {
        $this->expectException(ApiException::class);
        DailyTimeCalculator::validateManualTimes(Date::parse('2026-09-03 09:00'), Date::parse('2026-09-03 18:00'), [
            ['startAt' => Date::parse('2026-09-03 22:00'), 'endAt' => Date::parse('2026-09-03 23:00')],
        ]);
    }

    public function test_invalid_clock_does_not_roll_into_next_day(): void
    {
        $helper = new class {
            use AttendanceSharedUtilities;
            public function parse(string $time): ?Date { return $this->combineTimeInput(Date::parse('2026-09-03'), $time, false); }
        };
        $this->expectException(ApiException::class);
        $helper->parse('24:70');
    }
}
