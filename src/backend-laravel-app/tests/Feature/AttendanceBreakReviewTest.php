<?php

namespace Tests\Feature;

use App\Services\Attendance\ConfirmedAttendancePolicy;
use App\Services\Attendance\DailyAttendanceService;
use Carbon\CarbonImmutable as Date;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AttendanceBreakReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_detail_warn_without_changing_breaks_raw_records_or_histories(): void
    {
        Date::setTestNow('2026-10-28 12:00:00');
        try {
            $employee = DB::table('employees')->insertGetId(['employee_code'=>'BR1','name'=>'休憩 確認','kana'=>'キュウケイ カクニン','employment_type'=>'PART_TIME','status'=>'ACTIVE','joined_on'=>'2026-09-01','created_at'=>now(),'updated_at'=>now()]);
            // The confirmed allocation stays at 45 minutes for exactly nine elapsed hours.
            $allocation = ConfirmedAttendancePolicy::breaks(Date::parse('2026-10-02 09:00:00'), Date::parse('2026-10-02 18:00:00'), ['policyKind'=>'PART_TIME'], '2026-10-02');
            $this->assertSame(45, $allocation['minutes']);
            $id = DB::table('attendance_daily')->insertGetId(['employee_id'=>$employee,'target_date'=>'2026-10-02','raw_clock_in_at'=>'2026-10-02 09:00:00','raw_clock_out_at'=>'2026-10-02 18:00:00','clock_in_at'=>'2026-10-02 09:00:00','clock_out_at'=>'2026-10-02 18:00:00','break_minutes'=>45,'work_minutes'=>495,'approval_status'=>'APPROVED','close_status'=>'CLOSED','updated_at'=>now()]);
            $segment = $allocation['breaks'][0];
            DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,'break_start_at'=>$segment['start'],'break_end_at'=>$segment['end'],'created_at'=>now(),'updated_at'=>now()]);
            $before = [DB::table('attendance_daily')->get()->all(), DB::table('attendance_daily_breaks')->get()->all(), DB::table('attendance_daily_histories')->get()->all()];

            $service = app(DailyAttendanceService::class);
            $list = $service->listDaily(['from'=>'2026-10-01','to'=>'2026-10-31'])['items'];
            $this->assertCount(1, $list);
            foreach ([$list[0], $service->detail($id)] as $daily) {
                $this->assertSame(45, $daily['breakMinutes']);
                $this->assertSame(495, $daily['workMinutes']);
                $this->assertSame('2026-10-02T09:00:00+09:00', $daily['rawClockInAt']);
                $this->assertSame('AUTOMATIC', $daily['breakReview']['source']);
                $this->assertSame('UNCONFIRMED', $daily['breakReview']['actualBreakStatus']);
                $this->assertSame(60, $daily['breakReview']['requiredMinutes']);
                $this->assertSame(15, $daily['breakReview']['shortageMinutes']);
                $this->assertTrue($daily['breakReview']['needsConfirmation']);
                $this->assertContains('BREAK_REVIEW_REQUIRED', array_column($daily['alerts'], 'code'));
                $this->assertSame('APPROVED', $daily['approvalStatus']);
                $this->assertSame('CLOSED', $daily['closeStatus']);
            }

            $after = [DB::table('attendance_daily')->get()->all(), DB::table('attendance_daily_breaks')->get()->all(), DB::table('attendance_daily_histories')->get()->all()];
            $this->assertEquals($before, $after);
        } finally {
            Date::setTestNow();
        }
    }
}
