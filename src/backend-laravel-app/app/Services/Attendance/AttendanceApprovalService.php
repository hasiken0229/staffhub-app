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

        $review = app(DailyApprovalReview::class);
        $query = DB::table('attendance_daily as ad')->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select('ad.*', 'e.employee_code', 'e.name as employee_name', 'e.department_name', 'e.location_name', 'e.employment_type');
        $review->applyFilters($query, $filters, 'ad');
        $pendingPage = null;
        if ($status === 'PENDING') {
            $pending = $review->pending($filters);
            $total = $pending->count();
            $pendingPage = $pending->forPage($page, $perPage)->values();
            $rows = $query->whereIntegerInRaw('ad.id', $pendingPage->pluck('id')->filter()->all())->get();
            $decisions = $pendingPage->keyBy('id');
            foreach ($rows as $row) {
                $row->review_status = 'PENDING';
                $row->review_reason = $decisions[$row->id]->review_reason;
            }
        } else {
            if ($status !== 'ALL' && $status !== 'APPROVED') { $query->where('ad.approval_status', $status); }
            if ($status === 'APPROVED') {
                $query->where('ad.approval_status', 'APPROVED');
                $review->restrictToConfirmedApprovals($query, $filters);
            }
            $total = (clone $query)->count();
            $rows = $query->orderBy('ad.target_date')->orderBy('e.employee_code')->forPage($page, $perPage)->get();
            $review->annotate($rows);
        }
        $this->preloadProjection($rows);
        $this->preloadDailyBreaks($rows);
        $alertSettings = $this->resolveAttendanceAlertSettings();
        $shortIntervalAlertMap = $this->buildShortIntervalAlertMap($rows, $alertSettings);

        $mapped = $rows->mapWithKeys(fn (object $row) => [$row->id => [
            ...$this->mapDailyRow($row, $this->buildAlertsForRow($row, $shortIntervalAlertMap, $alertSettings)),
            'employmentType' => $row->employment_type,
        ]]);
        $items = $pendingPage === null ? $mapped->values() : $pendingPage->map(fn ($r) => $r->id !== null ? $mapped[$r->id] : $this->missingScheduledItem($r));
        return [
            'items' => $items->all(),
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

        $months = MonthMutationLock::forRecord('attendance_daily', $dailyId);
        return DB::transaction(function () use ($months, $dailyId, $decision, $comment, $actor) {
            MonthMutationLock::acquire($months);
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
            // Approval fixes the projected calculation before any subsequent month close.
            if ($decision === 'APPROVED') {
                $daily->employee_code = DB::table('employees')->where('id', $daily->employee_id)->value('employee_code');
                $projected = $this->projectScheduledPunchFallback($daily);
                if (DailyConfirmation::issue($projected) !== null || $this->singlePunchNeedsConfirmation($projected)) {
                    throw new ApiException('INCOMPLETE_DAILY', '出退勤・実働または公休・全休の区分を確定してから承認してください。', 422);
                }
                if (!empty($projected->saturday_holiday)) {
                    $values = ['clock_in_at'=>null, 'clock_out_at'=>null, 'break_minutes'=>0, 'work_minutes'=>0, 'schedule_name'=>'公休', 'work_type_id'=>null, 'remark'=>$projected->remark];
                    DB::table('attendance_daily')->where('id', $dailyId)->update($values);
                    $this->syncAutoBreak($dailyId, null);
                    $this->addAudit('SYSTEM', null, 'ATTENDANCE_SATURDAY_HOLIDAY', 'ATTENDANCE_DAILY', (string) $dailyId, ['before'=>(array)$daily, 'after'=>$values]);
                }
                if (!empty($projected->schedule_inferred_side)) {
                    $this->freezeSinglePunch($daily, $projected);
                }
            }
            $review = app(DailyApprovalReview::class);
            $review->annotate(collect([$daily]));
            if ($previousStatus === $decision && $daily->review_status === $decision && ($daily->approval_comment ?? '') === ($comment ?? '')) {
                return ['id' => $dailyId, 'status' => $decision, 'comment' => $comment, 'unchanged' => true];
            }
            $operatorId = $this->resolveOperatorEmployeeId($actor, (int) $daily->employee_id);

            DB::table('attendance_daily')
                ->where('id', $dailyId)
                ->update([
                    'approval_status' => $decision,
                    'approval_comment' => $comment,
                    'approved_by' => $operatorId > 0 ? $operatorId : null,
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

        return \App\Services\BatchOperation::run($dailyIds, fn ($dailyId) => $this->decideApproval($dailyId, $decision, $comment, $actor));
    }
}
