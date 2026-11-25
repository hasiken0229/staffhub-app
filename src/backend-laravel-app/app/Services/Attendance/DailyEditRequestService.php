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
final class DailyEditRequestService extends AttendanceServiceSupport
{
    public function __construct(private readonly DailyEditService $dailyEditService)
    {
    }
    public function createDailyEditRequest(int $employeeId, array $payload): array
    {
        return DB::transaction(fn () => $this->createDailyEditRequestLocked($employeeId, $payload));
    }

    private function createDailyEditRequestLocked(int $employeeId, array $payload): array
    {
        $targetDate = CarbonImmutable::parse((string) $payload['targetDate'])->startOfDay();
        $this->assertMonthOpen($targetDate->format('Y-m'));

        // The month lock serializes retries and concurrent submissions for the same day.
        $existing = DB::table('attendance_daily_edit_requests')->where('employee_id', $employeeId)
            ->where('target_date', $targetDate->toDateString())->whereIn('status', ['PENDING', 'RETURNED'])
            ->lockForUpdate()->first();
        if ($existing !== null) {
            throw new ApiException('DUPLICATE_REQUEST', 'この日の修正申請はすでにあります。申請状況を確認し、内容を変更する場合は元の申請を取り消してください。', 409, [
                ['field' => 'targetDate', 'message' => '既存の修正申請（ID '.$existing->id.' / '.($existing->status === 'PENDING' ? '承認待ち' : '差戻し').'）を確認してください。'],
            ]);
        }

        $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)
            ->where('target_date', $targetDate->toDateString())->lockForUpdate()->first();
        $baseline = DailyEditRequestReview::snapshot($daily);
        $clockInAt = !array_key_exists('clockInTime', $payload) && !empty($daily->clock_in_at)
            ? CarbonImmutable::parse($daily->clock_in_at)
            : $this->combineTimeInput($targetDate, $payload['clockInTime'] ?? null, (bool) ($payload['clockInNextDay'] ?? false));
        $clockOutAt = !array_key_exists('clockOutTime', $payload) && !empty($daily->clock_out_at)
            ? CarbonImmutable::parse($daily->clock_out_at)
            : $this->combineTimeInput($targetDate, $payload['clockOutTime'] ?? null, (bool) ($payload['clockOutNextDay'] ?? false));
        $breaks = array_key_exists('breaks', $payload)
            ? $this->normalizeBreakPayload($payload['breaks'] ?? [], $targetDate)
            : array_map(static fn ($segment) => [
                'startAt' => $segment[0] ? CarbonImmutable::parse($segment[0]) : null,
                'endAt' => $segment[1] ? CarbonImmutable::parse($segment[1]) : null,
            ], $baseline['breaks']['segments'] ?? []);
        DailyTimeCalculator::validateManualTimes($clockInAt, $clockOutAt, $breaks);
        // Employee time/comment corrections do not imply clearing the work type.
        $workTypeId = isset($payload['workTypeId']) && $payload['workTypeId'] !== null && $payload['workTypeId'] !== ''
            ? (int) $payload['workTypeId']
            : ($daily?->work_type_id !== null ? (int) $daily->work_type_id : null);
        if ($workTypeId !== null && !DB::table('work_type_settings')->where('id', $workTypeId)->exists()) {
            throw new ApiException('VALIDATION_ERROR', '勤務区分が不正です。', 422);
        }

        $id = (int) DB::table('attendance_daily_edit_requests')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => $targetDate->toDateString(),
            'work_type_id' => $workTypeId,
            'daily_baseline' => json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
            'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'),
            'breaks_json' => json_encode(array_map(fn (array $break) => [
                'startTime' => $break['startAt']?->format('H:i'),
                'startNextDay' => $break['startAt'] !== null && $break['startAt']->toDateString() > $targetDate->toDateString(),
                'endTime' => $break['endAt']?->format('H:i'),
                'endNextDay' => $break['endAt'] !== null && $break['endAt']->toDateString() > $targetDate->toDateString(),
            ], $breaks), JSON_UNESCAPED_UNICODE),
            'remark' => array_key_exists('remark', $payload) ? $this->nullableTrim($payload['remark']) : ($daily->remark ?? null),
            'employee_comment' => $this->nullableTrim($payload['employeeComment'] ?? null),
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->addAudit('EMPLOYEE', $employeeId, 'ATTENDANCE_DAILY_EDIT_REQUEST_CREATED', 'ATTENDANCE_DAILY_EDIT_REQUEST', (string) $id, [
            'targetDate' => $targetDate->toDateString(),
        ]);

        return $this->dailyEditRequestDetail($id);
    }

    public function listDailyEditRequestsForEmployee(int $employeeId, array $filters = []): array
    {
        $query = $this->dailyEditRequestQuery()->where('ader.employee_id', $employeeId);
        if (!empty($filters['status'])) {
            $query->where('ader.status', strtoupper((string) $filters['status']));
        }

        return $query->orderByDesc('ader.created_at')->get()->map(fn (object $row) => $this->mapDailyEditRequestRow($row))->all();
    }

    public function cancelDailyEditRequest(int $requestId, int $employeeId): array
    {
        $months = MonthMutationLock::forRecord('attendance_daily_edit_requests', $requestId);
        return DB::transaction(function () use ($months, $requestId, $employeeId) {
            MonthMutationLock::acquire($months);
            $request = DB::table('attendance_daily_edit_requests')->where('id', $requestId)->where('employee_id', $employeeId)->lockForUpdate()->first();
            if ($request === null) { throw new ApiException('NOT_FOUND', '日次修正申請が見つかりません。', 404); }
            if ($request->status === 'CANCELLED') { return $this->dailyEditRequestDetail($requestId); }
            if (!in_array($request->status, ['PENDING', 'RETURNED'], true)) { throw new ApiException('INVALID_STATUS', '承認済みの修正申請は取り消せません。管理者へ確認してください。', 409); }
            DB::table('attendance_daily_edit_requests')->where('id', $requestId)->update(['status'=>'CANCELLED', 'cancelled_at'=>now(), 'updated_at'=>now()]);
            $this->addAudit('EMPLOYEE', $employeeId, 'ATTENDANCE_DAILY_EDIT_REQUEST_CANCELLED', 'ATTENDANCE_DAILY_EDIT_REQUEST', (string) $requestId, ['targetDate'=>$request->target_date, 'previousStatus'=>$request->status]);
            // Cancellation changes only the request and never rewrites a daily attendance row.
            return $this->dailyEditRequestDetail($requestId);
        });
    }

    public function listDailyEditRequestsForAdmin(array $filters = []): array
    {
        $query = $this->dailyEditRequestQuery();
        if (!empty($filters['status'])) {
            $query->where('ader.status', strtoupper((string) $filters['status']));
        }
        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }
        if (!empty($filters['departmentName'])) {
            $query->where('e.department_name', 'like', '%' . $filters['departmentName'] . '%');
        }
        if (!empty($filters['from'])) {
            $query->whereDate('ader.target_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('ader.target_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('ader.created_at')->get()->map(fn (object $row) => $this->mapDailyEditRequestRow($row))->all();
    }

    public function decideDailyEditRequest(int $requestId, string $decision, ?string $comment, GenericUser $actor): array
    {
        $decision = strtoupper($decision);
        if (!in_array($decision, ['APPROVED', 'RETURNED'], true)) {
            throw new ApiException('VALIDATION_ERROR', '不正な承認操作です。', 422);
        }

        $months = MonthMutationLock::forRecord('attendance_daily_edit_requests', $requestId);
        return DB::transaction(function () use ($months, $requestId, $decision, $comment, $actor) {
            MonthMutationLock::acquire($months);
            $request = DB::table('attendance_daily_edit_requests')->where('id', $requestId)->lockForUpdate()->first();
            if ($request === null) {
                throw new ApiException('NOT_FOUND', '日次修正申請が見つかりません。', 404);
            }
            if ($request->status !== 'PENDING') {
                throw new ApiException('VALIDATION_ERROR', 'この日次修正申請は処理済みです。', 422);
            }

            $targetDate = CarbonImmutable::parse((string) $request->target_date);
            $this->assertMonthOpen($targetDate->format('Y-m'));
            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $request->employee_id);

            if ($decision === 'APPROVED') {
                $daily = DB::table('attendance_daily')->where('employee_id', $request->employee_id)
                    ->where('target_date', $request->target_date)->lockForUpdate()->first();
                $review = DailyEditRequestReview::compare($request, DailyEditRequestReview::snapshot($daily));
                if ($review['conflict'] !== null) {
                    throw new ApiException('STALE_DAILY_EDIT_REQUEST', $review['conflict'], 409);
                }
                $dailyId = $daily?->id ?? $this->ensureDailyForManualEdit((int) $request->employee_id, $targetDate);
                $breaks = json_decode((string) ($request->breaks_json ?? '[]'), true);
                $update = [
                    'clockInTime' => $request->clock_in_at ? CarbonImmutable::parse((string) $request->clock_in_at)->format('H:i') : null,
                    'clockInNextDay' => $request->clock_in_at ? CarbonImmutable::parse((string) $request->clock_in_at)->toDateString() > $targetDate->toDateString() : false,
                    'clockOutTime' => $request->clock_out_at ? CarbonImmutable::parse((string) $request->clock_out_at)->format('H:i') : null,
                    'clockOutNextDay' => $request->clock_out_at ? CarbonImmutable::parse((string) $request->clock_out_at)->toDateString() > $targetDate->toDateString() : false,
                    'breaks' => is_array($breaks) ? $breaks : [],
                    'remark' => $request->remark,
                    'supervisorComment' => $comment,
                    'approvalStatus' => 'APPROVED',
                    'approvalComment' => $comment,
                ];
                // Old requests may contain NULL because the employee UI had no
                // work-type field. Preserve that day's type and historical name.
                // Sending an unchanged ID would also replace the historical name
                // with the current master name, so only apply a different type.
                if ($request->work_type_id !== null && (int) $request->work_type_id !== (int) ($daily->work_type_id ?? 0)) {
                    $update['workTypeId'] = (int) $request->work_type_id;
                }
                $fields = ['clock_in_at' => ['clockInTime', 'clockInNextDay'], 'clock_out_at' => ['clockOutTime', 'clockOutNextDay'],
                    'breaks' => ['breaks'], 'work_type_id' => ['workTypeId'], 'remark' => ['remark'], 'supervisor_comment' => ['supervisorComment']];
                foreach ($review['keep'] as $keep) {
                    foreach ($fields[$keep] as $field) { unset($update[$field]); }
                }
                $this->dailyEditService->updateDaily($dailyId, $update, $actor);
            }

            DB::table('attendance_daily_edit_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => $decision,
                    'approved_by' => $operatorId > 0 ? $operatorId : null,
                    'approved_at' => now(),
                    'decision_comment' => $comment,
                    'updated_at' => now(),
                ]);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'ATTENDANCE_DAILY_EDIT_REQUEST_' . $decision,
                'ATTENDANCE_DAILY_EDIT_REQUEST',
                (string) $requestId,
                ['comment' => $comment]
            );

            return $this->dailyEditRequestDetail($requestId);
        });
    }
}
