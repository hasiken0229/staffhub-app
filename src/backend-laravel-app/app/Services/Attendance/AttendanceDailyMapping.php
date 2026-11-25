<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceDailyMapping
{
    protected function dailyDetail(int $dailyId): array
    {
        $row = $this->dailyDetailQuery()
            ->where('ad.id', $dailyId)
            ->first();

        if ($row === null) {
            throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
        }

        return $this->mapDailyDetailRow($row);
    }

    protected function mapEventRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'employeeId' => $row->employee_id !== null ? (int) $row->employee_id : null,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'deviceId' => (int) $row->device_id,
            'deviceCode' => $row->device_code,
            'deviceName' => $row->device_name,
            'cardUid' => $row->card_uid,
            'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            'eventType' => $row->event_type,
            'receiveStatus' => $row->receive_status,
            'rejectionReason' => $row->rejection_reason,
            'offlineSaved' => (bool) $row->offline_saved,
        ];
    }

    protected function mapDailyRow(object $row, array $alerts = []): array
    {
        if (!isset($row->review_status)) { app(DailyApprovalReview::class)->annotate(collect([$row])); }
        $row = $this->attendanceDayProjection($row);
        $restored = ConfirmedAttendancePolicy::hrmosSinglePunchResult($row);
        $inferred = $restored !== null ? $restored['inferredSide'] : ($row->schedule_inferred_side ?? null);
        if ($restored === null && $inferred === null && !empty($row->source_system) && empty($row->is_manually_edited)
            && ($row->approval_status ?? '') === 'APPROVED' && (empty($row->raw_clock_in_at) xor empty($row->raw_clock_out_at))) {
            $record = $row->frozen_fallback_record ?? $this->singlePunchApprovalRecords(collect([$row->id]))->get($row->id);
            if ($record && $record['after']['clock_in_at'] === $row->clock_in_at && $record['after']['clock_out_at'] === $row->clock_out_at
                && $record['before']['raw_clock_in_at'] === $row->raw_clock_in_at && $record['before']['raw_clock_out_at'] === $row->raw_clock_out_at) {
                $inferred = $record['inferredSide'];
            }
        }
        if ($inferred === null && empty($row->is_manually_edited) && empty($row->source_system) && empty($row->break_overtime_review)
            && (($row->approval_status ?? '') === 'APPROVED' || ($row->close_status ?? '') === 'CLOSED' || !empty($row->projection_month_closed))
            && !empty($row->clock_in_at) && !empty($row->clock_out_at)
            && (empty($row->raw_clock_in_at) xor empty($row->raw_clock_out_at))) {
            $stamp = CarbonImmutable::parse($row->raw_clock_in_at ?? $row->raw_clock_out_at)->getTimestamp();
            $fromIn = abs(CarbonImmutable::parse($row->clock_in_at)->getTimestamp() - $stamp);
            $fromOut = abs(CarbonImmutable::parse($row->clock_out_at)->getTimestamp() - $stamp);
            if ($fromIn !== $fromOut) { $inferred = $fromIn < $fromOut ? 'CLOCK_OUT' : 'CLOCK_IN'; }
        }
        $note = $inferred ? '予定補完：1回の打刻を'.($inferred === 'CLOCK_IN' ? '退勤' : '出勤').'として採用し、'.($inferred === 'CLOCK_IN' ? '出勤' : '退勤').'を勤務予定で補完' : null;
        if ($note) {
            $alerts[] = ['code'=>'SCHEDULED_PUNCH_FALLBACK','name'=>'予定補完','severity'=>'warning','message'=>$note];
        }
        $breakReview = BreakReviewPolicy::review($row);
        $confirmedBreak = BreakReviewPolicy::confirmation($row);
        $overtime = $confirmedBreak['overtimeMinutes'] ?? $restored['overtimeMinutes'] ?? null;
        if ($breakReview['needsConfirmation']) {
            $alerts[] = ['code'=>'BREAK_REVIEW_REQUIRED','name'=>'休憩取得の確認','severity'=>'warning','message'=>$breakReview['message']];
        }
        return [
            'dayState' => AttendanceDayState::classify($row),
            'scheduleInferredSide' => $inferred,
            'sourceSystem' => $row->source_system ?? null,
            'timeSource' => ($confirmedBreak['confirmationSource'] ?? null) === 'ADMIN_RULE' ? 'RULE'
                : (!empty($row->is_manually_edited) ? 'MANUAL' : ($inferred ? 'SCHEDULE' : (!empty($row->source_system) ? 'IMPORTED' : (($row->raw_clock_in_at !== $row->clock_in_at || $row->raw_clock_out_at !== $row->clock_out_at) ? 'RULE' : 'PUNCH')))),
            'id' => (int) $row->id,
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'departmentName' => $row->department_name ?? null,
            'locationName' => $row->location_name ?? null,
            'targetDate' => $row->target_date,
            'scheduleName' => $row->schedule_name ?? null,
            'workStyleName' => $this->displayWorkStyle($row),
            'workTypeId' => isset($row->work_type_id) && $row->work_type_id !== null ? (int) $row->work_type_id : null,
            'rawClockInAt' => $row->raw_clock_in_at ? CarbonImmutable::parse($row->raw_clock_in_at)->toIso8601String() : null,
            'rawClockOutAt' => $row->raw_clock_out_at ? CarbonImmutable::parse($row->raw_clock_out_at)->toIso8601String() : null,
            'clockInAt' => $row->clock_in_at ? CarbonImmutable::parse($row->clock_in_at)->toIso8601String() : null,
            'clockOutAt' => $row->clock_out_at ? CarbonImmutable::parse($row->clock_out_at)->toIso8601String() : null,
            'breakMinutes' => isset($row->break_minutes) ? (int) $row->break_minutes : 0,
            'breakReview' => $breakReview,
            'breakOvertimeReview' => $confirmedBreak,
            'overtimeMinutesOverride' => $overtime,
            'workMinutes' => $row->work_minutes !== null ? (int) $row->work_minutes : null,
            'overtimeMinutes' => $overtime ?? ($row->work_minutes !== null ? ConfirmedAttendancePolicy::overtime(['employmentType' => $row->employment_type ?? '', 'targetDate' => $row->target_date], (int) $row->work_minutes) : null),
            'isManuallyEdited' => (bool) ($row->is_manually_edited ?? false),
            'absenceFlag' => (bool) $row->absence_flag,
            'specialLeaveFlag' => (bool) $row->special_leave_flag,
            'requestLeaveName' => $row->request_leave_name ?? null,
            'requestLeaveDays' => isset($row->request_leave_days) ? (float) $row->request_leave_days : null,
            'paidLeaveUnit' => $row->paid_leave_unit !== null ? (float) $row->paid_leave_unit : null,
            'hourPaidLeaveMinutes' => isset($row->hour_paid_leave_minutes) ? (int) $row->hour_paid_leave_minutes : 0,
            'childCareLeaveMinutes' => isset($row->child_care_leave_minutes) ? (int) $row->child_care_leave_minutes : 0,
            'nursingCareLeaveMinutes' => isset($row->nursing_care_leave_minutes) ? (int) $row->nursing_care_leave_minutes : 0,
            'childCareLeaveDays' => empty($row->clock_in_at) && empty($row->clock_out_at) && empty($row->child_care_leave_minutes) && str_contains((string) ($row->schedule_name ?? ''), '子の看護') ? 1 : 0,
            'nursingCareLeaveDays' => empty($row->clock_in_at) && empty($row->clock_out_at) && empty($row->nursing_care_leave_minutes) && str_contains((string) ($row->schedule_name ?? ''), '介護休暇') ? 1 : 0,
            'remark' => $note ? trim(($row->remark ?? '').' / '.$note, ' /') : ($row->remark ?? null),
            'supervisorComment' => $row->supervisor_comment ?? null,
            'approvalStatus' => $row->review_status,
            'approvalReviewReason' => $row->review_reason,
            'approvalComment' => $row->approval_comment ?? null,
            'approvedAt' => !empty($row->approved_at) ? CarbonImmutable::parse($row->approved_at)->toIso8601String() : null,
            'closeStatus' => $row->close_status ?? 'OPEN',
            'manualEditedAt' => !empty($row->manual_edited_at) ? CarbonImmutable::parse($row->manual_edited_at)->toIso8601String() : null,
            'breaks' => isset($row->schedule_projected_breaks) ? array_map(fn ($b, $i) => $this->mapBreakRow((object) ['id'=>0, 'segment_no'=>$i+1, 'break_start_at'=>$b['start'], 'break_end_at'=>$b['end']], $row->target_date), $row->schedule_projected_breaks, array_keys($row->schedule_projected_breaks)) : ($row->loaded_breaks ?? $this->dailyBreaksForRow((int) $row->id, (string) $row->target_date)),
            'alerts' => $alerts,
            'alertSummary' => $this->formatAlertSummary($alerts),
        ];
    }

    protected function mapGridRow(object $row, array $alerts = []): array
    {
        $row = $this->projectScheduledPunchFallback($row);
        return [
            ...$this->mapDailyRow($row, $alerts),
            'departmentName' => $row->department_name ?? null,
            'employmentType' => $row->employment_type ?? null,
            'workStyleName' => $this->displayWorkStyle($row),
            'graphSegments' => $this->buildGraphSegments($row),
            'rowState' => 'RECORDED',
        ];
    }

    protected function buildMissingCalendarRow(object $employee, CarbonImmutable $targetDate): array
    {
        $holiday = SaturdayHolidayPolicy::applies((object) ['target_date'=>$targetDate->toDateString(), 'employee_code'=>$employee->employee_code]);
        return [
            'id' => null,
            'employeeId' => (int) $employee->employee_id,
            'employeeCode' => $employee->employee_code,
            'employeeName' => $employee->employee_name,
            'departmentName' => $employee->department_name ?? null,
            'locationName' => $employee->location_name ?? null,
            'targetDate' => $targetDate->toDateString(),
            'scheduleName' => $holiday ? '公休' : null,
            'workTypeId' => null,
            'rawClockInAt' => null,
            'rawClockOutAt' => null,
            'clockInAt' => null,
            'clockOutAt' => null,
            'breakMinutes' => 0,
            'workMinutes' => $holiday ? 0 : null,
            'isManuallyEdited' => false,
            'absenceFlag' => false,
            'specialLeaveFlag' => false,
            'paidLeaveUnit' => null,
            'hourPaidLeaveMinutes' => 0,
            'childCareLeaveMinutes' => 0,
            'nursingCareLeaveMinutes' => 0,
            'remark' => $holiday ? SaturdayHolidayPolicy::NOTE : '打刻・休暇実績なし',
            'supervisorComment' => null,
            'approvalStatus' => null,
            'approvalReviewReason' => null,
            'approvalComment' => null,
            'approvedAt' => null,
            'closeStatus' => null,
            'manualEditedAt' => null,
            'employmentType' => $employee->employment_type ?? null,
            'workStyleName' => $holiday ? '公休' : '実績未登録',
            'graphSegments' => [],
            'rowState' => 'MISSING',
            'alerts' => [],
            'alertSummary' => '-',
        ];
    }

    protected function missingScheduledItem(object $shift): array
    {
        return [...$this->buildMissingCalendarRow($shift, CarbonImmutable::parse($shift->target_date)),
            'scheduleName'=>$shift->schedule_name, 'workStyleName'=>$shift->schedule_name ?: '勤務予定',
            'dayState'=>'MISSING', 'approvalStatus'=>$shift->review_reason === null ? 'NOT_REQUIRED' : 'PENDING', 'approvalReviewReason'=>$shift->review_reason,
            'workMinutes'=>null, 'remark'=>$shift->review_reason];
    }

    protected function monthlyCalendarKey(int $employeeId, string $targetDate): string
    {
        return $employeeId . ':' . $targetDate;
    }

    private function displayWorkStyle(object $row): string
    {
        return ($row->request_leave_name ?? null) ?: (($row->schedule_name ?? null) ?: $this->inferWorkStyleName($row));
    }

    protected function inferWorkStyleName(object $row): string
    {
        if ((bool) ($row->absence_flag ?? false)) {
            return '欠勤';
        }

        if ((bool) ($row->special_leave_flag ?? false)) {
            return '特休';
        }

        if (($row->paid_leave_unit ?? null) !== null) {
            return (float) $row->paid_leave_unit >= 1 ? '有給休暇' : '半日有給';
        }

        return '通常';
    }

    protected function buildGraphSegments(object $row): array
    {
        $segments = [];

        if (!empty($row->clock_in_at) && !empty($row->clock_out_at)) {
            $start = CarbonImmutable::parse($row->clock_in_at);
            $end = CarbonImmutable::parse($row->clock_out_at);

            $segments[] = [
                'kind' => 'WORK',
                'startHour' => (int) $start->format('G'),
                'startMinute' => (int) $start->format('i'),
                'endHour' => (int) $end->format('G'),
                'endMinute' => (int) $end->format('i'),
            ];
        }

        if (($row->paid_leave_unit ?? null) !== null) {
            $segments[] = [
                'kind' => 'PAID_LEAVE',
                'unit' => (float) $row->paid_leave_unit,
            ];
        }

        if ((bool) ($row->special_leave_flag ?? false)) {
            $segments[] = ['kind' => 'SPECIAL_LEAVE'];
        }

        if ((bool) ($row->absence_flag ?? false)) {
            $segments[] = ['kind' => 'ABSENCE'];
        }

        return $segments;
    }

    protected function dailyDetailQuery(): object
    {
        return DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->leftJoin('employees as editor', 'editor.id', '=', 'ad.manual_edited_by')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
                'ad.target_date',
                'ad.schedule_name',
                'ad.work_type_id',
                'ad.harmos_total_work_minutes', 'ad.harmos_leave_summary', 'ad.source_system', 'ad.source_import_type', 'ad.approved_by',
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.break_overtime_review',
                'ad.absence_flag',
                'ad.special_leave_flag', 'ad.request_leave_name', 'ad.request_leave_days',
                'ad.paid_leave_unit',
                'ad.hour_paid_leave_minutes',
                'ad.child_care_leave_minutes',
                'ad.nursing_care_leave_minutes',
                'ad.remark',
                'ad.supervisor_comment',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'ad.close_status',
                'ad.manual_edited_at',
                'editor.name as manual_edited_by_name',
                'editor.employee_code as manual_edited_by_code',
            ]);
    }

    protected function mapDailyDetailRow(object $row): array
    {
        $row = $this->projectScheduledPunchFallback($row);
        $detail = [
            ...$this->mapDailyRow($row),
            'employmentType' => $row->employment_type ?? null,
            'manualEditedByName' => null,
            'manualEditedByCode' => null,
            'workStyleName' => $this->displayWorkStyle($row),
            'graphSegments' => $this->buildGraphSegments($row),
        ];

        $breaks = DB::table('attendance_daily_breaks')
            ->where('attendance_daily_id', $row->id)
            ->orderBy('segment_no')
            ->get()
            ->map(fn (object $break) => $this->mapBreakRow($break, (string) $row->target_date))
            ->all();

        if (!isset($row->schedule_projected_breaks)) { $detail['breaks'] = $breaks; }
        $histories = DB::table('attendance_daily_histories')
            ->where('attendance_daily_id', $row->id)
            ->orderByDesc('acted_at')->orderByDesc('id')->get();
        $detail['hasHistories'] = $histories->isNotEmpty();
        if (!empty($row->manual_edited_at)) {
            $edits = $histories->whereIn('action_type', ['MANUAL_EDITED', 'BREAK_OVERTIME_CONFIRMED']);
            $timeFields = ['clockInAt', 'clockOutAt', 'breakMinutes', 'workMinutes', 'breaks', 'isManuallyEdited', 'breakOvertimeReview'];
            $editorHistory = $edits->first(fn (object $history) => in_array($history->field_key, $timeFields, true)) ?? $edits->first();
            if ($editorHistory !== null) {
                $identity = $this->dailyHistoryActorPresentation($editorHistory, $this->legacyDailyHistoryAdminNames(collect([$editorHistory])));
                $detail['manualEditedByName'] = $identity['actorName'];
                $detail['manualEditedByCode'] = $identity['actorEmployeeCode'];
            }
        }
        $rule = $this->resolveDailyScheduleRule((int) $row->employee_id, (string) $row->target_date);
        $confirmedBreak = $detail['breakOvertimeReview'] !== null;
        $startReason = null;
        if (!$confirmedBreak && empty($row->source_system) && empty($row->is_manually_edited)
            && !empty($rule['punchBreakRule']) && !empty($row->raw_clock_in_at) && !empty($row->raw_clock_out_at)
            && !empty($row->clock_in_at)) {
            $saved = CarbonImmutable::parse($row->clock_in_at);
            // Explain the saved day even if its original schedule or calculation now differs.
            $planned = $rule['regularPunchStart'];
            if (!$planned && $rule['punchBreakRule'] === 'THREE_SHIFT') {
                $planned = ConfirmedAttendancePolicy::nearestThreeShiftStart($row->target_date, CarbonImmutable::parse($row->raw_clock_in_at));
            }
            $startReason = ($planned ? '予定始業 '.substr($planned, 0, 5).'、' : '').'採用始業 '.$saved->format('H:i').'。';
            $calculation = $this->regularPunchCalculation($row->raw_clock_in_at, $row->raw_clock_out_at, $rule, $row->target_date);
            if ($calculation !== null && $calculation['start']->equalTo($saved) && $row->clock_out_at === $row->raw_clock_out_at
                && $calculation['minutes'] === (int) $row->break_minutes
                && isset($row->work_minutes) && DailyTimeCalculator::actual($saved, CarbonImmutable::parse($row->clock_out_at), $calculation['minutes']) === (int) $row->work_minutes) {
                $startReason .= match (true) {
                    $calculation['branch'] === 'RAW' => '原打刻間が9時間1分以上のため、原打刻の始業・退勤と休憩60分で計算しています。',
                    $rule['punchBreakRule'] === 'THREE_SHIFT' => sprintf('予定始業と原打刻のうち遅い方を採用し、個別勤務ルールの休憩%d分で計算しています。', $calculation['minutes']),
                    default => sprintf('予定始業と原打刻のうち遅い方を採用し、%sから追加休憩%d分を計上しています。',
                        $calculation['additionalBreakStart'], $calculation['additionalBreakMinutes']),
                };
            }
        }
        $estimatedSide = $detail['scheduleInferredSide'] ?? null;
        $detail['adoptionReasons'] = array_values(array_filter([
            ($detail['breakOvertimeReview']['confirmationSource'] ?? null) === 'ADMIN_RULE'
                ? '管理者指定の計算ルールを適用。'.$detail['breakReview']['note']
                : ($confirmedBreak ? '確認済みの始業と原打刻の退勤から、実際に取得した休憩を差し引いて計上しています。' : null),
            !$confirmedBreak && !empty($row->is_manually_edited) ? '手動補正の採用時刻・休憩を使用しています。' : null,
            !empty($row->source_system) ? '移行元: '.$row->source_system.'。原本と採用時刻を分けて保持しています。' : null,
            $startReason ?? (!$estimatedSide && !$confirmedBreak && $rule['scheduledClockIn'] ? '予定出勤 '.$rule['scheduledClockIn'].' / 予定より前の時間: '.($rule['includeBeforeStart'] ? '採用' : '予定まで切り上げ') : null),
            !$estimatedSide && !$confirmedBreak && $rule['scheduledClockOut'] ? '予定退勤 '.$rule['scheduledClockOut'].' / 予定より後の時間: '.($rule['includeAfterEnd'] ? '採用' : ($rule['allowLaterShift'] ? '通常は予定まで。後ろ倒し予定または予定開始の翌分以降の出勤で、両打刻がある日は原打刻を採用' : '予定まで切り下げ')) : null,
            $rule['maxWorkMinutes'] !== null && empty($row->source_system) && empty($row->is_manually_edited) ? '自動計算の実働上限: '.$rule['maxWorkMinutes'].'分（休憩控除後）。' : null,
            !$confirmedBreak && $rule['policyVersion'] ? '適用ルール: '.$rule['policyVersion'] : null,
            $estimatedSide ? sprintf('1回の打刻を%sとして採用し、%sを予定の%sで補完しています。', $estimatedSide === 'CLOCK_IN' ? '退勤' : '出勤',
                $estimatedSide === 'CLOCK_IN' ? '出勤' : '退勤', substr($estimatedSide === 'CLOCK_IN' ? $detail['clockInAt'] : $detail['clockOutAt'], 11, 5)) : null,
            AttendanceDayState::isDayOff($row) ? '公休日のため実働0分です。' : null,
            !empty($row->source_system) && empty($row->is_manually_edited) ? '実働・休憩は移行元の保存値を保持しています。現在のルールだけで再計算した値とは異なる場合があります。' : '実働は採用出勤～採用退勤の経過秒を分へ切り捨て、休憩分を差し引きます。',
        ]));

        return $detail;
    }

    protected function mapBreakRow(object $row, string $targetDate): array
    {
        $date = CarbonImmutable::parse($targetDate)->toDateString();
        $startAt = !empty($row->break_start_at) ? CarbonImmutable::parse((string) $row->break_start_at) : null;
        $endAt = !empty($row->break_end_at) ? CarbonImmutable::parse((string) $row->break_end_at) : null;

        return [
            'id' => (int) $row->id,
            'segmentNo' => (int) $row->segment_no,
            'startAt' => $startAt?->toIso8601String(),
            'endAt' => $endAt?->toIso8601String(),
            'startTime' => $startAt?->format('H:i'),
            'endTime' => $endAt?->format('H:i'),
            'startNextDay' => $startAt !== null && $startAt->toDateString() > $date,
            'endNextDay' => $endAt !== null && $endAt->toDateString() > $date,
        ];
    }

    /** Attach one request's immutable break snapshot; never cache it on the service. */
    protected function preloadDailyBreaks(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $byDailyId = DB::table('attendance_daily_breaks')
            ->whereIntegerInRaw('attendance_daily_id', $ids)
            ->orderBy('attendance_daily_id')->orderBy('segment_no')->orderBy('id')
            ->get()->groupBy('attendance_daily_id');
        foreach ($rows as $row) {
            $segments = $byDailyId->get((int) $row->id, collect());
            $row->has_recorded_breaks = $segments->contains(fn ($b) => $b->created_by !== null || $b->updated_by !== null);
            $row->loaded_breaks = $segments
                ->map(fn (object $break) => $this->mapBreakRow($break, (string) $row->target_date))->all();
        }
    }

    protected function dailyBreaksForRow(int $dailyId, string $targetDate): array
    {
        return DB::table('attendance_daily_breaks')
            ->where('attendance_daily_id', $dailyId)
            ->orderBy('segment_no')
            ->get()
            ->map(fn (object $break) => $this->mapBreakRow($break, $targetDate))
            ->all();
    }
}
