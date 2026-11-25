<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class P1PunchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;
    private int $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = DB::table('attendance_devices')->insertGetId([
            'device_code'=>'P1-SYNTHETIC','name'=>'合成端末','device_secret_hash'=>Hash::make('synthetic-secret'),
            'is_active'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->employee = DB::table('employees')->insertGetId([
            'employee_code'=>'P1-SYNTHETIC','name'=>'合成職員','employment_type'=>'FULL_TIME',
            'status'=>'ACTIVE','joined_on'=>'2026-01-01','created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id'=>$this->employee,'card_uid'=>'P1-SYNTHETIC-CARD','is_active'=>1,
            'assigned_at'=>'2026-01-01 00:00:00','created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function payload(string $time='2026-10-12T08:30:00+09:00', string $key='P1-original'): array
    {
        return ['deviceCode'=>'P1-SYNTHETIC','deviceSecret'=>'synthetic-secret','cardUid'=>'P1-SYNTHETIC-CARD',
            'occurredAt'=>$time,'dedupeKey'=>$key,'appVersion'=>'synthetic-test'];
    }

    #[DataProvider('invalidDevices')]
    public function test_existing_key_never_bypasses_device_authentication(string $mode): void
    {
        $payload=$this->payload();
        $this->postJson('/api/attendance/punch',$payload)->assertOk();
        if ($mode==='missing') $payload['deviceCode']='P1-NO-SUCH-DEVICE';
        if ($mode==='secret') $payload['deviceSecret']='wrong-synthetic-secret';
        if ($mode==='disabled') DB::table('attendance_devices')->where('id',$this->device)->update(['is_active'=>0]);
        $before=DB::table('attendance_events')->first();

        $response=$this->postJson('/api/attendance/punch',$payload)->assertStatus(403);

        $this->assertArrayNotHasKey('data',$response->json());
        $this->assertStringNotContainsString('合成職員',$response->getContent());
        $this->assertEquals($before,DB::table('attendance_events')->first());
        $this->assertDatabaseCount('attendance_events',1);
    }

    public static function invalidDevices(): array { return [['missing'],['secret'],['disabled']]; }

    public function test_shared_terminal_can_read_only_its_current_day_confirmed_receipts_without_punching(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 18:00:00', 'Asia/Tokyo'));
        $this->postJson('/api/attendance/punch', $this->payload('2026-11-24T08:00:00+09:00', 'yesterday'))->assertOk();
        $this->postJson('/api/attendance/punch', $this->payload('2026-11-25T08:00:00+09:00', 'today'))->assertOk();
        $this->postJson('/api/attendance/punch', $this->payload('2026-11-25T08:03:01+09:00', 'unconfirmed'))->assertOk();
        $other = $this->payload('2026-11-25T17:00:00+09:00', 'another-device');
        $other['deviceCode'] = 'OTHER-TERMINAL';
        DB::table('attendance_devices')->insert([
            'device_code' => $other['deviceCode'], 'name' => '別のテスト端末',
            'device_secret_hash' => Hash::make($other['deviceSecret']), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/attendance/punch', $other)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
        $before = DB::table('attendance_events')->orderBy('id')->get();
        $dailyBefore = DB::table('attendance_daily')->orderBy('id')->get();
        $auth = ['deviceCode' => 'P1-SYNTHETIC', 'deviceSecret' => 'synthetic-secret'];
        $this->postJson('/api/attendance/devices/today-receipts', $auth)
            ->assertOk()->assertJsonCount(1, 'data.receipts')
            ->assertJsonPath('data.receipts.0.eventType', 'CLOCK_IN')
            ->assertJsonPath('data.receipts.0.occurredAt', '2026-11-25T08:00:00+09:00');
        $auth['deviceSecret'] = 'incorrect';
        $this->postJson('/api/attendance/devices/today-receipts', $auth)->assertForbidden();
        $this->assertEquals($before, DB::table('attendance_events')->orderBy('id')->get());
        $this->assertEquals($dailyBefore, DB::table('attendance_daily')->orderBy('id')->get());
    }

    #[DataProvider('conflictingPayloads')]
    public function test_same_key_with_different_input_is_409_and_original_is_unchanged(string $field): void
    {
        $payload=$this->payload();
        $this->postJson('/api/attendance/punch',$payload)->assertOk();
        $before=DB::table('attendance_events')->first();
        if ($field==='card') $payload['cardUid']='ANOTHER-SYNTHETIC-CARD';
        if ($field==='time') $payload['occurredAt']='2026-10-12T08:30:01+09:00';
        if ($field==='device') {
            DB::table('attendance_devices')->insert(['device_code'=>'P1-OTHER','name'=>'別の合成端末',
                'device_secret_hash'=>Hash::make('synthetic-secret'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $payload['deviceCode']='P1-OTHER';
        }

        $this->postJson('/api/attendance/punch',$payload)->assertStatus(409)->assertJsonPath('error.code','DEDUPE_CONFLICT');

        $this->assertEquals($before,DB::table('attendance_events')->first());
        $this->assertDatabaseCount('attendance_events',1);
    }

    public static function conflictingPayloads(): array { return [['card'],['time'],['device']]; }

    public function test_valid_replay_after_month_close_returns_same_event_without_any_new_rows(): void
    {
        $payload=$this->payload();
        $first=$this->postJson('/api/attendance/punch',$payload)->assertOk();
        DB::table('attendance_monthly_closes')->where('target_year_month','2026-10')->update(['status'=>'CLOSED']);
        $tables=['attendance_events','attendance_daily','attendance_monthly_closes','audit_logs'];
        $before=[];
        foreach ($tables as $table) $before[$table]=DB::table($table)->orderBy('id')->get()->toJson();

        $this->postJson('/api/attendance/punch',$payload)->assertOk()
            ->assertJsonPath('data.attendanceEventId',$first->json('data.attendanceEventId'));

        foreach ($tables as $table) $this->assertSame($before[$table],DB::table($table)->orderBy('id')->get()->toJson(),$table);
    }

    public function test_equivalent_timezone_and_subsecond_precision_are_normalized_for_replay(): void
    {
        $payload=$this->payload('2026-10-12T15:15:00.1234567Z');
        $id=$this->postJson('/api/attendance/punch',$payload)->assertOk()->json('data.attendanceEventId');
        $this->assertDatabaseHas('attendance_events',['id'=>$id,'occurred_at'=>'2026-10-13 00:15:00']);
        $this->assertDatabaseHas('attendance_daily',['target_date'=>'2026-10-13']);
        $payload['occurredAt']='2026-10-13T00:15:00.1234567+09:00';
        $this->postJson('/api/attendance/punch',$payload)->assertOk()->assertJsonPath('data.attendanceEventId',$id);
        $this->assertDatabaseCount('attendance_events',1);
    }

    public function test_reversed_arrival_is_saved_once_for_review_without_assigning_a_backward_clock_out(): void
    {
        $this->postJson('/api/attendance/punch',$this->payload('2026-10-12T17:30:00+09:00','P1-later'))
            ->assertOk()->assertJsonPath('data.eventType','CLOCK_IN');
        $before=DB::table('attendance_daily')->first();
        $payload=$this->payload('2026-10-12T08:30:00+09:00','P1-earlier');

        $result=$this->postJson('/api/attendance/punch',$payload)->assertOk()
            ->assertJsonPath('data.resultType','WARNING')->assertJsonPath('data.eventType',null);
        $this->assertStringContainsString('管理者',$result->json('data.resultMessage'));
        $this->assertDatabaseHas('attendance_events',['dedupe_key'=>'P1-earlier','occurred_at'=>'2026-10-12 08:30:00',
            'event_type'=>null,'receive_status'=>'REJECTED','rejection_reason'=>'OUT_OF_ORDER_PUNCH']);
        $this->assertEquals($before,DB::table('attendance_daily')->first());
        $this->postJson('/api/attendance/punch',$payload)->assertOk()->assertJsonPath('data.resultType','WARNING')
            ->assertJsonPath('data.attendanceEventId',$result->json('data.attendanceEventId'));
        $this->assertDatabaseCount('attendance_events',2);
        $this->assertDatabaseHas('audit_logs',['action'=>'ATTENDANCE_REVIEW_REQUIRED']);
    }

    #[DataProvider('protectedDays')]
    public function test_late_arrival_cannot_rewrite_approved_manual_or_closed_daily(string $column, mixed $value): void
    {
        $this->postJson('/api/attendance/punch',$this->payload())->assertOk();
        DB::table('attendance_daily')->where('employee_id',$this->employee)->update([$column=>$value]);
        $before=DB::table('attendance_daily')->first();

        $this->postJson('/api/attendance/punch',$this->payload('2026-10-12T17:30:00+09:00','P1-protected'))
            ->assertOk()->assertJsonPath('data.resultType','WARNING')->assertJsonPath('data.eventType',null);

        $this->assertEquals($before,DB::table('attendance_daily')->first());
        $this->assertDatabaseHas('attendance_events',['dedupe_key'=>'P1-protected','rejection_reason'=>'CONFIRMED_DAY_PUNCH']);
    }

    public static function protectedDays(): array { return [['approval_status','APPROVED'],['is_manually_edited',1],['close_status','CLOSED']]; }

    public function test_new_punch_for_closed_month_does_not_modify_originals(): void
    {
        $this->postJson('/api/attendance/punch',$this->payload())->assertOk();
        DB::table('attendance_monthly_closes')->where('target_year_month','2026-10')->update(['status'=>'CLOSED']);
        $before=DB::table('attendance_daily')->first();

        $this->postJson('/api/attendance/punch',$this->payload('2026-10-12T17:30:00+09:00','P1-closed'))
            ->assertStatus(422)->assertJsonPath('error.code','CLOSED_PERIOD');

        $this->assertEquals($before,DB::table('attendance_daily')->first());
        $this->assertDatabaseCount('attendance_events',1);
    }
}
