<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceDailyEditRequestMapping
{
    protected function dailyEditRequestQuery(): object
    {
        return DB::table('attendance_daily_edit_requests as ader')
            ->join('employees as e', 'e.id', '=', 'ader.employee_id')
            ->leftJoin('work_type_settings as wt', 'wt.id', '=', 'ader.work_type_id')
            ->leftJoin('employees as approver', 'approver.id', '=', 'ader.approved_by')
            ->select([
                'ader.id',
                'ader.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
                'ader.target_date',
                'ader.work_type_id',
                'wt.name as work_type_name',
                'ader.clock_in_at',
                'ader.clock_out_at',
                'ader.breaks_json',
                'ader.remark',
                'ader.employee_comment',
                'ader.status',
                'ader.approved_by',
                'approver.name as approved_by_name',
                'ader.approved_at',
                'ader.decision_comment',
                'ader.cancelled_at',
                'ader.created_at',
                'ader.updated_at',
            ]);
    }

    protected function dailyEditRequestDetail(int $requestId): array
    {
        $row = $this->dailyEditRequestQuery()
            ->where('ader.id', $requestId)
            ->first();

        if ($row === null) {
            throw new ApiException('NOT_FOUND', '日次修正申請が見つかりません。', 404);
        }

        return $this->mapDailyEditRequestRow($row);
    }

    protected function mapDailyEditRequestRow(object $row): array
    {
        $targetDate = CarbonImmutable::parse((string) $row->target_date)->toDateString();
        $breaks = json_decode((string) ($row->breaks_json ?? '[]'), true);

        return [
            'id' => (int) $row->id,
            'employee' => [
                'id' => (int) $row->employee_id,
                'employeeCode' => $row->employee_code,
                'name' => $row->employee_name,
                'departmentName' => $row->department_name,
                'locationName' => $row->location_name,
                'employmentType' => $row->employment_type,
            ],
            'targetDate' => $targetDate,
            'workTypeId' => $row->work_type_id !== null ? (int) $row->work_type_id : null,
            'workTypeName' => $row->work_type_name,
            'clockInAt' => $row->clock_in_at ? CarbonImmutable::parse((string) $row->clock_in_at)->toIso8601String() : null,
            'clockOutAt' => $row->clock_out_at ? CarbonImmutable::parse((string) $row->clock_out_at)->toIso8601String() : null,
            'clockInTime' => $row->clock_in_at ? CarbonImmutable::parse((string) $row->clock_in_at)->format('H:i') : null,
            'clockInNextDay' => $row->clock_in_at ? CarbonImmutable::parse((string) $row->clock_in_at)->toDateString() > $targetDate : false,
            'clockOutTime' => $row->clock_out_at ? CarbonImmutable::parse((string) $row->clock_out_at)->format('H:i') : null,
            'clockOutNextDay' => $row->clock_out_at ? CarbonImmutable::parse((string) $row->clock_out_at)->toDateString() > $targetDate : false,
            'breaks' => is_array($breaks) ? $breaks : [],
            'remark' => $row->remark,
            'employeeComment' => $row->employee_comment,
            'status' => $row->status,
            'approvedByName' => $row->approved_by_name,
            'approvedAt' => $row->approved_at ? CarbonImmutable::parse((string) $row->approved_at)->toIso8601String() : null,
            'decisionComment' => $row->decision_comment,
            'cancelledAt' => $row->cancelled_at ? CarbonImmutable::parse((string) $row->cancelled_at)->toIso8601String() : null,
            'createdAt' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'updatedAt' => CarbonImmutable::parse((string) $row->updated_at)->toIso8601String(),
        ];
    }

    protected function ensureDailyForManualEdit(int $employeeId, CarbonImmutable $targetDate): int
    {
        $existing = DB::table('attendance_daily')
            ->where('employee_id', $employeeId)
            ->where('target_date', $targetDate->toDateString())
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => $targetDate->toDateString(),
            'clock_in_at' => null,
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'raw_clock_in_at' => null,
            'raw_clock_out_at' => null,
            'is_manually_edited' => 0,
            'work_type_id' => null,
            'supervisor_comment' => null,
            'manual_edited_by' => null,
            'manual_edited_at' => null,
            'remark' => null,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => self::MONTH_CLOSE_OPEN,
            'updated_at' => now(),
        ]);
    }
}
