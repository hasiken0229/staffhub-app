<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceDailyEditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_evening_stamp_in_the_clock_in_column_projects_as_clock_out_and_freezes_on_approval(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 24));
        $employee = $this->employee('970019');
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee, 'standard_clock_in_time'=>'08:00','standard_clock_out_time'=>'15:30']);
        $date = '2026-07-04';
        $id = $this->dailyAttendance($employee, [
            'target_date'=>$date, 'raw_clock_in_at'=>"$date 15:29:00", 'raw_clock_out_at'=>null,
            'clock_in_at'=>"$date 15:29:00", 'clock_out_at'=>"$date 15:30:00", 'break_minutes'=>0, 'work_minutes'=>1,
        ]);
        $attendance = app(\App\Services\Attendance\DailyAttendanceService::class);
        $row = $attendance->detail($id);
        $this->assertSame(['CLOCK_IN',404,45], [$row['scheduleInferredSide'],$row['workMinutes'],$row['breakMinutes']]);
        $this->assertSame(["08:00","15:29","15:29",null], [substr($row['clockInAt'],11,5),substr($row['clockOutAt'],11,5),substr($row['rawClockInAt'],11,5),$row['rawClockOutAt']]);
        $this->assertStringContainsString('退勤として採用', $row['remark']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id, 'work_minutes'=>1]);
        $list = $attendance->listDailyGrid(['targetMonth'=>'2026-07'])['items'][0];
        $this->assertSame(['CLOCK_IN',404], [$list['scheduleInferredSide'],$list['workMinutes']]);
        app(\App\Services\Attendance\AttendanceApprovalService::class)->decideApproval($id,'APPROVED',null,new \Illuminate\Auth\GenericUser(['id'=>$employee,'role'=>'EMPLOYEE','employee_id'=>$employee]));
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'clock_in_at'=>"$date 08:00:00",'clock_out_at'=>"$date 15:29:00",'raw_clock_in_at'=>"$date 15:29:00",'raw_clock_out_at'=>null,'work_minutes'=>404,'break_minutes'=>45]);
        DB::table('employee_attendance_settings')->where('employee_id',$employee)->update(['standard_clock_out_time'=>'18:00']);
        $frozen=$attendance->detail($id);
        $this->assertSame(['CLOCK_IN',404], [$frozen['scheduleInferredSide'],$frozen['workMinutes']]);
    }

    public function test_schedule_estimate_is_read_only_consistent_in_exports_and_frozen_on_approval(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        try {
            $employeeId=$this->employee('970017');
            DB::table('employee_attendance_settings')->insert(['employee_id'=>$employeeId,'standard_clock_in_time'=>'08:30','standard_clock_out_time'=>'14:30','include_before_start'=>0,'include_after_end'=>0]);
            $id=$this->dailyAttendance($employeeId,['target_date'=>'2026-09-03','raw_clock_in_at'=>'2026-09-03 08:25:00','clock_in_at'=>'2026-09-03 08:25:00','clock_out_at'=>null,'break_minutes'=>0,'work_minutes'=>null]);
            $before=(array) DB::table('attendance_daily')->where('id',$id)->first();
            $svc=app(\App\Services\AttendanceService::class);
            $item=$svc->listDailyGrid(['targetMonth'=>'2026-09'])['items'][0];
            $this->assertSame(360,$item['workMinutes']);
            $this->assertSame('CLOCK_OUT',$item['scheduleInferredSide']);
            $this->assertStringContainsString('予定補完',$item['remark']);
            $this->assertNull($item['rawClockOutAt']);
            $this->assertNotEmpty($item['graphSegments']);
            $csv=app(\App\Services\Reports\AttendanceReportExportService::class)->exportDailyAttendanceCsv(['from'=>'2026-09-03','to'=>'2026-09-03']);
            $this->assertStringContainsString('予定補完',$csv);
            $pdf=app(\App\Services\Reports\MonthlyWorksPdfExportService::class)->exportMonthlyWorksPdf($employeeId,'2026-09');
            $this->assertSame(360,$pdf['summary']['totalActualWorkMinutes']);
            $this->assertSame($before,(array) DB::table('attendance_daily')->where('id',$id)->first());
            app(\App\Services\Attendance\AttendanceApprovalService::class)->decideApproval($id,'APPROVED',null,new \Illuminate\Auth\GenericUser(['id'=>$employeeId,'role'=>'EMPLOYEE','employee_id'=>$employeeId]));
            $this->assertDatabaseHas('attendance_daily',['id'=>$id,'work_minutes'=>360,'raw_clock_out_at'=>null,'clock_out_at'=>'2026-09-03 14:30:00','approval_status'=>'APPROVED']);
            DB::table('attendance_daily')->where('id',$id)->update(['close_status'=>'CLOSED']);
            $this->assertSame(360,$svc->listDailyGrid(['targetMonth'=>'2026-09'])['items'][0]['workMinutes']);
        } finally { \Carbon\CarbonImmutable::setTestNow(); }
    }

    public function test_both_daily_edit_routes_validate_the_database_character_limit_and_preserve_the_original(): void
    {
        $admin = $this->adminToken();
        $employeeId = $this->employee('R255');
        $dailyId = $this->dailyAttendance($employeeId);
        $limit = str_repeat('備', 255);
        $oversize = $limit.'考';
        $url = '/api/admin/attendance/daily/'.$dailyId;
        $this->withToken($admin)->patchJson($url, ['remark'=>$limit])->assertOk();
        $this->withToken($admin)->patchJson($url, ['remark'=>$oversize])->assertStatus(422)->assertJsonPath('error.details.0.field','remark');
        $this->assertDatabaseHas('attendance_daily', ['id'=>$dailyId,'remark'=>$limit]);

        DB::table('employee_auth')->insert(['employee_id'=>$employeeId, 'login_id'=>'remark@example.test',
            'password_hash'=>Hash::make('example-password'), 'created_at'=>now(), 'updated_at'=>now()]);
        $token = $this->postJson('/api/auth/login', ['loginId'=>'remark@example.test','password'=>'example-password',
            'audience'=>'EMPLOYEE'])->assertOk()->json('data.accessToken');
        $payload = ['targetDate'=>'2026-04-21', 'clockInTime'=>'09:01'];
        $this->withToken($token)->postJson('/api/attendance/daily-edit-requests', [...$payload,'remark'=>$oversize])
            ->assertStatus(422)->assertJsonPath('error.details.0.field','remark');
        $id = $this->withToken($token)->postJson('/api/attendance/daily-edit-requests', [...$payload,'remark'=>$limit])
            ->assertOk()->json('data.id');
        DB::table('attendance_daily_edit_requests')->where('id',$id)->update(['remark'=>$oversize]);
        $this->withToken($admin)->postJson('/api/admin/attendance/daily-edit-requests/'.$id.'/approve')
            ->assertStatus(422)->assertJsonPath('error.details.0.field','remark');
        $this->assertDatabaseHas('attendance_daily_edit_requests', ['id'=>$id,'status'=>'PENDING']);
        $this->assertDatabaseHas('attendance_daily', ['id'=>$dailyId,'remark'=>$limit]);
    }

    public function test_admin_can_create_blank_daily_attendance_for_manual_edit(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E200');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/daily', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-04-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.employeeId', $employeeId)
            ->assertJsonPath('data.targetDate', '2026-04-20')
            ->assertJsonPath('data.clockInAt', null)
            ->assertJsonPath('data.clockOutAt', null)
            ->assertJsonPath('data.approvalStatus', 'PENDING')
            ->assertJsonPath('data.isManuallyEdited', false);

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-20',
            'clock_in_at' => null,
            'clock_out_at' => null,
            'approval_status' => 'PENDING',
            'close_status' => 'OPEN',
        ]);
        $this->assertDatabaseHas('attendance_daily_histories', [
            'action_type' => 'MANUAL_DAILY_CREATED',
            'field_key' => 'targetDate',
            'new_value' => '2026-04-20',
        ]);
    }

    public function test_admin_daily_create_is_idempotent_for_existing_date(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E199');
        $dailyId = $this->dailyAttendance($employeeId, [
            'target_date' => '2026-04-19',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/daily', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-04-19',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $dailyId);

        $this->assertSame(1, DB::table('attendance_daily')
            ->where('employee_id', $employeeId)
            ->where('target_date', '2026-04-19')
            ->count());
    }

    public function test_admin_cannot_create_daily_attendance_in_closed_month(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E198');
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
            ->postJson('/api/admin/attendance/daily', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-05-02',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CLOSED_PERIOD');

        $this->assertDatabaseMissing('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-05-02',
        ]);
    }

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
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'break_start_at' => '2026-04-21 13:00:00',
            'break_end_at' => '2026-04-21 13:45:00',
        ]);
        $this->assertDatabaseHas('attendance_daily', ['id' => $dailyId, 'break_minutes' => 60, 'approval_status' => 'PENDING']);
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
