<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Reports\PayrollReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PayrollReadinessSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unclosed_payroll_api_returns_422_without_export_history_or_daily_changes(): void
    {
        DB::table('users')->insert(['name' => '給与確認管理者', 'email' => 'payroll-safety@example.test', 'password' => Hash::make('TestPassword123!')]);
        $token = $this->postJson('/api/auth/login', ['loginId' => 'payroll-safety@example.test', 'password' => 'TestPassword123!', 'audience' => 'ADMIN'])->assertOk()->json('data.accessToken');
        $employee = $this->employee();
        $this->day($employee, '2026-09-03', ['clock_out_at' => null, 'work_minutes' => null]);
        $before = DB::table('attendance_daily')->first();

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/admin/reports/monthly-payroll-csv?targetMonth=2026-09')
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_NOT_READY')
            ->assertJsonPath('error.details.0.code', 'MONTH_NOT_CLOSED');

        $this->assertEquals($before, DB::table('attendance_daily')->first());
        $this->assertDatabaseCount('import_histories', 0);
        $this->assertDatabaseCount('attendance_monthly_closes', 0);
    }

    public function test_missing_minutes_never_become_zero_even_if_month_and_row_claim_to_be_closed(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $id = $this->day($employee, '2026-09-03', ['clock_out_at' => null, 'work_minutes' => null]);
        $before = DB::table('attendance_daily')->where('id', $id)->first();
        $this->assertBlocked('MISSING_WORK_MINUTES');
        $this->assertEquals($before, DB::table('attendance_daily')->where('id', $id)->first());
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_every_existing_row_requires_approval_and_close(string $approval, string $close): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $this->day($employee, '2026-09-03', ['approval_status' => $approval, 'close_status' => $close]);
        $this->assertBlocked('DAILY_NOT_CONFIRMED');
    }

    public static function unconfirmedStatuses(): array
    {
        return [['PENDING', 'CLOSED'], ['RETURNED', 'CLOSED'], ['APPROVED', 'OPEN']];
    }

    public function test_a_one_sided_punch_cannot_be_exported_as_explicit_zero(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $this->day($employee, '2026-09-03', ['clock_out_at' => null, 'work_minutes' => 0]);
        $this->assertBlocked('INCOMPLETE_PUNCHES');
    }

    public function test_confirmed_zero_and_holiday_are_exported_without_inventing_missing_calendar_rows(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $this->day($employee, '2026-09-03', ['clock_out_at' => '2026-09-03 09:00:00', 'break_minutes' => 0, 'work_minutes' => 0]);
        $this->day($employee, '2026-09-05', ['clock_in_at' => null, 'clock_out_at' => null, 'work_minutes' => 0, 'schedule_name' => '公休']);
        $csv = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09');
        $this->assertSame(0, $csv['summary']['totalBaseMinutes']);
        $this->assertStringContainsString('P100,給与確認職員,0時間00分,0時間00分', mb_convert_encoding($csv['content'], 'UTF-8', 'SJIS-win'));
        $this->assertDatabaseCount('attendance_daily', 2);
    }

    public function test_part_time_cutoff_requires_both_months_and_all_rows_inside_the_period(): void
    {
        $employee = $this->employee('PART_TIME');
        $this->close('2026-10');
        $this->day($employee, '2026-09-21', ['work_minutes' => 120]);
        $this->day($employee, '2026-10-20', ['work_minutes' => 180]);
        $this->day($employee, '2026-09-20', ['work_minutes' => null, 'approval_status' => 'PENDING']);
        $this->day($employee, '2026-10-21', ['work_minutes' => null, 'approval_status' => 'PENDING']);
        $this->assertBlocked('MONTH_NOT_CLOSED', '2026-10');
        $this->close('2026-09');
        DB::table('attendance_daily')->where('employee_id', $employee)->where('target_date', '2026-09-21')->update(['approval_status' => 'PENDING']);
        $this->assertBlocked('DAILY_NOT_CONFIRMED', '2026-10');
        DB::table('attendance_daily')->where('employee_id', $employee)->where('target_date', '2026-09-21')->update(['approval_status' => 'APPROVED']);
        $csv = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-10');
        $this->assertSame(300, $csv['summary']['totalBaseMinutes']);
        $lines = preg_split('/\r?\n/', trim(mb_convert_encoding($csv['content'], 'UTF-8', 'SJIS-win')));
        $this->assertSame(['個人Ｃ', '氏名', '時給１時間', '前月普通残業１'], str_getcsv($lines[0]));
        $this->assertSame(['P100', '給与確認職員', '5時間00分', '0時間00分'], str_getcsv($lines[1]));
    }

    public function test_pending_correction_inside_payroll_period_blocks_export(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $this->day($employee, '2026-09-03');
        DB::table('attendance_daily_edit_requests')->insert(['employee_id' => $employee, 'target_date' => '2026-09-03', 'status' => 'RETURNED']);
        $this->assertBlocked('PENDING_REQUESTS');
    }

    public function test_only_confirmed_current_values_override_the_part_time_overtime_in_payroll(): void
    {
        $employee = $this->employee('PART_TIME');
        $this->close('2026-09');
        $this->close('2026-10');
        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
            $in = $date.' 07:56:00';
            $out = $date.' 16:58:00';
            $facts = ['clockInAt'=>$in, 'clockOutAt'=>$out, 'rawClockInAt'=>$in, 'rawClockOutAt'=>$out,
                'breakMinutes'=>60, 'workMinutes'=>482, 'overtimeMinutes'=>2];
            $this->day($employee, $date, [
                'raw_clock_in_at'=>$in, 'raw_clock_out_at'=>$date === '2026-10-13' ? $date.' 17:00:00' : $out,
                'clock_in_at'=>$in, 'clock_out_at'=>$out, 'break_minutes'=>60, 'work_minutes'=>482,
                'break_overtime_review'=>$date === '2026-10-14' ? null : json_encode($facts),
            ]);
        }
        $csv = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-10');
        $this->assertSame(2, $csv['summary']['totalOvertimeMinutes']);
        $this->assertSame(1446, $csv['summary']['totalBaseMinutes']);
    }

    public function test_reconciled_overtime_is_exported_only_for_the_matching_hrmos_day(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        foreach ([3, 4, 5, 6] as $day) {
            $date = sprintf('2026-09-%02d', $day);
            $start = $date.' 09:00:00';
            $end = $date.' 19:00:00';
            $result = [
                'version' => 1, 'rawClockInAt' => $start, 'rawClockOutAt' => null,
                'clockInAt' => $start, 'clockOutAt' => $end, 'breakMinutes' => 60, 'workMinutes' => 540,
                'inferredSide' => null, 'overtimeMinutes' => 0,
            ];
            $this->day($employee, $date, [
                'clock_in_at' => $start, 'clock_out_at' => $end, 'raw_clock_in_at' => $start,
                'raw_clock_out_at' => $day === 4 ? $end : null, 'work_minutes' => 540,
                'is_manually_edited' => $day === 5, 'source_system' => 'HARMOS',
                'source_import_type' => $day === 6 ? 'HARMOS_ATTENDANCE_DAILY_CSV' : 'HARMOS_SINGLE_PUNCH_RECONCILIATION',
                'harmos_leave_summary' => json_encode(['singlePunchReconciliation' => $result]),
                'approval_comment' => '確認済み',
            ]);
        }
        $csv = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09');
        $this->assertSame(2160, $csv['summary']['totalBaseMinutes']);
        $this->assertSame(180, $csv['summary']['totalOvertimeMinutes']);
    }

    public function test_pending_leave_overlapping_the_previous_month_blocks_part_time_export(): void
    {
        $employee = $this->employee('PART_TIME');
        $this->close('2026-09');
        $this->close('2026-10');
        $this->day($employee, '2026-09-30');
        DB::table('leave_types')->insertOrIgnore(['code' => 'PAID', 'name' => '有給休暇', 'requires_balance' => true, 'allows_half_day' => true, 'sort_order' => 1]);
        $request = DB::table('leave_requests')->insertGetId(['employee_id' => $employee, 'leave_type_code' => 'PAID', 'start_date' => '2026-09-20', 'end_date' => '2026-09-22', 'day_unit' => 'FULL', 'quantity_days' => 3, 'status' => 'PENDING']);
        $this->assertBlocked('PENDING_REQUESTS', '2026-10');
        DB::table('leave_requests')->where('id', $request)->update(['end_date' => '2026-09-20']);
        $this->assertSame(480, app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-10')['summary']['totalBaseMinutes']);
    }

    public function test_schedule_estimate_exports_only_after_explicit_approval_and_month_close(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-10-15 18:00:00');
        try {
            $employee = $this->employee();
            DB::table('employees')->where('id', $employee)->update(['employee_code' => '970017', 'joined_on' => '2026-09-03', 'retired_on' => '2026-09-03']);
            DB::table('employee_attendance_settings')->insert(['employee_id' => $employee, 'standard_clock_in_time' => '08:30', 'standard_clock_out_time' => '14:30', 'include_before_start' => 0, 'include_after_end' => 0]);
            $id = $this->day($employee, '2026-09-03', ['raw_clock_in_at' => '2026-09-03 08:25:00', 'clock_in_at' => '2026-09-03 08:25:00', 'clock_out_at' => null, 'work_minutes' => null, 'approval_status' => 'PENDING', 'close_status' => 'OPEN']);
            $this->assertBlocked('MONTH_NOT_CLOSED');
            $this->assertNull(DB::table('attendance_daily')->where('id', $id)->value('work_minutes'));
            $actor = new \Illuminate\Auth\GenericUser(['id' => $employee, 'role' => 'EMPLOYEE', 'name' => '給与確認職員']);
            app(\App\Services\Attendance\AttendanceApprovalService::class)->decideApproval($id, 'APPROVED', '予定補完を確認', $actor);
            $this->assertBlocked('MONTH_NOT_CLOSED');
            app(\App\Services\Attendance\MonthCloseService::class)->updateMonthlyClose('2026-09', 'CLOSED', null, $actor);
            $this->assertSame(360, app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09')['summary']['totalBaseMinutes']);
            $this->assertNull(DB::table('attendance_daily')->where('id', $id)->value('raw_clock_out_at'));
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_accepted_event_without_daily_row_blocks_export_in_closed_month(): void
    {
        $employee = $this->employee();
        $this->close('2026-09');
        $device = DB::table('attendance_devices')->insertGetId(['device_code' => 'SYNTHETIC', 'name' => '合成端末', 'is_active' => true]);
        DB::table('attendance_events')->insert(['employee_id' => $employee, 'device_id' => $device, 'card_uid' => 'SYNTHETIC', 'occurred_at' => '2026-09-03 09:00:00', 'event_type' => 'CLOCK_IN', 'source_type' => 'CARD_READER', 'receive_status' => 'ACCEPTED', 'created_at' => now()]);
        $this->assertBlocked('MISSING_DAILY');
    }

    private function assertBlocked(string $reason, string $month = '2026-09'): void
    {
        try {
            app(PayrollReportExportService::class)->exportMonthlyPayrollCsv($month);
            $this->fail('Unconfirmed payroll must not produce a CSV.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('PAYROLL_NOT_READY', $exception->errorCode);
            $this->assertSame($reason, $exception->details[0]['code']);
        }
    }

    private function employee(string $employmentType = 'FULL_TIME'): int
    {
        return DB::table('employees')->insertGetId(['employee_code' => 'P100', 'name' => '給与確認職員', 'kana' => 'キュウヨ', 'employment_type' => $employmentType, 'status' => 'ACTIVE', 'joined_on' => '2026-01-01']);
    }

    private function day(int $employee, string $date, array $overrides = []): int
    {
        return DB::table('attendance_daily')->insertGetId(array_replace(['employee_id' => $employee, 'target_date' => $date, 'schedule_name' => '通常勤務', 'clock_in_at' => $date.' 09:00:00', 'clock_out_at' => $date.' 18:00:00', 'break_minutes' => 60, 'work_minutes' => 480, 'approval_status' => 'APPROVED', 'close_status' => 'CLOSED', 'updated_at' => now()], $overrides));
    }

    private function close(string $month): void
    {
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => $month, 'status' => 'CLOSED', 'closed_at' => now()]);
    }
}
