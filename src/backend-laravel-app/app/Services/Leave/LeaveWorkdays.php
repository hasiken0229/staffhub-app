<?php

namespace App\Services\Leave;

use App\Services\Attendance\AttendanceDayState;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

final class LeaveWorkdays
{
    public static function dates(object $request): array
    {
        if (($request->effective_dates ?? null) !== null) {
            return json_decode($request->effective_dates, true, flags: JSON_THROW_ON_ERROR);
        }
        return self::calendar($request->start_date, $request->end_date);
    }

    private static function calendar(string $start, string $end): array
    {
        if ($end < $start) { throw new \InvalidArgumentException('終了日は開始日以降を指定してください。'); }
        $dates = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $dates[] = $day->toDateString();
        }
        return $dates;
    }

    public static function preview(int $employeeId, string $start, string $end): array
    {
        $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)
            ->whereBetween('target_date', [$start, $end])->get()->keyBy('target_date');
        $shifts = DB::table('attendance_shift_schedules as s')
            ->leftJoin('work_type_settings as w', 'w.id', '=', 's.work_type_id')
            ->where('s.employee_id', $employeeId)->whereBetween('s.target_date', [$start, $end])
            ->get(['s.target_date', 'w.name as schedule_name', 's.scheduled_clock_in_time', 's.scheduled_clock_out_time'])->keyBy('target_date');
        $dates = $excluded = $unconfirmed = [];
        foreach (self::calendar($start, $end) as $date) {
            $row = $daily->get($date);
            $shift = $shifts->get($date);
            // A personal shift takes precedence over an older daily label. A
            // date with actual punches is never silently inferred to be off.
            $scheduled = $shift ?? $row;
            $hasWorkSchedule = $scheduled && ((!empty($shift->scheduled_clock_in_time) && !empty($shift->scheduled_clock_out_time))
                || preg_match('/(?:公休|休日|日曜)(?:出勤|勤務)/u', (string) ($scheduled->schedule_name ?? '')));
            if (!$hasWorkSchedule && empty($row->clock_in_at) && empty($row->clock_out_at)
                && ($shift ? AttendanceDayState::isDayOff($shift) : ($row && AttendanceDayState::isDayOff($row)))) {
                $excluded[] = $date;
                continue;
            }
            $dates[] = $date;
            if (!$shift && empty($row->schedule_name) && empty($row->clock_in_at) && empty($row->clock_out_at)) {
                $unconfirmed[] = $date;
            }
        }
        return ['dates' => $dates, 'excludedDates' => $excluded, 'unconfirmedDates' => $unconfirmed];
    }
}
