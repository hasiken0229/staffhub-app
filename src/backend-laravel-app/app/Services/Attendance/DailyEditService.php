<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\AuditLogService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
final class DailyEditService extends AttendanceServiceSupport
{
    public function updateDaily(int $dailyId, array $payload, GenericUser $actor): array
    {
        return DB::transaction(function () use ($dailyId, $payload, $actor) {
            $daily = DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->lockForUpdate()
                ->first();

            if ($daily === null) {
                throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
            }

            $targetDate = CarbonImmutable::parse((string) $daily->target_date);
            if (($daily->close_status ?? self::MONTH_CLOSE_OPEN) === self::MONTH_CLOSE_CLOSED || $this->isMonthClosed($targetDate->format('Y-m'))) {
                throw new ApiException('CLOSED_PERIOD', '締め済み月の日次勤怠は更新できません。', 422, [
                    ['field' => 'id', 'message' => '締め解除後に更新してください。'],
                ]);
            }

            $workTypeId = isset($payload['workTypeId']) && $payload['workTypeId'] !== null && $payload['workTypeId'] !== ''
                ? (int) $payload['workTypeId']
                : null;
            $workTypeName = null;
            if ($workTypeId !== null) {
                $workTypeName = DB::table('work_type_settings')->where('id', $workTypeId)->value('name');
                if ($workTypeName === null) {
                    throw new ApiException('VALIDATION_ERROR', '勤務区分が不正です。', 422, [
                        ['field' => 'workTypeId', 'message' => '存在する勤務区分を指定してください。'],
                    ]);
                }
            }

            $clockInAt = $this->combineTimeInput($targetDate, $payload['clockInTime'] ?? null, (bool) ($payload['clockInNextDay'] ?? false));
            $clockOutAt = $this->combineTimeInput($targetDate, $payload['clockOutTime'] ?? null, (bool) ($payload['clockOutNextDay'] ?? false));
            $breaks = $this->normalizeBreakPayload($payload['breaks'] ?? [], $targetDate);
            $breakMinutes = $this->sumBreakMinutes($breaks);
            $workMinutes = $this->calculateWorkMinutes($clockInAt, $clockOutAt, $breakMinutes);
            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $daily->employee_id);
            $now = now();

            $approvalStatus = isset($payload['approvalStatus']) && $payload['approvalStatus'] !== ''
                ? strtoupper((string) $payload['approvalStatus'])
                : ($daily->approval_status ?? 'PENDING');
            if (!in_array($approvalStatus, ['PENDING', 'APPROVED', 'RETURNED'], true)) {
                throw new ApiException('VALIDATION_ERROR', '承認状態が不正です。', 422, [
                    ['field' => 'approvalStatus', 'message' => 'PENDING, APPROVED, RETURNED のいずれかを指定してください。'],
                ]);
            }

            $before = $this->dailyDetail($dailyId);

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'work_type_id' => $workTypeId,
                    'schedule_name' => $workTypeName,
                    'clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
                    'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'),
                    'break_minutes' => $breakMinutes,
                    'work_minutes' => $workMinutes,
                    'remark' => $this->nullableTrim($payload['remark'] ?? null),
                    'supervisor_comment' => $this->nullableTrim($payload['supervisorComment'] ?? null),
                    'approval_status' => $approvalStatus,
                    'approval_comment' => $this->nullableTrim($payload['approvalComment'] ?? null),
                    'approved_by' => $approvalStatus === 'APPROVED' ? $operatorId : null,
                    'approved_at' => $approvalStatus === 'APPROVED' ? $now : null,
                    'is_manually_edited' => 1,
                    'manual_edited_by' => $operatorId > 0 ? $operatorId : null,
                    'manual_edited_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('attendance_daily_breaks')->where('attendance_daily_id', $dailyId)->delete();
            foreach ($breaks as $index => $break) {
                DB::table('attendance_daily_breaks')->insert([
                    'attendance_daily_id' => $dailyId,
                    'segment_no' => $index + 1,
                    'break_start_at' => $break['startAt']?->format('Y-m-d H:i:s'),
                    'break_end_at' => $break['endAt']?->format('Y-m-d H:i:s'),
                    'created_by' => $operatorId > 0 ? $operatorId : null,
                    'updated_by' => $operatorId > 0 ? $operatorId : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $after = $this->dailyDetail($dailyId);
            $this->recordDailyDiffHistories($dailyId, 'MANUAL_EDITED', $before, $after, $actor, $operatorId, $this->nullableTrim($payload['approvalComment'] ?? null));
            $this->autoResolveClearedErrors($dailyId, $operatorId);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'ATTENDANCE_DAILY_MANUAL_EDITED',
                'ATTENDANCE_DAILY',
                (string) $dailyId,
                [
                    'employeeId' => (int) $daily->employee_id,
                    'targetDate' => $daily->target_date,
                    'approvalStatus' => $approvalStatus,
                ]
            );

            return $after;
        });
    }

    public function resetManualEdit(int $dailyId, GenericUser $actor): array
    {
        return DB::transaction(function () use ($dailyId, $actor) {
            $daily = DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->lockForUpdate()
                ->first();

            if ($daily === null) {
                throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
            }

            $targetMonth = CarbonImmutable::parse((string) $daily->target_date)->format('Y-m');
            if (($daily->close_status ?? self::MONTH_CLOSE_OPEN) === self::MONTH_CLOSE_CLOSED || $this->isMonthClosed($targetMonth)) {
                throw new ApiException('CLOSED_PERIOD', '締め済み月の日次勤怠は更新できません。', 422);
            }

            $before = $this->dailyDetail($dailyId);
            $breakMinutes = (int) ($daily->break_minutes ?? 0);
            $clockInAt = !empty($daily->raw_clock_in_at) ? CarbonImmutable::parse((string) $daily->raw_clock_in_at) : null;
            $clockOutAt = !empty($daily->raw_clock_out_at) ? CarbonImmutable::parse((string) $daily->raw_clock_out_at) : null;

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
                    'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'),
                    'work_minutes' => $this->calculateWorkMinutes($clockInAt, $clockOutAt, $breakMinutes),
                    'is_manually_edited' => 0,
                    'manual_edited_by' => null,
                    'manual_edited_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('attendance_daily_breaks')->where('attendance_daily_id', $dailyId)->delete();

            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $daily->employee_id);
            $after = $this->dailyDetail($dailyId);
            $this->recordDailyDiffHistories($dailyId, 'MANUAL_EDIT_RESET', $before, $after, $actor, $operatorId, null);
            $this->autoResolveClearedErrors($dailyId, $operatorId);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'ATTENDANCE_DAILY_MANUAL_EDIT_RESET',
                'ATTENDANCE_DAILY',
                (string) $dailyId,
                ['employeeId' => (int) $daily->employee_id, 'targetDate' => $daily->target_date]
            );

            return $after;
        });
    }

    public function histories(int $dailyId): array
    {
        if (!DB::table('attendance_daily')->where('id', $dailyId)->exists()) {
            throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
        }

        return DB::table('attendance_daily_histories')
            ->where('attendance_daily_id', $dailyId)
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'actedAt' => CarbonImmutable::parse($row->acted_at)->toIso8601String(),
                'actionType' => $row->action_type,
                'fieldKey' => $row->field_key,
                'fieldLabel' => $this->dailyHistoryFieldLabel((string) $row->field_key),
                'oldValue' => $row->old_value,
                'newValue' => $row->new_value,
                'actorRole' => $row->actor_role,
                'actorEmployeeCode' => $row->actor_employee_code,
                'actorName' => $row->actor_name,
                'comment' => $row->comment,
            ])
            ->all();
    }
}
