<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_decide_individual_attendance_approval(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E501');
        $dailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-07-01',
            'clock_in_at' => '2026-07-01 09:00:00',
            'clock_out_at' => '2026-07-01 18:00:00',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/approvals?status=PENDING&from=2026-07-01&to=2026-07-31&employeeCode=E501')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $dailyId)
            ->assertJsonPath('data.0.employeeCode', 'E501')
            ->assertJsonPath('data.0.approvalStatus', 'PENDING');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/approvals/' . $dailyId . '/approve', [
                'comment' => '確認済み',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $dailyId)
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.comment', '確認済み');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $dailyId,
            'approval_status' => 'APPROVED',
            'approval_comment' => '確認済み',
            'approved_by' => $employeeId,
        ]);
        $this->assertDatabaseHas('attendance_daily_histories', [
            'attendance_daily_id' => $dailyId,
            'action_type' => 'ATTENDANCE_DAILY_APPROVED',
            'field_key' => 'approvalStatus',
            'old_value' => 'PENDING',
            'new_value' => 'APPROVED',
            'comment' => '確認済み',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'ADMIN',
            'action' => 'ATTENDANCE_DAILY_APPROVED',
            'target_type' => 'ATTENDANCE_DAILY',
            'target_id' => (string) $dailyId,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/approvals?status=APPROVED&from=2026-07-01&to=2026-07-31&employeeCode=E501')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $dailyId)
            ->assertJsonPath('data.0.approvalStatus', 'APPROVED')
            ->assertJsonPath('data.0.approvalComment', '確認済み');
    }

    public function test_admin_can_bulk_approve_and_bulk_return_attendance_approvals(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E502');
        $firstDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-07-02',
            'clock_in_at' => '2026-07-02 09:00:00',
            'clock_out_at' => '2026-07-02 18:00:00',
        ]);
        $secondDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-07-03',
            'clock_in_at' => '2026-07-03 09:00:00',
            'clock_out_at' => '2026-07-03 18:00:00',
        ]);
        $approvedDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-07-04',
            'clock_in_at' => '2026-07-04 09:00:00',
            'clock_out_at' => '2026-07-04 18:00:00',
            'approval_status' => 'APPROVED',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/approvals/bulk-approve', [
                'ids' => [$firstDailyId, $secondDailyId, $firstDailyId],
                'comment' => '一括承認',
            ])
            ->assertOk()
            ->assertJsonPath('data.updatedCount', 2)
            ->assertJsonPath('data.items.0.id', $firstDailyId)
            ->assertJsonPath('data.items.0.status', 'APPROVED')
            ->assertJsonPath('data.items.1.id', $secondDailyId)
            ->assertJsonPath('data.items.1.status', 'APPROVED');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $firstDailyId,
            'approval_status' => 'APPROVED',
            'approval_comment' => '一括承認',
        ]);
        $this->assertDatabaseHas('attendance_daily', [
            'id' => $secondDailyId,
            'approval_status' => 'APPROVED',
            'approval_comment' => '一括承認',
        ]);
        $this->assertSame(1, DB::table('attendance_daily_histories')
            ->where('attendance_daily_id', $firstDailyId)
            ->where('action_type', 'ATTENDANCE_DAILY_APPROVED')
            ->count());

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/approvals/bulk-return', [
                'ids' => [$approvedDailyId],
                'comment' => '要確認のため差戻し',
            ])
            ->assertOk()
            ->assertJsonPath('data.updatedCount', 1)
            ->assertJsonPath('data.items.0.id', $approvedDailyId)
            ->assertJsonPath('data.items.0.status', 'RETURNED');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $approvedDailyId,
            'approval_status' => 'RETURNED',
            'approval_comment' => '要確認のため差戻し',
        ]);
        $this->assertDatabaseHas('attendance_daily_histories', [
            'attendance_daily_id' => $approvedDailyId,
            'action_type' => 'ATTENDANCE_DAILY_RETURNED',
            'field_key' => 'approvalStatus',
            'old_value' => 'APPROVED',
            'new_value' => 'RETURNED',
            'comment' => '要確認のため差戻し',
        ]);
    }

    public function test_admin_cannot_decide_attendance_approval_in_closed_period(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E503');
        $rowClosedDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-07-05',
            'clock_in_at' => '2026-07-05 09:00:00',
            'clock_out_at' => '2026-07-05 18:00:00',
            'close_status' => 'CLOSED',
        ]);
        $monthClosedDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-08-01',
            'clock_in_at' => '2026-08-01 09:00:00',
            'clock_out_at' => '2026-08-01 18:00:00',
        ]);
        DB::table('attendance_monthly_closes')->insert([
            'target_year_month' => '2026-08',
            'status' => 'CLOSED',
            'note' => '締め済み',
            'closed_at' => now(),
            'closed_by' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/approvals/' . $rowClosedDailyId . '/approve', [
                'comment' => '締め済み行',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/approvals/' . $monthClosedDailyId . '/return', [
                'comment' => '締め済み月',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $rowClosedDailyId,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
        ]);
        $this->assertDatabaseHas('attendance_daily', [
            'id' => $monthClosedDailyId,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
        ]);
        $this->assertDatabaseMissing('attendance_daily_histories', [
            'attendance_daily_id' => $rowClosedDailyId,
            'action_type' => 'ATTENDANCE_DAILY_APPROVED',
        ]);
        $this->assertDatabaseMissing('attendance_daily_histories', [
            'attendance_daily_id' => $monthClosedDailyId,
            'action_type' => 'ATTENDANCE_DAILY_RETURNED',
        ]);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-attendance-approval@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-attendance-approval@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => '勤怠 承認',
            'kana' => 'キンタイ ショウニン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dailyAttendance(int $employeeId, array $overrides = []): int
    {
        return (int) DB::table('attendance_daily')->insertGetId(array_merge([
            'employee_id' => $employeeId,
            'target_date' => '2026-07-01',
            'schedule_name' => '通常勤務',
            'work_type_id' => null,
            'raw_clock_in_at' => null,
            'raw_clock_out_at' => null,
            'clock_in_at' => '2026-07-01 09:00:00',
            'clock_out_at' => '2026-07-01 18:00:00',
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
            'approval_status' => 'PENDING',
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
