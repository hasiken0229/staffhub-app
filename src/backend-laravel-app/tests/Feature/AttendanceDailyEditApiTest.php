<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceDailyEditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_manual_daily_edit_to_raw_punches(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E201');
        $dailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-04-21',
            'raw_clock_in_at' => '2026-04-21 09:00:00',
            'raw_clock_out_at' => '2026-04-21 18:00:00',
            'clock_in_at' => '2026-04-21 10:00:00',
            'clock_out_at' => '2026-04-21 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 420,
            'remark' => '修正前',
            'approval_status' => 'APPROVED',
            'is_manually_edited' => 1,
            'manual_edited_by' => $employeeId,
            'manual_edited_at' => now(),
        ]);
        DB::table('attendance_daily_breaks')->insert([
            'attendance_daily_id' => $dailyId,
            'segment_no' => 1,
            'break_start_at' => '2026-04-21 12:00:00',
            'break_end_at' => '2026-04-21 13:00:00',
            'created_by' => $employeeId,
            'updated_by' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/attendance/daily/' . $dailyId . '/manual-edit')
            ->assertOk()
            ->assertJsonPath('data.rawClockInAt', '2026-04-21T09:00:00+09:00')
            ->assertJsonPath('data.clockInAt', '2026-04-21T09:00:00+09:00')
            ->assertJsonPath('data.clockOutAt', '2026-04-21T18:00:00+09:00')
            ->assertJsonPath('data.workMinutes', 480)
            ->assertJsonPath('data.isManuallyEdited', false);

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $dailyId,
            'clock_in_at' => '2026-04-21 09:00:00',
            'clock_out_at' => '2026-04-21 18:00:00',
            'work_minutes' => 480,
            'is_manually_edited' => 0,
            'manual_edited_by' => null,
            'manual_edited_at' => null,
        ]);
        $this->assertDatabaseMissing('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
        ]);
        $this->assertDatabaseHas('attendance_daily_histories', [
            'attendance_daily_id' => $dailyId,
            'action_type' => 'MANUAL_EDIT_RESET',
            'field_key' => 'clockInAt',
        ]);
    }

    public function test_admin_cannot_update_or_reset_daily_attendance_in_closed_period(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E202');
        $rowClosedDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-04-22',
            'clock_in_at' => '2026-04-22 09:00:00',
            'clock_out_at' => '2026-04-22 18:00:00',
            'close_status' => 'CLOSED',
            'is_manually_edited' => 1,
        ]);
        $monthClosedDailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-05-01',
            'raw_clock_in_at' => '2026-05-01 09:00:00',
            'raw_clock_out_at' => '2026-05-01 18:00:00',
            'clock_in_at' => '2026-05-01 10:00:00',
            'clock_out_at' => '2026-05-01 18:00:00',
            'work_minutes' => 420,
            'is_manually_edited' => 1,
        ]);
        DB::table('attendance_monthly_closes')->insert([
            'target_year_month' => '2026-05',
            'status' => 'CLOSED',
            'note' => '締め済み',
            'closed_at' => now(),
            'closed_by' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/admin/attendance/daily/' . $rowClosedDailyId, [
                'clockInTime' => '08:30',
                'clockOutTime' => '17:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/attendance/daily/' . $monthClosedDailyId . '/manual-edit')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $rowClosedDailyId,
            'clock_in_at' => '2026-04-22 09:00:00',
            'clock_out_at' => '2026-04-22 18:00:00',
            'close_status' => 'CLOSED',
        ]);
        $this->assertDatabaseHas('attendance_daily', [
            'id' => $monthClosedDailyId,
            'clock_in_at' => '2026-05-01 10:00:00',
            'work_minutes' => 420,
            'is_manually_edited' => 1,
        ]);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-daily-edit@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-daily-edit@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => '日次 修正',
            'kana' => 'ニチジ シュウセイ',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dailyAttendance(int $employeeId, array $overrides = []): int
    {
        return (int) DB::table('attendance_daily')->insertGetId(array_merge([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-21',
            'schedule_name' => '通常勤務',
            'work_type_id' => null,
            'raw_clock_in_at' => null,
            'raw_clock_out_at' => null,
            'clock_in_at' => '2026-04-21 09:00:00',
            'clock_out_at' => '2026-04-21 18:00:00',
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
