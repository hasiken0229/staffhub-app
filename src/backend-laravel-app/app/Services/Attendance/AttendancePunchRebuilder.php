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

        $workMinutes = null;
        if ($clockInAt !== null && $clockOutAt !== null) {
            $workMinutes = CarbonImmutable::parse($clockOutAt)->diffInMinutes(CarbonImmutable::parse($clockInAt), true);
        }

        $existing = DB::table('attendance_daily')
            ->where('employee_id', $employeeId)
            ->where('target_date', $targetDate)
            ->first();

        $isManuallyEdited = $existing !== null && (bool) ($existing->is_manually_edited ?? false);
        $breakMinutes = (int) ($existing?->break_minutes ?? 0);
        $effectiveClockInAt = $isManuallyEdited ? $existing->clock_in_at : $clockInAt;
        $effectiveClockOutAt = $isManuallyEdited ? $existing->clock_out_at : $clockOutAt;
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

            DB::table('attendance_daily')->insert($values);
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
}
