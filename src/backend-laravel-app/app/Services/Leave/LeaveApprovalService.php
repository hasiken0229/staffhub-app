<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class LeaveApprovalService extends LeaveRequestSupport
{
    public function __construct(private readonly LeaveLedgerService $ledgerService)
    {
    }

    public function detailForAdmin(int $requestId): array
    {
        $request = $this->loadRequestDetail($requestId);
        if ($request === null) {
            throw new ApiException('NOT_FOUND', '届出が見つかりません。', 404);
        }

        return $this->mapLeaveDetail($request);
    }


    public function listForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 20)));

        $query = DB::table('leave_requests as lr')
            ->join('employees as e', 'e.id', '=', 'lr.employee_id')
            ->join('leave_types as lt', 'lt.code', '=', 'lr.leave_type_code')
            ->leftJoin('employees as approver', 'approver.id', '=', 'lr.approved_by')
            ->select([
                'lr.id',
                'lr.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
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
                'approver.name as approved_by_name',
                'lr.approved_at',
                'lr.decision_comment',
                'lr.created_at',
            ]);

        if (!empty($filters['status'])) {
            $query->where('lr.status', strtoupper((string) $filters['status']));
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['departmentName'])) {
            $query->where('e.department_name', 'like', '%' . $filters['departmentName'] . '%');
        }

        if (!empty($filters['leaveTypeCode'])) {
            $query->where('lr.leave_type_code', strtoupper((string) $filters['leaveTypeCode']));
        }

        if (!empty($filters['requestCategory'])) {
            $query->where('lr.request_category', strtoupper((string) $filters['requestCategory']));
        }

        if (!empty($filters['timeLeaveType'])) {
            $query->where('lr.time_leave_type', strtoupper((string) $filters['timeLeaveType']));
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
            'items' => $rows->map(fn (object $row) => [
                ...$this->mapLeaveSummary($row),
                'employee' => [
                    'id' => (int) $row->employee_id,
                    'employeeCode' => $row->employee_code,
                    'name' => $row->employee_name,
                    'departmentName' => $row->department_name,
                    'locationName' => $row->location_name,
                    'employmentType' => $row->employment_type,
                ],
                'approvedByName' => $row->approved_by_name,
                'decisionComment' => $row->decision_comment,
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function decide(int $requestId, string $decision, ?string $comment, GenericUser $actor): array
    {
        $decision = strtoupper($decision);
        if (!in_array($decision, ['APPROVED', 'REJECTED', 'RETURNED'], true)) {
            throw new ApiException('VALIDATION_ERROR', '不正な承認操作です。', 422);
        }

        return DB::transaction(function () use ($requestId, $decision, $comment, $actor) {
            $request = DB::table('leave_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new ApiException('NOT_FOUND', '休暇申請が見つかりません。', 404);
            }

            if (!in_array($request->status, ['PENDING', 'RETURNED'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'この休暇申請はすでに処理済みです。', 422);
            }

            $this->assertRequestMonthOpen($request);

            $operatorEmployeeId = $this->resolveOperatorEmployeeId($actor, (int) $request->employee_id);
            $approvedAt = $decision === 'APPROVED' ? now() : null;
            $approvedBy = $decision === 'APPROVED' ? $operatorEmployeeId : null;

            DB::table('leave_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => $decision,
                    'approved_by' => $approvedBy,
                    'approved_at' => $approvedAt,
                    'decision_comment' => $comment,
                    'cancelled_by' => null,
                    'cancelled_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('leave_request_actions')->insert([
                'leave_request_id' => $requestId,
                'action_by' => $operatorEmployeeId,
                'action_type' => $decision,
                'comment' => $comment,
                'acted_at' => now(),
            ]);

            $this->ledgerService->recalculatePaidLeaveUsage((int) $request->employee_id);
            $this->syncAttendanceDailyFromApprovedLeaves((int) $request->employee_id);
            $this->ledgerService->syncPaidLeaveLedger((int) $request->employee_id);
            $this->createDecisionNotification((int) $request->employee_id, $requestId, $decision, $comment);

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'LEAVE_REQUEST_' . $decision,
                'LEAVE_REQUEST',
                (string) $requestId,
                ['comment' => $comment]
            );

            return [
                'id' => $requestId,
                'status' => $decision,
                'comment' => $comment,
            ];
        });
    }

    public function bulkDecide(array $requestIds, string $decision, ?string $comment, GenericUser $actor): array
    {
        $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
        if ($requestIds === []) {
            throw new ApiException('VALIDATION_ERROR', '一括処理の対象がありません。', 422, [
                ['field' => 'ids', 'message' => '少なくとも1件選択してください。'],
            ]);
        }

        $items = [];
        foreach ($requestIds as $requestId) {
            $items[] = $this->decide($requestId, $decision, $comment, $actor);
        }

        return [
            'updatedCount' => count($items),
            'items' => $items,
        ];
    }

    public function listWorkProcedures(array $filters): array
    {
        $filters['leaveTypeCode'] = $filters['leaveTypeCode'] ?? null;
        return $this->listForAdmin($filters);
    }

}
