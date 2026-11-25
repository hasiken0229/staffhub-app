<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendancePunchRebuilder
{
    protected function formatExistingPunchResult(object $event): array
    {
        $reviewMessage = match ($event->rejection_reason ?? null) {
            'SHORT_SHIFT_CONFIRMATION_REQUIRED' => '出勤から10分以内のため、このタッチは退勤にせず、時刻を保存しました。勤務を続ける方は操作不要です。退勤する方は短時間退勤を確定するか、管理者にこの時刻をお伝えください。',
            'SHORT_SHIFT_REVIEW_ONLY' => '勤怠の確定、追加の打刻、またはカード割当の変更により、短時間退勤を確定できません。タッチ時刻は保存済みです。管理者に確認を依頼してください。',
            PunchClockReview::REASON => '端末の打刻時刻を確認できないため、原本を保存しました。勤怠は未確定です。再打刻せず、管理者に実際の時刻を伝えてください。',
            'OUT_OF_ORDER_PUNCH' => '打刻時刻が既存の記録より前のため、確認対象として保存しました。管理者に勤怠の確認を依頼してください。',
            'CONFIRMED_DAY_PUNCH' => '承認済み・修正済みの勤怠は変更せず、打刻を確認対象として保存しました。管理者に確認を依頼してください。',
            'IMPORTED_DAY_PUNCH' => '移行済みの出退勤に追加の打刻が届きました。打刻を確認対象として保存しました。',
            'HISTORICAL_EMPLOYEE_PUNCH' => '過去の在籍・カード割当期間内の打刻原本を確認対象として保存しました。勤怠へは自動反映していません。管理者に確認を依頼してください。',
            'EMPLOYEE_NOT_EMPLOYED' => '打刻日の在籍を確認できないため勤怠へ反映していません。原本を確認対象として保存しました。管理者へ連絡してください。',
            'CARD_ASSIGNMENT_REVIEW_REQUIRED' => '打刻時点のカード割当を特定できないため、原本を確認対象として保存しました。管理者にカード履歴の確認を依頼してください。',
            'PREVIOUS_DAY_OPEN_PUNCH' => '前日の退勤が未記録です。今回の打刻は当日の出勤として保存しました。日跨ぎ勤務の場合は、管理者に勤務開始日の退勤を翌日指定で個別修正するよう依頼してください。',
            default => null,
        };
        if ($event->receive_status === 'REJECTED' && $reviewMessage === null) {
            $reason = $event->rejection_reason ?? 'PUNCH_REJECTED';
            $message = match ($reason) {
                'CARD_NOT_REGISTERED' => 'このカードは登録されていません。',
                'TOO_MANY_PUNCHES' => '本日は既に出勤・退勤が記録されています。管理画面で確認してください。',
                'SHORT_INTERVAL_PUNCH' => '直前の打刻から3分以内のため、打刻を受け付けできません。',
                default => 'この打刻は受け付けできませんでした。',
            };

            throw new ApiException($reason, $message, 400);
        }

        $employee = null;
        if ($event->employee_id !== null) {
            $employeeRow = DB::table('employees')->where('id', $event->employee_id)->first();
            if ($employeeRow !== null) {
                $employee = [
                    'id' => (int) $employeeRow->id,
                    'employeeCode' => $employeeRow->employee_code,
                    'name' => $employeeRow->name,
                ];
            }
        }

        return [
            'attendanceEventId' => (int) $event->id,
            'employee' => $employee,
            'eventType' => $event->event_type,
            'resultType' => $reviewMessage !== null ? 'WARNING' : 'SUCCESS',
            ...($reviewMessage !== null ? ['reasonCode' => $event->rejection_reason] : []),
            'resultMessage' => $reviewMessage ?? ($event->event_type === 'CLOCK_OUT' ? '退勤を記録しました。' : '出勤を記録しました。'),
            'occurredAt' => CarbonImmutable::parse($event->occurred_at)->toIso8601String(),
            'offlineAccepted' => (bool) $event->offline_saved,
        ];
    }

    protected function insertAttendanceEvent(array $attributes): int
    {
        return (int) DB::table('attendance_events')->insertGetId($attributes);
    }

    protected function rebuildDaily(int $employeeId, CarbonImmutable $occurredAt): void
    {
        $this->assertMonthOpen($occurredAt->format('Y-m'));

        $dayStart = $occurredAt->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd = $occurredAt->endOfDay()->format('Y-m-d H:i:s');
        $targetDate = $occurredAt->toDateString();

        $events = DB::table('attendance_events')
            ->where('employee_id', $employeeId)
            ->where('receive_status', 'ACCEPTED')
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->orderBy('occurred_at')
            ->get();

        $clockInAt = $events->firstWhere('event_type', 'CLOCK_IN')?->occurred_at;
        $clockOutAt = $this->findClockOutAfterClockIn($events, $clockInAt);

        $existing = DB::table('attendance_daily')
            ->where('employee_id', $employeeId)
            ->where('target_date', $targetDate)
            ->first();

        $isManuallyEdited = $existing !== null && (bool) ($existing->is_manually_edited ?? false);
        $scheduleRule = $this->resolveDailyScheduleRule($employeeId, $targetDate);
        $keepBreaks = $existing !== null && $scheduleRule['allowLaterShift'];
        $automatic = $isManuallyEdited ? null : $this->automaticPunchResult(
            $clockInAt, $clockOutAt, $scheduleRule, $targetDate, $keepBreaks ? (int) $existing->break_minutes : null,
        );
        $autoBreak = $automatic['autoBreak'] ?? null;

        $values = [
            'employee_id' => $employeeId,
            'target_date' => $targetDate,
            'raw_clock_in_at' => $clockInAt,
            'raw_clock_out_at' => $clockOutAt,
            'clock_in_at' => $isManuallyEdited ? $existing->clock_in_at : $automatic['clockInAt'],
            'clock_out_at' => $isManuallyEdited ? $existing->clock_out_at : $automatic['clockOutAt'],
            'break_minutes' => $isManuallyEdited ? (int) $existing->break_minutes : $automatic['breakMinutes'],
            'work_minutes' => $isManuallyEdited ? $existing->work_minutes : $automatic['workMinutes'],
            'updated_at' => now(),
        ];

        if (! $isManuallyEdited && $scheduleRule['workTypeId'] !== null && !$scheduleRule['preserveDailyWorkType']) {
            $values['work_type_id'] = $scheduleRule['workTypeId'];
            $values['schedule_name'] = $scheduleRule['workTypeName'];
        }

        if ($existing === null) {
            $values += [
                'late_flag' => 0,
                'early_leave_flag' => 0,
                'absence_flag' => 0,
                'special_leave_flag' => 0,
                'paid_leave_unit' => null,
                'hour_paid_leave_minutes' => 0,
                'child_care_leave_minutes' => 0,
                'nursing_care_leave_minutes' => 0,
                'remark' => null,
                'approval_status' => 'PENDING',
                'approval_comment' => null,
                'approved_by' => null,
                'approved_at' => null,
                'close_status' => 'OPEN',
                'is_manually_edited' => 0,
                'work_type_id' => null,
                'supervisor_comment' => null,
                'manual_edited_by' => null,
                'manual_edited_at' => null,
            ];

            $dailyId = (int) DB::table('attendance_daily')->insertGetId($values);
            $this->syncAutoBreak($dailyId, $autoBreak);

            return;
        }

        DB::table('attendance_daily')
            ->where('id', $existing->id)
            ->update([
                ...$values,
                'approval_status' => $isManuallyEdited ? $existing->approval_status : 'PENDING',
                'approval_comment' => $isManuallyEdited ? $existing->approval_comment : null,
                'approved_by' => $isManuallyEdited ? $existing->approved_by : null,
                'approved_at' => $isManuallyEdited ? $existing->approved_at : null,
            ]);

        if (! $isManuallyEdited && !$keepBreaks) {
            $this->syncAutoBreak((int) $existing->id, $autoBreak);
        }
    }

    protected function findClockOutAfterClockIn(Collection $events, ?string $clockInAt): ?string
    {
        $clockOut = null;

        foreach ($events as $event) {
            if ($event->event_type !== 'CLOCK_OUT') {
                continue;
            }

            if ($clockInAt === null || CarbonImmutable::parse($event->occurred_at)->greaterThanOrEqualTo(CarbonImmutable::parse($clockInAt))) {
                $clockOut = $event->occurred_at;
            }
        }

        return $clockOut;
    }

    /**
     * @return array{workTypeId:?int,workTypeName:?string,scheduledClockIn:?string,scheduledClockOut:?string,includeBeforeStart:bool,includeAfterEnd:bool}
     */
    protected function resolveDailyScheduleRule(int $employeeId, string $targetDate): array
    {
        $setting = DB::table('employee_attendance_settings')
            ->where('employee_id', $employeeId)
            ->first();
        $shift = DB::table('attendance_shift_schedules as ass')
            ->leftJoin('work_type_settings as wt', 'wt.id', '=', 'ass.work_type_id')
            ->where('ass.employee_id', $employeeId)
            ->where('ass.target_date', $targetDate)
            ->select([
                'ass.work_type_id',
                'wt.name as work_type_name',
                'wt.start_time as work_type_start_time',
                'wt.end_time as work_type_end_time',
                'wt.standard_day_minutes',
                'ass.scheduled_clock_in_time',
                'ass.scheduled_clock_out_time',
            ])
            ->first();

        $employee = DB::table('employees as e')->where('e.id', $employeeId)
            ->leftJoin('attendance_daily as ad', fn ($join) => $join->on('ad.employee_id', '=', 'e.id')->where('ad.target_date', $targetDate))
            ->leftJoin('work_type_settings as day_type', 'day_type.id', '=', 'ad.work_type_id')
            ->first(['e.*', 'ad.schedule_name', 'ad.work_type_id', 'day_type.name as daily_type_name',
                'day_type.start_time as daily_type_start', 'day_type.end_time as daily_type_end', 'day_type.standard_day_minutes as daily_standard_minutes',
                'ad.absence_flag','ad.special_leave_flag','ad.paid_leave_unit','ad.request_leave_days',
                'ad.hour_paid_leave_minutes','ad.child_care_leave_minutes','ad.nursing_care_leave_minutes']);
        if (!empty($employee->schedule_name) && ($employee->daily_type_start === null || $employee->daily_type_end === null || $employee->daily_standard_minutes === null)) {
            $name = $employee->schedule_name;
            $matches = DB::table('work_type_settings')->where('name', $name)->get(['name','start_time','end_time','standard_day_minutes'])
                ->filter(fn ($type) => $type->name === $name);
            if ($matches->count() === 1) {
                $type = $matches->first();
                $employee->daily_type_start ??= $type->start_time;
                $employee->daily_type_end ??= $type->end_time;
                $employee->daily_standard_minutes ??= $type->standard_day_minutes;
            }
        }
        return $this->scheduleRuleFromRecords($employee, $setting, $shift, $targetDate, $employee);
    }

    protected function scheduleRuleFromRecords(?object $employee, ?object $setting, ?object $shift, string $targetDate, ?object $day = null): array
    {
        $policy = $employee ? ConfirmedAttendancePolicy::profile($employee, $targetDate) : null;
        $code = (string) ($employee->employee_code ?? '');
        $dailyScheduleNames = ($day->schedule_name ?? '').' '.($day->daily_type_name ?? '');
        $scheduleNames = ($shift?->work_type_name ?? '').' '.$dailyScheduleNames;
        $standard = $shift->standard_day_minutes ?? $day->daily_standard_minutes ?? null;
        $ordinaryDay = !ConfirmedAttendancePolicy::requiresIndividualBreakReview($scheduleNames)
            && !AttendanceDayState::hasLeave((object) ['schedule_name' => $scheduleNames])
            && empty($day->absence_flag) && empty($day->special_leave_flag)
            && (float) ($day->request_leave_days ?? 0) <= 0 && (float) ($day->paid_leave_unit ?? 0) <= 0
            && empty($day->hour_paid_leave_minutes) && empty($day->child_care_leave_minutes) && empty($day->nursing_care_leave_minutes);
        $mode = null;
        if ($ordinaryDay && ConfirmedAttendancePolicy::usesRawPunchFullBreakRule($policy['kind'] ?? null, $code, $standard)) {
            $mode = match (true) {
                ConfirmedAttendancePolicy::usesThreeShiftRule($code) => 'THREE_SHIFT',
                ConfirmedAttendancePolicy::usesRegularPunchRule($policy['kind'] ?? null, $code, $standard) => 'REGULAR',
                default => 'RAW_LONG_ONLY',
            };
        }
        $dayStart = $shift->scheduled_clock_in_time ?? $shift->work_type_start_time ?? $day->daily_type_start ?? null;
        $dayEnd = $shift->scheduled_clock_out_time ?? $shift->work_type_end_time ?? $day->daily_type_end ?? null;
        $personalFallback = $dayStart === null && $dayEnd === null;
        $singleStart = $dayStart ?? ($personalFallback ? ($setting->standard_clock_in_time ?? $policy['defaultClockIn'] ?? null) : null);
        $singleEnd = $dayEnd ?? ($personalFallback ? ($setting->standard_clock_out_time ?? $policy['defaultClockOut'] ?? null) : null);

        return [
            'allowScheduledFallback' => $code !== '970005' && $ordinaryDay,
            'employeeCode' => $code,
            'singlePunchStart' => $this->normalizeTimeForRule($singleStart),
            'singlePunchEnd' => $this->normalizeTimeForRule($singleEnd),
            'policyKind' => $policy['kind'] ?? null,
            'policyVersion' => $policy['version'] ?? null,
            'allowLaterShift' => $policy['allowLaterShift'] ?? false,
            'usualClockIn' => $policy['defaultClockIn'] ?? null,
            'maxWorkMinutes' => $policy['maxWorkMinutes'] ?? null,
            'workTypeId' => $shift?->work_type_id !== null ? (int) $shift->work_type_id : null,
            'workTypeName' => $shift?->work_type_name ?? null,
            'preserveDailyWorkType' => !empty($day->work_type_id) || (!empty($day->schedule_name) && $day->schedule_name !== $shift?->work_type_name),
            'punchBreakRule' => $mode,
            'hasDayPlan' => $dayStart !== null,
            'regularPunchStart' => $mode === null ? null : $this->normalizeTimeForRule(
                $dayStart ?? ($mode === 'THREE_SHIFT' ? null : $setting?->standard_clock_in_time),
            ),
            'scheduledClockIn' => $this->normalizeTimeForRule($shift?->scheduled_clock_in_time ?? $setting?->standard_clock_in_time ?? $policy['defaultClockIn'] ?? null),
            'scheduledClockOut' => $this->normalizeTimeForRule($shift?->scheduled_clock_out_time ?? $setting?->standard_clock_out_time ?? $policy['defaultClockOut'] ?? null),
            'shiftEndForBreak' => $this->normalizeTimeForRule($shift?->scheduled_clock_out_time ?? $shift?->work_type_end_time ?? null),
            'includeBeforeStart' => $policy['includeBeforeStart'] ?? (bool) ($setting?->include_before_start ?? false),
            'includeAfterEnd' => $policy['includeAfterEnd'] ?? (bool) ($setting?->include_after_end ?? false),
        ];
    }

    /** Fill only the missing side of a past day's punch pair; never fabricate raw events. */
    protected function resolveEffectivePunches(?string $rawIn, ?string $rawOut, array $rule, string $date): array
    {
        $in = $this->applyClockInRule($rawIn, $rule, $date);
        $out = $this->applyClockOutRule($rawOut, $rule, $date);
        if (!empty($rule['allowLaterShift']) && $rawIn !== null && !empty($rule['scheduledClockIn'])) {
            $rawStart = CarbonImmutable::parse($rawIn);
            $plannedStart = $this->scheduleBoundaryDateTime($date, $rule['scheduledClockIn']);
            $usualStart = $this->scheduleBoundaryDateTime($date, $rule['usualClockIn']);
            if ($rawOut !== null && ($plannedStart->greaterThan($usualStart) || $rawStart->startOfMinute()->greaterThan($plannedStart))) {
                // An explicitly later roster or a later punched start uses the complete observed pair.
                $in = $rawIn;
                $out = $rawOut;
            } elseif ($rawStart->isSameMinute($plannedStart)) {
                $in = $plannedStart->format('Y-m-d H:i:s');
            }
        }
        $side = null;
        if (($rule['allowScheduledFallback'] ?? false) && $date >= '2026-04-01'
            && $date < CarbonImmutable::now('Asia/Tokyo')->toDateString()
            && (($rawIn === null) xor ($rawOut === null))) {
            $start = $this->scheduleBoundaryDateTime($date, array_key_exists('singlePunchStart', $rule) ? $rule['singlePunchStart'] : $rule['scheduledClockIn']);
            $end = $this->scheduleBoundaryDateTime($date, array_key_exists('singlePunchEnd', $rule) ? $rule['singlePunchEnd'] : $rule['scheduledClockOut']);
            $stamp = $rawIn ?? $rawOut;
            $punch = CarbonImmutable::parse($stamp);
            if ($start && $end && $start->lessThan($end) && ($punch->isSameDay($start) || $punch->isSameDay($end))) {
                $toStart = abs($start->getTimestamp() - $punch->getTimestamp());
                $toEnd = abs($end->getTimestamp() - $punch->getTimestamp());
                $rule['scheduledClockIn'] = $start->format('H:i:s');
                $rule['scheduledClockOut'] = $end->format('H:i:s');
                if ($toStart < $toEnd && $punch->lessThan($end)) {
                    $in = !empty($rule['allowLaterShift']) && $punch->isSameMinute($start)
                        ? $start->format('Y-m-d H:i:s') : $this->applyClockInRule($stamp, $rule, $date);
                    $out = $end->format('Y-m-d H:i:s');
                    $side = 'CLOCK_OUT';
                } elseif ($toEnd < $toStart && $punch->greaterThan($start)) {
                    $in = $start->format('Y-m-d H:i:s');
                    $out = $this->applyClockOutRule($stamp, $rule, $date);
                    $side = 'CLOCK_IN';
                }
            }
        }
        return ['clockInAt'=>$in, 'clockOutAt'=>$out, 'inferredSide'=>$side];
    }

    protected function automaticPunchResult(?string $rawIn, ?string $rawOut, array $rule, string $date, ?int $recordedBreakMinutes = null): array
    {
        if (($rawIn === null) xor ($rawOut === null)) {
            $fixedEmployee = $rule['employeeCode'] ?? '';
            if ($date >= '2026-04-01' && $date < ConfirmedAttendancePolicy::EFFECTIVE_FROM) {
                if ($fixedEmployee === '970017' && $rule['singlePunchStart'] === '08:30:00' && $rule['singlePunchEnd'] === '14:30:00') {
                    $rule['policyKind'] = 'REGULAR_SHORT_TIME';
                    $rule['maxWorkMinutes'] = 360;
                    $rule['includeBeforeStart'] = $rule['includeAfterEnd'] = false;
                } elseif ($fixedEmployee === '970019' && $rule['singlePunchStart'] === '08:00:00' && $rule['singlePunchEnd'] === '15:30:00') {
                    $rule['policyKind'] = 'REGULAR';
                    $rule['includeBeforeStart'] = false;
                    $rule['includeAfterEnd'] = true;
                }
            }
        }
        $effective = $this->resolveEffectivePunches($rawIn, $rawOut, $rule, $date);
        $start = $effective['clockInAt'] !== null ? CarbonImmutable::parse($effective['clockInAt']) : null;
        $end = $effective['clockOutAt'] !== null ? CarbonImmutable::parse($effective['clockOutAt']) : null;
        if ($effective['inferredSide'] !== null) {
            $rule['scheduledClockOut'] = $rule['singlePunchEnd'] ?? $rule['scheduledClockOut'];
        }
        // A work-type end anchors the regular post-shift break only on a complete punch pair.
        if ($rawIn !== null && $rawOut !== null && ($rule['policyKind'] ?? null) === 'REGULAR' && isset($rule['shiftEndForBreak'])) {
            $rule['scheduledClockOut'] = $rule['shiftEndForBreak'];
        }
        $result = $this->regularPunchCalculation($rawIn, $rawOut, $rule, $date);
        if ($result !== null) {
            $start = $result['start'];
            $effective['clockInAt'] = $start->format('Y-m-d H:i:s');
            $effective['clockOutAt'] = $rawOut;
            $end = CarbonImmutable::parse($rawOut);
        }
        $autoBreak = null;
        if ($recordedBreakMinutes === null) {
            if ($effective['inferredSide'] !== null && in_array($rule['punchBreakRule'] ?? null, ['REGULAR', 'THREE_SHIFT'], true)) {
                // One observed punch can anchor a scheduled break, but cannot establish a nine-hour raw pair.
                $scheduled = $this->scheduleBoundaryDateTime($date, $rule['singlePunchStart']);
                $autoBreak = ConfirmedAttendancePolicy::breaks($start, $end, ['policyKind'=>'REGULAR', 'additionalBreakAnchor'=>$scheduled->addMinutes(525)], $date);
            } else {
                $autoBreak = $result === null ? $this->buildAutoBreak($start, $end, $rule, $date)
                    : ['minutes' => $result['minutes'], 'breaks' => $result['breaks']];
            }
        }
        $breakMinutes = $recordedBreakMinutes ?? (int) ($autoBreak['minutes'] ?? 0);
        $workMinutes = DailyTimeCalculator::actual($start, $end, $breakMinutes);
        if ($workMinutes !== null && isset($rule['maxWorkMinutes'])) {
            $workMinutes = min($workMinutes, $rule['maxWorkMinutes']);
        }

        return $effective + ['breakMinutes' => $breakMinutes, 'workMinutes' => $workMinutes, 'autoBreak' => $autoBreak];
    }

    protected function regularPunchCalculation(?string $rawIn, ?string $rawOut, array $rule, string $date): ?array
    {
        $mode = $rule['punchBreakRule'] ?? null;
        if (!$rawIn || !$rawOut || $mode === null) { return null; }
        $start = CarbonImmutable::parse($rawIn);
        $end = CarbonImmutable::parse($rawOut);
        if (!$start->lessThan($end) || (!$rule['hasDayPlan'] && !$start->isSameDay($end))) { return null; }
        $plan = $rule['regularPunchStart'];
        if ($mode === 'THREE_SHIFT' && !$plan) {
            $plan = ConfirmedAttendancePolicy::nearestThreeShiftStart($date, $start);
        }
        $planned = $this->scheduleBoundaryDateTime($date, $plan);
        if (ConfirmedAttendancePolicy::rawPunchNeedsFullBreak($start, $end)) {
            return ConfirmedAttendancePolicy::rawPunchFullBreak($start, $end, $planned, $date);
        }
        if ($planned === null || !$planned->lessThan($end) || $mode === 'RAW_LONG_ONLY') { return null; }
        return ConfirmedAttendancePolicy::regularPunchRule($start, $end, $planned, $date);
    }

    /** Read-only projection also handles a clock-in that becomes incomplete after midnight. */
    protected function projectScheduledPunchFallback(object $row): object
    {
        $holiday = SaturdayHolidayPolicy::project($row);
        if (!empty($holiday->saturday_holiday) && !($row->projection_month_closed ?? $this->isMonthClosed(substr((string) $row->target_date, 0, 7)))) {
            return $holiday;
        }
        $imported = !empty($row->source_system);
        $savedImport = $imported && ($row->source_system !== 'HARMOS' || ($row->work_minutes ?? null) !== null
            || !empty($row->harmos_total_work_minutes) || (!empty($row->clock_in_at) && !empty($row->clock_out_at)));
        if (!empty($row->schedule_inferred_side) || $savedImport || !empty($row->is_manually_edited)
            || !empty($row->break_overtime_review) || ($row->approval_status ?? 'PENDING') !== 'PENDING'
            || ($row->close_status ?? 'OPEN') === 'CLOSED' || $row->target_date < '2026-04-01'
            || $row->target_date >= CarbonImmutable::today('Asia/Tokyo')->toDateString()
            || (string) ($row->employee_code ?? '') === '970005'
            || !((empty($row->raw_clock_in_at)) xor (empty($row->raw_clock_out_at)))) { return $row; }
        if ($row->projection_month_closed ?? $this->isMonthClosed(substr((string) $row->target_date, 0, 7))) {
            $row = clone $row;
            $row->projection_month_closed = true;
            return $row;
        }
        $rule = $row->projection_schedule ?? $this->resolveDailyScheduleRule((int) $row->employee_id, (string) $row->target_date);
        $keepBreaks = $rule['allowLaterShift'] || ((int) ($row->break_minutes ?? 0) > 0
            && ($imported || ($row->has_recorded_breaks ?? DB::table('attendance_daily_breaks')->where('attendance_daily_id',$row->id)
                ->where(fn ($q) => $q->whereNotNull('created_by')->orWhereNotNull('updated_by'))->exists())));
        $effective = $this->automaticPunchResult(
            $row->raw_clock_in_at, $row->raw_clock_out_at, $rule, $row->target_date, $keepBreaks ? (int) $row->break_minutes : null,
        );
        if ($effective['inferredSide'] === null) { return $row; }
        $row = clone $row;
        $row->clock_in_at = $effective['clockInAt']; $row->clock_out_at = $effective['clockOutAt'];
        $row->break_minutes = $effective['breakMinutes'];
        $row->work_minutes = $effective['workMinutes'];
        $row->schedule_inferred_side = $effective['inferredSide'];
        if (!$keepBreaks) {
            $row->schedule_projected_breaks = $effective['autoBreak']['breaks'] ?? [];
        }
        return $row;
    }

    /** Called only inside the approval transaction with the daily row locked. */
    protected function freezeSinglePunch(object $daily, object $projected): void
    {
        $values = ['clock_in_at'=>$projected->clock_in_at, 'clock_out_at'=>$projected->clock_out_at,
            'break_minutes'=>$projected->break_minutes, 'work_minutes'=>$projected->work_minutes];
        DB::table('attendance_daily')->where('id',$daily->id)->update($values);
        if (isset($projected->schedule_projected_breaks)) {
            $this->syncAutoBreak($daily->id, ['breaks'=>$projected->schedule_projected_breaks, 'minutes'=>$projected->break_minutes]);
        }
        $this->addAudit('SYSTEM', null, 'ATTENDANCE_SCHEDULED_PUNCH_FALLBACK', 'ATTENDANCE_DAILY', (string) $daily->id,
            ['before'=>(array) $daily, 'after'=>$values, 'inferredSide'=>$projected->schedule_inferred_side]);
    }

    protected function singlePunchNeedsConfirmation(object $row): bool
    {
        return $row->target_date >= '2026-04-01' && ($row->approval_status ?? 'PENDING') === 'PENDING'
            && empty($row->source_system) && empty($row->is_manually_edited) && empty($row->break_overtime_review)
            && empty($row->schedule_inferred_side) && (empty($row->raw_clock_in_at) xor empty($row->raw_clock_out_at));
    }

    /**
     * @param  array{scheduledClockIn:?string,includeBeforeStart:bool}  $rule
     */
    protected function applyClockInRule(?string $rawClockInAt, array $rule, string $targetDate): ?string
    {
        if ($rawClockInAt === null || $rule['scheduledClockIn'] === null || $rule['includeBeforeStart']) {
            return $rawClockInAt;
        }

        $raw = CarbonImmutable::parse($rawClockInAt);
        $scheduled = $this->scheduleBoundaryDateTime($targetDate, $rule['scheduledClockIn']);

        return $raw->lessThan($scheduled) ? $scheduled->format('Y-m-d H:i:s') : $rawClockInAt;
    }

    /**
     * @param  array{scheduledClockOut:?string,includeAfterEnd:bool}  $rule
     */
    protected function applyClockOutRule(?string $rawClockOutAt, array $rule, string $targetDate): ?string
    {
        if (empty($rule['policyKind']) || $rawClockOutAt === null || empty($rule['scheduledClockOut']) || $rule['includeAfterEnd']) {
            return $rawClockOutAt;
        }
        $raw = CarbonImmutable::parse($rawClockOutAt);
        $scheduled = $this->scheduleBoundaryDateTime($targetDate, $rule['scheduledClockOut']);
        return $raw->greaterThan($scheduled) ? $scheduled->format('Y-m-d H:i:s') : $rawClockOutAt;
    }

    /**
     * @param  array{scheduledClockOut:?string}  $rule
     * @return array{breaks:array<int,array{start:string,end:string,minutes:int}>,minutes:int}|null
     */
    protected function buildAutoBreak(?CarbonImmutable $clockInAt, ?CarbonImmutable $clockOutAt, array $rule, string $targetDate): ?array
    {
        if ($clockInAt === null || $clockOutAt === null || ! $clockOutAt->greaterThan($clockInAt)) {
            return null;
        }

        if (!empty($rule['policyKind'])) {
            return ConfirmedAttendancePolicy::breaks($clockInAt, $clockOutAt, $rule, $targetDate);
        }

        $breakRule = array_key_exists('activeBreakRule',$rule) ? $rule['activeBreakRule'] : DB::table('attendance_break_rules')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
        $baseBreakMinutes = max(0, (int) ($breakRule?->base_break_minutes ?? 45));
        $thresholdWorkMinutes = max(0, (int) ($breakRule?->threshold_work_minutes ?? 480));
        $maxBreakMinutes = max($baseBreakMinutes, (int) ($breakRule?->threshold_break_minutes ?? 60));
        $boundMinutes = (int) floor($clockInAt->diffInMinutes($clockOutAt));
        if ($baseBreakMinutes <= 0 || $boundMinutes <= $baseBreakMinutes) {
            return null;
        }

        $breaks = [];
        $baseStart = CarbonImmutable::parse($clockInAt->toDateString().' 13:00:00');
        $baseEnd = $baseStart->addMinutes($baseBreakMinutes);
        if (! $baseStart->greaterThanOrEqualTo($clockInAt) || ! $baseEnd->lessThanOrEqualTo($clockOutAt)) {
            $baseStart = $clockInAt->addMinutes((int) floor(($boundMinutes - $baseBreakMinutes) / 2));
            $baseEnd = $baseStart->addMinutes($baseBreakMinutes);
        }

        $breaks[] = [
            'start' => $baseStart->format('Y-m-d H:i:s'),
            'end' => $baseEnd->format('Y-m-d H:i:s'),
            'minutes' => $baseBreakMinutes,
        ];

        $scheduledClockOutAt = $this->scheduleBoundaryDateTime($targetDate, $rule['scheduledClockOut'] ?? null);
        $requiredBreakMinutes = $thresholdWorkMinutes > 0
            ? min($maxBreakMinutes, max($baseBreakMinutes, $boundMinutes - $thresholdWorkMinutes))
            : $baseBreakMinutes;
        $additionalMinutes = max(0, $requiredBreakMinutes - $baseBreakMinutes);
        if ($additionalMinutes > 0) {
            $additionalEnd = $clockOutAt;
            $latestRequiredStart = $clockOutAt->subMinutes($additionalMinutes);
            $additionalStart = $scheduledClockOutAt !== null
                && $clockOutAt->greaterThan($scheduledClockOutAt)
                && $scheduledClockOutAt->greaterThanOrEqualTo($latestRequiredStart)
                    ? $scheduledClockOutAt
                    : $latestRequiredStart;

            $breaks[] = [
                'start' => $additionalStart->format('Y-m-d H:i:s'),
                'end' => $additionalEnd->format('Y-m-d H:i:s'),
                'minutes' => $additionalStart->diffInMinutes($additionalEnd),
            ];
        }

        return [
            'breaks' => $breaks,
            'minutes' => array_sum(array_map(fn (array $break) => (int) $break['minutes'], $breaks)),
        ];
    }

    /**
     * @param  array{breaks:array<int,array{start:string,end:string,minutes:int}>,minutes:int}|null  $autoBreak
     */
    protected function syncAutoBreak(int $dailyId, ?array $autoBreak): void
    {
        DB::table('attendance_daily_breaks')
            ->where('attendance_daily_id', $dailyId)
            ->delete();

        if ($autoBreak === null) {
            return;
        }

        foreach ($autoBreak['breaks'] as $index => $break) {
            DB::table('attendance_daily_breaks')->insert([
                'attendance_daily_id' => $dailyId,
                'segment_no' => $index + 1,
                'break_start_at' => $break['start'],
                'break_end_at' => $break['end'],
                'created_by' => null,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function normalizeTimeForRule(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^(\d{2}:\d{2})/', $value, $matches) === 1) {
            return $matches[1].':00';
        }

        return $value;
    }

    protected function scheduleBoundaryDateTime(string $targetDate, ?string $time): ?CarbonImmutable
    {
        return ConfirmedAttendancePolicy::scheduleBoundary($targetDate, $time);
    }
}
