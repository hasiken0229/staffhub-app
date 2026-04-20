<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceErrorRuleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_toggle_attendance_error_rule_and_custom_threshold_affects_detection(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('ER001');
        $this->dailyAttendance($employeeId, [
            'target_date' => '2026-09-01',
            'clock_in_at' => '2026-09-01 06:00:00',
            'clock_out_at' => '2026-09-01 19:10:00',
            'break_minutes' => 60,
            'work_minutes' => 730,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-09&toMonth=2026-09&errorCode=LONG_WORK')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.errorCode', 'LONG_WORK');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/system-masters/attendance-error-rules', [
                'code' => 'LONG_WORK',
                'name' => '長時間勤務',
                'minWorkMinutes' => 720,
                'enabled' => false,
                'sortOrder' => 80,
            ])
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'LONG_WORK',
                'enabled' => false,
            ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-09&toMonth=2026-09&errorCode=LONG_WORK')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/system-masters/attendance-error-rules', [
                'code' => 'LONG_WORK',
                'name' => '長時間勤務（10時間超）',
                'minWorkMinutes' => 600,
                'enabled' => true,
                'sortOrder' => 80,
            ])
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'LONG_WORK',
                'name' => '長時間勤務（10時間超）',
                'minWorkMinutes' => 600,
                'enabled' => true,
            ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-09&toMonth=2026-09&errorCode=LONG_WORK')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.errorName', '長時間勤務（10時間超）');
    }

    public function test_attendance_error_resolution_status_changes_are_recorded_as_histories(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('ER002');
        $this->dailyAttendance($employeeId, [
            'target_date' => '2026-09-02',
            'clock_in_at' => '2026-09-02 09:00:00',
            'clock_out_at' => '2026-09-02 19:10:00',
            'break_minutes' => 50,
            'work_minutes' => 560,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/errors/resolve', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-09-02',
                'errorCode' => 'SHORT_BREAK_OVER_8',
                'status' => 'IN_PROGRESS',
                'comment' => '確認中',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/errors/resolve', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-09-02',
                'errorCode' => 'SHORT_BREAK_OVER_8',
                'status' => 'IGNORED',
                'comment' => '園長確認済み',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'IGNORED');

        $this->assertDatabaseHas('attendance_error_resolution_histories', [
            'employee_id' => $employeeId,
            'target_date' => '2026-09-02',
            'error_code' => 'SHORT_BREAK_OVER_8',
            'old_status' => null,
            'new_status' => 'IN_PROGRESS',
            'comment' => '確認中',
        ]);
        $this->assertDatabaseHas('attendance_error_resolution_histories', [
            'employee_id' => $employeeId,
            'target_date' => '2026-09-02',
            'error_code' => 'SHORT_BREAK_OVER_8',
            'old_status' => 'IN_PROGRESS',
            'new_status' => 'IGNORED',
            'comment' => '園長確認済み',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-09&toMonth=2026-09&handlingStatus=IGNORED')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.errorCode', 'SHORT_BREAK_OVER_8')
            ->assertJsonPath('data.0.handlingStatus', 'IGNORED');

        $histories = $response->json('data.0.histories');
        $this->assertCount(2, $histories);
        $newStatuses = array_column($histories, 'newStatus');
        $this->assertContains('IN_PROGRESS', $newStatuses);
        $this->assertContains('IGNORED', $newStatuses);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-attendance-error@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-attendance-error@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => 'エラー 職員',
            'kana' => 'エラー ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-09-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dailyAttendance(int $employeeId, array $overrides = []): int
    {
        return (int) DB::table('attendance_daily')->insertGetId(array_merge([
            'employee_id' => $employeeId,
            'target_date' => '2026-09-01',
            'schedule_name' => '通常勤務',
            'work_type_id' => null,
            'raw_clock_in_at' => null,
            'raw_clock_out_at' => null,
            'clock_in_at' => '2026-09-01 09:00:00',
            'clock_out_at' => '2026-09-01 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'APPROVED',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'is_manually_edited' => 0,
            'manual_edited_by' => null,
            'manual_edited_at' => null,
            'updated_at' => now(),
        ], $overrides));
    }
}
