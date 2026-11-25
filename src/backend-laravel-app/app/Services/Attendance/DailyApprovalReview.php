<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolves an approval obligation without manufacturing an approver or rewriting punch history. */
final class DailyApprovalReview extends AttendanceServiceSupport
{
    private const HELD_PUNCH = '採用されていない追加・後着の打刻があります。打刻履歴を確認してください';
    private const SHORT_SHIFT_HELD = '出勤直後のタッチを保留しています。短時間で退勤した場合は、打刻履歴で時刻を確認して勤怠を修正してください';
    private const HELD_REASONS = ['OUT_OF_ORDER_PUNCH','CONFIRMED_DAY_PUNCH','IMPORTED_DAY_PUNCH','TOO_MANY_PUNCHES','HISTORICAL_EMPLOYEE_PUNCH','SHORT_SHIFT_CONFIRMATION_REQUIRED','SHORT_SHIFT_REVIEW_ONLY', PunchClockReview::REASON];
    private const MISSING_SHIFT = '勤務予定がありますが出退勤の記録がありません';
    private const PUNCH_OPTIONAL_EMPLOYEES = ['970012', '970005', '970019'];

    public function annotate(Collection $rows, ?array $rejected = null): void
    {
        if ($rows->isEmpty()) { return; }
        if ($rows->every(fn ($row) => ($row->close_status ?? 'OPEN') === 'CLOSED' && ($row->approval_status ?? 'PENDING') !== 'PENDING')) {
            foreach ($rows as $row) { $row->review_status = $row->approval_status; $row->review_reason = null; }
            return;
        }
        $rejected ??= $this->unreviewedPunches($rows->min('target_date'), $rows->max('target_date'), $rows->pluck('employee_id')->unique()->all());
        $pending = $rows->filter(function (object $row) use ($rejected) {
            $row->review_status = $row->approval_status ?? 'PENDING';
            $row->review_reason = null;
            if (isset($rejected[$row->id]) && $row->review_status !== 'RETURNED' && ($row->close_status ?? 'OPEN') !== 'CLOSED') {
                $row->review_status = 'PENDING';
                $row->review_reason = $rejected[$row->id];
                return false;
            }
            return $row->review_status === 'PENDING' || $this->legacyImportDefault($row) || $this->leaveDecision($row);
        });
        if ($pending->isEmpty()) { return; }
        $rules = $this->attendanceErrorRules();
        $now = CarbonImmutable::now('Asia/Tokyo');
        $from = $pending->min('target_date');
        $to = $pending->max('target_date');
        $ids = $pending->pluck('employee_id')->unique()->all();
        $handled = [];
        foreach (DB::table('attendance_error_resolutions')->whereIntegerInRaw('employee_id', $ids)
            ->whereBetween('target_date', [$from, $to])->whereIn('status', ['RESOLVED', 'IGNORED'])
            ->get(['employee_id', 'target_date', 'error_code']) as $r) {
            $handled[$r->employee_id.':'.$r->target_date][$r->error_code] = true;
        }
        // Yesterday's overnight shifts can still be running. Older rows need no schedule lookup.
        $today = $now->toDateString();
        $yesterday = $now->subDay()->toDateString();
        $recent = $pending->filter(fn ($r) => $r->target_date >= $yesterday
            && $r->target_date <= $today && (empty($r->raw_clock_in_at) || empty($r->raw_clock_out_at)));
        $ends = [];
        if ($recent->isNotEmpty()) {
            $settings = DB::table('employee_attendance_settings')->whereIntegerInRaw('employee_id', $recent->pluck('employee_id')->unique()->all())->get()->keyBy('employee_id');
            $shifts = $this->shiftQuery()->whereIntegerInRaw('s.employee_id', $recent->pluck('employee_id')->unique()->all())
                ->whereBetween('s.target_date', [$recent->min('target_date'), $recent->max('target_date')])->get()->keyBy(fn ($r) => $r->employee_id.':'.$r->target_date);
            foreach ($recent as $r) {
                $key = $r->employee_id.':'.$r->target_date;
                $s = $shifts->get($key);
                $setting = $settings->get($r->employee_id);
                $policy = ConfirmedAttendancePolicy::profile($r, $r->target_date);
                $ends[$key] = $this->endAt($r->target_date, $s->start_time ?? $setting->standard_clock_in_time ?? $policy['defaultClockIn'] ?? null,
                    $s->end_time ?? $setting->standard_clock_out_time ?? $policy['defaultClockOut'] ?? null);
            }
        }
        foreach ($pending as $r) {
            $key = $r->employee_id.':'.$r->target_date;
            [$r->review_status, $r->review_reason] = $this->decision($r, $rules, $handled[$key] ?? [], $today, $now, $ends[$key] ?? null);
        }
    }

    public function restrictToConfirmedApprovals(Builder $query, array $filters): void
    {
        $query->whereIntegerNotInRaw('ad.id',array_keys($this->unreviewedPunches($filters['from'] ?? null,$filters['to'] ?? null)));
        $query->where(function ($q) {
            $q->whereNull('ad.source_import_type')->orWhere('ad.source_import_type', '<>', 'HARMOS_ATTENDANCE_DAILY_CSV')
                ->orWhereNotNull('ad.approved_by')->orWhereNotNull('ad.approved_at')->orWhere('ad.approval_comment', '<>', '')
                ->orWhere('ad.close_status','CLOSED');
        })->where(fn ($q) => $q->whereNull('ad.approval_comment')->orWhere('ad.approval_comment','<>','休暇承認により自動反映')->orWhere('ad.close_status','CLOSED'));
    }

    public static function confirmedFullLeave(object $row): bool
    {
        return ($row->approval_comment ?? '') === '休暇承認により自動反映' && AttendanceDayState::hasLeave($row)
            && empty($row->raw_clock_in_at) && empty($row->raw_clock_out_at) && empty($row->clock_in_at) && empty($row->clock_out_at)
            && (int) ($row->work_minutes ?? 0) === 0;
    }

    private function leaveDecision(object $r): bool
    {
        // This signature confirms the leave request, including half/hour leave, not the day's remaining work.
        return ($r->approval_status ?? null) === 'APPROVED' && ($r->close_status ?? 'OPEN') !== 'CLOSED'
            && ($r->approval_comment ?? null) === '休暇承認により自動反映';
    }

    private function legacyImportDefault(object $row): bool
    {
        return ($row->approval_status ?? null) === 'APPROVED' && ($row->close_status ?? 'OPEN') !== 'CLOSED'
            && ($row->source_import_type ?? null) === 'HARMOS_ATTENDANCE_DAILY_CSV'
            && empty($row->approved_by) && empty($row->approved_at) && empty($row->approval_comment);
    }

    private function decision(object $r, array $rules, array $handled, string $today, CarbonImmutable $now, ?CarbonImmutable $end): array
    {
        if ($r->target_date > $today) { return ['NOT_DUE', null]; }
        $in = $r->raw_clock_in_at ?? null;
        $out = $r->raw_clock_out_at ?? null;
        $hasPair = $in && $out;
        $optionalPunch = !$hasPair && in_array((string) ($r->employee_code ?? ''), self::PUNCH_OPTIONAL_EMPLOYEES, true);
        // With no observed pair an automatic shift estimate is not evidence of actual work or a short break.
        if ($optionalPunch && empty($r->is_manually_edited) && empty($r->break_overtime_review)) {
            if ((int) ($r->break_minutes ?? 0) < 0) { return ['PENDING', '休憩時間を確認してください']; }
            if ($r->clock_in_at && $r->clock_out_at && $r->clock_out_at <= $r->clock_in_at) {
                return ['PENDING', '採用時刻の順序を確認してください'];
            }
            return ['NOT_REQUIRED', null];
        }
        if (!$in && !$out) {
            if (self::confirmedFullLeave($r)) { return ['NOT_REQUIRED', null]; }
            $rest = AttendanceDayState::isDayOff($r) || AttendanceDayState::hasLeave($r);
            if ((!$rest && empty($r->is_manually_edited) && SaturdayHolidayPolicy::applies($r))
                || ($rest && empty($r->clock_in_at) && empty($r->clock_out_at) && isset($r->work_minutes) && (int) $r->work_minutes === 0)) {
                return ['NOT_REQUIRED', null];
            }
        }
        if (!$hasPair && ($end ? $now->lessThanOrEqualTo($end->addMinutes(30)) : $r->target_date === $today)) {
            return ['NOT_DUE', null];
        }
        $reason = null;
        if (!$hasPair && !$optionalPunch) {
            $reason = !$in && !$out ? '出勤・退勤の打刻がありません' : '1回しか打刻がありません';
        } elseif ($hasPair && $out <= $in) {
            $reason = '出退勤の原打刻の順序を確認してください';
        } elseif ($this->legacyImportDefault($r)) {
            $reason = '移行元の出退勤打刻・承認の原本を確認してください';
        } elseif (empty($r->clock_in_at) || empty($r->clock_out_at) || $r->clock_out_at <= $r->clock_in_at || (int) ($r->work_minutes ?? 0) <= 0) {
            $reason = '採用時刻または実働が未確定です';
        } elseif ((int) $r->break_minutes < 0) {
            $reason = '休憩時間を確認してください';
        } else {
            $errors = [];
            if (AttendanceDayState::hasLeave($r) && ($rule = $this->enabledErrorRule($rules, 'LEAVE_WITH_WORK', '休日・休暇の出勤'))) {
                $errors[] = ['errorCode'=>'LEAVE_WITH_WORK', 'errorName'=>$rule['name']];
            }
            $span = (strtotime($r->clock_out_at) - strtotime($r->clock_in_at)) / 60;
            if ((int) $r->work_minutes > $span) { $reason = '実働が採用時刻の間隔を超えています'; }
            foreach ([...$errors, ...$this->workDurationErrors((int) $r->work_minutes, (int) $r->break_minutes, $span, $rules)] as $error) {
                if (!isset($handled[$error['errorCode']])) { $reason ??= $error['errorName']; }
            }
            if (!empty($r->is_manually_edited) && BreakReviewPolicy::confirmation($r) === null) { $reason ??= '手修正した日次勤怠の確認が必要です'; }
        }
        return $reason === null ? ['NOT_REQUIRED', null] : ['PENDING', $reason];
    }

    private function shiftQuery(): Builder
    {
        return DB::table('attendance_shift_schedules as s')->leftJoin('work_type_settings as wt', 'wt.id', '=', 's.work_type_id')
            ->select(['s.employee_id', 's.target_date', 'wt.name as schedule_name'])
            ->selectRaw('COALESCE(s.scheduled_clock_in_time, wt.start_time) as start_time, COALESCE(s.scheduled_clock_out_time, wt.end_time) as end_time');
    }

    private function endAt(string $date, ?string $start, ?string $end): ?CarbonImmutable
    {
        if (!$end) { return null; }
        $until = CarbonImmutable::parse($date.' '.$end, 'Asia/Tokyo');
        return $start && $end < $start ? $until->addDay() : $until;
    }

    public function absentDays(array $filters = [], bool $includeOptionalShifts = false): Collection
    {
        $shifts = $this->missingShifts($filters)->keyBy(fn ($r) => $r->employee_id.':'.$r->target_date);
        $date = 'substr(ev.occurred_at,1,10)';
        $q = DB::table('attendance_events as ev')->join('employees as e','e.id','=','ev.employee_id')
            ->where('ev.receive_status','REJECTED')->whereIn('ev.rejection_reason',self::HELD_REASONS)
            ->whereRaw("$date >= e.joined_on AND (e.retired_on IS NULL OR $date <= e.retired_on)")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_daily as ad')->whereColumn('ad.employee_id','ev.employee_id')->whereRaw("ad.target_date = $date"))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_monthly_closes as mc')->where('mc.status','CLOSED')->whereRaw('mc.target_year_month = substr(ev.occurred_at,1,7)'))
            ->where('ev.occurred_at','<=', CarbonImmutable::now('Asia/Tokyo')->format('Y-m-d H:i:s'))
            ->select('ev.employee_id','e.employee_code','e.name as employee_name','e.employment_type','e.department_name','e.location_name')
            ->selectRaw("$date as target_date")->distinct();
        if (!empty($filters['from'])) { $q->where('ev.occurred_at','>=',$filters['from'].' 00:00:00'); }
        if (!empty($filters['to'])) { $q->where('ev.occurred_at','<=',$filters['to'].' 23:59:59'); }
        foreach (['employeeCode'=>'employee_code','departmentName'=>'department_name'] as $key=>$column) {
            if (!empty($filters[$key])) { $q->where("e.$column",'like','%'.$filters[$key].'%'); }
        }
        foreach ($q->get() as $r) {
            $key = $r->employee_id.':'.$r->target_date;
            $r->id = null;
            $r->schedule_name = $shifts->get($key)->schedule_name ?? '打刻履歴あり';
            $r->review_reason = self::HELD_PUNCH;
            $shifts->put($key,$r);
        }
        foreach ($shifts as $key => $shift) {
            if ($shift->review_reason === self::MISSING_SHIFT && in_array((string) $shift->employee_code, self::PUNCH_OPTIONAL_EMPLOYEES, true)) {
                if ($includeOptionalShifts) {
                    $shift->review_reason = null;
                } else {
                    $shifts->forget($key);
                }
            }
        }
        return $shifts->values();
    }

    private function missingShifts(array $filters): Collection
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $query = $this->shiftQuery()->join('employees as e', 'e.id', '=', 's.employee_id')
            ->leftJoin('attendance_daily as ad', fn ($join) => $join->on('ad.employee_id', '=', 's.employee_id')->on('ad.target_date', '=', 's.target_date'))
            ->whereNull('ad.id')->whereColumn('s.target_date', '>=', 'e.joined_on')
            ->where(fn ($q) => $q->whereNull('e.retired_on')->orWhereColumn('s.target_date', '<=', 'e.retired_on'))
            ->where('s.target_date', '<=', $now->toDateString())->addSelect('e.employee_code','e.name as employee_name','e.employment_type','e.department_name','e.location_name');
        $this->applyFilters($query, $filters, 's');
        $shifts = $query->get()->filter(fn ($s) => $s->start_time && $s->end_time && !AttendanceDayState::isDayOff($s)
            && !AttendanceDayState::hasLeave($s) && $now->greaterThan($this->endAt($s->target_date, $s->start_time, $s->end_time)->addMinutes(30)));
        if ($shifts->isEmpty()) { return collect(); }
        $closed = DB::table('attendance_monthly_closes')->where('status','CLOSED')->pluck('target_year_month')->flip();
        $leave = DB::table('leave_requests')->where('status', 'APPROVED')->where('day_unit', 'FULL')
            ->where(fn ($q) => $q->whereNull('request_category')->orWhere('request_category','LEAVE'))
            ->whereIntegerInRaw('employee_id', $shifts->pluck('employee_id')->unique()->all())
            ->where('start_date','<=',$shifts->max('target_date'))->where('end_date','>=',$shifts->min('target_date'))->get()->groupBy('employee_id');
        return $shifts->reject(function ($s) use ($closed, $leave) {
            if ($closed->has(substr($s->target_date, 0, 7))) { return true; }
            foreach ($leave->get($s->employee_id, []) as $request) {
                if ($request->start_date <= $s->target_date && $request->end_date >= $s->target_date
                    && ($request->effective_dates === null || in_array($s->target_date, json_decode($request->effective_dates, true), true))
                    && in_array($request->attendance_effect ?? $request->leave_type_code, ['PAID','ABSENCE','SPECIAL'], true)) { return true; }
            }
            return false;
        })->each(function ($s) { $s->review_reason = self::MISSING_SHIFT; $s->id = null; });
    }

    /** Repeated card touches near an accepted punch do not reopen a normal day. */
    private function unreviewedPunches(?string $from, ?string $to, array $employees = []): array
    {
        $q = DB::table('attendance_events as ev')->join('attendance_daily as ad', function ($join) {
            $join->on('ad.employee_id','=','ev.employee_id')->whereRaw('ad.target_date = substr(ev.occurred_at,1,10)');
        })->where('ev.receive_status','REJECTED')->whereIn('ev.rejection_reason',self::HELD_REASONS)
            ->where('ad.close_status','<>','CLOSED')->where('ad.approval_status','<>','RETURNED')
            ->where(fn ($q) => $q->whereNull('ad.approved_at')->orWhereColumn('ev.created_at','>','ad.approved_at'));
        if ($from) { $q->where('ev.occurred_at','>=',$from.' 00:00:00'); }
        if ($to) { $q->where('ev.occurred_at','<=',$to.' 23:59:59'); }
        if ($employees) { $q->whereIntegerInRaw('ev.employee_id',$employees); }
        $reasons = [];
        foreach ($q->get(['ad.id','ad.raw_clock_in_at','ad.raw_clock_out_at','ev.occurred_at','ev.rejection_reason']) as $r) {
            // The later ordinary clock-out resolves an unconfirmed short tap.
            // Its exact timestamp remains available in the event history.
            if (in_array($r->rejection_reason, ['SHORT_SHIFT_CONFIRMATION_REQUIRED','SHORT_SHIFT_REVIEW_ONLY'], true)) {
                if (!$r->raw_clock_out_at || $r->raw_clock_out_at < $r->occurred_at) {
                    $reasons[$r->id] ??= self::SHORT_SHIFT_HELD;
                }
                continue;
            }
            if ($r->rejection_reason === 'TOO_MANY_PUNCHES' && collect([$r->raw_clock_in_at, $r->raw_clock_out_at])->filter()
                ->contains(fn ($time) => abs(strtotime($time) - strtotime($r->occurred_at)) <= 180)) { continue; }
            $reasons[$r->id] = self::HELD_PUNCH;
        }
        return $reasons;
    }

    public function applyFilters(Builder $query, array $filters, string $alias): void
    {
        if (!empty($filters['employeeId'])) { $query->where("$alias.employee_id", (int) $filters['employeeId']); }
        if (!empty($filters['from'])) { $query->where("$alias.target_date", '>=', $filters['from']); }
        if (!empty($filters['to'])) { $query->where("$alias.target_date", '<=', $filters['to']); }
        foreach (['employeeCode'=>'employee_code','departmentName'=>'department_name'] as $key => $column) {
            if (!empty($filters[$key])) { $query->where("e.$column", 'like', '%'.$filters[$key].'%'); }
        }
    }

    public function pending(array $filters = [], bool $sort = true): Collection
    {
        $rejected = $this->unreviewedPunches($filters['from'] ?? null, $filters['to'] ?? null);
        $query = DB::table('attendance_daily as ad')->join('employees as e','e.id','=','ad.employee_id')
            ->select(['ad.id','ad.employee_id','ad.target_date','ad.approval_status','ad.approval_comment','ad.approved_at','ad.approved_by',
                'ad.close_status','ad.source_import_type','ad.raw_clock_in_at','ad.raw_clock_out_at','ad.clock_in_at','ad.clock_out_at',
                'ad.break_minutes','ad.work_minutes','ad.is_manually_edited','ad.break_overtime_review','ad.schedule_name','ad.request_leave_days','ad.absence_flag',
                'ad.special_leave_flag','ad.paid_leave_unit','ad.hour_paid_leave_minutes','ad.child_care_leave_minutes','ad.nursing_care_leave_minutes',
                'e.employee_code','e.employment_type'])

            ->where(fn ($q) => $q->where('ad.approval_status','PENDING')->orWhereIntegerInRaw('ad.id',array_keys($rejected))
                ->orWhere(fn ($q) => $q->where('ad.approval_status','APPROVED')->where('ad.approval_comment','休暇承認により自動反映'))
                ->orWhere(fn ($q) => $q->where('ad.approval_status','APPROVED')
                ->where('ad.source_import_type','HARMOS_ATTENDANCE_DAILY_CSV')->whereNull('ad.approved_by')->whereNull('ad.approved_at')
                ->where(fn ($q) => $q->whereNull('ad.approval_comment')->orWhere('ad.approval_comment',''))))
            ->where('ad.close_status','<>','CLOSED')->where('ad.target_date','<=',CarbonImmutable::today('Asia/Tokyo')->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_monthly_closes as mc')
                ->where('mc.status','CLOSED')->whereRaw('mc.target_year_month = substr(ad.target_date, 1, 7)'));
        // Discard ordinary rows in SQL. Everything outside this deliberately narrow subset goes
        // through the complete policy, including named leave, manual edits, and administrator rules.
        $rules = $this->attendanceErrorRules();
        $shortLimit = min($rules['MISSING_BREAK']['minWorkMinutes'] ?? 360, $rules['SHORT_BREAK_6_TO_8']['minWorkMinutes'] ?? 360,
            $rules['SHORT_BREAK_OVER_8']['minWorkMinutes'] ?? 480, $rules['LONG_WORK']['minWorkMinutes'] ?? 720);
        $longLimit = $rules['LONG_WORK']['minWorkMinutes'] ?? 720;
        $minBreak = max(1, $rules['MISSING_BREAK']['requiredBreakMinutes'] ?? 1, $rules['SHORT_BREAK_6_TO_8']['requiredBreakMinutes'] ?? 45,
            $rules['SHORT_BREAK_OVER_8']['requiredBreakMinutes'] ?? 60);
        $maxBreak = $rules['BREAK_TOO_LONG']['maxBreakMinutes'] ?? 240;
        $span = DB::getDriverName() === 'sqlite'
            ? "(strftime('%s',ad.clock_out_at) - strftime('%s',ad.clock_in_at))"
            : 'timestampdiff(SECOND,ad.clock_in_at,ad.clock_out_at)';
        $query->whereNot(function ($q) use ($rejected, $shortLimit, $longLimit, $minBreak, $maxBreak, $span) {
            $q->where('ad.approval_status','PENDING')->whereIntegerNotInRaw('ad.id', array_keys($rejected))
                ->where('ad.is_manually_edited',0)->where('ad.absence_flag',0)->where('ad.special_leave_flag',0)
                ->whereRaw("coalesce(ad.request_leave_days,0) = 0 AND coalesce(ad.paid_leave_unit,0) = 0")
                ->whereRaw("coalesce(ad.schedule_name,'') IN ('','通常','通常勤務','出勤','日勤','平日','常勤','パート')")
                ->whereNotNull('ad.raw_clock_in_at')->whereNotNull('ad.raw_clock_out_at')->whereColumn('ad.raw_clock_out_at','>','ad.raw_clock_in_at')
                ->whereNotNull('ad.clock_in_at')->whereNotNull('ad.clock_out_at')->whereColumn('ad.clock_out_at','>','ad.clock_in_at')
                ->whereNotNull('ad.work_minutes')->whereNotNull('ad.break_minutes')
                ->whereRaw("$span >= ad.work_minutes * 60 AND $span > ad.break_minutes * 60")
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereBetween('ad.work_minutes',[1,$shortLimit])->where('ad.break_minutes',0))
                    ->orWhere(fn ($q) => $q->whereBetween('ad.work_minutes',[1,$longLimit])->whereBetween('ad.break_minutes',[$minBreak,$maxBreak])));
        });
        $this->applyFilters($query, $filters, 'ad');
        $rows = $query->get();
        $this->annotate($rows, $rejected);
        $items = $rows->where('review_status','PENDING')->concat($this->absentDays($filters));
        return $sort ? $items->sortBy([['target_date','desc'],['employee_code','asc']])->values() : $items;
    }
}
