<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\NotificationMailService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LeaveApplyService extends LeaveRequestSupport
{
    public function __construct(private readonly LeaveLedgerService $ledgerService)
    {
    }

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
                'lt.name as leave_type_name',
                'lr.start_date',
                'lr.end_date',
                'lr.day_unit',
                'lr.half_day_type',
                'lr.quantity_days',
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

        if (!empty($filters['status'])) {
            $query->where('lr.status', strtoupper((string) $filters['status']));
        }

        if (!empty($filters['from'])) {
            $query->whereDate('lr.start_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('lr.end_date', '<=', $filters['to']);
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
            if ((bool) $type->requires_balance) {
                $available = $this->ledgerService->calculateAvailablePaidLeave($employeeId);
                if ($available + 0.0001 < $quantityDays) {
                    throw new ApiException('INSUFFICIENT_PAID_LEAVE', '有給残数が不足しています。', 422, [
                        ['field' => 'leaveTypeCode', 'message' => '有給残数が不足しています。'],
                    ]);
                }
            }

            $requestId = (int) DB::table('leave_requests')->insertGetId([
                'employee_id' => $employeeId,
                'leave_type_code' => $type->code,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'day_unit' => $validated['dayUnit'],
                'half_day_type' => isset($payload['halfDayType']) ? strtoupper((string) $payload['halfDayType']) : null,
                'quantity_days' => $quantityDays,
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


    public function cancelForEmployee(int $employeeId, int $requestId, ?string $comment = null): array
    {
        return DB::transaction(function () use ($employeeId, $requestId, $comment) {
            $request = DB::table('leave_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null || (int) $request->employee_id !== $employeeId) {
                throw new ApiException('NOT_FOUND', '休暇申請が見つかりません。', 404);
            }

            if (!in_array($request->status, ['PENDING', 'RETURNED', 'APPROVED'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'この休暇申請は取消できません。', 422);
            }

            $this->assertRequestMonthOpen($request);

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
            $this->syncAttendanceDailyFromApprovedLeaves($employeeId);
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
