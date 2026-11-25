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
    public function createDaily(int $employeeId, string $targetDateValue, GenericUser $actor): array
    {
        return DB::transaction(function () use ($employeeId, $targetDateValue, $actor) {
            $targetDate = CarbonImmutable::parse($targetDateValue);
            $targetMonth = $targetDate->format('Y-m');
            MonthMutationLock::acquire([$targetMonth]);
            if ($this->isMonthClosed($targetMonth)) {
                throw new ApiException('CLOSED_PERIOD', '締め済み月の日次勤怠は作成できません。', 422, [
                    ['field' => 'targetDate', 'message' => '締め解除後に作成してください。'],
                ]);
            }

            $employee = DB::table('employees')->where('id', $employeeId)->first();
            if ($employee === null) {
                throw new ApiException('VALIDATION_ERROR', '職員が見つかりません。', 422, [
                    ['field' => 'employeeId', 'message' => '存在する職員を指定してください。'],
                ]);
            }

            $existing = DB::table('attendance_daily')
                ->where('employee_id', $employeeId)
                ->where('target_date', $targetDate->toDateString())
                ->first();
            if ($existing !== null) {
                return $this->dailyDetail((int) $existing->id);
            }

            $now = now();
            $dailyId = (int) DB::table('attendance_daily')->insertGetId([
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
                'updated_at' => $now,
            ]);

            $operatorId = $this->resolveOperatorEmployeeId($actor, $employeeId);
            $this->recordDailyHistory($dailyId, 'MANUAL_DAILY_CREATED', 'targetDate', null, $targetDate->toDateString(), $actor, $operatorId, null);
            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'ATTENDANCE_DAILY_MANUAL_CREATED',
                'ATTENDANCE_DAILY',
                (string) $dailyId,
                ['employeeId' => $employeeId, 'targetDate' => $targetDate->toDateString()]
            );

            return $this->dailyDetail($dailyId);
        });
    }

    public function updateDaily(int $dailyId, array $payload, GenericUser $actor): array
    {
        // Requests created before the API enforced the daily table's 255-character limit can still be pending.
        if (mb_strlen((string) ($payload['remark'] ?? '')) > 255) {
            throw new ApiException('VALIDATION_ERROR', '備考を255文字以内に修正して再申請してください。', 422, [
                ['field'=>'remark', 'message'=>'備考は255文字以内で入力してください。'],
            ]);
        }
        $months = MonthMutationLock::forRecord('attendance_daily', $dailyId);
        return DB::transaction(function () use ($months, $dailyId, $payload, $actor) {
            MonthMutationLock::acquire($months);
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
            $before = $this->dailyDetail($dailyId);

            $workTypeId = array_key_exists('workTypeId', $payload) ? ($payload['workTypeId'] !== null && $payload['workTypeId'] !== '' ? (int) $payload['workTypeId'] : null) : $daily->work_type_id;
            $workTypeName = array_key_exists('workTypeId', $payload) ? null : $daily->schedule_name;
            if (array_key_exists('workTypeId', $payload) && $workTypeId !== null) {
                $workTypeName = DB::table('work_type_settings')->where('id', $workTypeId)->value('name');
                if ($workTypeName === null) {
                    throw new ApiException('VALIDATION_ERROR', '勤務区分が不正です。', 422, [
                        ['field' => 'workTypeId', 'message' => '存在する勤務区分を指定してください。'],
                    ]);
                }
            }

            $preserveClock = function (string $field, string $timeKey, string $dayKey) use ($daily, $payload, $targetDate): ?CarbonImmutable {
                $current = !empty($daily->$field) ? CarbonImmutable::parse($daily->$field) : null;
                if (!array_key_exists($timeKey, $payload)) { return $current; }
                $nextDay = array_key_exists($dayKey, $payload) ? (bool) $payload[$dayKey] : ($current !== null && $current->toDateString() > $targetDate->toDateString());
                $requested = $this->combineTimeInput($targetDate, $payload[$timeKey], $nextDay);
                // A round trip through HH:mm must not erase original seconds.
                return $current !== null && $requested !== null && $current->format('Y-m-d H:i') === $requested->format('Y-m-d H:i') ? $current : $requested;
            };
            $clockInAt = $preserveClock('clock_in_at', 'clockInTime', 'clockInNextDay');
            $clockOutAt = $preserveClock('clock_out_at', 'clockOutTime', 'clockOutNextDay');
            $oldBreaks = DB::table('attendance_daily_breaks')->where('attendance_daily_id', $dailyId)->orderBy('segment_no')->get();
            $breaks = array_key_exists('breaks', $payload) ? $this->normalizeBreakPayload($payload['breaks'] ?? [], $targetDate) : $oldBreaks->map(fn ($b) => ['startAt'=>$b->break_start_at ? CarbonImmutable::parse($b->break_start_at) : null, 'endAt'=>$b->break_end_at ? CarbonImmutable::parse($b->break_end_at) : null])->all();
            foreach ($breaks as $index => &$break) {
                $old = $oldBreaks[$index] ?? null;
                foreach (['startAt'=>'break_start_at', 'endAt'=>'break_end_at'] as $key=>$column) {
                    if ($old !== null && !empty($old->$column) && $break[$key]?->format('Y-m-d H:i') === CarbonImmutable::parse($old->$column)->format('Y-m-d H:i')) { $break[$key] = CarbonImmutable::parse($old->$column); }
                }
            }
            unset($break);
            $clockChanged = $clockInAt?->format('Y-m-d H:i:s') !== $daily->clock_in_at || $clockOutAt?->format('Y-m-d H:i:s') !== $daily->clock_out_at;
            $breakChanged = json_encode(array_map(fn ($b) => [$b['startAt']?->format('Y-m-d H:i:s'), $b['endAt']?->format('Y-m-d H:i:s')], $breaks)) !== json_encode($oldBreaks->map(fn ($b) => [$b->break_start_at, $b->break_end_at])->all());
            // Editing an inferred day starts from the times/breaks the editor displayed.
            // Metadata-only saves leave the stored incomplete source untouched.
            if (($clockChanged || $breakChanged) && !empty($before['scheduleInferredSide'])) {
                if (!array_key_exists('clockInTime', $payload)) { $clockInAt = $before['clockInAt'] ? CarbonImmutable::parse($before['clockInAt']) : null; }
                if (!array_key_exists('clockOutTime', $payload)) { $clockOutAt = $before['clockOutAt'] ? CarbonImmutable::parse($before['clockOutAt']) : null; }
                if (!array_key_exists('breaks', $payload)) { $breaks = $this->normalizeBreakPayload($before['breaks'] ?? [], $targetDate); }
                $clockChanged = $clockInAt?->format('Y-m-d H:i:s') !== $daily->clock_in_at || $clockOutAt?->format('Y-m-d H:i:s') !== $daily->clock_out_at;
                $breakChanged = json_encode(array_map(fn ($b) => [$b['startAt']?->format('Y-m-d H:i:s'), $b['endAt']?->format('Y-m-d H:i:s')], $breaks)) !== json_encode($oldBreaks->map(fn ($b) => [$b->break_start_at, $b->break_end_at])->all());
            }
            if ($clockChanged || $breakChanged) { DailyTimeCalculator::validateManualTimes($clockInAt, $clockOutAt, $breaks); }
            $breakMinutes = $this->sumBreakMinutes($breaks);
            // Imported rows can have a break total without observed/segmented break times.
            if (!$breakChanged) { $breakMinutes = (int) $daily->break_minutes; }
            $timeChanged = $clockChanged || $breakChanged;
            $workMinutes = $timeChanged ? $this->calculateWorkMinutes($clockInAt, $clockOutAt, $breakMinutes) : $daily->work_minutes;
            if (!$timeChanged) { $breakMinutes = (int) $daily->break_minutes; }
            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $daily->employee_id);
            $now = now();

            $approvalStatus = isset($payload['approvalStatus']) && $payload['approvalStatus'] !== ''
                ? strtoupper((string) $payload['approvalStatus'])
                : ($timeChanged ? 'PENDING' : ($daily->approval_status ?? 'PENDING'));
            if (!in_array($approvalStatus, ['PENDING', 'APPROVED', 'RETURNED'], true)) {
                throw new ApiException('VALIDATION_ERROR', '承認状態が不正です。', 422, [
                    ['field' => 'approvalStatus', 'message' => 'PENDING, APPROVED, RETURNED のいずれかを指定してください。'],
                ]);
            }

            if ($approvalStatus === 'APPROVED') {
                if (!$timeChanged && $daily->approval_status === 'PENDING') {
                    $projected = $this->projectScheduledPunchFallback($daily);
                    if (!empty($projected->schedule_inferred_side)) {
                        if ($workTypeId != $daily->work_type_id || $workTypeName !== $daily->schedule_name) {
                            throw new ApiException('INCOMPLETE_DAILY','勤務区分を変更する場合は採用する出退勤時刻を確認してください。',422);
                        }
                        $this->freezeSinglePunch($daily, $projected);
                        $clockInAt = CarbonImmutable::parse($projected->clock_in_at);
                        $clockOutAt = CarbonImmutable::parse($projected->clock_out_at);
                        $breakMinutes = $projected->break_minutes;
                        $workMinutes = $projected->work_minutes;
                    } elseif ($this->singlePunchNeedsConfirmation($projected)) {
                        throw new ApiException('INCOMPLETE_DAILY','当日の予定または実際に勤務した時刻を確認してください。',422);
                    }
                }
                $candidate = (object) array_merge((array) $daily, ['clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
                    'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'), 'schedule_name' => $workTypeName, 'work_minutes' => $workMinutes]);
                if (DailyConfirmation::issue($candidate) !== null) {
                    throw new ApiException('INCOMPLETE_DAILY', '採用時刻・実働または公休・全休の区分を確定してから承認してください。', 422);
                }
            }

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'work_type_id' => $workTypeId,
                    ...($timeChanged || $workTypeId != $daily->work_type_id || $workTypeName !== $daily->schedule_name
                        ? ['break_overtime_review' => null] : []),
                    'schedule_name' => $workTypeName,
                    'clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
                    'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'),
                    'break_minutes' => $breakMinutes,
                    'work_minutes' => $workMinutes,
                    'remark' => array_key_exists('remark', $payload) ? $this->nullableTrim($payload['remark']) : $daily->remark,
                    'supervisor_comment' => array_key_exists('supervisorComment', $payload) ? $this->nullableTrim($payload['supervisorComment']) : $daily->supervisor_comment,
                    'approval_status' => $approvalStatus,
                    'approval_comment' => array_key_exists('approvalComment', $payload) ? $this->nullableTrim($payload['approvalComment']) : $daily->approval_comment,
                    'approved_by' => $approvalStatus === 'APPROVED' ? ($daily->approval_status === 'APPROVED' && !$timeChanged ? $daily->approved_by : ($operatorId > 0 ? $operatorId : null)) : null,
                    'approved_at' => $approvalStatus === 'APPROVED' ? ($daily->approval_status === 'APPROVED' && !$timeChanged ? $daily->approved_at : $now) : null,
                    'is_manually_edited' => $timeChanged ? 1 : $daily->is_manually_edited,
                    'manual_edited_by' => $timeChanged ? ($operatorId > 0 ? $operatorId : null) : $daily->manual_edited_by,
                    'manual_edited_at' => $timeChanged ? $now : $daily->manual_edited_at,
                    'updated_at' => $now,
                ]);

            if ($breakChanged) { DB::table('attendance_daily_breaks')->where('attendance_daily_id', $dailyId)->delete(); }
            foreach ($breakChanged ? $breaks : [] as $index => $break) {
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
            // The returned detail must include the history snapshot just recorded.
            $after = $this->dailyDetail($dailyId);
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
        $months = MonthMutationLock::forRecord('attendance_daily', $dailyId);
        return DB::transaction(function () use ($months, $dailyId, $actor) {
            MonthMutationLock::acquire($months);
            $daily = DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->lockForUpdate()
                ->first();

            if ($daily === null) {
                throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
            }

            $targetDate = CarbonImmutable::parse((string) $daily->target_date);
            $targetMonth = $targetDate->format('Y-m');
            if (($daily->close_status ?? self::MONTH_CLOSE_OPEN) === self::MONTH_CLOSE_CLOSED || $this->isMonthClosed($targetMonth)) {
                throw new ApiException('CLOSED_PERIOD', '締め済み月の日次勤怠は更新できません。', 422);
            }

            $before = $this->dailyDetail($dailyId);
            $rule = $this->resolveDailyScheduleRule((int) $daily->employee_id, $targetDate->toDateString());
            $effective = $this->automaticPunchResult($daily->raw_clock_in_at, $daily->raw_clock_out_at, $rule, $targetDate->toDateString());

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'clock_in_at' => $effective['clockInAt'],
                    'clock_out_at' => $effective['clockOutAt'],
                    'work_minutes' => $effective['workMinutes'],
                    'break_minutes' => $effective['breakMinutes'],
                    'work_type_id' => $rule['workTypeId'],
                    'schedule_name' => $rule['workTypeName'],
                    'approval_status' => 'PENDING',
                    'approval_comment' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'is_manually_edited' => 0,
                    'break_overtime_review' => null,
                    'manual_edited_by' => null,
                    'manual_edited_at' => null,
                    'updated_at' => now(),
                ]);

            $this->syncAutoBreak($dailyId, $effective['autoBreak']);

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

        $histories = DB::table('attendance_daily_histories')
            ->where('attendance_daily_id', $dailyId)
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->get();
        // Legacy ADMIN rows contain an unrelated employee snapshot. Recover the
        // display only from the recorded ADMIN id, without rewriting evidence.
        $adminNames = $this->legacyDailyHistoryAdminNames($histories);

        return $histories->map(function (object $row) use ($adminNames): array {
            return [
                'id' => (int) $row->id,
                'actedAt' => CarbonImmutable::parse($row->acted_at)->toIso8601String(),
                'actionType' => $row->action_type,
                'fieldKey' => $row->field_key,
                'fieldLabel' => $this->dailyHistoryFieldLabel((string) $row->field_key),
                'oldValue' => $row->old_value,
                'newValue' => $row->new_value,
                ...$this->dailyHistoryActorPresentation($row, $adminNames),
                'comment' => $row->comment,
            ];
        })
            ->all();
    }
}
