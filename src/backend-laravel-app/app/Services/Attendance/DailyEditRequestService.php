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
        $targetDate = CarbonImmutable::parse((string) $payload['targetDate'])->startOfDay();
        $this->assertMonthOpen($targetDate->format('Y-m'));

        $clockInAt = $this->combineTimeInput($targetDate, $payload['clockInTime'] ?? null, (bool) ($payload['clockInNextDay'] ?? false));
        $clockOutAt = $this->combineTimeInput($targetDate, $payload['clockOutTime'] ?? null, (bool) ($payload['clockOutNextDay'] ?? false));
        $breaks = $this->normalizeBreakPayload($payload['breaks'] ?? [], $targetDate);
        $workTypeId = isset($payload['workTypeId']) && $payload['workTypeId'] !== null && $payload['workTypeId'] !== ''
            ? (int) $payload['workTypeId']
            : null;
        if ($workTypeId !== null && !DB::table('work_type_settings')->where('id', $workTypeId)->exists()) {
            throw new ApiException('VALIDATION_ERROR', '勤務区分が不正です。', 422);
        }

        $id = (int) DB::table('attendance_daily_edit_requests')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => $targetDate->toDateString(),
            'work_type_id' => $workTypeId,
            'clock_in_at' => $clockInAt?->format('Y-m-d H:i:s'),
            'clock_out_at' => $clockOutAt?->format('Y-m-d H:i:s'),
            'breaks_json' => json_encode(array_map(fn (array $break) => [
                'startTime' => $break['startAt']?->format('H:i'),
                'startNextDay' => $break['startAt'] !== null && $break['startAt']->toDateString() > $targetDate->toDateString(),
                'endTime' => $break['endAt']?->format('H:i'),
                'endNextDay' => $break['endAt'] !== null && $break['endAt']->toDateString() > $targetDate->toDateString(),
            ], $breaks), JSON_UNESCAPED_UNICODE),
            'remark' => $this->nullableTrim($payload['remark'] ?? null),
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

        return DB::transaction(function () use ($requestId, $decision, $comment, $actor) {
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
                $dailyId = $this->ensureDailyForManualEdit((int) $request->employee_id, $targetDate);
                $breaks = json_decode((string) ($request->breaks_json ?? '[]'), true);
                $this->dailyEditService->updateDaily($dailyId, [
                    'workTypeId' => $request->work_type_id,
                    'clockInTime' => $request->clock_in_at ? CarbonImmutable::parse((string) $request->clock_in_at)->format('H:i') : null,
                    'clockInNextDay' => $request->clock_in_at ? CarbonImmutable::parse((string) $request->clock_in_at)->toDateString() > $targetDate->toDateString() : false,
                    'clockOutTime' => $request->clock_out_at ? CarbonImmutable::parse((string) $request->clock_out_at)->format('H:i') : null,
                    'clockOutNextDay' => $request->clock_out_at ? CarbonImmutable::parse((string) $request->clock_out_at)->toDateString() > $targetDate->toDateString() : false,
                    'breaks' => is_array($breaks) ? $breaks : [],
                    'remark' => $request->remark,
                    'supervisorComment' => $comment,
                    'approvalStatus' => 'APPROVED',
                    'approvalComment' => $comment,
                ], $actor);
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
