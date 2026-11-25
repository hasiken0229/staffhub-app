<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use App\Services\BatchOperation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;

final class MonthApprovalService extends AttendanceServiceSupport
{
    private ?string $previewMonth = null;
    private bool $previewClosed = false;
    private array $previewRelations = [];
    private ?array $previewErrorRules = null;
    public function __construct(private readonly AttendanceApprovalService $approvalService) {}

    protected function isMonthClosed(string $month): bool
    {
        return $this->previewMonth === $month ? $this->previewClosed : parent::isMonthClosed($month);
    }
    protected function attendanceErrorRules(): array
    {
        return $this->previewErrorRules ?? parent::attendanceErrorRules();
    }

    private function query()
    {
        return DB::table('attendance_daily as ad')->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->select(['ad.*', 'e.employee_code', 'e.name as employee_name', 'e.department_name', 'e.location_name', 'e.employment_type']);
    }

    public function preview(string $targetMonth): array
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);
        $scope = ['from'=>$targetMonth.'-01', 'to'=>CarbonImmutable::parse($targetMonth.'-01')->endOfMonth()->toDateString()];
        $pending = app(DailyApprovalReview::class)->pending($scope)->whereNotNull('id')->keyBy('id');
        $rows = $this->query()->whereIntegerInRaw('ad.id',$pending->keys()->all())->orderBy('ad.target_date')->orderBy('e.employee_code')->get();
        foreach ($rows as $row) {
            $row->review_status = 'PENDING';
            $row->review_reason = $pending[$row->id]->review_reason;
        }
        $this->previewClosed = parent::isMonthClosed($targetMonth);
        $this->previewMonth = $targetMonth;
        $this->previewErrorRules = parent::attendanceErrorRules();
        $employeeIds = $rows->pluck('employee_id')->unique()->all();
        foreach (['attendance_daily_breaks'=>'attendance_daily_id', 'attendance_daily_edit_requests'=>'employee_id', 'attendance_error_resolutions'=>'employee_id', 'attendance_shift_schedules'=>'employee_id', 'employee_attendance_settings'=>'employee_id', 'leave_requests'=>'employee_id'] as $table=>$key) {
            $query = DB::table($table)->whereIn($key, $key === 'attendance_daily_id' ? $rows->pluck('id')->all() : $employeeIds);
            if (in_array($table,['attendance_daily_edit_requests','attendance_error_resolutions','attendance_shift_schedules'],true)) { $query->whereBetween('target_date',[$targetMonth.'-01',CarbonImmutable::parse($targetMonth.'-01')->endOfMonth()->toDateString()]); }
            if ($table === 'leave_requests') { $query->where('start_date','<=',CarbonImmutable::parse($targetMonth.'-01')->endOfMonth()->toDateString())->where('end_date','>=',$targetMonth.'-01'); }
            $this->previewRelations[$table] = $query->orderBy($table === 'employee_attendance_settings' ? 'employee_id' : 'id')->get()->groupBy($key)->map(fn ($group) => $group->map(fn ($r)=>(array)$r)->all())->all();
        }
        $this->previewRelations['errorRules'] = DB::table('attendance_error_rules')->orderBy('code')->get()->map(fn ($r)=>(array)$r)->all();
        $this->previewRelations['breakRules'] = DB::table('attendance_break_rules')->orderBy('id')->get()->map(fn ($r)=>(array)$r)->all();
        try { $items = $rows->map(fn ($row) => $this->candidate($row))->all(); }
        finally { $this->previewMonth = null; $this->previewRelations = []; $this->previewErrorRules = null; }
        $eligible = count(array_filter($items, fn ($item) => $item['eligible']));
        return ['targetMonth'=>$targetMonth, 'items'=>$items, 'eligibleCount'=>$eligible, 'excludedCount'=>count($items)-$eligible];
    }

    private function candidate(object $row): array
    {
        if (!isset($row->review_status)) { app(DailyApprovalReview::class)->annotate(collect([$row])); }
        $effective = $this->attendanceDayProjection($row);
        $daily = $this->mapDailyRow($effective);
        $relations = $this->relationsFor($row);
        $reasons = [];
        if ($daily['approvalReviewReason'] !== null && str_contains($daily['approvalReviewReason'], '打刻履歴')) { $reasons[] = $daily['approvalReviewReason']; }
        if ($this->isMonthClosed(substr($row->target_date, 0, 7)) || ($row->close_status ?? 'OPEN') === 'CLOSED') { $reasons[] = '締め済み'; }
        if ($row->review_status !== 'PENDING') { $reasons[] = '承認待ちではありません'; }
        if ($row->target_date >= CarbonImmutable::today('Asia/Tokyo')->toDateString()) { $reasons[] = '当日・未来日のため対象外'; }
        $state = AttendanceDayState::classify($effective);
        if (!in_array($state, ['COMPLETE', 'HOLIDAY', 'LEAVE'], true) || DailyConfirmation::issue($effective) !== null) { $reasons[] = '出退勤または勤務時間が未確定'; }
        if (!empty($daily['scheduleInferredSide'])) { $reasons[] = '予定補完のため個別確認が必要'; }
        if (array_filter($relations['attendance_daily_edit_requests'],fn ($r)=>in_array($r['status'], ['PENDING', 'RETURNED'], true))) { $reasons[] = '未処理の修正申請あり'; }
        if (array_filter($relations['leave_requests'],fn ($r)=>in_array($r['status'], ['PENDING', 'RETURNED'], true))) { $reasons[] = '未処理の届出・休暇申請あり'; }
        $resolutions = array_column($relations['attendance_error_resolutions'],'status','error_code');
        foreach ($this->detectDailyErrors($effective) as $error) {
            if (!in_array($resolutions[$error['errorCode']] ?? 'OPEN', ['RESOLVED', 'IGNORED'], true)) { $reasons[] = $error['errorName']; }
        }
        return ['daily'=>$daily, 'eligible'=>$reasons === [], 'reasons'=>array_values(array_unique($reasons)), 'fingerprint'=>hash('sha256', json_encode([(array)$row, $daily, $relations, ConfirmedAttendancePolicy::VERSION], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function relationsFor(object $row): array
    {
        $relations = [];
        foreach (['attendance_daily_breaks'=>'attendance_daily_id', 'attendance_daily_edit_requests'=>'employee_id', 'attendance_error_resolutions'=>'employee_id', 'attendance_shift_schedules'=>'employee_id', 'employee_attendance_settings'=>'employee_id', 'leave_requests'=>'employee_id'] as $table=>$key) {
            if ($this->previewMonth !== null) {
                $records = $this->previewRelations[$table][$key === 'attendance_daily_id' ? $row->id : $row->employee_id] ?? [];
                if (in_array($table, ['attendance_daily_edit_requests', 'attendance_error_resolutions', 'attendance_shift_schedules'], true)) { $records=array_values(array_filter($records,fn($r)=>$r['target_date']===$row->target_date)); }
                if ($table==='leave_requests') { $records=array_values(array_filter($records,fn($r)=>$r['start_date']<=$row->target_date&&$r['end_date']>=$row->target_date)); }
                $relations[$table]=$records; continue;
            }
            $query = DB::table($table)->where($key, $key === 'attendance_daily_id' ? $row->id : $row->employee_id);
            if (in_array($table, ['attendance_daily_edit_requests', 'attendance_error_resolutions', 'attendance_shift_schedules'], true)) { $query->where('target_date', $row->target_date); }
            if ($table === 'leave_requests') { $query->where('start_date', '<=', $row->target_date)->where('end_date', '>=', $row->target_date); }
            $relations[$table] = $query->orderBy($table === 'employee_attendance_settings' ? 'employee_id' : 'id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $relations['errorRules'] = $this->previewMonth!==null ? $this->previewRelations['errorRules'] : DB::table('attendance_error_rules')->orderBy('code')->get()->map(fn ($r) => (array) $r)->all();
        $relations['breakRules'] = $this->previewMonth!==null ? $this->previewRelations['breakRules'] : DB::table('attendance_break_rules')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        return $relations;
    }

    public function approve(string $targetMonth, array $candidates, ?string $comment, GenericUser $actor): array
    {
        $targetMonth = $this->validateTargetMonth($targetMonth);
        $byId = [];
        foreach ($candidates as $candidate) { $byId[(int)$candidate['id']] = (string)$candidate['fingerprint']; }
        $result = BatchOperation::run(array_keys($byId), function ($id) use ($targetMonth, $byId, $comment, $actor) {
            return DB::transaction(function () use ($id, $targetMonth, $byId, $comment, $actor) {
                MonthMutationLock::acquire([$targetMonth]);
                $row = $this->query()->where('ad.id', $id)->lockForUpdate()->first();
                if ($row === null || substr($row->target_date, 0, 7) !== $targetMonth) { throw new ApiException('NOT_ELIGIBLE', '対象月の候補ではありません。', 422); }
                $current = $this->candidate($row);
                if (!hash_equals($current['fingerprint'], $byId[$id])) { throw new ApiException('STALE_PREVIEW', '確認後に勤怠または関連申請が変更されました。再取得して確認してください。', 409); }
                if (!$current['eligible']) { throw new ApiException('NOT_ELIGIBLE', implode(' / ', $current['reasons']), 422); }
                return $this->approvalService->decideApproval($id, 'APPROVED', $comment, $actor);
            });
        });
        $result['skippedCount'] = 0;
        foreach ($result['errors'] as &$error) {
            if (in_array($error['code'], ['STALE_PREVIEW', 'NOT_ELIGIBLE', 'CLOSED_PERIOD'], true)) { $error['status'] = 'SKIPPED'; $result['skippedCount']++; }
        }
        unset($error);
        $result['targetMonth'] = $targetMonth;
        return $result;
    }
}
