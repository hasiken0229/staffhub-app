<?php

namespace Tests\Feature;

use App\Services\Attendance\DailyEditService;
use App\Services\Attendance\PunchService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmployeeShortTimePunchPolicyTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-24 17:00:00');
        $this->employeeId = DB::table('employees')->insertGetId([
            'employee_code' => '970017', 'name' => 'テスト職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2026-01-01',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function punchPairs(): array
    {
        return [
            'default hours and early punch' => ['08:20:10','14:46:00','08:30:00','14:30:00',360],
            'seconds within scheduled starting minute' => ['08:30:51','14:30:00','08:30:00','14:30:00',360],
            'late arrival and later actual end' => ['09:31:37','14:55:44','09:31:37','14:55:44',324],
            'late long day retains full end punch' => ['09:01:28','15:45:20','09:01:28','15:45:20',360],
            'less than six hours by one second' => ['09:01:28','15:01:27','09:01:28','15:01:27',359],
            'early departure' => ['08:22:06','13:00:00','08:30:00','13:00:00',270],
        ];
    }

    #[DataProvider('punchPairs')]
    public function test_minutes_and_effective_times_are_separate(string $in, string $out, string $acceptedIn, string $acceptedOut, int $minutes): void
    {
        $date = '2026-11-12';
        $result = app(PunchService::class)->previewAutomaticPunches($this->employeeId, $date, "$date $in", "$date $out");
        $this->assertSame("$date $acceptedIn", $result['clockInAt']);
        $this->assertSame("$date $acceptedOut", $result['clockOutAt']);
        $this->assertSame($minutes, $result['workMinutes']);
        $this->assertSame(0, $result['breakMinutes']);
        $this->assertNull($result['inferredSide']);
        $this->assertDatabaseCount('attendance_events', 0);
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public function test_per_employee_settings_shifts_and_old_periods_keep_their_own_rules(): void
    {
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $this->employeeId, 'standard_clock_in_time' => '08:30', 'standard_clock_out_time' => '14:30',
            'include_before_start' => false, 'include_after_end' => false,
        ]);
        $service = app(PunchService::class);
        foreach (['2026-09-07', '2026-10-30'] as $date) {
            $old = $service->previewAutomaticPunches($this->employeeId, $date, "$date 09:31:37", "$date 14:55:44");
            $this->assertSame("$date 14:30:00", $old['clockOutAt']);
            $this->assertSame(298, $old['workMinutes']);
            $onTime = $service->previewAutomaticPunches($this->employeeId, $date, "$date 08:30:51", "$date 14:30:00");
            $this->assertSame(359, $onTime['workMinutes']);
        }
        $firstDay = '2026-11-01';
        $first = $service->previewAutomaticPunches($this->employeeId, $firstDay, "$firstDay 09:31:37", "$firstDay 15:55:44");
        $this->assertSame("$firstDay 15:55:44", $first['clockOutAt']);
        $this->assertSame(360, $first['workMinutes']);
        DB::table('employee_attendance_settings')->where('employee_id', $this->employeeId)->update([
            'standard_clock_in_time'=>'09:00', 'standard_clock_out_time'=>'15:00',
        ]);
        $date = '2026-11-13';
        $custom = $service->previewAutomaticPunches($this->employeeId, $date, "$date 08:59:08", "$date 15:12:22");
        $this->assertSame("$date 08:59:08", $custom['clockInAt']);
        $this->assertSame("$date 15:12:22", $custom['clockOutAt']);
        $this->assertSame(360, $custom['workMinutes']);
        DB::table('attendance_shift_schedules')->insert([
            'employee_id'=>$this->employeeId, 'target_date'=>$date, 'scheduled_clock_in_time'=>'10:00', 'scheduled_clock_out_time'=>'16:00',
        ]);
        $shift = $service->previewAutomaticPunches($this->employeeId, $date, "$date 09:58:00", "$date 16:04:00");
        $this->assertSame("$date 09:58:00", $shift['clockInAt']);
        $this->assertSame("$date 16:04:00", $shift['clockOutAt']);
        $this->assertSame(360, $shift['workMinutes']);
        $missingShiftEnd = $service->previewAutomaticPunches($this->employeeId, $date, "$date 09:58:00", null);
        $this->assertSame("$date 10:00:00", $missingShiftEnd['clockInAt']);
        $this->assertSame("$date 16:00:00", $missingShiftEnd['clockOutAt']);
        $this->assertSame('CLOCK_OUT', $missingShiftEnd['inferredSide']);
        $other = DB::table('employees')->insertGetId(['employee_code'=>'970002','name'=>'比較用','employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-01-01']);
        $regular = $service->previewAutomaticPunches($other, $date, "$date 09:00:00", "$date 17:00:00");
        $this->assertSame(435, $regular['workMinutes']);
    }

    public function test_inference_requires_a_missing_side_and_never_invents_a_later_shift(): void
    {
        $service = app(PunchService::class);
        $date = '2026-11-16';
        $past = $service->previewAutomaticPunches($this->employeeId, $date, "$date 09:40:00", null);
        $this->assertSame('CLOCK_OUT', $past['inferredSide']);
        $this->assertSame("$date 14:30:00", $past['clockOutAt']);
        $this->assertSame(290, $past['workMinutes']);
        $onlyOut = $service->previewAutomaticPunches($this->employeeId, $date, null, "$date 15:10:00");
        $this->assertSame('CLOCK_IN', $onlyOut['inferredSide']);
        $this->assertSame("$date 08:30:00", $onlyOut['clockInAt']);
        $this->assertSame("$date 14:30:00", $onlyOut['clockOutAt']);
        $today = $service->previewAutomaticPunches($this->employeeId, '2026-11-24', '2026-11-24 09:40:00', null);
        $this->assertNull($today['clockOutAt']);
        $this->assertNull($today['workMinutes']);
        $withBreak = $service->previewAutomaticPunches($this->employeeId, $date, "$date 09:00:00", "$date 15:15:00", 30);
        $this->assertSame(30, $withBreak['breakMinutes']);
        $this->assertSame(345, $withBreak['workMinutes']);
    }

    public function test_card_punch_and_explicit_reset_share_calculation_and_preserve_the_raw_values_and_leave(): void
    {
        $date = '2026-11-17';
        DB::table('attendance_devices')->insert(['device_code'=>'SHORT-TEST','name'=>'確認用端末','device_secret_hash'=>Hash::make('synthetic'),'is_active'=>1]);
        DB::table('employee_cards')->insert(['employee_id'=>$this->employeeId,'card_uid'=>'SHORT-TEST','is_active'=>1,'assigned_at'=>'2026-01-01 00:00:00']);
        $payload = ['deviceCode'=>'SHORT-TEST', 'deviceSecret'=>'synthetic', 'cardUid'=>'SHORT-TEST'];
        $this->postJson('/api/attendance/punch', $payload+['occurredAt'=>"$date"."T09:01:23+09:00",'dedupeKey'=>'short-in'])->assertOk();
        $day = DB::table('attendance_daily')->where('employee_id', $this->employeeId)->where('target_date', $date);
        $day->update(['hour_paid_leave_minutes'=>60, 'break_minutes'=>45]);
        DB::table('attendance_daily_breaks')->insert([
            'attendance_daily_id'=>(clone $day)->value('id'), 'segment_no'=>1,
            'break_start_at'=>"$date 12:00:00", 'break_end_at'=>"$date 12:45:00",
        ]);
        $this->postJson('/api/attendance/punch', $payload+['occurredAt'=>"$date"."T15:39:44+09:00",'dedupeKey'=>'short-out'])->assertOk();
        $expected = ['employee_id'=>$this->employeeId,'target_date'=>$date,'clock_in_at'=>"$date 09:01:23",'clock_out_at'=>"$date 15:39:44",
            'raw_clock_in_at'=>"$date 09:01:23",'raw_clock_out_at'=>"$date 15:39:44",'break_minutes'=>0,'work_minutes'=>360,'hour_paid_leave_minutes'=>60];
        $this->assertDatabaseHas('attendance_daily', array_merge($expected, ['break_minutes'=>45, 'work_minutes'=>353]));
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseCount('attendance_daily_breaks', 1);
        $day->update(['is_manually_edited'=>1,'clock_out_at'=>"$date 14:59:00",'work_minutes'=>357]);
        $this->postJson('/api/attendance/punch', $payload+['occurredAt'=>"$date"."T15:59:44+09:00",'dedupeKey'=>'short-confirmed'])->assertOk()->assertJsonPath('data.resultType','WARNING');
        $this->assertSame(357,(int) $day->value('work_minutes'));
        app(DailyEditService::class)->resetManualEdit((int)(clone $day)->value('id'), new GenericUser(['id'=>$this->employeeId,'role'=>'ADMIN']));
        $this->assertDatabaseHas('attendance_daily', $expected+['is_manually_edited'=>0]);
    }
}

