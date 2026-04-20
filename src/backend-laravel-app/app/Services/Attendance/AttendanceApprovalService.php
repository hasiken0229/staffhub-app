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
final class AttendanceApprovalService extends AttendanceServiceSupport
{
    public function listApprovals(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(10000, (int) ($filters['perPage'] ?? 50)));
        $status = strtoupper((string) ($filters['status'] ?? 'PENDING'));

        $query = DB::table('attendance_daily as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select([
                'ad.id',
                'ad.employee_id',
                'e.employee_code',
                'e.name as employee_name',
                'e.department_name',
                'e.location_name',
                'e.employment_type',
                'ad.target_date',
                'ad.schedule_name',
                'ad.work_type_id',
                'ad.raw_clock_in_at',
                'ad.raw_clock_out_at',
                'ad.clock_in_at',
                'ad.clock_out_at',
                'ad.break_minutes',
                'ad.work_minutes',
                'ad.is_manually_edited',
                'ad.absence_flag',
                'ad.special_leave_flag',
                'ad.paid_leave_unit',
                'ad.hour_paid_leave_minutes',
                'ad.child_care_leave_minutes',
                'ad.nursing_care_leave_minutes',
                'ad.remark',
                'ad.supervisor_comment',
                'ad.approval_status',
                'ad.approval_comment',
                'ad.approved_at',
                'ad.close_status',
                'ad.manual_edited_at',
            ]);

        if ($status !== 'ALL') {
            $query->where('ad.approval_status', $status);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('ad.target_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('ad.target_date', '<=', $filters['to']);
        }

        if (!empty($filters['employeeCode'])) {
            $query->where('e.employee_code', 'like', '%' . $filters['employeeCode'] . '%');
        }

        if (!empty($filters['departmentName'])) {
            $query->where('e.department_name', 'like', '%' . $filters['departmentName'] . '%');
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderBy('ad.target_date')
            ->orderBy('e.employee_code')
            ->forPage($page, $perPage)
            ->get();
        $alertSettings = $this->resolveAttendanceAlertSettings();
        $shortIntervalAlertMap = $this->buildShortIntervalAlertMap($rows, $alertSettings);

        return [
            'items' => $rows->map(fn (object $row) => [
                ...$this->mapDailyRow($row, $this->buildAlertsForRow($row, $shortIntervalAlertMap, $alertSettings)),
                'departmentName' => $row->department_name,
                'employmentType' => $row->employment_type,
                'overtimeMinutes' => max(0, ((int) ($row->work_minutes ?? 0)) - 8 * 60),
            ])->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function decideApproval(int $dailyId, string $decision, ?string $comment, GenericUser $actor): array
    {
        $decision = strtoupper($decision);
        if (!in_array($decision, ['APPROVED', 'RETURNED'], true)) {
            throw new ApiException('VALIDATION_ERROR', '不正な承認操作です。', 422);
        }

        return DB::transaction(function () use ($dailyId, $decision, $comment, $actor) {
            $daily = DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->lockForUpdate()
                ->first();

            if ($daily === null) {
                throw new ApiException('NOT_FOUND', '日次勤怠が見つかりません。', 404);
            }

            if (($daily->close_status ?? self::MONTH_CLOSE_OPEN) === self::MONTH_CLOSE_CLOSED || $this->isMonthClosed(CarbonImmutable::parse((string) $daily->target_date)->format('Y-m'))) {
                throw new ApiException('CLOSED_PERIOD', '締め済み月の日次勤怠は更新できません。', 422, [
                    ['field' => 'id', 'message' => '締め解除後に更新してください。'],
                ]);
            }

            $previousStatus = $daily->approval_status ?? 'PENDING';
            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $daily->employee_id);

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'approval_status' => $decision,
                    'approval_comment' => $comment,
                    'approved_by' => $operatorId,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->recordDailyHistory(
                $dailyId,
                'ATTENDANCE_DAILY_' . $decision,
                'approvalStatus',
                $previousStatus,
                $decision,
                $actor,
                $operatorId,
                $comment
            );

            $this->addAudit(
                strtoupper((string) $actor->role) === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
                (int) $actor->id,
                'ATTENDANCE_DAILY_' . $decision,
                'ATTENDANCE_DAILY',
                (string) $dailyId,
                [
                    'comment' => $comment,
                    'employeeId' => (int) $daily->employee_id,
                    'targetDate' => $daily->target_date,
                ]
            );

            return [
                'id' => $dailyId,
                'status' => $decision,
                'comment' => $comment,
            ];
        });
    }

    public function bulkDecideApproval(array $dailyIds, string $decision, ?string $comment, GenericUser $actor): array
    {
        $dailyIds = array_values(array_unique(array_map('intval', $dailyIds)));
        if ($dailyIds === []) {
            throw new ApiException('VALIDATION_ERROR', '一括処理の対象がありません。', 422, [
                ['field' => 'ids', 'message' => '少なくとも1件選択してください。'],
            ]);
        }

        $items = [];
        foreach ($dailyIds as $dailyId) {
            $items[] = $this->decideApproval($dailyId, $decision, $comment, $actor);
        }

        return [
            'updatedCount' => count($items),
            'items' => $items,
        ];
    }
}
