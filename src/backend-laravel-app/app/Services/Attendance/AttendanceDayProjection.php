<?php

namespace App\Services\Attendance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceDayProjection
{
    /** Load immutable display inputs for the selected page only. */
    protected function preloadProjection(Collection $rows): void
    {
        $incomplete = $rows->filter(fn ($r) => empty($r->raw_clock_in_at) || empty($r->raw_clock_out_at) || empty($r->clock_in_at) || empty($r->clock_out_at));
        if ($incomplete->isEmpty()) { return; }
        $ids = $incomplete->pluck('employee_id')->unique()->all();
        $settings = DB::table('employee_attendance_settings')->whereIntegerInRaw('employee_id',$ids)->get()->keyBy('employee_id');
        $people = DB::table('employees')->whereIntegerInRaw('id',$ids)->get()->keyBy('id');
        $shifts = DB::table('attendance_shift_schedules as s')->leftJoin('work_type_settings as wt','wt.id','=','s.work_type_id')
            ->whereIntegerInRaw('s.employee_id',$ids)->whereBetween('s.target_date', [$incomplete->min('target_date'),$incomplete->max('target_date')])
            ->get(['s.*','wt.name as work_type_name','wt.start_time as work_type_start_time','wt.end_time as work_type_end_time','wt.standard_day_minutes'])
            ->keyBy(fn ($r) => $r->employee_id.':'.$r->target_date);
        $closed = DB::table('attendance_monthly_closes')->where('status','CLOSED')->pluck('target_year_month')->flip();
        $breakRule = DB::table('attendance_break_rules')->where('is_active',true)->orderBy('id')->first();
        $days = $incomplete->filter(fn ($r) => !empty($r->work_type_id) || !empty($r->schedule_name));
        $types = $days->isEmpty() ? collect() : DB::table('work_type_settings')
            ->whereIntegerInRaw('id', $days->pluck('work_type_id')->filter()->unique()->all())
            ->orWhereIn('name', $days->pluck('schedule_name')->filter()->unique()->all())
            ->get(['id','name','start_time','end_time','standard_day_minutes']);
        $byId = $types->keyBy('id');
        $byName = $types->groupBy('name');
        $frozenIds = $incomplete->filter(fn ($r) => !empty($r->source_system) && ($r->approval_status ?? '') === 'APPROVED')->pluck('id');
        $frozen = $this->singlePunchApprovalRecords($frozenIds);
        foreach ($incomplete as $r) {
            $r->frozen_fallback_record = $frozen->get($r->id) ?? [];
            $named = $byName->get($r->schedule_name ?? '');
            $type = $byId->get($r->work_type_id ?? null);
            $fallback = $named?->count() === 1 ? $named->first() : null;
            $r->daily_type_name = $type->name ?? null;
            $r->daily_type_start = $type->start_time ?? $fallback->start_time ?? null;
            $r->daily_type_end = $type->end_time ?? $fallback->end_time ?? null;
            $r->daily_standard_minutes = $type->standard_day_minutes ?? $fallback->standard_day_minutes ?? null;
            $rule = $this->scheduleRuleFromRecords($people->get($r->employee_id),$settings->get($r->employee_id),$shifts->get($r->employee_id.':'.$r->target_date),$r->target_date, $r);
            $r->projection_schedule = $rule + ['activeBreakRule'=>$breakRule];
            $r->projection_month_closed = $closed->has(substr($r->target_date,0,7));
        }
    }

    protected function singlePunchApprovalRecords(Collection $ids): Collection
    {
        if ($ids->isEmpty()) { return collect(); }
        return DB::table('audit_logs')->where('action','ATTENDANCE_SCHEDULED_PUNCH_FALLBACK')
            ->where('target_type','ATTENDANCE_DAILY')->whereIn('target_id',$ids->map(fn ($id) => (string) $id)->all())
            ->orderBy('id')->get(['target_id','detail_json'])
            ->mapWithKeys(fn ($log) => [$log->target_id => json_decode((string) $log->detail_json, true)]);
    }

    protected function attendanceDayProjection(object $row): object
    {
        $row = $this->projectScheduledPunchFallback($row);
        if ((!empty($row->clock_in_at) xor !empty($row->clock_out_at))
            && !empty($row->employee_id) && !empty($row->target_date)) {
            $row = clone $row;
            $rule = $row->projection_schedule ?? $this->resolveDailyScheduleRule((int) $row->employee_id, (string) $row->target_date);
            $row->expected_end_at = $this->scheduleBoundaryDateTime((string) $row->target_date, $rule['scheduledClockOut'])?->format('Y-m-d H:i:s');
        }
        return $row;
    }
}
