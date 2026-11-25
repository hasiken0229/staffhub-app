<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable as Date;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class BreakOvertimeReviewService extends AttendanceServiceSupport
{
    private const BREAK_ERRORS = ['SHORT_BREAK_OVER_8', 'SHORT_BREAK_6_TO_8', 'MISSING_BREAK'];
    private const FIRST_DATE = '2026-04-01';

    public function preview(int $id, array $input, GenericUser $actor): array
    {
        $this->requireAdmin($actor);
        return DB::transaction(fn () => $this->proposal($this->row($id), $input, $actor, false));
    }

    public function apply(int $id, array $input, GenericUser $actor): array
    {
        if (in_array($input['breakBasis'] ?? null, ['SCHEDULE_END', 'REGULAR_PUNCH'], true)) {
            return $this->applyDayRule($id, $input, $actor);
        }
        if (isset($input['breakBasis'])) {
            throw new ApiException('VALIDATION_ERROR', '休憩の計算方法が不正です。', 422);
        }
        return $this->settle($id, $input, $actor, null);
    }

    public function applyDayRule(int $id, array $input, GenericUser $actor): array
    {
        $rule = $input['breakBasis'] ?? null;
        if (!in_array($rule, ['SCHEDULE_END', 'REGULAR_PUNCH', 'RAW_NINE_HOURS', 'RAW_FIXED_60'], true)) {
            throw new ApiException('VALIDATION_ERROR', '当日の休憩ルールを指定してください。', 422);
        }
        return $this->settle($id, $input, $actor, $rule);
    }

    /** Internal application of the administrator's standing rule, without attesting to an observed break. */
    public function applyAdminRule(int $id, string $token, GenericUser $actor): array
    {
        return $this->settle($id, ['token' => $token, 'confirmed' => true, 'startBasis' => 'RULE',
            'breakBasis' => 'REGULAR_PUNCH'], $actor, 'THREE_SHIFT');
    }

    private function settle(int $id, array $input, GenericUser $actor, ?string $ruleKind): array
    {
        $this->requireAdmin($actor);
        if (($input['confirmed'] ?? false) !== true || !is_string($input['token'] ?? null) || $input['token'] === '') {
            throw new ApiException('CONFIRMATION_REQUIRED', '試算と実際の始業・休憩を確認してください。', 422);
        }
        $months = MonthMutationLock::forRecord('attendance_daily', $id);
        return DB::transaction(function () use ($id, $input, $actor, $months, $ruleKind) {
            MonthMutationLock::acquire($months);
            $row = $this->row($id, true);
            if ($ruleKind === 'THREE_SHIFT' && !$this->usesThreeShiftRule($row)) {
                throw new ApiException('VALIDATION_ERROR', 'この職員は指定の勤務ルールの対象外です。', 422);
            }
            $receipt = json_decode($row->break_overtime_review ?? 'null', true);
            $requestData = [(int) $actor->id, $input['token'], $this->choices($input)];
            if ($ruleKind !== null) { $requestData[] = 'ADMIN_RULE'; }
            $requestKey = $this->digest($requestData);
            if (($receipt['requestKey'] ?? null) === $requestKey && BreakReviewPolicy::confirmation($row) !== null) {
                return ['dailyId' => $id, 'workMinutes' => $receipt['workMinutes'], 'overtimeMinutes' => $receipt['overtimeMinutes']];
            }
            $p = $this->proposal($row, $input, $actor, true);
            if (!$p['canApply'] || !hash_equals((string) $p['token'], $input['token'])) {
                throw new ApiException('STALE_PREVIEW', $p['message'] ?? '日次または入力内容が変わりました。再試算してください。', 409);
            }
            $regularPunchRule = in_array($ruleKind, ['REGULAR_PUNCH', 'THREE_SHIFT'], true);
            if ($regularPunchRule) { $ruleKind = $p['ruleKind']; }
            $now = now();
            $start = Date::parse($p['adoptedStart'])->format('Y-m-d H:i:s');
            $operator = $this->resolveOperatorEmployeeId($actor, (int) $row->employee_id);
            $facts = [
                'version' => 1, 'workMinutes' => $p['workMinutes'], 'breakMinutes' => $p['breakMinutes'],
                'clockInAt' => $start, 'clockOutAt' => $row->raw_clock_out_at,
                'rawClockInAt' => $row->raw_clock_in_at, 'rawClockOutAt' => $row->raw_clock_out_at,
                'overtimeMinutes' => $p['overtimeMinutes'], 'plannedStart' => $p['plannedStart'],
                'plannedStartSource' => $p['plannedStartSource'], 'startBasis' => $input['startBasis'],
                'recordedBreakMinutes' => $p['recordedBreakMinutes'], 'branch' => $p['branch'],
                'confirmationSource' => $ruleKind !== null ? 'ADMIN_RULE' : 'ACTUAL',
                'confirmedBy' => (int) $actor->id, 'confirmedAt' => $now->format('Y-m-d H:i:s'), 'requestKey' => $requestKey,
            ];
            if ($ruleKind !== null) {
                $facts['ruleKind'] = $ruleKind;
                if ($ruleKind === 'SCHEDULE_END' || $regularPunchRule) {
                    $facts['scheduledEnd'] = $p['scheduledEnd'];
                    $facts['scheduledEndSource'] = $p['scheduledEndSource'];
                    $facts['additionalBreakMinutes'] = $p['additionalBreakMinutes'];
                }
                if ($regularPunchRule) {
                    $facts['additionalBreakStart'] = $p['additionalBreakStart'];
                    $facts['additionalBreakStartSource'] = $p['additionalBreakStartSource'];
                }
            }
            DB::table('attendance_daily')->where('id', $id)->update([
                'clock_in_at' => $start, 'clock_out_at' => $row->raw_clock_out_at,
                'break_minutes' => $p['breakMinutes'], 'work_minutes' => $p['workMinutes'],
                'break_overtime_review' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'is_manually_edited' => true, 'manual_edited_by' => $operator ?: null, 'manual_edited_at' => $now,
                'updated_at' => $now,
            ]);
            // The total was confirmed, not individual rest timestamps. Keep source intervals intact.
            $before = ['clockInAt' => $row->clock_in_at, 'clockOutAt' => $row->clock_out_at,
                'breakMinutes' => (int) $row->break_minutes, 'workMinutes' => (int) $row->work_minutes];
            $comment = match ($ruleKind) {
                'SCHEDULE_END' => sprintf('予定終業%sからの追加休憩%d分を含む休憩%d分で計上', $p['scheduledEnd'], $p['additionalBreakMinutes'], $p['breakMinutes']),
                'REGULAR_BREAK_WINDOW' => sprintf('予定始業から8時間45分後の%sから追加休憩%d分を含む休憩%d分で計上', $p['additionalBreakStart'], $p['additionalBreakMinutes'], $p['breakMinutes']),
                'RAW_NINE_HOURS' => $regularPunchRule && $input['startBasis'] === 'ACTUAL'
                    ? '原打刻間が9時間1分以上のため確認した始業と休憩60分で計上' : '原打刻間が9時間1分以上のため原打刻と休憩60分で計上',
                'RAW_FIXED_60' => '当日の管理者指定により出退勤の原打刻と休憩60分で計上',
                default => '実際の始業と休憩時間を確認して計上',
            };
            $this->recordDailyDiffHistories($id, 'BREAK_OVERTIME_CONFIRMED', $before,
                array_intersect_key($facts, $before), $actor, $operator, $comment);
            $this->recordDailyHistory($id, 'BREAK_OVERTIME_CONFIRMED', 'breakOvertimeReview', null,
                ['branch' => $p['branch'], 'plannedStart' => $p['plannedStart'], 'startBasis' => $input['startBasis'],
                 'breakMinutes' => $p['breakMinutes'], 'overtimeMinutes' => $p['overtimeMinutes'],
                 'confirmationSource' => $facts['confirmationSource'], 'ruleKind' => $ruleKind], $actor, $operator, $comment);
            $this->autoResolveClearedErrors($id, $operator);
            $this->addAudit('ADMIN', (int) $actor->id, 'BREAK_OVERTIME_CONFIRMED', 'ATTENDANCE_DAILY', (string) $id,
                ['employeeId' => (int) $row->employee_id, 'targetDate' => $row->target_date, 'before' => $before, 'after' => $facts]);
            return ['dailyId' => $id, 'workMinutes' => $p['workMinutes'], 'overtimeMinutes' => $p['overtimeMinutes']];
        }, 3);
    }

    private function requireAdmin(GenericUser $actor): void
    {
        if ($actor->role !== 'ADMIN') { throw new ApiException('FORBIDDEN', '管理者のみ確認できます。', 403); }
    }

    private function row(int $id, bool $lock = false): object
    {
        $query = DB::table('attendance_daily as ad')->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->where('ad.id', $id)->select('ad.*', 'e.employee_code', 'e.employment_type', 'e.is_management_supervisor', 'e.is_dependent_within');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) { throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404); }
        return $row;
    }

    private function choices(array $input): array
    {
        $choices = [$input['plannedStart'] ?? null, $input['startBasis'] ?? null, $input['actualStart'] ?? null,
            (bool) ($input['actualStartNextDay'] ?? false), $input['actualBreakMinutes'] ?? null];
        if (isset($input['breakBasis'])) { $choices[] = $input['breakBasis']; }
        return $choices;
    }

    private function digest(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function usesThreeShiftRule(object $row): bool
    {
        return ConfirmedAttendancePolicy::usesThreeShiftRule((string) $row->employee_code);
    }

    private function schedule(object $row, Date $rawStart, bool $lock): array
    {
        $query = DB::table('attendance_shift_schedules')->where('employee_id', $row->employee_id)->where('target_date', $row->target_date);
        $shift = ($lock ? $query->lockForUpdate() : $query)->first();
        $start = $shift->scheduled_clock_in_time ?? null;
        $end = $shift->scheduled_clock_out_time ?? null;
        $startSource = $start ? '日別シフト' : null;
        $endSource = $end ? '日別シフト' : null;
        $standard = null;
        $typeName = null;
        $fill = static function (?object $type, string $label) use (&$start, &$end, &$startSource, &$endSource, &$standard, &$typeName) {
            if (!$start && !empty($type->start_time)) { $start = $type->start_time; $startSource = $label; }
            if (!$end && !empty($type->end_time)) { $end = $type->end_time; $endSource = $label; }
            $standard ??= $type->standard_day_minutes ?? null;
            $typeName ??= $type->name ?? null;
        };
        foreach (['日別シフトの勤務区分' => $shift->work_type_id ?? null, '当日の勤務区分' => $row->work_type_id] as $label => $id) {
            if (!$id || ($start && $end && $standard !== null)) { continue; }
            $type = DB::table('work_type_settings')->where('id', $id);
            $fill(($lock ? $type->lockForUpdate() : $type)->first(['name', 'start_time', 'end_time', 'standard_day_minutes']), $label);
        }
        if ((!$start || !$end || $standard === null) && !empty($row->schedule_name)) {
            // MySQL's default collation is case- and width-insensitive; trust only one exact name.
            $type = DB::table('work_type_settings')->where('name', $row->schedule_name);
            $matches = ($lock ? $type->lockForUpdate() : $type)->get(['name','start_time','end_time','standard_day_minutes'])
                ->filter(fn ($type) => $type->name === $row->schedule_name);
            if ($matches->count() === 1) { $fill($matches->first(), '当日の勤務区分'); }
        }
        $end = $end ? substr($end, 0, 5) : null;
        if ($start || !$this->usesThreeShiftRule($row) || !$rawStart->isSameDay(Date::parse($row->raw_clock_out_at))
            || ConfirmedAttendancePolicy::requiresIndividualBreakReview(($row->schedule_name ?? '').' '.$typeName)) {
            return [$start ? substr($start, 0, 5) : null, $startSource, $end, $endSource, $standard, $typeName];
        }

        $nearestStart = ConfirmedAttendancePolicy::nearestThreeShiftStart($row->target_date, $rawStart);
        return [$nearestStart, '原出勤打刻に最も近い予定始業の候補（7:45・9:00・9:30）', $end, $endSource, $standard, $typeName];
    }

    private function exclusion(object $r): ?string
    {
        if ($r->target_date < self::FIRST_DATE || $r->target_date > Date::today('Asia/Tokyo')->toDateString()
            || $r->close_status === 'CLOSED' || $this->isMonthClosed(substr($r->target_date, 0, 7))) {
            return '過年度・締め済み・未来の日次は対象外です。';
        }
        if ($r->approval_status !== 'PENDING' || $r->is_manually_edited || !empty($r->break_overtime_review)) {
            return '承認済み・差戻し・補正済みの日次は対象外です。';
        }
        if (in_array((string) $r->employee_code, ['970005', '970017'], true) || $r->is_management_supervisor || $r->employment_type === '管理監督者') {
            return '管理監督者・個別の短時間勤務ルールは対象外です。';
        }
        if (empty($r->raw_clock_in_at) || empty($r->raw_clock_out_at) || $r->raw_clock_out_at <= $r->raw_clock_in_at ||
            empty($r->clock_in_at) || empty($r->clock_out_at) || $r->work_minutes === null || $r->break_minutes === null || (int) $r->break_minutes < 0) {
            return '出退勤の原打刻と計算済みの勤務記録が必要です。';
        }
        if ($r->absence_flag || $r->special_leave_flag || (float) $r->paid_leave_unit > 0 || (float) $r->request_leave_days > 0
            || $r->hour_paid_leave_minutes || $r->child_care_leave_minutes || $r->nursing_care_leave_minutes || AttendanceDayState::hasLeave($r)) {
            return '休暇を含む勤務は個別に確認してください。';
        }
        $codes = array_intersect(self::BREAK_ERRORS, array_column($this->detectDailyErrors($r), 'errorCode'));
        if ($codes) {
            $handled = DB::table('attendance_error_resolutions')->where('employee_id', $r->employee_id)->where('target_date', $r->target_date)
                ->whereIn('error_code', $codes)->whereIn('status', ['RESOLVED','IGNORED'])->pluck('error_code')->all();
            if (array_diff($codes, $handled)) { return null; }
        }
        return '未確認の休憩不足がある勤務だけが対象です。';
    }

    private function proposal(object $row, array $input, GenericUser $actor, bool $lock): array
    {
        $result = ['eligible' => false, 'message' => null, 'plannedStart' => null, 'plannedStartSource' => null,
            'recordedBreakMinutes' => (int) $row->break_minutes, 'branch' => null, 'adoptedStart' => null,
            'rawEnd' => $row->raw_clock_out_at ? Date::parse($row->raw_clock_out_at)->toIso8601String() : null,
            'breakMinutes' => null, 'workMinutes' => null, 'overtimeMinutes' => null, 'token' => null, 'canApply' => false];
        $defaults = ['defaultStartBasis' => null, 'defaultBreakMinutes' => null];
        $reason = $this->exclusion($row);
        if ($reason !== null) { return array_replace($result, ['message' => $reason]) + $defaults; }
        $raw = Date::parse($row->raw_clock_in_at);
        $end = Date::parse($row->raw_clock_out_at);
        $recorded = (int) $row->break_minutes;
        if (DailyTimeCalculator::actual($raw, $end, $recorded) < 480) {
            return array_replace($result, ['message' => '原打刻と当日の休憩から求めた実働が8時間未満です。']) + $defaults;
        }
        [$savedPlan, $source, $scheduledEnd, $endSource, $standard, $typeName] = $this->schedule($row, $raw, $lock);
        $kind = ConfirmedAttendancePolicy::profile($row, $row->target_date)['kind'] ?? null;
        $individualReviewDay = !empty($row->saturday_holiday) || (!$raw->isSameDay($end) && $savedPlan === null)
            || ConfirmedAttendancePolicy::requiresIndividualBreakReview(($row->schedule_name ?? '').' '.$typeName);
        $threeShiftEmployee = $this->usesThreeShiftRule($row);
        $threeShiftRule = $threeShiftEmployee && !$individualReviewDay && ($standard === null || $standard >= 480);
        $regular = !$threeShiftEmployee && $kind === 'REGULAR';
        $windowAvailable = $regular && !$individualReviewDay && $scheduledEnd !== null;
        $ordinaryShift = ($threeShiftRule || ConfirmedAttendancePolicy::usesRegularPunchRule($kind, (string) $row->employee_code, $standard))
            && !$individualReviewDay;
        if ($ordinaryShift && $savedPlan === null) {
            $settings = DB::table('employee_attendance_settings')->where('employee_id', $row->employee_id);
            $standardStart = ($lock ? $settings->lockForUpdate() : $settings)->value('standard_clock_in_time');
            if ($standardStart) {
                $savedPlan = substr($standardStart, 0, 5);
                $source = '職員の標準始業';
            }
        }
        $plan = $input['plannedStart'] ?? $savedPlan;
        $longDay = ConfirmedAttendancePolicy::rawPunchNeedsFullBreak($raw, $end);
        $rawRuleAvailable = $longDay && $regular && !$individualReviewDay
            && ConfirmedAttendancePolicy::usesRawPunchFullBreakRule($kind, (string) $row->employee_code, $standard);
        $punchRuleAvailable = $rawRuleAvailable || ($ordinaryShift && $plan !== null);
        $breakBasis = $input['breakBasis'] ?? null;
        if ($breakBasis !== null && !(($breakBasis === 'REGULAR_PUNCH' && $punchRuleAvailable)
            || ($breakBasis === 'SCHEDULE_END' && $windowAvailable)
            || ($breakBasis === 'RAW_NINE_HOURS' && $regular && $longDay)
            || ($breakBasis === 'RAW_FIXED_60' && $regular))) {
            throw new ApiException('VALIDATION_ERROR', '指定の休憩ルールをこの日次に適用できません。', 422);
        }
        if ($punchRuleAvailable || ($ordinaryShift && $typeName !== null)) {
            $defaults['defaultBreakBasis'] = 'REGULAR_PUNCH';
            $defaults['defaultStartBasis'] = 'RULE';
        } elseif ($windowAvailable && !$ordinaryShift) {
            $defaults['defaultBreakBasis'] = 'SCHEDULE_END';
            $defaults['defaultStartBasis'] = $savedPlan !== null ? 'RULE' : null;
        }
        $result['scheduledEnd'] = $scheduledEnd;
        $result['scheduledEndSource'] = $endSource;
        $result['eligible'] = true;
        $result['plannedStart'] = $plan;
        $result['plannedStartSource'] = $plan !== $savedPlan ? '管理者が入力した当日の予定' : $source;
        $basis = $input['startBasis'] ?? null;
        $useDefaultBreak = $breakBasis === null && $basis === null && !isset($input['actualBreakMinutes']);
        $usePunchRule = $breakBasis === 'REGULAR_PUNCH' || ($punchRuleAvailable && $useDefaultBreak);
        $useEndWindow = $breakBasis === 'SCHEDULE_END' || (!$ordinaryShift && !$rawRuleAvailable && $windowAvailable && $useDefaultBreak);
        $endWindow = ['policyKind'=>'REGULAR', 'scheduledClockOut'=>$scheduledEnd];
        if ($plan !== null && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $plan)) {
            throw new ApiException('VALIDATION_ERROR', '予定始業は HH:mm で指定してください。', 422);
        }
        $scheduled = ConfirmedAttendancePolicy::scheduleBoundary($row->target_date, $plan);
        $auto = null;
        if ($basis !== null && !in_array($basis, ['RULE','ACTUAL'], true)) { throw new ApiException('VALIDATION_ERROR', '始業の確認方法が不正です。', 422); }
        $actualStart = null;
        if ($basis === 'ACTUAL') {
            if (empty($input['actualStart'])) { return array_replace($result, ['message' => '実際に勤務を始めた時刻を入力してください。']) + $defaults; }
            $start = $this->combineTimeInput(Date::parse($row->target_date), $input['actualStart'], (bool) ($input['actualStartNextDay'] ?? false));
            if ($start->format('Y-m-d H:i') === $raw->format('Y-m-d H:i')) { $start = $raw; }
            $actualStart = $start;
        }
        if ($usePunchRule) {
            if (!$longDay && (!$scheduled || $scheduled->greaterThanOrEqualTo($end))) {
                return array_replace($result, ['message' => '当日の予定始業が退勤より前かを確認してください。']) + $defaults;
            }
            $auto = ConfirmedAttendancePolicy::regularPunchRule($raw, $end, $scheduled, $row->target_date, $actualStart);
            $start = $auto['start'];
            $over = $auto['ruleKind'] === 'RAW_NINE_HOURS';
        } elseif ($actualStart !== null) {
            $over = DailyTimeCalculator::actual($actualStart, $end, $recorded) > 480;
        } elseif ($longDay && ($threeShiftRule || ($rawRuleAvailable && $breakBasis === null))) {
            // Nine hours and one elapsed minute between the punches selects the original start.
            $start = $raw;
            $over = true;
        } else {
            if (!$plan) { return array_replace($result, ['message' => '当日の予定始業または実際に勤務を始めた時刻を確認してください。']) + $defaults; }
            if ($scheduled->greaterThanOrEqualTo($end)) { return array_replace($result, ['message' => '当日の予定始業が退勤より前かを確認してください。']) + $defaults; }
            // Use the same elapsed-minute convention as saved attendance; an early punch alone cannot establish early work.
            $trialStart = $raw->max($scheduled);
            $trialBreak = $useEndWindow ? ConfirmedAttendancePolicy::breaks($trialStart, $end, $endWindow, $row->target_date)['minutes'] : $recorded;
            $over = !$threeShiftRule && !($ordinaryShift && $breakBasis === null) && DailyTimeCalculator::actual($trialStart, $end, $trialBreak) > 480;
            $start = $over ? $raw : $trialStart;
        }
        $span = DailyTimeCalculator::span($start, $end);
        $confirmedBreak = $input['actualBreakMinutes'] ?? null;
        if ($confirmedBreak !== null && filter_var($confirmedBreak, FILTER_VALIDATE_INT) === false) { throw new ApiException('VALIDATION_ERROR', '休憩は分単位の整数で指定してください。', 422); }
        $break = (int) ($confirmedBreak ?? $defaults['defaultBreakMinutes'] ?? ($over ? max(60, $recorded) : $recorded));
        if ($auto !== null) {
            $break = $auto['minutes'];
            $result += array_intersect_key($auto, array_flip(['ruleKind', 'additionalBreakStart', 'additionalBreakStartSource', 'additionalBreakMinutes']));
        } elseif (in_array($breakBasis, ['RAW_NINE_HOURS', 'RAW_FIXED_60'], true)) {
            if (!$start->equalTo($raw)) { throw new ApiException('VALIDATION_ERROR', '原打刻の始業を指定してください。', 422); }
            $break = 60;
        } elseif ($useEndWindow) {
            $window = ConfirmedAttendancePolicy::breaks($start, $end, $endWindow, $row->target_date);
            if ($window !== null) {
                $break = $window['minutes'];
                $midday = ConfirmedAttendancePolicy::breaks($start, $end, ['policyKind'=>'REGULAR'], $row->target_date);
                $result['additionalBreakMinutes'] = $break - $midday['minutes'];
            }
        }
        if ($breakBasis !== null && $confirmedBreak !== null && (int) $confirmedBreak !== $break) {
            throw new ApiException('VALIDATION_ERROR', '指定された休憩分と当日の計算結果が一致しません。', 422);
        }
        if ($span === null || $span <= 0 || $break < 0 || $break >= $span) {
            return array_replace($result, ['message' => '始業・退勤と実際に取得した休憩の長さを確認してください。']) + $defaults;
        }
        $result['branch'] = $over ? 'RAW' : 'SCHEDULED';
        if ($auto !== null) {
            $result['branch'] = $auto['branch'];
        } elseif ($breakBasis === null && $actualStart !== null) {
            $result['branch'] = 'ACTUAL';
        } elseif ($breakBasis !== null && $basis === 'ACTUAL') {
            $result['branch'] = $start->equalTo($raw) ? 'RAW' : ($scheduled && $start->equalTo($scheduled) ? 'SCHEDULED' : 'ACTUAL');
        }
        $result['adoptedStart'] = $start->toIso8601String();
        $result['breakMinutes'] = $break;
        $result['workMinutes'] = $span - $break;
        $result['overtimeMinutes'] = max(0, $result['workMinutes'] - 480);
        $result['canApply'] = $basis !== null && ($confirmedBreak !== null || $breakBasis !== null);
        $result['message'] = $result['canApply'] && $result['workMinutes'] > 480 && $break < 60
            ? 'この休憩分で計上すると、休憩不足は引き続き要確認です。' : null;
        if (($auto['ruleKind'] ?? null) === 'REGULAR_BREAK_WINDOW' && $actualStart === null && $result['workMinutes'] > 480) {
            $result['canApply'] = false;
            $result['message'] = '予定の休憩枠では実働が8時間を超えます。実際の始業・休憩時間を確認してください。';
        }
        // An older screen describes only a break after the saved end. It cannot confirm a different rule.
        if ($breakBasis === 'SCHEDULE_END' && $punchRuleAvailable) {
            $current = ConfirmedAttendancePolicy::regularPunchRule($raw, $end, $scheduled, $row->target_date, $actualStart);
            if ($current['additionalBreakStart'] !== $scheduledEnd || !$current['start']->equalTo($start) || $current['minutes'] !== $break) {
                $result['canApply'] = false;
                $result['message'] = '休憩の計算ルールが更新されました。画面を再読み込みして試算してください。';
            }
        }
        // Bind the actual input, source values, proposal and actor; a later plan change invalidates even a manual override.
        $result['token'] = $this->digest([(array) $row, $savedPlan, $source, $this->choices($input), $result, (int) $actor->id]);
        return $result + $defaults;
    }
}
