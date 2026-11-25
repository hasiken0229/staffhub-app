<?php

namespace Tests\Feature;

use App\Services\Reports\AttendanceRoundingReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AttendanceRoundingReviewTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $type='FULL_TIME'): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'P2-SECONDS','name'=>'合成職員',
            'employment_type'=>$type,'status'=>'ACTIVE','joined_on'=>'2026-01-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function day(int $id,string $date,int $seconds,int $minutes): int
    {
        $start=CarbonImmutable::parse($date.' 08:30:00');
        return DB::table('attendance_daily')->insertGetId(['employee_id'=>$id,'target_date'=>$date,
            'clock_in_at'=>$start->format('Y-m-d H:i:s'),'clock_out_at'=>$start->addSeconds($seconds)->format('Y-m-d H:i:s'),
            'break_minutes'=>0,'work_minutes'=>$minutes,'is_manually_edited'=>1,'updated_at'=>now()]);
    }

    #[DataProvider('secondBoundaries')]
    public function test_second_boundaries_are_reviewed_without_changing_stored_minutes(int $seconds,int $minutes,int $remainder): void
    {
        $id=$this->employee();$this->day($id,'2026-09-01',$seconds,$minutes);
        $before=DB::table('attendance_daily')->first();
        $result=app(AttendanceRoundingReviewService::class)->review('2026-09',$id)['employees'][0];
        $this->assertSame($seconds,$result['comparableSeconds']);
        $this->assertSame($minutes,$result['monthlyFloorMinutes']);
        $this->assertSame($remainder,$result['retainedRemainderSeconds']);
        $this->assertEquals($before,DB::table('attendance_daily')->first());
    }

    public static function secondBoundaries(): array { return [[59,0,59],[60,1,0],[61,1,1]]; }

    public function test_thirty_days_of_59_second_remainders_report_29_minutes_without_applying_it(): void
    {
        $id=$this->employee();
        for ($day=1;$day<=30;$day++) $this->day($id,sprintf('2026-09-%02d',$day),21659,360);
        $before=DB::table('attendance_daily')->orderBy('id')->get()->toJson();
        $review=app(AttendanceRoundingReviewService::class)->review('2026-09',$id);
        $result=$review['employees'][0];
        $this->assertSame(10800,$result['comparableStoredMinutes']);
        $this->assertSame(10829,$result['monthlyFloorMinutes']);
        $this->assertSame(29,$result['differenceMinutes']);
        $this->assertSame(1770,$result['retainedRemainderSeconds']);
        $this->assertFalse($review['payrollValuesChanged']);$this->assertFalse($review['roundingRuleChanged']);
        $this->assertSame($before,DB::table('attendance_daily')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('import_histories',0);
    }

    public function test_segment_seconds_are_a_separate_reference_not_an_observed_break_or_new_deduction(): void
    {
        $id=$this->employee();$daily=$this->day($id,'2026-09-01',240,4);
        foreach ([1,2] as $part) DB::table('attendance_daily_breaks')->insert([
            'attendance_daily_id'=>$daily,'segment_no'=>$part,'break_start_at'=>sprintf('2026-09-01 08:%02d:00',30+$part),
            'break_end_at'=>sprintf('2026-09-01 08:%02d:59',30+$part),'created_at'=>now(),'updated_at'=>now()]);
        $result=app(AttendanceRoundingReviewService::class)->review('2026-09',$id);
        $row=$result['employees'][0]['rows'][0];
        $this->assertSame(118,$row['savedBreakSegmentSeconds']);
        $this->assertSame(122,$row['segmentBasedNetSeconds']);
        $this->assertSame(4,$row['storedMinutes']);
        $this->assertFalse($result['breaksObserved']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$daily,'break_minutes'=>0,'work_minutes'=>4]);
    }

    public function test_imported_missing_and_different_values_are_not_silently_recalculated_or_counted_as_zero(): void
    {
        $id=$this->employee();$a=$this->day($id,'2026-09-01',21659,519);$b=$this->day($id,'2026-09-02',21659,360);
        DB::table('attendance_daily')->where('id',$a)->update(['source_system'=>'HARMOS']);
        DB::table('attendance_daily')->where('id',$b)->update(['work_minutes'=>null]);
        $result=app(AttendanceRoundingReviewService::class)->review('2026-09',$id)['employees'][0];
        $this->assertSame(519,$result['knownStoredMinutes']);$this->assertSame(1,$result['missingStoredMinuteRows']);
        $this->assertSame(0,$result['comparableDayCount']);$this->assertNull($result['monthlyFloorMinutes']);
        $this->assertNull($result['differenceMinutes']);
        $this->assertSame('IMPORTED_VALUE_RETAINED',$result['rows'][0]['reason']);
        $this->assertSame('INCOMPLETE',$result['rows'][1]['reason']);
    }

    public function test_part_time_period_uses_the_existing_cutoff_and_excludes_neighboring_dates(): void
    {
        $id=$this->employee('PART_TIME');
        foreach (['2026-08-20','2026-08-21','2026-09-20','2026-09-21'] as $date) $this->day($id,$date,61,1);
        $result=app(AttendanceRoundingReviewService::class)->review('2026-09',$id)['employees'][0];
        $this->assertSame('2026-08-21',$result['periodStart']);$this->assertSame('2026-09-20',$result['periodEnd']);
        $this->assertSame(2,$result['comparableDayCount']);$this->assertSame(122,$result['comparableSeconds']);
        $this->assertSame(['2026-08-21','2026-09-20'],array_column($result['rows'],'targetDate'));
    }

    public function test_review_endpoint_requires_admin_and_does_not_create_an_export_history(): void
    {
        $id=$this->employee();$this->day($id,'2026-09-01',61,1);
        $this->getJson('/api/admin/reports/rounding-review?targetMonth=2026-09')->assertStatus(401);
        DB::table('users')->insert(['name'=>'合成管理者','email'=>'rounding@example.test','password'=>Hash::make('synthetic-pass')]);
        $token=$this->postJson('/api/auth/login',['audience'=>'ADMIN','loginId'=>'rounding@example.test','password'=>'synthetic-pass'])
            ->assertOk()->json('data.accessToken');
        $this->withHeader('Authorization','Bearer '.$token)->getJson('/api/admin/reports/rounding-review?targetMonth=2026-09')
            ->assertOk()->assertJsonPath('data.readOnly',true)->assertJsonPath('data.employees.0.comparableSeconds',61);
        $this->assertDatabaseCount('import_histories',0);$this->assertDatabaseCount('attendance_daily',1);
    }
}
