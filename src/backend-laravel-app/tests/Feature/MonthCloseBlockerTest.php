<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MonthCloseBlockerTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_close_is_blocked_by_pending_daily_open_errors_and_pending_edit_requests(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E401', '2026-06-10', '2026-06-10');
        $this->dailyAttendance($employeeId, [
            'target_date' => '2026-06-10',
            'clock_in_at' => '2026-06-10 09:00:00',
            'clock_out_at' => null,
            'work_minutes' => null,
            'approval_status' => 'PENDING',
        ]);
        DB::table('attendance_daily_edit_requests')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-06-10',
            'work_type_id' => null,
            'clock_in_at' => '2026-06-10 09:15:00',
            'clock_out_at' => '2026-06-10 18:15:00',
            'breaks_json' => json_encode([['startTime' => '12:00', 'endTime' => '13:00']]),
            'remark' => null,
            'employee_comment' => '打刻修正をお願いします',
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'decision_comment' => null,
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-06')
            ->assertOk()
            ->assertJsonPath('data.canClose', false)
            ->assertJsonPath('data.summary.unsubmittedDailyCount', 0)
            ->assertJsonPath('data.summary.pendingApprovalCount', 1)
            ->assertJsonPath('data.summary.openErrorCount', 1)
            ->assertJsonPath('data.summary.pendingDailyEditRequestCount', 1)
            ->assertJsonPath('data.blockers.0.code', 'PENDING_APPROVAL')
            ->assertJsonPath('data.blockers.1.code', 'OPEN_ERRORS')
            ->assertJsonPath('data.blockers.2.code', 'PENDING_DAILY_EDIT_REQUESTS')
            ->assertJsonPath('data.payrollBlockers.0.code', 'MONTH_NOT_CLOSED');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/month-close', [
                'targetMonth' => '2026-06',
                'status' => 'CLOSED',
                'note' => '締め処理',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonFragment(['message' => '承認待ち: 1件'])
            ->assertJsonFragment(['message' => '未対応・対応中エラー: 1件'])
            ->assertJsonFragment(['message' => '未処理の修正申請: 1件']);

        $this->assertDatabaseMissing('attendance_monthly_closes', [
            'target_year_month' => '2026-06',
            'status' => 'CLOSED',
        ]);
        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-06-10',
            'close_status' => 'OPEN',
        ]);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-month-close@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-month-close@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code, string $joinedOn, ?string $retiredOn = null): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => '月締 職員',
            'kana' => 'ツキジメ ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => $joinedOn,
            'retired_on' => $retiredOn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dailyAttendance(int $employeeId, array $overrides = []): int
    {
        return (int) DB::table('attendance_daily')->insertGetId(array_merge([
            'employee_id' => $employeeId,
            'target_date' => '2026-06-10',
            'schedule_name' => '通常勤務',
            'work_type_id' => null,
            'raw_clock_in_at' => null,
            'raw_clock_out_at' => null,
            'clock_in_at' => '2026-06-10 09:00:00',
            'clock_out_at' => '2026-06-10 18:00:00',
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
