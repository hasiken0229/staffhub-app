<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuditLogAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_audit_logs_by_actor_and_action(): void
    {
        $adminId = DB::table('users')->insertGetId([
            'name' => '監査管理者',
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
            'joined_on' => '2024-04-01',
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

        DB::table('audit_logs')->insert([
            [
                'actor_type' => 'ADMIN',
                'actor_id' => $adminId,
                'action' => 'NOTICE_CREATED',
                'target_type' => 'NOTICE',
                'target_id' => '10',
                'detail_json' => json_encode(['title' => '4月のお知らせ', 'noticeType' => 'GENERAL'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => '2026-03-29 09:00:00',
                'ip_address' => '127.0.0.1',
            ],
            [
                'actor_type' => 'EMPLOYEE',
                'actor_id' => $employeeId,
                'action' => 'LEAVE_REQUEST_CREATED',
                'target_type' => 'LEAVE_REQUEST',
                'target_id' => '22',
                'detail_json' => json_encode(['leaveTypeName' => '有給休暇'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => '2026-03-28 10:30:00',
                'ip_address' => '127.0.0.2',
            ],
            [
                'actor_type' => 'DEVICE',
                'actor_id' => $deviceId,
                'action' => 'ATTENDANCE_ACCEPTED',
                'target_type' => 'ATTENDANCE_EVENT',
                'target_id' => '33',
                'detail_json' => json_encode(['employeeCode' => 'E001', 'eventType' => 'CLOCK_IN'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => '2026-03-27 08:00:00',
                'ip_address' => '127.0.0.3',
            ],
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/audit-logs?actor=監査管理者&action=NOTICE_CREATED&from=2026-03-29&to=2026-03-29');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actorType', 'ADMIN')
            ->assertJsonPath('data.0.actorLabel', '監査管理者 (管理者)')
            ->assertJsonPath('data.0.action', 'NOTICE_CREATED')
            ->assertJsonPath('data.0.targetType', 'NOTICE')
            ->assertJsonPath('data.0.ipAddress', '127.0.0.1');

        $this->assertStringContainsString('title: 4月のお知らせ', (string) $response->json('data.0.detail'));
    }
}
