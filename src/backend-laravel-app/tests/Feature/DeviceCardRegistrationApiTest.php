<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class DeviceCardRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_list_active_employees_and_assign_card(): void
    {
        $deviceId = (int) DB::table('attendance_devices')->insertGetId([
            'device_code' => 'PC-TEST-01',
            'name' => 'テスト端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E8001',
            'name' => '登録 花子',
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
        DB::table('employees')->insert([
            'employee_code' => 'E8999',
            'name' => '退職 職員',
            'kana' => null,
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'RETIRED',
            'joined_on' => '2020-04-01',
            'retired_on' => '2026-03-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/attendance/card-registration/employees', $this->devicePayload())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employeeCode', 'E8001');

        $this->postJson('/api/attendance/card-registration/assign', [
            ...$this->devicePayload(),
            'employeeId' => $employeeId,
            'cardUid' => 'abc123',
        ])
            ->assertOk()
            ->assertJsonPath('data.employeeId', $employeeId)
            ->assertJsonPath('data.cardUid', 'ABC123');

        $this->assertDatabaseHas('employee_cards', [
            'employee_id' => $employeeId,
            'card_uid' => 'ABC123',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'DEVICE',
            'actor_id' => $deviceId,
            'action' => 'CARD_ASSIGNED_FROM_DEVICE',
        ]);
    }

    public function test_device_card_registration_rejects_wrong_secret(): void
    {
        DB::table('attendance_devices')->insert([
            'device_code' => 'PC-TEST-01',
            'name' => 'テスト端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/attendance/card-registration/employees', [
            'deviceCode' => 'PC-TEST-01',
            'deviceSecret' => 'wrong',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    private function devicePayload(): array
    {
        return [
            'deviceCode' => 'PC-TEST-01',
            'deviceSecret' => 'secret',
        ];
    }
}
