<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/** Confirmed 2026-10-09: an elapsed Saturday without either punch is a day off. */
final class SaturdayHolidayPolicy
{
    public const NOTE = '土曜公休：出退勤の原打刻なし（2026-10-09確認ルール）';

    public static function applies(object $row): bool
    {
        $date = (string) ($row->target_date ?? '');
        if ($date < '2026-09-01' || $date >= CarbonImmutable::today('Asia/Tokyo')->toDateString()
            || !CarbonImmutable::parse($date)->isSaturday()
            || (string) ($row->employee_code ?? '') === '970005'
            || !empty($row->raw_clock_in_at) || !empty($row->raw_clock_out_at)
            || !empty($row->is_manually_edited) || ($row->close_status ?? 'OPEN') === 'CLOSED') {
            return false;
        }
        foreach (['absence_flag', 'special_leave_flag', 'paid_leave_unit', 'hour_paid_leave_minutes', 'child_care_leave_minutes', 'nursing_care_leave_minutes'] as $field) {
            // MySQL returns DECIMAL zero as the nonempty string '0.00'.
            if ((float) ($row->$field ?? 0) !== 0.0) { return false; }
        }
        // Full-day imported leave is sometimes represented only by its work-type name.
        return !preg_match('/有休|有給|看護|介護|特休|特別休|欠勤|休業|休職|産休|育休|振休|代休/', (string) ($row->schedule_name ?? ''));
    }

    public static function project(object $row): object
    {
        if (!self::applies($row)) { return $row; }
        $row = clone $row;
        $row->clock_in_at = null;
        $row->clock_out_at = null;
        $row->break_minutes = 0;
        $row->work_minutes = 0;
        $row->schedule_name = '公休';
        $row->work_type_id = null;
        $row->schedule_projected_breaks = [];
        $row->saturday_holiday = true;
        if (!str_contains((string) ($row->remark ?? ''), self::NOTE)) {
            $annotated = trim(($row->remark ?? '').' / '.self::NOTE, ' /');
            // The day-off decision is audited separately. Preserve a full-length original remark.
            if (mb_strlen($annotated) <= 255) { $row->remark = $annotated; }
        }
        return $row;
    }
}
