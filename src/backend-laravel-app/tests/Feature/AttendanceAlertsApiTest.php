<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_grid_includes_missing_and_short_interval_alerts(): void
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'E001',
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = DB::table('attendance_devices')->insertGetId([
            'device_code' => 'RC-01',
            'name' => '玄関端末',
            'location_name' => '玄関',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-02',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-02 09:00:00',
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'remark' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'updated_at' => now(),
        ]);

        DB::table('attendance_events')->insert([
            [
                'employee_id' => $employeeId,
                'device_id' => $deviceId,
                'card_uid' => 'CARD-001',
                'occurred_at' => '2026-04-02 09:00:00',
                'event_type' => 'CLOCK_IN',
                'source_type' => 'CARD_READER',
                'receive_status' => 'ACCEPTED',
                'rejection_reason' => null,
                'offline_saved' => 0,
                'dedupe_key' => 'dedupe-1',
                'created_at' => now(),
            ],
            [
                'employee_id' => $employeeId,
                'device_id' => $deviceId,
                'card_uid' => 'CARD-001',
                'occurred_at' => '2026-04-02 09:01:00',
                'event_type' => 'CLOCK_OUT',
                'source_type' => 'CARD_READER',
                'receive_status' => 'ACCEPTED',
                'rejection_reason' => null,
                'offline_saved' => 0,
                'dedupe_key' => 'dedupe-2',
                'created_at' => now(),
            ],
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/daily-grid?targetMonth=2026-04');

        $response->assertOk()
            ->assertJsonPath('data.0.employeeCode', 'E001')
            ->assertJsonPath('data.0.alerts.0.code', 'UNCLOCKED_OUT')
            ->assertJsonPath('data.0.alerts.1.code', 'MISSING_PUNCH')
            ->assertJsonPath('data.0.alerts.2.code', 'SHORT_INTERVAL');

        $this->assertStringContainsString('未退勤アラート', (string) $response->json('data.0.alertSummary'));
        $this->assertStringContainsString('打刻漏れ', (string) $response->json('data.0.alertSummary'));
        $this->assertStringContainsString('短時間の連続打刻', (string) $response->json('data.0.alertSummary'));
    }
}
