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
        return [
            'id' => (int) $row->id,
            'employeeId' => (int) $row->employee_id,
            'employeeCode' => $row->employee_code,
            'employeeName' => $row->employee_name,
            'departmentName' => $row->department_name ?? null,
            'locationName' => $row->location_name ?? null,
            'targetDate' => $row->target_date,
            'scheduleName' => $row->schedule_name ?? null,
            'workTypeId' => isset($row->work_type_id) && $row->work_type_id !== null ? (int) $row->work_type_id : null,
            'rawClockInAt' => $row->raw_clock_in_at ? CarbonImmutable::parse($row->raw_clock_in_at)->toIso8601String() : null,
            'rawClockOutAt' => $row->raw_clock_out_at ? CarbonImmutable::parse($row->raw_clock_out_at)->toIso8601String() : null,
            'clockInAt' => $row->clock_in_at ? CarbonImmutable::parse($row->clock_in_at)->toIso8601String() : null,
            'clockOutAt' => $row->clock_out_at ? CarbonImmutable::parse($row->clock_out_at)->toIso8601String() : null,
            'breakMinutes' => isset($row->break_minutes) ? (int) $row->break_minutes : 0,
            'workMinutes' => $row->work_minutes !== null ? (int) $row->work_minutes : null,
            'isManuallyEdited' => (bool) ($row->is_manually_edited ?? false),
            'absenceFlag' => (bool) $row->absence_flag,
            'specialLeaveFlag' => (bool) $row->special_leave_flag,
            'paidLeaveUnit' => $row->paid_leave_unit !== null ? (float) $row->paid_leave_unit : null,
            'hourPaidLeaveMinutes' => isset($row->hour_paid_leave_minutes) ? (int) $row->hour_paid_leave_minutes : 0,
            'childCareLeaveMinutes' => isset($row->child_care_leave_minutes) ? (int) $row->child_care_leave_minutes : 0,
            'nursingCareLeaveMinutes' => isset($row->nursing_care_leave_minutes) ? (int) $row->nursing_care_leave_minutes : 0,
            'remark' => $row->remark ?? null,
            'supervisorComment' => $row->supervisor_comment ?? null,
            'approvalStatus' => $row->approval_status ?? 'PENDING',
            'approvalComment' => $row->approval_comment ?? null,
            'approvedAt' => !empty($row->approved_at) ? CarbonImmutable::parse($row->approved_at)->toIso8601String() : null,
            'closeStatus' => $row->close_status ?? 'OPEN',
            'manualEditedAt' => !empty($row->manual_edited_at) ? CarbonImmutable::parse($row->manual_edited_at)->toIso8601String() : null,
            'breaks' => $this->dailyBreaksForRow((int) $row->id, (string) $row->target_date),
            'alerts' => $alerts,
            'alertSummary' => $this->formatAlertSummary($alerts),
        ];
    }

    protected function mapGridRow(object $row, array $alerts = []): array
    {
        return [
            ...$this->mapDailyRow($row, $alerts),
            'departmentName' => $row->department_name ?? null,
            'employmentType' => $row->employment_type ?? null,
            'workStyleName' => $row->schedule_name ?: $this->inferWorkStyleName($row),
            'graphSegments' => $this->buildGraphSegments($row),
            'rowState' => 'RECORDED',
        ];
    }

    protected function buildMissingCalendarRow(object $employee, CarbonImmutable $targetDate): array
    {
        return [
            'id' => null,
            'employeeId' => (int) $employee->employee_id,
            'employeeCode' => $employee->employee_code,
            'employeeName' => $employee->employee_name,
            'departmentName' => $employee->department_name ?? null,
            'locationName' => $employee->location_name ?? null,
            'targetDate' => $targetDate->toDateString(),
            'scheduleName' => null,
            'workTypeId' => null,
            'rawClockInAt' => null,
            'rawClockOutAt' => null,
            'clockInAt' => null,
            'clockOutAt' => null,
            'breakMinutes' => 0,
            'workMinutes' => null,
            'isManuallyEdited' => false,
            'absenceFlag' => false,
            'specialLeaveFlag' => false,
            'paidLeaveUnit' => null,
            'hourPaidLeaveMinutes' => 0,
            'childCareLeaveMinutes' => 0,
            'nursingCareLeaveMinutes' => 0,
            'remark' => '打刻・休暇実績なし',
            'supervisorComment' => null,
            'approvalStatus' => null,
            'approvalComment' => null,
            'approvedAt' => null,
            'closeStatus' => null,
            'manualEditedAt' => null,
            'employmentType' => $employee->employment_type ?? null,
            'workStyleName' => '実績未登録',
            'graphSegments' => [],
            'rowState' => 'MISSING',
            'alerts' => [],
            'alertSummary' => '-',
        ];
    }

    protected function monthlyCalendarKey(int $employeeId, string $targetDate): string
    {
        return $employeeId . ':' . $targetDate;
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
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.absence_flag',
                'ad.special_leave_flag',
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
        $detail = [
            ...$this->mapDailyRow($row),
            'employmentType' => $row->employment_type ?? null,
            'manualEditedByName' => $row->manual_edited_by_name ?? null,
            'manualEditedByCode' => $row->manual_edited_by_code ?? null,
            'workStyleName' => $row->schedule_name ?: $this->inferWorkStyleName($row),
            'graphSegments' => $this->buildGraphSegments($row),
        ];

        $breaks = DB::table('attendance_daily_breaks')
            ->where('attendance_daily_id', $row->id)
            ->orderBy('segment_no')
            ->get()
            ->map(fn (object $break) => $this->mapBreakRow($break, (string) $row->target_date))
            ->all();

        $detail['breaks'] = $breaks;
        $detail['hasHistories'] = DB::table('attendance_daily_histories')
            ->where('attendance_daily_id', $row->id)
            ->exists();

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
