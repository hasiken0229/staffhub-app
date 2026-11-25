<?php

namespace Tests\Feature;

use App\Services\Attendance\AttendanceErrorService;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\DailyEditService;
use App\Services\Attendance\MonthApprovalService;
use App\Services\Attendance\MonthCloseService;
use Carbon\CarbonImmutable as Date;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AttendanceReviewSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Date::setTestNow('2026-10-10 12:00:00'); }
    protected function tearDown(): void { Date::setTestNow(); parent::tearDown(); }
    private function actor(int $employee): GenericUser { return new GenericUser(['id'=>$employee,'role'=>'EMPLOYEE']); }
    private function employee(string $code='T901'): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>$code,'name'=>'確認 職員','kana'=>'カクニン','department_name'=>'保育','location_name'=>'本園','employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-10-01','retired_on'=>null,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function daily(int $employee, array $values=[]): int
    {
        return DB::table('attendance_daily')->insertGetId(array_merge(['employee_id'=>$employee,'target_date'=>'2026-10-02','schedule_name'=>'通常勤務','work_type_id'=>null,'raw_clock_in_at'=>'2026-10-02 08:00:40','raw_clock_out_at'=>'2026-10-02 17:00:20','clock_in_at'=>'2026-10-02 08:00:40','clock_out_at'=>'2026-10-02 17:00:20','break_minutes'=>60,'work_minutes'=>479,'late_flag'=>0,'early_leave_flag'=>0,'absence_flag'=>0,'special_leave_flag'=>0,'paid_leave_unit'=>null,'hour_paid_leave_minutes'=>0,'child_care_leave_minutes'=>0,'nursing_care_leave_minutes'=>0,'remark'=>null,'approval_status'=>'PENDING','approval_comment'=>null,'approved_by'=>null,'approved_at'=>null,'close_status'=>'OPEN','is_manually_edited'=>0,'manual_edited_by'=>null,'manual_edited_at'=>null,'updated_at'=>now()],$values));
    }
    public function test_metadata_only_and_unchanged_minute_payload_preserve_seconds_calculation_and_approval(): void
    {
        $e=$this->employee(); $id=$this->daily($e,['approval_status'=>'APPROVED','approved_by'=>$e,'approved_at'=>'2026-10-05 09:00:00']);
        $service=app(DailyEditService::class);
        $service->updateDaily($id,['remark'=>'備考だけ'], $this->actor($e));
        $service->updateDaily($id,['clockInTime'=>'08:00','clockOutTime'=>'17:00','supervisorComment'=>'確認'], $this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:00:40','clock_out_at'=>'2026-10-02 17:00:20','work_minutes'=>479,'break_minutes'=>60,'is_manually_edited'=>0,'manual_edited_at'=>null,'approval_status'=>'APPROVED','approved_at'=>'2026-10-05 09:00:00']);
        $service->updateDaily($id,['clockOutTime'=>'17:05'], $this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:00:40','clock_out_at'=>'2026-10-02 17:05:00','work_minutes'=>484,'is_manually_edited'=>1,'approval_status'=>'PENDING']);
    }
    public function test_night_times_and_breaks_survive_comment_save(): void
    {
        $e=$this->employee(); $id=$this->daily($e,['clock_in_at'=>'2026-10-02 22:00:40','clock_out_at'=>'2026-10-03 06:00:20','work_minutes'=>434,'break_minutes'=>45,'is_manually_edited'=>1]);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,'break_start_at'=>'2026-10-03 02:00:10','break_end_at'=>'2026-10-03 02:45:10','created_at'=>now(),'updated_at'=>now()]);
        app(DailyEditService::class)->updateDaily($id,['remark'=>'翌日勤務の確認'], $this->actor($e));
        $detail=app(DailyAttendanceService::class)->detail($id);
        $this->assertSame('2026-10-03T06:00:20+09:00',$detail['clockOutAt']);
        $this->assertTrue($detail['breaks'][0]['startNextDay']);
        $this->assertSame('2026-10-03T02:00:10+09:00',$detail['breaks'][0]['startAt']);
        $this->assertSame(434,$detail['workMinutes']);
    }
    public function test_clock_edit_on_projected_day_keeps_the_displayed_adopted_start_and_break(): void
    {
        $e=$this->employee();DB::table('employee_attendance_settings')->insert(['employee_id'=>$e,'standard_clock_in_time'=>'08:30','standard_clock_out_time'=>'17:15','include_before_start'=>0,'include_after_end'=>0]);
        $id=$this->daily($e,['raw_clock_in_at'=>'2026-10-02 08:25:40','raw_clock_out_at'=>null,'clock_in_at'=>'2026-10-02 08:25:40','clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $service=app(DailyEditService::class);$detail=app(DailyAttendanceService::class)->detail($id);
        $this->assertSame(45,$detail['breakMinutes']);$this->assertSame('2026-10-02T08:30:00+09:00',$detail['clockInAt']);
        $service->updateDaily($id,['remark'=>'備考だけ'],$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:25:40','clock_out_at'=>null,'break_minutes'=>0,'work_minutes'=>null,'is_manually_edited'=>0]);
        try {$service->updateDaily($id,['clockOutTime'=>'12:00'],$this->actor($e));$this->fail('Projected break outside the edited shift must be rejected');}
        catch (\App\Services\ApiException $error) {$this->assertSame('VALIDATION_ERROR',$error->errorCode);$this->assertSame('breaks',$error->details[0]['field']);}
        $service->updateDaily($id,['clockOutTime'=>'17:00','clockOutNextDay'=>false],$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:30:00','clock_out_at'=>'2026-10-02 17:00:00','break_minutes'=>45,'work_minutes'=>465,'is_manually_edited'=>1,'raw_clock_out_at'=>null]);
        $this->assertDatabaseHas('attendance_daily_breaks',['attendance_daily_id'=>$id,'break_start_at'=>'2026-10-02 12:00:00','break_end_at'=>'2026-10-02 12:45:00']);
    }
    public function test_edit_on_a_projected_full_shift_keeps_the_displayed_additional_break(): void
    {
        $employee = $this->employee();
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee,'standard_clock_in_time'=>'08:30','standard_clock_out_time'=>'17:30']);
        $id=$this->daily($employee,['raw_clock_in_at'=>'2026-10-02 08:25:40','raw_clock_out_at'=>null,'clock_in_at'=>'2026-10-02 08:25:40','clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $detail=app(DailyAttendanceService::class)->detail($id);
        $this->assertSame(['CLOCK_OUT',60,480],[$detail['scheduleInferredSide'],$detail['breakMinutes'],$detail['workMinutes']]);
        $this->assertSame(['12:00','17:15'],array_column($detail['breaks'],'startTime'));
        $this->assertSame(['12:45','17:30'],array_column($detail['breaks'],'endTime'));
        app(DailyEditService::class)->updateDaily($id,['clockOutTime'=>'17:45'],$this->actor($employee));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:30:00','clock_out_at'=>'2026-10-02 17:45:00','break_minutes'=>60,'work_minutes'=>495,'raw_clock_out_at'=>null]);
        $this->assertDatabaseHas('attendance_daily_breaks',['attendance_daily_id'=>$id,'break_start_at'=>'2026-10-02 17:15:00','break_end_at'=>'2026-10-02 17:30:00']);
    }
    public function test_comment_save_preserves_an_unfinished_break_without_validating_or_inventing_an_end_time(): void
    {
        $e=$this->employee();$id=$this->daily($e,['target_date'=>'2026-10-10','clock_in_at'=>'2026-10-10 08:00:40','clock_out_at'=>null,'raw_clock_in_at'=>'2026-10-10 08:00:40','raw_clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,'break_start_at'=>'2026-10-10 11:00:10','break_end_at'=>null,'created_at'=>now(),'updated_at'=>now()]);
        app(DailyEditService::class)->updateDaily($id,['remark'=>'勤務中の備考'],$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_out_at'=>null,'work_minutes'=>null,'is_manually_edited'=>0,'remark'=>'勤務中の備考']);
        $this->assertDatabaseHas('attendance_daily_breaks',['attendance_daily_id'=>$id,'break_start_at'=>'2026-10-10 11:00:10','break_end_at'=>null]);
    }
    public function test_correction_approval_with_unchanged_minutes_preserves_seconds_and_next_day_breaks(): void
    {
        $e=$this->employee();$id=$this->daily($e,['clock_in_at'=>'2026-10-02 22:00:40','clock_out_at'=>'2026-10-03 06:00:20','work_minutes'=>434,'break_minutes'=>45]);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,'break_start_at'=>'2026-10-03 02:00:10','break_end_at'=>'2026-10-03 02:45:10','created_at'=>now(),'updated_at'=>now()]);
        $service=app(\App\Services\Attendance\DailyEditRequestService::class);
        $request=$service->createDailyEditRequest($e,['targetDate'=>'2026-10-02','clockInTime'=>'22:00','clockOutTime'=>'06:00','clockOutNextDay'=>true,'breaks'=>[['startTime'=>'02:00','endTime'=>'02:45','startNextDay'=>true,'endNextDay'=>true]],'employeeComment'=>'備考の確認']);
        $this->assertSame(434,$request['proposedWorkMinutes']);$this->assertSame(45,$request['proposedBreakMinutes']);
        $service->decideDailyEditRequest($request['id'],'APPROVED','確認',$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 22:00:40','clock_out_at'=>'2026-10-03 06:00:20','work_minutes'=>434,'is_manually_edited'=>0,'approval_status'=>'APPROVED']);
        $this->assertDatabaseHas('attendance_daily_breaks',['attendance_daily_id'=>$id,'break_start_at'=>'2026-10-03 02:00:10','break_end_at'=>'2026-10-03 02:45:10']);
    }
    public function test_duplicate_corrections_are_rejected_and_own_pending_or_returned_requests_can_be_cancelled_and_resubmitted(): void
    {
        $e=$this->employee();$other=$this->employee('T902');$id=$this->daily($e);$service=app(\App\Services\Attendance\DailyEditRequestService::class);
        $payload=['targetDate'=>'2026-10-02','clockInTime'=>'09:00','clockOutTime'=>'18:00','employeeComment'=>'修正'];
        $request=$service->createDailyEditRequest($e,$payload);
        foreach ([$payload,array_merge($payload,['clockInTime'=>'09:05'])] as $retry) {
            try {$service->createDailyEditRequest($e,$retry);$this->fail('Duplicate must be rejected');}
            catch (\App\Services\ApiException $error) {$this->assertSame('DUPLICATE_REQUEST',$error->errorCode);$this->assertSame(409,$error->status);$this->assertSame('targetDate',$error->details[0]['field']);}
        }
        $this->assertSame(1,DB::table('attendance_daily_edit_requests')->count());
        try {$service->cancelDailyEditRequest($request['id'],$other);$this->fail('Other employee cannot cancel');}
        catch (\App\Services\ApiException $error) {$this->assertSame('NOT_FOUND',$error->errorCode);}
        $this->assertSame('CANCELLED',$service->cancelDailyEditRequest($request['id'],$e)['status']);
        $this->assertSame('CANCELLED',$service->cancelDailyEditRequest($request['id'],$e)['status']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>'2026-10-02 08:00:40','work_minutes'=>479]);
        $new=$service->createDailyEditRequest($e,$payload);$this->assertNotSame($request['id'],$new['id']);
        $service->decideDailyEditRequest($new['id'],'RETURNED','内容確認',$this->actor($e));
        $this->assertSame('CANCELLED',$service->cancelDailyEditRequest($new['id'],$e)['status']);
        $approved=$service->createDailyEditRequest($e,$payload);$service->decideDailyEditRequest($approved['id'],'APPROVED','確認',$this->actor($e));
        try {$service->cancelDailyEditRequest($approved['id'],$e);$this->fail('Approved cannot be cancelled');}
        catch (\App\Services\ApiException $error) {$this->assertSame('INVALID_STATUS',$error->errorCode);}
        $this->assertSame(3,DB::table('attendance_daily_edit_requests')->count());
    }
    public function test_holiday_future_and_active_shift_have_no_missing_punch_error_but_past_missing_does(): void
    {
        $e=$this->employee();
        $this->daily($e,['target_date'=>'2026-10-03','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'clock_in_at'=>null,'clock_out_at'=>null,'work_minutes'=>0,'break_minutes'=>0,'schedule_name'=>'公休']);
        $this->daily($e,['target_date'=>'2026-10-11','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'clock_in_at'=>null,'clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $this->daily($e,['target_date'=>'2026-10-10','raw_clock_in_at'=>'2026-10-10 08:08:00','raw_clock_out_at'=>null,'clock_in_at'=>'2026-10-10 08:08:00','clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $this->daily($e,['target_date'=>'2026-10-01','raw_clock_in_at'=>'2026-10-01 08:08:00','raw_clock_out_at'=>null,'clock_in_at'=>'2026-10-01 08:08:00','clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $errors=app(AttendanceErrorService::class)->listErrors(['fromMonth'=>'2026-10','toMonth'=>'2026-10'])['items'];
        $this->assertCount(1,$errors);$this->assertSame('2026-10-01',$errors[0]['targetDate']);
        $rows=app(DailyAttendanceService::class)->listDailyGrid(['targetMonth'=>'2026-10'])['items'];
        $today=collect($rows)->firstWhere('targetDate','2026-10-10');
        $this->assertSame('WORKING',$today['dayState']);$this->assertSame([], $today['alerts']);
    }
    public function test_missing_breakdown_does_not_relax_the_month_close_blocker(): void
    {
        $this->employee();$service=app(MonthCloseService::class);
        $row=$service->monthCloseStatus(['targetMonth'=>'2026-10'])['items'][0];
        $this->assertSame(31,$row['unsubmittedCount']);
        $this->assertSame(['workday'=>7,'future'=>21,'holiday'=>2,'today'=>1],$row['missingBreakdown']);
        $this->assertCount(31,$row['dayDetails']);
        $this->assertFalse($service->monthClosePrecheck('2026-10')['canClose']);
    }
    public function test_safe_batch_revalidates_each_fingerprint_and_leaves_changed_or_ineligible_rows_unapproved(): void
    {
        $e=$this->employee();$ok=$this->daily($e,['is_manually_edited'=>1]);$stale=$this->daily($e,['target_date'=>'2026-10-05','is_manually_edited'=>1]);
        $missing=$this->daily($e,['target_date'=>'2026-10-06','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'clock_in_at'=>null,'clock_out_at'=>null,'work_minutes'=>null]);
        $service=app(MonthApprovalService::class);$preview=$service->preview('2026-10');
        $candidates=collect($preview['items'])->keyBy('daily.id');
        $this->assertTrue($candidates[$ok]['eligible']);$this->assertFalse($candidates[$missing]['eligible']);
        DB::table('attendance_daily')->where('id',$stale)->update(['remark'=>'別画面の修正']);
        $result=$service->approve('2026-10',collect($preview['items'])->map(fn($c)=>['id'=>$c['daily']['id'],'fingerprint'=>$c['fingerprint']])->all(),'一括確認',$this->actor($e));
        $this->assertSame(1,$result['updatedCount']);$this->assertSame(2,$result['skippedCount']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$ok,'approval_status'=>'APPROVED']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$stale,'approval_status'=>'PENDING']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$missing,'approval_status'=>'PENDING']);
        $this->assertDatabaseMissing('attendance_monthly_closes',['target_year_month'=>'2026-10','status'=>'CLOSED']);
    }
    public function test_batch_excludes_schedule_fallback_and_closed_month(): void
    {
        $e=$this->employee('970017');DB::table('employee_attendance_settings')->insert(['employee_id'=>$e,'standard_clock_in_time'=>'08:30','standard_clock_out_time'=>'14:30','include_before_start'=>0,'include_after_end'=>0]);
        $id=$this->daily($e,['raw_clock_in_at'=>'2026-10-02 08:25:00','raw_clock_out_at'=>null,'clock_in_at'=>'2026-10-02 08:30:00','clock_out_at'=>null,'work_minutes'=>null,'break_minutes'=>0]);
        $service=app(MonthApprovalService::class);$candidate=$service->preview('2026-10')['items'][0];
        $this->assertFalse($candidate['eligible']);$this->assertContains('予定補完のため個別確認が必要',$candidate['reasons']);
        DB::table('attendance_monthly_closes')->insert(['target_year_month'=>'2026-10','status'=>'CLOSED','created_at'=>now(),'updated_at'=>now()]);
        $result=$service->approve('2026-10',[['id'=>$id,'fingerprint'=>$candidate['fingerprint']]],null,$this->actor($e));
        $this->assertSame(0,$result['updatedCount']);$this->assertSame(1,$result['skippedCount']);
    }
    public function test_fully_registered_future_month_still_cannot_be_closed(): void
    {
        $e=$this->employee();DB::table('employees')->where('id',$e)->update(['joined_on'=>'2026-11-02','retired_on'=>'2026-11-02']);
        $this->daily($e,['target_date'=>'2026-11-02','clock_in_at'=>null,'clock_out_at'=>null,'raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'work_minutes'=>0,'break_minutes'=>0,'schedule_name'=>'公休','approval_status'=>'APPROVED']);
        $precheck=app(MonthCloseService::class)->monthClosePrecheck('2026-11');
        $this->assertSame(0,$precheck['summary']['unsubmittedDailyCount']);
        $this->assertFalse($precheck['canClose']);$this->assertContains('PERIOD_NOT_FINISHED',array_column($precheck['blockers'],'code'));
    }
    public function test_added_correction_request_invalidates_a_previously_normal_candidate(): void
    {
        $e=$this->employee();$id=$this->daily($e,['is_manually_edited'=>1]);$service=app(MonthApprovalService::class);$candidate=$service->preview('2026-10')['items'][0];
        app(\App\Services\Attendance\DailyEditRequestService::class)->createDailyEditRequest($e,['targetDate'=>'2026-10-02','clockInTime'=>'09:00','clockOutTime'=>'18:00','breaks'=>[['startTime'=>'12:00','endTime'=>'13:00']],'employeeComment'=>'確認後に申請']);
        $result=$service->approve('2026-10',[['id'=>$id,'fingerprint'=>$candidate['fingerprint']]],null,$this->actor($e));
        $this->assertSame(0,$result['updatedCount']);$this->assertSame('STALE_PREVIEW',$result['errors'][0]['code']);
        $next=$service->preview('2026-10')['items'][0];$this->assertFalse($next['eligible']);$this->assertContains('未処理の修正申請あり',$next['reasons']);
    }
    public function test_partial_leave_without_punches_is_not_exempted_or_bulk_approved(): void
    {
        $e=$this->employee();
        foreach ([['paid_leave_unit'=>0.5,'schedule_name'=>'有給休暇'],['hour_paid_leave_minutes'=>60,'schedule_name'=>'時間有給'],['child_care_leave_minutes'=>60,'schedule_name'=>'子の看護休暇']] as $i=>$leave) {
            $this->daily($e,array_merge(['target_date'=>'2026-10-0'.($i+5),'clock_in_at'=>null,'clock_out_at'=>null,'raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'break_minutes'=>0,'work_minutes'=>null],$leave));
        }
        $errors=app(AttendanceErrorService::class)->listErrors(['fromMonth'=>'2026-10','toMonth'=>'2026-10'])['items'];
        $this->assertCount(3,$errors);$this->assertSame(['MISSING_BOTH_PUNCHES'],array_values(array_unique(array_column($errors,'errorCode'))));
        $preview=app(MonthApprovalService::class)->preview('2026-10');$this->assertSame(0,$preview['eligibleCount']);$this->assertSame(3,$preview['excludedCount']);
        $id=$this->daily($e,['target_date'=>'2026-10-08','clock_in_at'=>'2026-10-08 13:00:00','clock_out_at'=>'2026-10-08 17:00:00','work_minutes'=>240,'break_minutes'=>0,'paid_leave_unit'=>0.5,'schedule_name'=>'有給休暇（午前）']);
        $after=app(AttendanceErrorService::class)->listErrors(['fromMonth'=>'2026-10','toMonth'=>'2026-10'])['items'];
        $this->assertCount(3,$after);$this->assertNotContains($id,array_column($after,'dailyId'));
    }

    public function test_attention_queue_dashboard_and_month_share_original_punch_provenance(): void
    {
        $e = $this->employee('970017');
        $normal = $this->daily($e);
        $short = $this->daily($e,['target_date'=>'2026-10-05','break_minutes'=>0,'work_minutes'=>359]);
        $miss = $this->daily($e,['target_date'=>'2026-10-06','raw_clock_out_at'=>null]);
        $break = $this->daily($e,['target_date'=>'2026-10-07','work_minutes'=>480,'break_minutes'=>5]);
        $import = $this->daily($e,['target_date'=>'2026-10-08','source_system'=>'HARMOS','source_import_type'=>'HARMOS_ATTENDANCE_DAILY_CSV','approval_status'=>'APPROVED']);
        $current = $this->daily($e,['target_date'=>'2026-10-10','raw_clock_out_at'=>null,'clock_out_at'=>null,'work_minutes'=>null]);
        $future = $this->daily($e,['target_date'=>'2026-10-11','raw_clock_out_at'=>null]);
        $q = app(\App\Services\Attendance\AttendanceApprovalService::class);
        $hub = app(\App\Services\Reports\ReportDashboardService::class);
        $this->assertSame(3,$hub->hubSummary()['pendingAttendanceApprovalCount']);
        $items = $q->listApprovals(['status'=>'PENDING','page'=>1,'perPage'=>2]);
        $this->assertSame(3,$items['meta']['total']);
        $this->assertSame([$import,$break],array_column($items['items'],'id'));
        $next = $q->listApprovals(['status'=>'PENDING','page'=>2,'perPage'=>2]);
        $this->assertSame([$miss],array_column($next['items'],'id'));
        $days = collect($q->listApprovals(['status'=>'ALL'])['items'])->keyBy('id');
        foreach ([$normal,$short] as $id) { $this->assertSame('NOT_REQUIRED',$days[$id]['approvalStatus']); }
        foreach ([$current,$future] as $id) { $this->assertSame('NOT_DUE',$days[$id]['approvalStatus']); }
        $this->assertSame('1回しか打刻がありません',$days[$miss]['approvalReviewReason']);
        $this->assertStringContainsString('休憩',$days[$break]['approvalReviewReason']);
        $this->assertSame(3,app(MonthCloseService::class)->monthCloseStatus(['targetMonth'=>'2026-10'])['items'][0]['pendingCount']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$normal,'approval_status'=>'PENDING','approved_at'=>null]);
        $q->decideApproval($import,'APPROVED',null,$this->actor($e));
        $this->assertSame(2,$hub->hubSummary()['pendingAttendanceApprovalCount']);
        DB::table('attendance_error_resolutions')->insert(['employee_id'=>$e,'target_date'=>'2026-10-07','error_code'=>'SHORT_BREAK_6_TO_8','status'=>'IGNORED']);
        $this->assertSame(1,$hub->hubSummary()['pendingAttendanceApprovalCount']);
    }

    public function test_checkout_only_and_no_original_punch_have_distinct_review_reasons(): void
    {
        $employee = $this->employee();
        $checkoutOnly = $this->daily($employee, ['raw_clock_in_at' => null]);
        $noPunch = $this->daily($employee, [
            'target_date' => '2026-10-05', 'raw_clock_in_at' => null, 'raw_clock_out_at' => null,
            'clock_in_at' => null, 'clock_out_at' => null, 'work_minutes' => null, 'break_minutes' => 0,
        ]);
        $queue = app(\App\Services\Attendance\AttendanceApprovalService::class)->listApprovals(['status' => 'PENDING']);
        $reasons = array_column($queue['items'], 'approvalReviewReason', 'id');

        $this->assertSame('1回しか打刻がありません', $reasons[$checkoutOnly]);
        $this->assertSame('出勤・退勤の打刻がありません', $reasons[$noPunch]);
    }

    public function test_absent_explicit_shift_becomes_actionable_only_after_its_end_in_tokyo(): void
    {
        $e = $this->employee();
        $shift = fn ($date,$start,$end) => DB::table('attendance_shift_schedules')->insert(['employee_id'=>$e,'target_date'=>$date,'scheduled_clock_in_time'=>$start,'scheduled_clock_out_time'=>$end]);
        $shift('2026-10-07','09:00','15:00');
        $shift('2026-10-10','08:00','14:00');
        $shift('2026-10-11','09:00','15:00');
        $shift('2026-10-06',null,null);
        $q = app(\App\Services\Attendance\AttendanceApprovalService::class);
        $list = $q->listApprovals(['status'=>'PENDING']);
        $this->assertSame(1,$list['meta']['total']);
        $this->assertNull($list['items'][0]['id']);
        $this->assertSame('2026-10-07',$list['items'][0]['targetDate']);
        Date::setTestNow('2026-10-10 14:30:00');
        $this->assertSame(1,$q->listApprovals(['status'=>'PENDING'])['meta']['total']);
        Date::setTestNow('2026-10-10 14:31:00');
        $this->assertSame(2,$q->listApprovals(['status'=>'PENDING'])['meta']['total']);
        $this->assertDatabaseCount('attendance_daily',0);
    }

    public function test_freeze_only_a_confirmed_in_employment_normal_day_and_recalculate_on_reopen(): void
    {
        $e = $this->employee();
        DB::table('employees')->where('id',$e)->update(['joined_on'=>'2026-10-02','retired_on'=>'2026-10-02']);
        $normal = $this->daily($e);
        $outside = $this->daily($e,['target_date'=>'2026-10-01']);
        Date::setTestNow('2026-11-01 12:00:00');
        $m = app(MonthCloseService::class);
        $this->assertTrue($m->monthClosePrecheck('2026-10')['canClose']);
        $m->updateMonthlyClose('2026-10','CLOSED',null,$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$normal,'approval_status'=>'NOT_REQUIRED','approved_at'=>null,'close_status'=>'CLOSED']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$outside,'approval_status'=>'PENDING','approved_at'=>null]);
        $m->updateMonthlyClose('2026-10','OPEN',null,$this->actor($e));
        $this->assertDatabaseHas('attendance_daily',['id'=>$normal,'approval_status'=>'PENDING','close_status'=>'OPEN']);
    }

    public function test_normal_days_do_not_require_per_day_database_queries_in_global_notifications(): void
    {
        $day = Date::parse('2025-01-01');
        for ($e=1;$e<=52;$e++) {
            $id = $this->employee('PERF'.$e);
            $rows=[];
            for ($i=0;$i<100;$i++) {
                $date=$day->addDays($i)->toDateString();
                $isException=$i===0;
                $rows[]=['employee_id'=>$id,'target_date'=>$date,'raw_clock_in_at'=>$date.' 09:00:00',
                    'raw_clock_out_at'=>$isException?null:$date.' 18:00:00','clock_in_at'=>$date.' 09:00:00','clock_out_at'=>$isException?null:$date.' 18:00:00',
                    'work_minutes'=>$isException?null:480,'break_minutes'=>60,'approval_status'=>'PENDING','updated_at'=>now()];
            }
            DB::table('attendance_daily')->insert($rows);
        }
        DB::enableQueryLog();
        $start=hrtime(true);
        $hub=app(\App\Services\Reports\ReportDashboardService::class)->hubSummary();
        $hubMs=(hrtime(true)-$start)/1e6;
        $hubSql=count(DB::getQueryLog());
        DB::flushQueryLog();
        $start=hrtime(true);
        $list=app(\App\Services\Attendance\AttendanceApprovalService::class)->listApprovals(['status'=>'PENDING','perPage'=>25]);
        $listMs=(hrtime(true)-$start)/1e6;
        $queries=count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(52,$hub['pendingAttendanceApprovalCount']);
        $this->assertSame(52,$list['meta']['total']);
        $this->assertCount(25,$list['items']);
        $this->assertLessThanOrEqual(16,$hubSql);
        $this->assertLessThanOrEqual(23,$queries);
        fwrite(STDERR,sprintf("DailyReview 5200 rows: hub %.1f ms (%d queries), page %.1f ms (%d queries)\n",$hubMs,$hubSql,$listMs,$queries));
    }

    public function test_historical_approval_and_leave_signatures_follow_their_source(): void
    {
        $e=$this->employee();$service=app(\App\Services\Attendance\AttendanceApprovalService::class);
        $official=$this->daily($e,['source_system'=>'HARMOS','source_import_type'=>'HARMOS_ATTENDANCE_DAILY_CSV','approval_status'=>'APPROVED','approval_comment'=>'HARMOS原票: 承認済み']);
        $unsubmitted=$this->daily($e,['target_date'=>'2026-10-05','source_system'=>'HARMOS','source_import_type'=>'HARMOS_ATTENDANCE_DAILY_CSV','approval_comment'=>'HARMOS原票: 未申請']);
        $noPunch=['raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'clock_in_at'=>null,'clock_out_at'=>null,'work_minutes'=>null,
            'approval_status'=>'APPROVED','approval_comment'=>'休暇承認により自動反映','approved_by'=>$e];
        $full=$this->daily($e,$noPunch+['target_date'=>'2026-10-06','paid_leave_unit'=>1]);
        $half=$this->daily($e,$noPunch+['target_date'=>'2026-10-07','paid_leave_unit'=>0.5]);
        $all=collect($service->listApprovals(['status'=>'ALL'])['items'])->keyBy('id');
        $this->assertSame('APPROVED',$all[$official]['approvalStatus']);
        $this->assertSame('NOT_REQUIRED',$all[$unsubmitted]['approvalStatus']);
        $this->assertSame('NOT_REQUIRED',$all[$full]['approvalStatus']);
        $this->assertSame('PENDING',$all[$half]['approvalStatus']);
        $this->assertSame([$half], array_column($service->listApprovals(['status'=>'PENDING'])['items'],'id'));
        $this->assertSame([$official], array_column($service->listApprovals(['status'=>'APPROVED'])['items'],'id'));
    }

    public function test_unadopted_device_events_are_visible_once_and_nearby_repeat_touches_are_ignored(): void
    {
        $e=$this->employee();
        $id=$this->daily($e,['approval_status'=>'APPROVED','approved_at'=>'2026-10-03 10:00:00','approved_by'=>$e]);
        $device=DB::table('attendance_devices')->insertGetId(['device_code'=>'REVIEW','name'=>'検証','is_active'=>1]);
        $event=function($when,$reason)use($e,$device) {
            DB::table('attendance_events')->insert(['employee_id'=>$e,'device_id'=>$device,'card_uid'=>'TEST','event_type'=>null,
                'occurred_at'=>$when,'source_type'=>'DEVICE','created_at'=>'2026-10-04 10:00:00','receive_status'=>'REJECTED','rejection_reason'=>$reason,'offline_saved'=>0]);
        };
        $service=app(\App\Services\Attendance\AttendanceApprovalService::class);
        $event('2026-10-02 17:01:00','TOO_MANY_PUNCHES');
        $this->assertSame(0,$service->listApprovals(['status'=>'PENDING'])['meta']['total']);
        $event('2026-10-02 18:02:00','CONFIRMED_DAY_PUNCH');
        $event('2026-10-02 18:03:00','OUT_OF_ORDER_PUNCH');
        $event('2026-10-01 08:00:00','HISTORICAL_EMPLOYEE_PUNCH');
        $items=$service->listApprovals(['status'=>'PENDING'])['items'];
        $this->assertSame([$id,null],array_column($items,'id'));
        $this->assertSame([], $service->listApprovals(['status'=>'APPROVED'])['items']);
        $this->assertStringContainsString('採用されていない',$items[0]['approvalReviewReason']);
        $service->decideApproval($id,'APPROVED',null,$this->actor($e));
        $this->assertSame([null],array_column($service->listApprovals(['status'=>'PENDING'])['items'],'id'));
        $this->assertDatabaseCount('attendance_events',4);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'raw_clock_out_at'=>'2026-10-02 17:00:20']);
    }
}
