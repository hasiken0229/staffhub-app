<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendancePunchLimitApiTest extends TestCase
{
    use RefreshDatabase;

    private int $deviceId;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = (int) DB::table('attendance_devices')->insertGetId([
            'device_code' => 'PC-TEST-01',
            'name' => 'テスト端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E7001',
            'name' => '打刻 太郎',
            'kana' => null,
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id' => $this->employeeId,
            'card_uid' => 'ABCDEF1234567890',
            'is_active' => 1,
            'assigned_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_third_punch_on_same_day_is_rejected(): void
    {
        $this->postPunch('2026-06-18T08:30:00+09:00', 'punch-1')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->postPunch('2026-06-18T17:30:00+09:00', 'punch-2')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_OUT');

        $this->postPunch('2026-06-18T18:00:00+09:00', 'punch-3')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TOO_MANY_PUNCHES');

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employeeId,
            'device_id' => $this->deviceId,
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'TOO_MANY_PUNCHES',
            'dedupe_key' => 'punch-3',
        ]);
    }

    public function test_punch_within_three_minutes_is_rejected(): void
    {
        $this->postPunch('2026-06-18T08:30:00+09:00', 'punch-1')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN');

        $this->postPunch('2026-06-18T08:33:00+09:00', 'punch-2')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SHORT_INTERVAL_PUNCH')
            ->assertJsonPath('error.message', '直前の打刻から3分以内のため、打刻を受け付けできません。');

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employeeId,
            'device_id' => $this->deviceId,
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'SHORT_INTERVAL_PUNCH',
            'dedupe_key' => 'punch-2',
        ]);

        $this->assertDatabaseCount('attendance_daily', 1);
        $this->assertSame(1, DB::table('attendance_events')->where('employee_id', $this->employeeId)->where('receive_status', 'ACCEPTED')->count());
    }

    private function postPunch(string $occurredAt, string $dedupeKey)
    {
        return $this->postJson('/api/attendance/punch', [
            'deviceCode' => 'PC-TEST-01',
            'deviceSecret' => 'secret',
            'cardUid' => 'ABCDEF1234567890',
            'occurredAt' => $occurredAt,
            'dedupeKey' => $dedupeKey,
            'appVersion' => 'test',
        ]);
    }
}
