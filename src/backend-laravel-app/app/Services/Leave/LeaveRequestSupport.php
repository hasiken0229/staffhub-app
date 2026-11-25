<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\AuditLogService;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class LeaveRequestSupport
{
    protected function validateLeavePayload(array $payload, ?int $employeeId = null): array
    {
        $requestCategory = strtoupper((string) ($payload['requestCategory'] ?? 'LEAVE'));
        if (! in_array($requestCategory, ['LEAVE', 'TIME_LEAVE'], true)) {
            throw new ApiException('VALIDATION_ERROR', '届出カテゴリが不正です。', 422, [
                ['field' => 'requestCategory', 'message' => 'LEAVE または TIME_LEAVE を指定してください。'],
            ]);
        }

        $leaveTypeCode = strtoupper((string) ($payload['leaveTypeCode'] ?? ''));
        if ($requestCategory === 'TIME_LEAVE') {
            $timeLeaveType = strtoupper((string) ($payload['timeLeaveType'] ?? ''));
            $leaveTypeCode = match ($timeLeaveType) {
                'PAID_HOURLY' => 'PAID',
                'CHILD_CARE_HOURLY' => 'SPECIAL',
                'NURSING_CARE_HOURLY' => 'SPECIAL',
                default => '',
            };
        } else {
            $timeLeaveType = null;
        }

        $typeQuery = DB::table('leave_types')->where('code', $leaveTypeCode);
        if (Schema::hasColumn('leave_types', 'is_active')) {
            $typeQuery->where('is_active', true);
        }

        $type = $typeQuery->first();

        if ($type === null) {
            throw new ApiException('VALIDATION_ERROR', '休暇区分が不正です。', 422, [
                ['field' => 'leaveTypeCode', 'message' => '休暇区分が不正です。'],
            ]);
        }

        if ($requestCategory === 'TIME_LEAVE') {
            if (! in_array($timeLeaveType, ['PAID_HOURLY', 'CHILD_CARE_HOURLY', 'NURSING_CARE_HOURLY'], true)) {
                throw new ApiException('VALIDATION_ERROR', '時間休暇種別が不正です。', 422, [
                    ['field' => 'timeLeaveType', 'message' => '時間休暇種別を選択してください。'],
                ]);
            }

            $targetDate = CarbonImmutable::parse((string) $payload['targetDate']);
            $startTime = $this->validateTimeString($payload['startTime'] ?? null, 'startTime');
            $endTime = $this->validateTimeString($payload['endTime'] ?? null, 'endTime');
            $startAt = CarbonImmutable::parse($targetDate->toDateString().' '.$startTime.':00');
            $endAt = CarbonImmutable::parse($targetDate->toDateString().' '.$endTime.':00');
            if ($endAt->lessThanOrEqualTo($startAt)) {
                throw new ApiException('VALIDATION_ERROR', '時間休暇の終了時刻が不正です。', 422, [
                    ['field' => 'endTime', 'message' => '終了時刻は開始時刻より後にしてください。'],
                ]);
            }

            $quantityMinutes = (int) $endAt->diffInMinutes($startAt, true);
            if (isset($payload['quantityMinutes']) && (int) $payload['quantityMinutes'] !== $quantityMinutes) {
                throw new ApiException('VALIDATION_ERROR', '時間帯と申請分数が一致しません。', 422, [
                    ['field' => 'quantityMinutes', 'message' => '開始・終了時刻から計算した分数と一致させてください。'],
                ]);
            }
            if ($quantityMinutes <= 0 || $quantityMinutes > 24 * 60) {
                throw new ApiException('VALIDATION_ERROR', '時間休暇の分数が不正です。', 422, [
                    ['field' => 'quantityMinutes', 'message' => '1分以上で指定してください。'],
                ]);
            }

            $standardDayMinutes = $this->standardDayMinutes($employeeId, $targetDate);
            if ($quantityMinutes > $standardDayMinutes) {
                throw new ApiException('VALIDATION_ERROR', '時間休暇の分数が1日の所定時間を超えています。', 422, [
                    ['field' => 'quantityMinutes', 'message' => '所定1日分（'.$standardDayMinutes.'分）以内で指定してください。'],
                ]);
            }

            $quantityDays = $quantityMinutes / $standardDayMinutes;

            return [
                'type' => $type,
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => $timeLeaveType,
                'targetDate' => $targetDate,
                'startDate' => $targetDate,
                'endDate' => $targetDate,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'quantityMinutes' => $quantityMinutes,
                'standardDayMinutes' => $standardDayMinutes,
                'dayUnit' => 'HOURLY',
                'quantityDays' => $quantityDays,
            ];
        }

        if (empty($payload['startDate']) || empty($payload['endDate']) || empty($payload['dayUnit'])) {
            throw new ApiException('VALIDATION_ERROR', '休暇申請の必須項目が不足しています。', 422);
        }

        $startDate = CarbonImmutable::parse($payload['startDate']);
        $endDate = CarbonImmutable::parse($payload['endDate']);
        if ($endDate->lessThan($startDate)) {
            throw new ApiException('VALIDATION_ERROR', '開始日と終了日の指定が不正です。', 422, [
                ['field' => 'endDate', 'message' => '終了日は開始日以降にしてください。'],
            ]);
        }

        $dayUnit = strtoupper((string) ($payload['dayUnit'] ?? ''));
        if (! in_array($dayUnit, ['FULL', 'HALF'], true)) {
            throw new ApiException('VALIDATION_ERROR', '休暇単位が不正です。', 422, [
                ['field' => 'dayUnit', 'message' => 'FULL または HALF を指定してください。'],
            ]);
        }

        if ($dayUnit === 'HALF') {
            if (! (bool) $type->allows_half_day) {
                throw new ApiException('VALIDATION_ERROR', 'この休暇区分は半日申請できません。', 422, [
                    ['field' => 'dayUnit', 'message' => 'この休暇区分は半日申請できません。'],
                ]);
            }

            if (! $startDate->isSameDay($endDate)) {
                throw new ApiException('VALIDATION_ERROR', '半日申請は同日のみ指定できます。', 422, [
                    ['field' => 'endDate', 'message' => '半日申請は同日のみ指定できます。'],
                ]);
            }

            $halfDayType = strtoupper((string) ($payload['halfDayType'] ?? ''));
            if (! in_array($halfDayType, ['AM', 'PM'], true)) {
                throw new ApiException('VALIDATION_ERROR', '半日区分は AM または PM を指定してください。', 422, [
                    ['field' => 'halfDayType', 'message' => 'AM または PM を指定してください。'],
                ]);
            }
        } elseif (! empty($payload['halfDayType'])) {
            throw new ApiException('VALIDATION_ERROR', '半日区分は半日申請時のみ指定できます。', 422, [
                ['field' => 'halfDayType', 'message' => '半日申請時のみ指定してください。'],
            ]);
        }

        $quantityDays = $dayUnit === 'HALF'
            ? 0.5
            : max(1, $startDate->diffInDays($endDate) + 1);

        return [
            'type' => $type,
            'requestCategory' => 'LEAVE',
            'timeLeaveType' => null,
            'targetDate' => null,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => null,
            'endTime' => null,
            'quantityMinutes' => null,
            'dayUnit' => $dayUnit,
            'quantityDays' => (float) $quantityDays,
        ];
    }

    protected function loadRequestDetail(int $requestId): ?object
    {
        return DB::table('leave_requests as lr')
            ->join('employees as e', 'e.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.code', '=', 'lr.leave_type_code')
            ->select([
                'lr.id',
                'lr.employee_id',
                'e.name as employee_name',
                'e.employee_code',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
                'lr.leave_type_code',
                DB::raw('COALESCE(lr.attendance_label, lt.name) as leave_type_name'),
                'lr.start_date',
                'lr.end_date',
                'lr.day_unit',
                'lr.half_day_type',
                'lr.quantity_days', 'lr.hourly_day_minutes', 'lr.effective_dates',
                'lr.request_category',
                'lr.time_leave_type',
                'lr.target_date',
                'lr.start_time',
                'lr.end_time',
                'lr.quantity_minutes',
                'lr.reason',
                'lr.status',
                'lr.approved_by',
                'lr.approved_at',
                'lr.decision_comment',
                'lr.created_at',
            ])
            ->where('lr.id', $requestId)
            ->first();
    }

    protected function mapLeaveSummary(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'leaveTypeCode' => $row->leave_type_code,
            'leaveTypeName' => $row->leave_type_name,
            'startDate' => $row->start_date,
            'endDate' => $row->end_date,
            'dayUnit' => $row->day_unit,
            'halfDayType' => $row->half_day_type,
            'quantityDays' => !empty($row->hourly_day_minutes) ? (int) $row->quantity_minutes / $row->hourly_day_minutes : (float) $row->quantity_days,
            'effectiveDates' => isset($row->effective_dates) ? LeaveWorkdays::dates($row) : null,
            'requestCategory' => $row->request_category ?? 'LEAVE',
            'timeLeaveType' => $row->time_leave_type ?? null,
            'targetDate' => $row->target_date ?? null,
            'startTime' => $row->start_time !== null ? substr((string) $row->start_time, 0, 5) : null,
            'endTime' => $row->end_time !== null ? substr((string) $row->end_time, 0, 5) : null,
            'quantityMinutes' => $row->quantity_minutes !== null ? (int) $row->quantity_minutes : null,
            'status' => $row->status,
            'reason' => $row->reason,
            'decisionComment' => $row->decision_comment,
            'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'approvedAt' => $row->approved_at ? CarbonImmutable::parse($row->approved_at)->toIso8601String() : null,
        ];
    }

    protected function mapLeaveDetail(object $request): array
    {
        $actions = DB::table('leave_request_actions as lra')
            ->leftJoin('employees as e', 'e.id', '=', 'lra.action_by')
            ->leftJoin('users as action_admin', function ($join) {
                $join->on('action_admin.id', '=', 'lra.actor_id')->where('lra.actor_type', 'ADMIN');
            })
            ->select([
                'lra.action_type',
                DB::raw("COALESCE(action_admin.name, e.name, '管理者') as action_by_name"),
                'lra.acted_at',
                'lra.comment',
            ])
            ->where('lra.leave_request_id', $request->id)
            ->orderBy('lra.acted_at')
            ->get()
            ->map(fn (object $action) => [
                'actionType' => $action->action_type,
                'actionByName' => $action->action_by_name,
                'actedAt' => CarbonImmutable::parse($action->acted_at)->toIso8601String(),
                'comment' => $action->comment,
            ])
            ->all();

        return [
            'id' => (int) $request->id,
            'employee' => [
                'id' => (int) $request->employee_id,
                'name' => $request->employee_name,
                'employeeCode' => $request->employee_code,
                'departmentName' => $request->department_name,
                'locationName' => $request->location_name,
                'employmentType' => $request->employment_type,
            ],
            'leaveTypeCode' => $request->leave_type_code,
            'leaveTypeName' => $request->leave_type_name,
            'startDate' => $request->start_date,
            'endDate' => $request->end_date,
            'dayUnit' => $request->day_unit,
            'halfDayType' => $request->half_day_type,
            'quantityDays' => !empty($request->hourly_day_minutes) ? (int) $request->quantity_minutes / $request->hourly_day_minutes : (float) $request->quantity_days,
            'effectiveDates' => isset($request->effective_dates) ? LeaveWorkdays::dates($request) : null,
            'requestCategory' => $request->request_category ?? 'LEAVE',
            'timeLeaveType' => $request->time_leave_type ?? null,
            'targetDate' => $request->target_date ?? null,
            'startTime' => $request->start_time !== null ? substr((string) $request->start_time, 0, 5) : null,
            'endTime' => $request->end_time !== null ? substr((string) $request->end_time, 0, 5) : null,
            'quantityMinutes' => $request->quantity_minutes !== null ? (int) $request->quantity_minutes : null,
            'reason' => $request->reason,
            'status' => $request->status,
            'decisionComment' => $request->decision_comment,
            'approvedAt' => $request->approved_at ? CarbonImmutable::parse($request->approved_at)->toIso8601String() : null,
            'actions' => $actions,
        ];
    }

    protected function assertNoOverlappingLeave(int $employeeId, object $candidate, ?int $excludeId = null): void
    {
        $query = DB::table('leave_requests')->where('employee_id', $employeeId)
            ->whereIn('status', ['PENDING', 'RETURNED', 'APPROVED'])
            ->where('start_date', '<=', $candidate->end_date)->where('end_date', '>=', $candidate->start_date);
        if ($excludeId !== null) { $query->where('id', '<>', $excludeId); }
        $dates = LeaveWorkdays::dates($candidate);
        $others = $query->lockForUpdate()->get()->filter(fn ($other) => array_intersect($dates, LeaveWorkdays::dates($other)) !== []);
        foreach ($others as $other) {
            $bothHourly = ($candidate->request_category ?? 'LEAVE') === 'TIME_LEAVE' && ($other->request_category ?? 'LEAVE') === 'TIME_LEAVE';
            $otherStart = substr((string) $other->start_time, 0, 5);
            $otherEnd = substr((string) $other->end_time, 0, 5);
            $knownInterval = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $otherStart)
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $otherEnd) && $otherEnd > $otherStart;
            if (!$bothHourly || !$knownInterval || (substr($candidate->start_time, 0, 5) < $otherEnd && substr($candidate->end_time, 0, 5) > $otherStart)) {
                throw new ApiException('LEAVE_OVERLAP', '同日または同じ時間帯の申請があります。既存申請を確認・取消してください。半日同士も同日重複は受け付けません。', 422);
            }
        }
        if (($candidate->request_category ?? 'LEAVE') === 'TIME_LEAVE') {
            $totalMinutes = (int) $candidate->quantity_minutes + (int) $others->sum('quantity_minutes');
            if ($totalMinutes > $this->standardDayMinutes($employeeId, CarbonImmutable::parse($candidate->start_date))) {
                throw new ApiException('LEAVE_DAILY_LIMIT', '同日の申請中・承認済み時間休暇の合計が所定1日分を超えます。', 422);
            }
        }
        // Imported/manual quantities have no reliable time interval. Never guess that they do not overlap.
        foreach ($dates as $date) {
            $p = DB::table('attendance_leave_projections')->where('employee_id', $employeeId)->where('target_date', $date)->first();
            $baseline = $p ? json_decode($p->baseline_json, true) : app(LeaveAttendanceProjection::class)->fields(
                DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->first());
            if (array_sum(array_map(static fn ($v) => (float) $v, $baseline)) > 0
                && ($p !== null || app(LeaveAttendanceProjection::class)->requests($employeeId, $date) === [])) {
                throw new ApiException('LEAVE_OVERLAP_WITH_RECORDED', $date.' に移行・手修正の休暇記録があります。原本と既存記録を確認してください。', 422);
            }
        }
    }

    protected function createDecisionNotification(int $employeeId, int $requestId, string $decision, ?string $comment): void
    {
        $titleMap = [
            'APPROVED' => '休暇申請が承認されました',
            'REJECTED' => '休暇申請が却下されました',
            'RETURNED' => '休暇申請が差し戻されました',
        ];

        DB::table('notifications')->insert([
            'employee_id' => $employeeId,
            'notification_type' => 'LEAVE_'.$decision,
            'title' => $titleMap[$decision] ?? '休暇申請が更新されました',
            'body' => $comment ?: '休暇申請の状態が更新されました。',
            'related_type' => 'LEAVE_REQUEST',
            'related_id' => $requestId,
            'is_read' => 0,
            'sent_at' => now(),
            'read_at' => null,
        ]);

        DB::afterCommit(function () use ($employeeId, $requestId, $decision, $comment): void {
            app(NotificationMailService::class)->sendLeaveDecision(
                $employeeId,
                $requestId,
                $decision,
                $comment,
            );
        });
    }

    protected function validateTimeString(mixed $value, string $field): string
    {
        $time = trim((string) ($value ?? ''));
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new ApiException('VALIDATION_ERROR', '時刻は HH:mm 形式で指定してください。', 422, [
                ['field' => $field, 'message' => 'HH:mm 形式で入力してください。'],
            ]);
        }

        return $time;
    }

    protected function standardDayMinutes(?int $employeeId = null, ?CarbonImmutable $targetDate = null): int
    {
        if ($employeeId !== null && $targetDate !== null) {
            $daily = DB::table('attendance_daily as ad')
                ->leftJoin('work_type_settings as wt', 'wt.id', '=', 'ad.work_type_id')
                ->where('ad.employee_id', $employeeId)
                ->where('ad.target_date', $targetDate->toDateString())
                ->first(['wt.standard_day_minutes']);

            if ($daily !== null && (int) ($daily->standard_day_minutes ?? 0) > 0) {
                return (int) $daily->standard_day_minutes;
            }

            $employmentStandard = DB::table('employees as e')
                ->leftJoin('employment_type_settings as ets', 'ets.code', '=', 'e.employment_type')
                ->where('e.id', $employeeId)
                ->value('ets.standard_day_minutes');

            if ((int) ($employmentStandard ?? 0) > 0) {
                return (int) $employmentStandard;
            }
        }

        $minutes = DB::table('paid_leave_settings')
            ->where('is_active', 1)
            ->orderBy('id')
            ->value('standard_day_minutes');

        return max(1, (int) ($minutes ?: 480));
    }

    protected function assertRequestMonthOpen(object $request): void
    {
        $date = ($request->request_category ?? 'LEAVE') === 'TIME_LEAVE'
            ? ($request->target_date ?? $request->start_date)
            : $request->start_date;
        $targetMonth = CarbonImmutable::parse((string) $date)->format('Y-m');

        $closed = DB::table('attendance_monthly_closes')
            ->where('target_year_month', $targetMonth)
            ->where('status', 'CLOSED')
            ->exists();

        if ($closed) {
            throw new ApiException('CLOSED_PERIOD', '締め済み月の届出は更新できません。', 422, [
                ['field' => 'targetDate', 'message' => '締め解除後に更新してください。'],
            ]);
        }
    }

    protected function resolveOperatorEmployeeId(GenericUser $actor, int $fallbackEmployeeId): ?int
    {
        if (strtoupper((string) $actor->role) === 'EMPLOYEE') {
            return (int) $actor->id;
        }

        $linked = (int) ($actor->employeeId ?? 0);
        return $linked > 0 && DB::table('employees')->where('id', $linked)->exists() ? $linked : null;
    }

    protected function addAudit(string $actorType, int $actorId, string $action, string $targetType, ?string $targetId, array $detail): void
    {
        app(AuditLogService::class)->record($actorType, $actorId, $action, $targetType, $targetId, $detail);
    }

    protected function assertEmployeeExists(int $employeeId): void
    {
        $exists = DB::table('employees')->where('id', $employeeId)->exists();
        if (! $exists) {
            throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404);
        }
    }
}
