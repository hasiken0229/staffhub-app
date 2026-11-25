<?php

namespace Tests\Feature;

use App\Services\Attendance\DailyEditRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DailyEditRequestWorkTypeSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); CarbonImmutable::setTestNow('2026-10-10 12:00:00'); }
    protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }

    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'TYPE-SAFETY','name'=>'勤務区分確認 職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-04-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function workType(string $name): int
    {
        return DB::table('work_type_settings')->insertGetId(['name'=>$name,'is_active'=>1,'sort_order'=>90,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function daily(int $employee, ?int $workType, string $name): int
    {
        return DB::table('attendance_daily')->insertGetId(['employee_id'=>$employee,'target_date'=>'2026-10-09',
            'work_type_id'=>$workType,'schedule_name'=>$name,'raw_clock_in_at'=>'2026-10-09 08:30:17','raw_clock_out_at'=>'2026-10-09 17:00:34',
            'clock_in_at'=>'2026-10-09 08:30:17','clock_out_at'=>'2026-10-09 17:00:34','break_minutes'=>0,'work_minutes'=>510,
            'is_manually_edited'=>0,'approval_status'=>'PENDING','close_status'=>'OPEN','updated_at'=>now()]);
    }

    private function payload(array $changes=[]): array
    {
        return array_merge(['targetDate'=>'2026-10-09','clockInTime'=>'08:35','clockOutTime'=>'17:00',
            'remark'=>'時刻と備考の訂正','employeeComment'=>'出勤時刻を確認しました'], $changes);
    }

    private function approve(int $request): array
    {
        return app(DailyEditRequestService::class)->decideDailyEditRequest($request,'APPROVED','確認済み',new GenericUser(['id'=>1,'role'=>'ADMIN']));
    }

    public function test_omitted_work_type_is_inherited_and_time_correction_preserves_the_historical_schedule_name(): void
    {
        $employee=$this->employee();$type=$this->workType('早番（現在のマスタ名）');$daily=$this->daily($employee,$type,'早番（当時の区分名）');
        $request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,$this->payload());
        $this->assertSame($type,$request['workTypeId']);$this->assertSame('PENDING',$request['status']);
        $result=$this->approve($request['id']);$this->assertSame('APPROVED',$result['status']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'work_type_id'=>$type,'schedule_name'=>'早番（当時の区分名）',
            'clock_in_at'=>'2026-10-09 08:35:00','clock_out_at'=>'2026-10-09 17:00:34','remark'=>'時刻と備考の訂正',
            'raw_clock_in_at'=>'2026-10-09 08:30:17','raw_clock_out_at'=>'2026-10-09 17:00:34','approval_status'=>'APPROVED']);
    }

    public function test_null_work_type_does_not_clear_the_type_for_a_comment_only_correction(): void
    {
        $employee=$this->employee();$type=$this->workType('遅番');$daily=$this->daily($employee,$type,'遅番');
        $request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,
            $this->payload(['workTypeId'=>null,'clockInTime'=>'08:30','clockOutTime'=>'17:00']));
        $this->assertSame($type,$request['workTypeId']);$this->approve($request['id']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'work_type_id'=>$type,'schedule_name'=>'遅番',
            'clock_in_at'=>'2026-10-09 08:30:17','clock_out_at'=>'2026-10-09 17:00:34','work_minutes'=>510,
            'is_manually_edited'=>0,'remark'=>'時刻と備考の訂正','approval_status'=>'APPROVED']);
    }

    public function test_legacy_pending_null_request_preserves_the_current_daily_type_and_name_at_approval(): void
    {
        $employee=$this->employee();$old=$this->workType('旧早番');$current=$this->workType('変更後の勤務');$daily=$this->daily($employee,$old,'旧早番');
        $request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,$this->payload());
        // Simulate an already stored request from the old employee UI.
        DB::table('attendance_daily_edit_requests')->where('id',$request['id'])->update(['work_type_id'=>null]);
        DB::table('attendance_daily')->where('id',$daily)->update(['work_type_id'=>$current,'schedule_name'=>'変更後の勤務（当時名）']);
        $this->approve($request['id']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'work_type_id'=>$current,'schedule_name'=>'変更後の勤務（当時名）',
            'clock_in_at'=>'2026-10-09 08:35:00','approval_status'=>'APPROVED']);
    }

    public function test_explicit_different_nonnull_work_type_still_changes_the_daily_type(): void
    {
        $employee=$this->employee();$old=$this->workType('早番');$next=$this->workType('遅番');$daily=$this->daily($employee,$old,'早番');
        $request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,$this->payload(['workTypeId'=>$next]));
        $this->assertSame($next,$request['workTypeId']);$this->approve($request['id']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'work_type_id'=>$next,'schedule_name'=>'遅番','approval_status'=>'APPROVED']);
    }

    public function test_imported_schedule_name_without_a_master_id_is_preserved(): void
    {
        $employee=$this->employee();$daily=$this->daily($employee,null,'HARMOS原本の勤務区分');
        $request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,$this->payload(['workTypeId'=>null]));
        $this->assertNull($request['workTypeId']);$this->approve($request['id']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'work_type_id'=>null,'schedule_name'=>'HARMOS原本の勤務区分',
            'clock_in_at'=>'2026-10-09 08:35:00','approval_status'=>'APPROVED']);
    }

    public function test_missing_daily_can_still_be_created_by_approving_a_request_without_a_type(): void
    {
        $employee=$this->employee();$request=app(DailyEditRequestService::class)->createDailyEditRequest($employee,$this->payload());
        $this->assertNull($request['workTypeId']);$this->assertDatabaseCount('attendance_daily',0);
        $this->approve($request['id']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-10-09','work_type_id'=>null,
            'schedule_name'=>null,'clock_in_at'=>'2026-10-09 08:35:00','approval_status'=>'APPROVED']);
    }
}
