<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ShiftScheduleSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): int
    {
        $id = DB::table('users')->insertGetId(['name'=>'シフト管理者','email'=>'shift@example.test',
            'password'=>Hash::make('VerifyOnly-2026!'),'created_at'=>now(),'updated_at'=>now()]);
        $token = $this->postJson('/api/auth/login', ['loginId'=>'shift@example.test','password'=>'VerifyOnly-2026!','audience'=>'ADMIN'])
            ->assertOk()->json('data.accessToken');
        $this->withHeader('Authorization', 'Bearer '.$token);
        return $id;
    }

    private function employee(string $code='SHIFT-1'): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>$code,'name'=>'シフト確認 職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-04-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function payload(int $employee, array $changes=[]): array
    {
        return array_merge(['employeeId'=>$employee,'targetDate'=>'2026-10-20',
            'scheduledClockInTime'=>'09:00','scheduledClockOutTime'=>'18:00','note'=>'原本確認済み'], $changes);
    }

    private function daily(int $employee, array $changes=[]): int
    {
        return DB::table('attendance_daily')->insertGetId(array_merge(['employee_id'=>$employee,'target_date'=>'2026-10-20',
            'raw_clock_in_at'=>'2026-10-20 08:59:37','raw_clock_out_at'=>'2026-10-20 18:01:24',
            'clock_in_at'=>'2026-10-20 09:00:00','clock_out_at'=>'2026-10-20 18:01:24','break_minutes'=>61,'work_minutes'=>480,
            'is_manually_edited'=>1,'approval_status'=>'APPROVED','approved_by'=>$employee,'approved_at'=>'2026-10-21 09:00:00',
            'close_status'=>'OPEN','updated_at'=>now()], $changes));
    }

    public function test_invalid_month_date_and_clock_input_return_field_errors_without_saving(): void
    {
        $this->admin(); $employee=$this->employee();
        foreach (['2026-13','2026-00','invalid','2026-1'] as $month) {
            $this->getJson('/api/admin/attendance/shift-schedules?targetMonth='.$month)->assertStatus(422);
        }
        foreach ([['targetDate'=>'2026-02-30'],['targetDate'=>'2026/10/20'],['scheduledClockInTime'=>'25:00'],
            ['scheduledClockOutTime'=>'17:99'],['scheduledClockInTime'=>'9:00']] as $invalid) {
            $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,$invalid))
                ->assertStatus(422)->assertJsonPath('error.details.0.field',array_key_first($invalid));
        }
        $this->assertDatabaseCount('attendance_shift_schedules',0);
        $this->assertDatabaseMissing('audit_logs',['action'=>'ATTENDANCE_SHIFT_SAVED']);
    }

    public function test_unknown_employee_and_work_type_are_rejected_before_any_write(): void
    {
        $this->admin(); $employee=$this->employee();
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload(999999))->assertStatus(422);
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['workTypeId'=>999999]))->assertStatus(422);
        $this->assertDatabaseCount('attendance_shift_schedules',0);
    }

    public function test_invalid_employee_fallback_times_cannot_be_saved(): void
    {
        $this->admin();$employee=$this->employee();
        foreach ([['standardClockInTime'=>'25:00'],['standardClockOutTime'=>'17:99']] as $invalid) {
            $this->postJson('/api/admin/attendance/employee-settings',['employeeId'=>$employee,...$invalid])
                ->assertStatus(422)->assertJsonPath('error.details.0.field',array_key_first($invalid));
        }
        $this->assertDatabaseCount('employee_attendance_settings',0);
        $this->postJson('/api/admin/attendance/employee-settings',['employeeId'=>$employee,
            'standardClockInTime'=>'08:30','standardClockOutTime'=>'17:30'])->assertOk();
        $this->assertDatabaseHas('employee_attendance_settings',['employee_id'=>$employee,'standard_clock_in_time'=>'08:30']);
    }

    public function test_month_close_and_closed_daily_both_block_schedule_changes(): void
    {
        $this->admin(); $employee=$this->employee();
        DB::table('attendance_monthly_closes')->insert(['target_year_month'=>'2026-10','status'=>'CLOSED','created_at'=>now(),'updated_at'=>now()]);
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee))
            ->assertStatus(422)->assertJsonPath('error.code','CLOSED_PERIOD');
        DB::table('attendance_monthly_closes')->where('target_year_month','2026-10')->update(['status'=>'OPEN']);
        $this->daily($employee,['close_status'=>'CLOSED']);
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee))
            ->assertStatus(422)->assertJsonPath('error.code','CLOSED_PERIOD');
        $this->assertDatabaseCount('attendance_shift_schedules',0);
        $this->assertDatabaseMissing('audit_logs',['action'=>'ATTENDANCE_SHIFT_SAVED']);
    }

    public function test_same_day_update_preserves_created_at_and_retry_is_a_noop_with_real_actor_audit(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 10:00:00'));
        $admin=$this->admin(); $employee=$this->employee();
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee))->assertOk()->assertJsonCount(1,'data');
        $original=DB::table('attendance_shift_schedules')->first();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 10:01:00'));
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['scheduledClockInTime'=>'08:30']))->assertOk();
        $updated=DB::table('attendance_shift_schedules')->first();
        $this->assertSame($original->id,$updated->id);$this->assertSame($original->created_at,$updated->created_at);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 10:02:00'));
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['scheduledClockInTime'=>'08:30']))->assertOk();
        $this->assertSame((array)$updated,(array)DB::table('attendance_shift_schedules')->first());
        $this->assertSame(2,DB::table('audit_logs')->where('action','ATTENDANCE_SHIFT_SAVED')->where('actor_type','ADMIN')->where('actor_id',$admin)->count());
        $audit=json_decode(DB::table('audit_logs')->where('action','ATTENDANCE_SHIFT_SAVED')->orderByDesc('id')->value('detail_json'),true);
        $this->assertSame('09:00:00',$audit['before']['scheduled_clock_in_time']);
        $this->assertSame('08:30:00',$audit['after']['scheduled_clock_in_time']);
    }

    public function test_valid_overnight_and_partial_fallback_are_kept_but_reverse_order_is_rejected(): void
    {
        $this->admin();$employee=$this->employee();
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['scheduledClockInTime'=>'22:00','scheduledClockOutTime'=>'02:00']))
            ->assertOk()->assertJsonPath('data.0.scheduledClockOutTime','02:00');
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee,'standard_clock_in_time'=>'09:00',
            'standard_clock_out_time'=>'18:00','include_before_start'=>0,'include_after_end'=>0]);
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['scheduledClockInTime'=>null]))->assertOk();
        $this->assertDatabaseHas('attendance_shift_schedules',['employee_id'=>$employee,'scheduled_clock_in_time'=>null,'scheduled_clock_out_time'=>'18:00:00']);
        foreach ([['scheduledClockOutTime'=>'08:00'],['scheduledClockInTime'=>'22:00','scheduledClockOutTime'=>'06:00'],
            ['scheduledClockInTime'=>null,'scheduledClockOutTime'=>'08:30']] as $invalid) {
            $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,$invalid))
                ->assertStatus(422)->assertJsonPath('error.details.0.field','scheduledClockOutTime');
        }
        $this->assertDatabaseHas('attendance_shift_schedules',['employee_id'=>$employee,'scheduled_clock_out_time'=>'18:00:00']);
    }

    public function test_schedule_save_does_not_rewrite_raw_adopted_manual_approved_records_or_breaks(): void
    {
        $this->admin();$employee=$this->employee();$daily=$this->daily($employee);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$daily,'segment_no'=>1,
            'break_start_at'=>'2026-10-20 12:00:13','break_end_at'=>'2026-10-20 13:01:13','created_at'=>now(),'updated_at'=>now()]);
        $beforeDaily=(array)DB::table('attendance_daily')->where('id',$daily)->first();
        $beforeBreak=(array)DB::table('attendance_daily_breaks')->first();
        $this->postJson('/api/admin/attendance/shift-schedules',$this->payload($employee,['scheduledClockInTime'=>'10:00']))->assertOk();
        $this->assertSame($beforeDaily,(array)DB::table('attendance_daily')->where('id',$daily)->first());
        $this->assertSame($beforeBreak,(array)DB::table('attendance_daily_breaks')->first());
    }
}
