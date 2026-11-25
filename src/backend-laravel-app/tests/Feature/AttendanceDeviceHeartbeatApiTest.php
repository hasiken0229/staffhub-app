<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceDeviceHeartbeatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('attendance_devices')->insert([
            'device_code' => 'PC-ENTRANCE-01',
            'name' => '玄関打刻端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('test-secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_heartbeat_uses_server_reception_time_and_records_client_status(): void
    {
        Carbon::setTestNow('2026-08-30 09:00:00+09:00');

        $this->postJson('/api/attendance/devices/heartbeat', [
            'deviceCode' => 'PC-ENTRANCE-01',
            'deviceSecret' => 'test-secret',
            'appVersion' => '1.2.3',
            'lastSeenAt' => '2026-08-30T09:01:30+09:00',
            'pendingOfflineCount' => 4,
            'readerStatus' => 'RC-S380 接続済',
        ])
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.deviceActive', true)
            ->assertJsonPath('data.clockDriftSeconds', 90)
            ->assertJsonPath('data.serverTime', '2026-08-30T09:00:00+09:00');

        $this->assertDatabaseHas('attendance_devices', [
            'device_code' => 'PC-ENTRANCE-01',
            'app_version' => '1.2.3',
            'last_seen_at' => '2026-08-30 09:00:00',
            'client_time_at' => '2026-08-30 09:01:30',
            'clock_drift_seconds' => 90,
            'pending_offline_count' => 4,
            'reader_status' => 'RC-S380 接続済',
        ]);
    }

    public function test_admin_can_list_attendance_device_status(): void
    {
        DB::table('attendance_devices')
            ->where('device_code', 'PC-ENTRANCE-01')
            ->update([
                'app_version' => '1.2.3',
                'last_seen_at' => '2026-08-30 09:00:00',
                'client_time_at' => '2026-08-30 09:01:30',
                'clock_drift_seconds' => 90,
                'pending_offline_count' => 4,
                'reader_status' => 'RC-S380 接続済',
            ]);

        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/attendance/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deviceCode', 'PC-ENTRANCE-01')
            ->assertJsonPath('data.0.name', '玄関打刻端末')
            ->assertJsonPath('data.0.locationName', '玄関')
            ->assertJsonPath('data.0.appVersion', '1.2.3')
            ->assertJsonPath('data.0.clockDriftSeconds', 90)
            ->assertJsonPath('data.0.pendingOfflineCount', 4)
            ->assertJsonPath('data.0.readerStatus', 'RC-S380 接続済')
            ->assertJsonPath('data.0.isActive', true);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '端末確認管理者',
            'email' => 'admin-device-status@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-device-status@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }
}
