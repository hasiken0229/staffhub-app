<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PortalSummaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_keeps_today_separate_from_selected_month_and_does_not_reveal_future_payroll(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 10:00:00');
        try {
            $employee = $this->employee();
            DB::table('attendance_daily')->insert([
                'employee_id' => $employee, 'target_date' => '2026-10-10',
                'raw_clock_in_at' => '2026-10-10 08:01:40', 'clock_in_at' => '2026-10-10 08:30:00',
                'approval_status' => 'PENDING', 'updated_at' => now(),
            ]);
            foreach (['2026-09' => now()->subDay(), '2026-10' => now()->addDay()] as $month => $published) {
                DB::table('payroll_statements')->insert([
                    'employee_id' => $employee, 'statement_type' => 'PAYROLL', 'target_year_month' => $month,
                    'file_path' => 'private/test.pdf', 'original_file_name' => 'test.pdf', 'uploaded_by' => $employee,
                    'published_at' => $published, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $other = DB::table('employees')->insertGetId([
                'employee_code' => 'SUM002', 'name' => '別の架空職員', 'employment_type' => 'FULL_TIME',
                'status' => 'ACTIVE', 'joined_on' => '2024-04-01', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('attendance_daily')->insert(['employee_id' => $other, 'target_date' => '2026-10-10', 'approval_status' => 'PENDING', 'updated_at' => now()]);
            DB::table('notifications')->insert(['employee_id' => $other, 'notification_type' => 'PERSONAL', 'title' => '別の職員への通知', 'body' => '架空', 'sent_at' => now(), 'is_read' => 0]);
            $token = $this->postJson('/api/auth/login', ['loginId' => 'summary-test', 'password' => 'Test1234!', 'audience' => 'EMPLOYEE'])->json('data.accessToken');
            DB::enableQueryLog();
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/mobile/home?view=today&employeeId='.$other)->assertOk()
                ->assertJsonPath('data.employee.id', $employee)->assertJsonPath('data.todayAttendance.employeeId', $employee)
                ->assertJsonPath('data.todayAttendance.rawClockInAt', '2026-10-10T08:01:40+09:00')
                ->assertJsonPath('data.todayAttendance.clockInAt', '2026-10-10T08:30:00+09:00')
                ->assertJsonPath('data.checkedAt', '2026-10-10T10:00:00+09:00')->assertJsonMissingPath('data.latestPayroll');
            $this->assertStringNotContainsString('payroll_statements', implode(' ', array_column(DB::getQueryLog(), 'query')));
            DB::disableQueryLog();
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/mobile/home?view=attention&employeeId='.$other)->assertOk()
                ->assertJsonPath('data.unreadNotificationCount', 0)->assertJsonMissingPath('data.todayAttendance');
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/mobile/home')->assertOk()
                ->assertJsonPath('data.todayAttendance.targetDate', '2026-10-10')
                ->assertJsonPath('data.todayAttendance.employeeId', $employee)
                ->assertJsonPath('data.todayAttendance.dayState', 'WORKING')
                ->assertJsonPath('data.latestPayroll.targetYearMonth', '2026-09');
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_hub_counts_all_pending_records_independently_of_attendance_filters(): void
    {
        $employee = $this->employee();
        DB::table('attendance_daily')->insert([
            ['employee_id' => $employee, 'target_date' => '2026-09-01', 'approval_status' => 'PENDING', 'updated_at' => now()],
            ['employee_id' => $employee, 'target_date' => '2026-10-01', 'approval_status' => 'PENDING', 'updated_at' => now()],
        ]);
        $summary = app(\App\Services\Reports\ReportDashboardService::class)->hubSummary();
        $this->assertSame(2, $summary['pendingAttendanceApprovalCount']);
        $this->assertSame(0, $summary['pendingDailyEditRequestCount']);
        $this->assertNotEmpty($summary['checkedAt']);
    }

    public function test_validation_returns_field_names_without_writing_invalid_data(): void
    {
        $this->employee();
        $token = $this->postJson('/api/auth/login', ['loginId' => 'summary-test', 'password' => 'Test1234!', 'audience' => 'EMPLOYEE'])->json('data.accessToken');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/leave/balance?asOf=invalid')->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonPath('error.details.0.field', 'asOf');
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_hub_published_payroll_count_excludes_deleted_and_future_statements(): void
    {
        $employee = $this->employee();
        foreach (['2026-08'=>['published_at'=>now()->subDay(), 'deleted_at'=>now()],
            '2026-09'=>['published_at'=>now()->subDay(), 'deleted_at'=>null],
            '2026-10'=>['published_at'=>now()->addDay(), 'deleted_at'=>null]] as $month=>$state) {
            DB::table('payroll_statements')->insert(['employee_id'=>$employee, 'statement_type'=>'PAYROLL',
                'target_year_month'=>$month, 'file_path'=>'private/test.pdf', 'original_file_name'=>'test.pdf',
                'uploaded_by'=>$employee, 'created_at'=>now(), 'updated_at'=>now(), ...$state]);
        }
        $this->assertSame(1, app(\App\Services\Reports\ReportDashboardService::class)->hubSummary()['publishedPayrollCount']);
    }

    private function employee(): int
    {
        $id = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'SUM001', 'name' => '集計確認', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2024-04-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_auth')->insert(['employee_id' => $id, 'login_id' => 'summary-test', 'password_hash' => Hash::make('Test1234!'), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
