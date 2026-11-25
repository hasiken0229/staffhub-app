<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\Attendance\MonthMutationLock;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LeaveApplyService extends LeaveRequestSupport
{
    public function __construct(private readonly LeaveLedgerService $ledgerService) {}

    public function listForEmployee(int $employeeId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.code', '=', 'lr.leave_type_code')
            ->select([
                'lr.id',
                'lr.employee_id',
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
            ->where('lr.employee_id', $employeeId);

        if (! empty($filters['status'])) {
            $query->where('lr.status', strtoupper((string) $filters['status']));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('lr.end_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('lr.start_date', '<=', $filters['to']);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('lr.created_at')
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => $rows->map(fn (object $row) => $this->mapLeaveSummary($row))->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function createForEmployee(int $employeeId, array $payload): array
    {
        $validated = $this->validateLeavePayload($payload, $employeeId);
        $type = $validated['type'];
        $startDate = $validated['startDate'];
        $endDate = $validated['endDate'];
        $quantityDays = $validated['quantityDays'];

        return DB::transaction(function () use ($employeeId, $payload, $validated, $type, $startDate, $endDate, $quantityDays) {
            MonthMutationLock::acquire(MonthMutationLock::months($startDate->toDateString(), $endDate->toDateString()));
            DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            $type = DB::table('leave_types')->where('code', $type->code)->lockForUpdate()->first();
            if (!$type || !$type->is_active || ($validated['dayUnit'] === 'HALF' && !$type->allows_half_day)) {
                throw new ApiException('LEAVE_TYPE_CHANGED', '休暇区分の設定が変更されました。再読込してください。', 409);
            }
            $effect = $type->attendance_effect ?? (in_array($type->code, ['PAID', 'ABSENCE', 'SPECIAL'], true) ? $type->code : null);
            if ($effect === null) {
                throw new ApiException('LEAVE_TYPE_UNCONFIGURED', 'この休暇区分は勤怠への反映方法が未設定です。管理者に確認してください。', 422);
            }
            $dates = null;
            if ($validated['requestCategory'] === 'LEAVE' && ($type->requires_balance || $type->code === 'PAID')) {
                $plan = LeaveWorkdays::preview($employeeId, $startDate->toDateString(), $endDate->toDateString());
                $dates = $plan['dates'];
                if ($dates === []) {
                    throw new ApiException('NO_LEAVE_WORKDAY', '対象期間はすべて個人の公休日です。', 422);
                }
                if (isset($payload['expectedDates']) && $payload['expectedDates'] !== $dates) {
                    throw new ApiException('LEAVE_DATES_CHANGED', '対象勤務日が変わりました。申請日を再確認してください。', 409);
                }
                $quantityDays = $validated['dayUnit'] === 'HALF' ? 0.5 : count($dates);
            }
            $encodedDates = $dates === null ? null : json_encode($dates, JSON_THROW_ON_ERROR);
            $hourlyBasis = null;
            $this->assertNoOverlappingLeave($employeeId, (object) [
                'effective_dates' => $encodedDates,
                'start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString(),
                'request_category' => $validated['requestCategory'], 'start_time' => $validated['startTime'], 'end_time' => $validated['endTime'],
                'quantity_minutes' => $validated['quantityMinutes'],
            ]);
            if ($validated['timeLeaveType'] === 'PAID_HOURLY') {
                app(HourlyPaidLeaveService::class)->assertAvailable($employeeId, $startDate->toDateString(), (int) $validated['quantityMinutes']);
                if (app(HourlyPaidLeaveService::class)->balance($employeeId, $startDate->toDateString()) !== null) {
                    $quantityDays = 0; // Separately granted hours do not also consume days.
                } else {
                    $hourlyBasis = $validated['standardDayMinutes'];
                    $quantityDays = $validated['quantityMinutes'] / $hourlyBasis;
                }
            }
            $balanceSource = $validated['requestCategory'] === 'TIME_LEAVE'
                ? ($hourlyBasis ? 'PAID' : 'NONE') : (($type->code === 'PAID' || $type->requires_balance) ? 'PAID' : 'NONE');
            if ($balanceSource === 'PAID') {
                $this->ledgerService->assertPaidLeaveAvailable($employeeId, $startDate->toDateString(), $endDate->toDateString(), $quantityDays, null, $dates, $hourlyBasis, $validated['quantityMinutes']);
            }

            $requestId = (int) DB::table('leave_requests')->insertGetId([
                'employee_id' => $employeeId,
                'leave_type_code' => $type->code,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'day_unit' => $validated['dayUnit'],
                'half_day_type' => isset($payload['halfDayType']) ? strtoupper((string) $payload['halfDayType']) : null,
                'quantity_days' => round($quantityDays, 2),
                'effective_dates' => $encodedDates,
                'attendance_effect' => $effect,
                'attendance_label' => $type->name,
                'balance_source' => $balanceSource,
                'hourly_day_minutes' => $hourlyBasis,
                'request_category' => $validated['requestCategory'],
                'time_leave_type' => $validated['timeLeaveType'],
                'target_date' => $validated['targetDate']?->toDateString(),
                'start_time' => $validated['startTime'],
                'end_time' => $validated['endTime'],
                'quantity_minutes' => $validated['quantityMinutes'],
                'reason' => $payload['reason'] ?? null,
                'status' => 'PENDING',
                'approved_by' => null,
                'approved_at' => null,
                'decision_comment' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('leave_request_actions')->insert([
                'leave_request_id' => $requestId,
                'action_by' => $employeeId,
                'action_type' => 'APPLIED',
                'comment' => null,
                'acted_at' => now(),
            ]);

            $this->addAudit('EMPLOYEE', $employeeId, 'LEAVE_REQUEST_CREATED', 'LEAVE_REQUEST', (string) $requestId, [
                'leaveTypeCode' => $type->code,
                'quantityDays' => $quantityDays,
                'requestCategory' => $validated['requestCategory'],
                'quantityMinutes' => $validated['quantityMinutes'],
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
            ]);

            DB::afterCommit(function () use ($employeeId, $requestId, $type, $startDate, $endDate, $quantityDays, $payload): void {
                app(NotificationMailService::class)->sendLeaveRequestCreated(
                    $employeeId,
                    $requestId,
                    (string) $type->name,
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                    (float) $quantityDays,
                    isset($payload['reason']) ? trim((string) $payload['reason']) : null,
                );
            });

            return [
                'id' => $requestId,
                'status' => 'PENDING',
                'quantityDays' => $quantityDays,
                'effectiveDates' => $dates,
                'createdAt' => now()->toIso8601String(),
            ];
        });
    }

    public function detailForEmployee(int $employeeId, int $requestId): array
    {
        $request = $this->loadRequestDetail($requestId);
        if ($request === null || (int) $request->employee_id !== $employeeId) {
            throw new ApiException('NOT_FOUND', '休暇申請が見つかりません。', 404);
        }

        return $this->mapLeaveDetail($request);
    }

    public function standardDayMinutesForEmployee(int $employeeId, ?string $targetDate = null): int
    {
        return $this->standardDayMinutes(
            $employeeId,
            $targetDate ? CarbonImmutable::parse($targetDate) : null,
        );
    }

    public function cancelForEmployee(int $employeeId, int $requestId, ?string $comment = null): array
    {
        $months = MonthMutationLock::forRecord('leave_requests', $requestId, 'start_date', 'end_date');
        return DB::transaction(function () use ($months, $employeeId, $requestId, $comment) {
            MonthMutationLock::acquire($months);
            DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            $request = DB::table('leave_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null || (int) $request->employee_id !== $employeeId) {
                throw new ApiException('NOT_FOUND', '休暇申請が見つかりません。', 404);
            }

            if (! in_array($request->status, ['PENDING', 'RETURNED', 'APPROVED'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'この休暇申請は取消できません。', 422);
            }

            $this->assertRequestMonthOpen($request);
            $wasApproved = $request->status === 'APPROVED';
            if ($wasApproved) {
                app(LeaveAttendanceProjection::class)->prepare($employeeId, LeaveWorkdays::dates($request));
            }

            DB::table('leave_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_by' => $employeeId,
                    'cancelled_at' => now(),
                    'decision_comment' => $comment,
                    'updated_at' => now(),
                ]);

            DB::table('leave_request_actions')->insert([
                'leave_request_id' => $requestId,
                'action_by' => $employeeId,
                'action_type' => 'CANCELLED',
                'comment' => $comment,
                'acted_at' => now(),
            ]);

            $this->ledgerService->recalculatePaidLeaveUsage($employeeId);
            if ($wasApproved) { app(LeaveAttendanceProjection::class)->sync($employeeId, LeaveWorkdays::dates($request)); }
            $this->ledgerService->syncPaidLeaveLedger($employeeId);

            $this->addAudit('EMPLOYEE', $employeeId, 'LEAVE_REQUEST_CANCELLED', 'LEAVE_REQUEST', (string) $requestId, [
                'comment' => $comment,
            ]);

            return [
                'id' => $requestId,
                'status' => 'CANCELLED',
                'comment' => $comment,
            ];
        });
    }
}
