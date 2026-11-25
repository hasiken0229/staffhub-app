<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/** Presentation/error state only. This never registers a day or relaxes month-close completeness. */
final class AttendanceDayState
{
    public static function isDayOff(object $row): bool
    {
        if (!empty($row->request_leave_days) || self::isPartialLeave($row)) { return false; }
        if (preg_match('/(?:公休|休日|日曜)(?:出勤|勤務)/u', (string) ($row->schedule_name ?? ''))) { return false; }
        return !empty($row->saturday_holiday)
            || (empty($row->clock_in_at) && empty($row->clock_out_at)
                && preg_match('/公休|休日|所定休|法定休|日曜/', (string) ($row->schedule_name ?? '')) === 1);
    }

    public static function hasLeave(object $row): bool
    {
        if (isset($row->request_leave_days) && (float) $row->request_leave_days === 0.5) { return false; }
        if (!empty($row->absence_flag) || (float) ($row->paid_leave_unit ?? 0) >= 1) { return true; }
        $name = (string) ($row->schedule_name ?? '');
        // A partial leave does not excuse the remaining workday's punches.
        if (self::isPartialLeave($row)) { return false; }
        return !empty($row->special_leave_flag) || preg_match('/有休|有給|看護|介護|特休|特別休|欠勤|休業|休職|産休|育休|振休|代休/', $name) === 1;
    }

    private static function isPartialLeave(object $row): bool
    {
        if (isset($row->request_leave_days) && (float) $row->request_leave_days === 0.5) { return true; }
        if ((float) ($row->paid_leave_unit ?? 0) >= 1 || !empty($row->absence_flag)) { return false; }
        return (float) ($row->paid_leave_unit ?? 0) > 0
            || (int) ($row->hour_paid_leave_minutes ?? 0) > 0
            || (int) ($row->child_care_leave_minutes ?? 0) > 0
            || (int) ($row->nursing_care_leave_minutes ?? 0) > 0
            || preg_match('/半日|午前|午後|時間|\bAM\b|\bPM\b/i', (string) ($row->schedule_name ?? '')) === 1;
    }

    public static function notDue(object $row): bool
    {
        $date = (string) ($row->target_date ?? '');
        if ($date === '') { return false; }
        $now = CarbonImmutable::now('Asia/Tokyo');
        if ($date > $now->toDateString()) { return true; }
        if (!empty($row->expected_end_at)) { return $now->lessThanOrEqualTo(CarbonImmutable::parse($row->expected_end_at, 'Asia/Tokyo')->addMinutes(30)); }
        if ($date < $now->toDateString()) { return false; }
        return $now->lessThanOrEqualTo($now->endOfDay());
    }

    public static function classify(object $row): string
    {
        if ((string) ($row->target_date ?? '') > CarbonImmutable::today('Asia/Tokyo')->toDateString()) { return 'FUTURE'; }
        if (self::isDayOff($row)) { return 'HOLIDAY'; }
        if (empty($row->clock_in_at) && empty($row->clock_out_at) && self::hasLeave($row)) { return 'LEAVE'; }
        if (!empty($row->clock_in_at) && empty($row->clock_out_at) && self::notDue($row)) { return 'WORKING'; }
        if (empty($row->clock_in_at) && empty($row->clock_out_at) && self::notDue($row)) { return 'NOT_DUE'; }
        if (!empty($row->clock_in_at) && !empty($row->clock_out_at)) {
            return $row->clock_out_at < $row->clock_in_at ? 'MISSING' : 'COMPLETE';
        }
        return 'MISSING';
    }
}
