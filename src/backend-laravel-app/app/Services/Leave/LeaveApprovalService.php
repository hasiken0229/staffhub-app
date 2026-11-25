<?php

namespace App\Services\Leave;

use App\Services\ApiException;
use App\Services\Attendance\MonthMutationLock;
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
            ->leftJoin('users as admin_approver', function ($join) {
                $join->on('admin_approver.id', '=', 'lr.approved_actor_id')->where('lr.approved_actor_type', 'ADMIN');
            })
            ->select([
                'lr.id',
                'lr.employee_id',
                'e.employee_code',
                'e.name as employee_name',
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
                DB::raw('COALESCE(admin_approver.name, approver.name) as approved_by_name'),
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
            $query->whereDate('lr.end_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('lr.start_date', '<=', $filters['to']);
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

        $months = MonthMutationLock::forRecord('leave_requests', $requestId, 'start_date', 'end_date');
        $employeeId = DB::table('leave_requests')->where('id', $requestId)->value('employee_id');
        return DB::transaction(function () use ($months, $requestId, $decision, $comment, $actor, $employeeId) {
            MonthMutationLock::acquire($months);
            if ($employeeId !== null) { DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first(); }
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
            if ($decision === 'APPROVED') {
                if ($request->attendance_effect === null && !in_array($request->leave_type_code, ['PAID', 'SPECIAL', 'ABSENCE'], true)) {
                    throw new ApiException('LEAVE_TYPE_REVIEW_REQUIRED', '旧方式で申請された独自休暇です。勤怠反映・消化先の設定を確認して再申請してください。', 422);
                }
                $checkDates = $request->effective_dates !== null
                    || ($request->attendance_effect === null && $request->leave_type_code === 'PAID' && $request->request_category !== 'TIME_LEAVE');
                if ($checkDates && LeaveWorkdays::dates($request) !== LeaveWorkdays::preview((int) $request->employee_id, $request->start_date, $request->end_date)['dates']) {
                    throw new ApiException('LEAVE_DATES_CHANGED', '申請後に対象勤務日が変わりました。対象日を確認して再申請してください。', 409);
                }
                $this->assertNoOverlappingLeave((int) $request->employee_id, $request, $requestId);
                if (($request->balance_source ?? ($request->leave_type_code === 'PAID' ? 'PAID' : 'NONE')) === 'PAID' && ($request->hourly_day_minutes || (float) $request->quantity_days > 0)) {
                    $this->ledgerService->assertPaidLeaveAvailable((int) $request->employee_id, $request->start_date,
                        $request->end_date, (float) $request->quantity_days, $requestId, LeaveWorkdays::dates($request), $request->hourly_day_minutes, $request->quantity_minutes);
                }
                app(LeaveAttendanceProjection::class)->prepare((int) $request->employee_id, LeaveWorkdays::dates($request));
            }
            if ($decision === 'APPROVED' && $request->time_leave_type === 'PAID_HOURLY') {
                app(HourlyPaidLeaveService::class)->assertAvailable((int) $request->employee_id, $request->start_date, (int) $request->quantity_minutes, $requestId);
                $managed = app(HourlyPaidLeaveService::class)->balance((int) $request->employee_id, $request->start_date) !== null;
                if ($managed && ($request->hourly_day_minutes || (float) $request->quantity_days > 0)) {
                    throw new ApiException('HOURLY_BALANCE_CHANGED', '申請後に時間有給の管理方法が変わりました。残数を確認して取消・再申請してください。', 422);
                }
            }

            $operatorEmployeeId = $this->resolveOperatorEmployeeId($actor, (int) $request->employee_id);
            $approvedAt = $decision === 'APPROVED' ? now() : null;
            $approvedBy = $decision === 'APPROVED' ? $operatorEmployeeId : null;

            DB::table('leave_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => $decision,
                    'approved_by' => $approvedBy,
                    'approved_actor_type' => $decision === 'APPROVED' ? 'ADMIN' : null,
                    'approved_actor_id' => $decision === 'APPROVED' ? (int) $actor->id : null,
                    'approved_at' => $approvedAt,
                    'decision_comment' => $comment,
                    'cancelled_by' => null,
                    'cancelled_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('leave_request_actions')->insert([
                'leave_request_id' => $requestId,
                'action_by' => $operatorEmployeeId,
                'actor_type' => 'ADMIN',
                'actor_id' => (int) $actor->id,
                'action_type' => $decision,
                'comment' => $comment,
                'acted_at' => now(),
            ]);

            $this->ledgerService->recalculatePaidLeaveUsage((int) $request->employee_id);
            if ($decision === 'APPROVED') { app(LeaveAttendanceProjection::class)->sync((int) $request->employee_id, LeaveWorkdays::dates($request)); }
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

        return \App\Services\BatchOperation::run($requestIds, fn ($requestId) => $this->decide($requestId, $decision, $comment, $actor));
    }

    public function listWorkProcedures(array $filters): array
    {
        $filters['leaveTypeCode'] = $filters['leaveTypeCode'] ?? null;
        return $this->listForAdmin($filters);
    }

}
