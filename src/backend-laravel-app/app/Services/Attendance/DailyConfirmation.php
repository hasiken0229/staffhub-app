<?php

namespace App\Services\Attendance;

final class DailyConfirmation
{
    public static function issue(object $day): ?string
    {
        if ($day->work_minutes === null || (int) $day->work_minutes < 0) {
            return 'MISSING_WORK_MINUTES';
        }
        $hasIn = ! empty($day->clock_in_at);
        $hasOut = ! empty($day->clock_out_at);
        if ($hasIn && $hasOut) {
            return $day->clock_out_at < $day->clock_in_at ? 'INCOMPLETE_PUNCHES' : null;
        }
        if (! $hasIn && ! $hasOut && (int) $day->work_minutes === 0 && (AttendanceDayState::isDayOff($day) || AttendanceDayState::hasLeave($day))) {
            return null;
        }

        return 'INCOMPLETE_PUNCHES';
    }
}
