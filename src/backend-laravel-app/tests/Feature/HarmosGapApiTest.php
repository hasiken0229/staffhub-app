<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class HarmosGapApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_daily_attendance_without_changing_raw_punches_and_history_is_recorded(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E100');
        $workTypeId = (int) DB::table('work_type_settings')->where('name', '通常勤務')->value('id');
        $dailyId = (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-10',
            'schedule_name' => '通常勤務',
            'work_type_id' => $workTypeId,
            'raw_clock_in_at' => '2026-04-10 09:00:00',
            'raw_clock_out_at' => '2026-04-10 18:00:00',
            'clock_in_at' => '2026-04-10 09:00:00',
            'clock_out_at' => '2026-04-10 18:00:00',
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
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/admin/attendance/daily/' . $dailyId, [
                'workTypeId' => $workTypeId,
                'clockInTime' => '10:00',
                'clockOutTime' => '18:00',
                'breaks' => [
                    ['startTime' => '12:00', 'endTime' => '13:00'],
                    ['startTime' => '15:00', 'endTime' => '15:30'],
                ],
                'remark' => '打刻漏れ補正',
                'supervisorComment' => '確認済み',
                'approvalStatus' => 'APPROVED',
                'approvalComment' => 'OK',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.rawClockInAt', '2026-04-10T09:00:00+09:00')
            ->assertJsonPath('data.clockInAt', '2026-04-10T10:00:00+09:00')
            ->assertJsonPath('data.breakMinutes', 90)
            ->assertJsonPath('data.workMinutes', 390)
            ->assertJsonPath('data.isManuallyEdited', true);

        $this->assertDatabaseHas('attendance_daily_histories', [
            'attendance_daily_id' => $dailyId,
            'action_type' => 'MANUAL_EDITED',
            'field_key' => 'clockInAt',
        ]);
    }

    public function test_attendance_error_and_month_close_status_reports_are_returned(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E101');
        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-02',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-02 09:00:00',
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
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
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.0.errorCode', 'MISSING_PUNCH');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close-status?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.0.pendingCount', 1);
    }

    public function test_time_paid_leave_request_approval_updates_daily_and_paid_leave_ledger(): void
    {
        $adminToken = $this->adminToken();
        $employeeId = $this->employee('E102');
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e102@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 1,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e102@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $requestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-12',
                'startTime' => '09:00',
                'endTime' => '11:00',
                'quantityMinutes' => 120,
                'reason' => '通院',
            ])
            ->assertOk()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/work-procedures/' . $requestId . '/approve', ['comment' => 'OK'])
            ->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-12',
            'hour_paid_leave_minutes' => 120,
        ]);
        $this->assertDatabaseHas('paid_leave_ledger', [
            'employee_id' => $employeeId,
            'entry_type' => 'USE',
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => $requestId,
            'days_delta' => -0.25,
        ]);
    }

    public function test_employee_daily_edit_request_is_approved_and_reflected_to_daily_attendance(): void
    {
        $adminToken = $this->adminToken();
        $employeeId = $this->employee('E103');
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e103@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e103@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $requestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/attendance/daily-edit-requests', [
                'targetDate' => '2026-04-14',
                'clockInTime' => '09:15',
                'clockOutTime' => '18:15',
                'breaks' => [
                    ['startTime' => '12:00', 'endTime' => '13:00'],
                ],
                'employeeComment' => '打刻修正をお願いします',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/attendance/daily-edit-requests?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/attendance/daily-edit-requests/' . $requestId . '/approve', ['comment' => '承認'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-14',
            'clock_in_at' => '2026-04-14 09:15:00',
            'clock_out_at' => '2026-04-14 18:15:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'approval_status' => 'APPROVED',
            'is_manually_edited' => 1,
        ]);
    }

    public function test_month_close_precheck_blocks_pending_items_and_daily_edit_auto_resolves_cleared_errors(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E104');
        $dailyId = (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-15 09:00:00',
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
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
            'updated_at' => now(),
        ]);
        DB::table('attendance_error_resolutions')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'error_code' => 'MISSING_PUNCH',
            'status' => 'OPEN',
            'comment' => null,
            'handled_by' => null,
            'handled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.canClose', false);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/admin/attendance/daily/' . $dailyId, [
                'clockInTime' => '09:00',
                'clockOutTime' => '18:00',
                'breaks' => [['startTime' => '12:00', 'endTime' => '13:00']],
                'approvalStatus' => 'APPROVED',
                'approvalComment' => 'OK',
            ])
            ->assertOk();

        $this->assertDatabaseHas('attendance_error_resolutions', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'error_code' => 'MISSING_PUNCH',
            'status' => 'RESOLVED',
        ]);
    }

    public function test_month_close_precheck_reports_payroll_readiness_after_close(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E106');
        DB::table('employees')->where('id', $employeeId)->update(['joined_on' => '2026-04-30']);

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-30',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-30 09:00:00',
            'clock_out_at' => '2026-04-30 18:00:00',
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
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.canClose', true)
            ->assertJsonPath('data.payrollReady', false)
            ->assertJsonPath('data.payrollBlockers.0.code', 'MONTH_NOT_CLOSED')
            ->assertJsonPath('data.summary.monthCloseStatus', 'OPEN');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/month-close', [
                'targetMonth' => '2026-04',
                'status' => 'CLOSED',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.payrollReady', true)
            ->assertJsonCount(0, 'data.payrollBlockers');

        $definitionId = DB::table('payroll_data_definitions')->insertGetId([
            'statement_type' => 'PAYROLL',
            'definition_name' => '給与CSV',
            'template_version' => 1,
            'field_count' => 0,
            'sample_file_name' => null,
            'sample_headers_json' => json_encode([]),
            'is_active' => 1,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payroll_import_batches')->insert([
            'statement_type' => 'PAYROLL',
            'payroll_data_definition_id' => $definitionId,
            'target_year_month' => '2026-04',
            'period_start_on' => '2026-04-01',
            'period_end_on' => '2026-04-30',
            'pay_date' => '2026-05-25',
            'publish_date' => '2026-05-20',
            'source_file_name' => 'payroll.csv',
            'processed_count' => 1,
            'success_count' => 1,
            'error_count' => 0,
            'status' => 'COMPLETED',
            'summary_json' => null,
            'created_by' => null,
            'deleted_at' => null,
            'deleted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.payrollReady', true)
            ->assertJsonPath('data.payrollWarnings.0.code', 'PAYROLL_BATCH_EXISTS')
            ->assertJsonPath('data.summary.payrollBatchCount', 1);
    }

    public function test_hourly_leave_uses_employee_standard_day_minutes_limit(): void
    {
        $employeeId = $this->employee('E105');
        DB::table('employment_type_settings')->updateOrInsert(
            ['code' => 'FULL_TIME'],
            [
                'label' => '常勤',
                'standard_day_minutes' => 240,
                'sort_order' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e105@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 2,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e105@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-16',
                'startTime' => '09:00',
                'endTime' => '14:00',
                'quantityMinutes' => 300,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-16',
                'startTime' => '09:00',
                'endTime' => '11:00',
                'quantityMinutes' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.quantityDays', 0.5);
    }

    public function test_work_procedure_detail_and_attendance_error_rule_history_are_available(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E107');
        $requestId = (int) DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type_code' => 'PAID',
            'start_date' => '2026-04-20',
            'end_date' => '2026-04-20',
            'day_unit' => 'FULL',
            'half_day_type' => null,
            'quantity_days' => 1,
            'request_category' => 'LEAVE',
            'time_leave_type' => null,
            'target_date' => null,
            'start_time' => null,
            'end_time' => null,
            'quantity_minutes' => null,
            'reason' => '私用',
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'decision_comment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('leave_request_actions')->insert([
            'leave_request_id' => $requestId,
            'action_by' => $employeeId,
            'action_type' => 'APPLIED',
            'comment' => '申請します',
            'acted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/work-procedures/' . $requestId)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.employee.departmentName', '保育')
            ->assertJsonPath('data.actions.0.actionType', 'APPLIED');

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-20',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-20 09:00:00',
            'clock_out_at' => '2026-04-20 19:10:00',
            'break_minutes' => 50,
            'work_minutes' => 560,
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
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&errorCode=SHORT_BREAK_OVER_8')
            ->assertOk()
            ->assertJsonPath('data.0.errorCode', 'SHORT_BREAK_OVER_8');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/errors/resolve', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-04-20',
                'errorCode' => 'SHORT_BREAK_OVER_8',
                'status' => 'IGNORED',
                'comment' => '園長確認済み',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'IGNORED');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&handlingStatus=IGNORED')
            ->assertOk()
            ->assertJsonPath('data.0.histories.0.newStatus', 'IGNORED')
            ->assertJsonPath('data.0.histories.0.comment', '園長確認済み');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/system-masters/attendance-error-rules', [
                'code' => 'SHORT_BREAK_OVER_8',
                'name' => '休憩不足（8時間超）',
                'minWorkMinutes' => 480,
                'requiredBreakMinutes' => 45,
                'enabled' => true,
                'sortOrder' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.5.requiredBreakMinutes', 45);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&errorCode=SHORT_BREAK_OVER_8')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => 'テスト 職員',
            'kana' => 'テスト ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
