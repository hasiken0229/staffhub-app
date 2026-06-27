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
        if ($event->receive_status === 'REJECTED' && $event->rejection_reason === 'CARD_NOT_REGISTERED') {
            throw new ApiException('CARD_NOT_REGISTERED', 'このカードは登録されていません。', 400);
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
            'resultType' => 'SUCCESS',
            'resultMessage' => $event->event_type === 'CLOCK_OUT' ? '退勤を記録しました。' : '出勤を記録しました。',
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
        $effectiveClockInAt = $isManuallyEdited ? $existing->clock_in_at : $this->applyClockInRule($clockInAt, $scheduleRule, $targetDate);
        $effectiveClockOutAt = $isManuallyEdited ? $existing->clock_out_at : $this->applyClockOutRule($clockOutAt, $scheduleRule, $targetDate);
        $autoBreak = $isManuallyEdited
            ? null
            : $this->buildAutoBreak(
                $effectiveClockInAt !== null ? CarbonImmutable::parse($effectiveClockInAt) : null,
                $effectiveClockOutAt !== null ? CarbonImmutable::parse($effectiveClockOutAt) : null,
                $scheduleRule,
                $targetDate,
            );
        $breakMinutes = $isManuallyEdited ? (int) ($existing?->break_minutes ?? 0) : (int) ($autoBreak['minutes'] ?? 0);
        $effectiveWorkMinutes = $isManuallyEdited
            ? $existing->work_minutes
            : $this->calculateWorkMinutes(
                $effectiveClockInAt !== null ? CarbonImmutable::parse($effectiveClockInAt) : null,
                $effectiveClockOutAt !== null ? CarbonImmutable::parse($effectiveClockOutAt) : null,
                $breakMinutes
            );

        $values = [
            'employee_id' => $employeeId,
            'target_date' => $targetDate,
            'raw_clock_in_at' => $clockInAt,
            'raw_clock_out_at' => $clockOutAt,
            'clock_in_at' => $effectiveClockInAt,
            'clock_out_at' => $effectiveClockOutAt,
            'break_minutes' => $breakMinutes,
            'work_minutes' => $effectiveWorkMinutes,
            'updated_at' => now(),
        ];

        if (!$isManuallyEdited && $scheduleRule['workTypeId'] !== null) {
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

        if (!$isManuallyEdited) {
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
                'ass.scheduled_clock_in_time',
                'ass.scheduled_clock_out_time',
            ])
            ->first();

        return [
            'workTypeId' => $shift?->work_type_id !== null ? (int) $shift->work_type_id : null,
            'workTypeName' => $shift?->work_type_name ?? null,
            'scheduledClockIn' => $this->normalizeTimeForRule($shift?->scheduled_clock_in_time ?? $setting?->standard_clock_in_time ?? null),
            'scheduledClockOut' => $this->normalizeTimeForRule($shift?->scheduled_clock_out_time ?? $setting?->standard_clock_out_time ?? null),
            'includeBeforeStart' => (bool) ($setting?->include_before_start ?? false),
            'includeAfterEnd' => (bool) ($setting?->include_after_end ?? false),
        ];
    }

    /**
     * @param array{scheduledClockIn:?string,includeBeforeStart:bool} $rule
     */
    protected function applyClockInRule(?string $rawClockInAt, array $rule, string $targetDate): ?string
    {
        if ($rawClockInAt === null || $rule['scheduledClockIn'] === null || $rule['includeBeforeStart']) {
            return $rawClockInAt;
        }

        $raw = CarbonImmutable::parse($rawClockInAt);
        $scheduled = CarbonImmutable::parse($targetDate . ' ' . $rule['scheduledClockIn']);

        return $raw->lessThan($scheduled) ? $scheduled->format('Y-m-d H:i:s') : $rawClockInAt;
    }

    /**
     * @param array{scheduledClockOut:?string,includeAfterEnd:bool} $rule
     */
    protected function applyClockOutRule(?string $rawClockOutAt, array $rule, string $targetDate): ?string
    {
        return $rawClockOutAt;
    }

    /**
     * @param array{scheduledClockOut:?string} $rule
     * @return array{breaks:array<int,array{start:string,end:string,minutes:int}>,minutes:int}|null
     */
    protected function buildAutoBreak(?CarbonImmutable $clockInAt, ?CarbonImmutable $clockOutAt, array $rule, string $targetDate): ?array
    {
        if ($clockInAt === null || $clockOutAt === null || !$clockOutAt->greaterThan($clockInAt)) {
            return null;
        }

        $breakRule = DB::table('attendance_break_rules')
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
        $baseStart = CarbonImmutable::parse($clockInAt->toDateString() . ' 13:00:00');
        $baseEnd = $baseStart->addMinutes($baseBreakMinutes);
        if (!$baseStart->greaterThanOrEqualTo($clockInAt) || !$baseEnd->lessThanOrEqualTo($clockOutAt)) {
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
     * @param array{breaks:array<int,array{start:string,end:string,minutes:int}>,minutes:int}|null $autoBreak
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
            return $matches[1] . ':00';
        }

        return $value;
    }

    protected function scheduleBoundaryDateTime(string $targetDate, ?string $time): ?CarbonImmutable
    {
        if ($time === null || $time === '') {
            return null;
        }

        $boundary = CarbonImmutable::parse($targetDate . ' ' . $time);
        if ($boundary->lessThanOrEqualTo(CarbonImmutable::parse($targetDate . ' 05:00:00'))) {
            return $boundary->addDay();
        }

        return $boundary;
    }
}
