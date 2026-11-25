<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ReportExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpunched_saturday_agrees_in_calendar_payroll_and_pdf_without_changing_source(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-10-09 18:00:00');
        try {
            $id=$this->createEmployee('S101','土曜確認','FULL_TIME');
            $this->createAttendanceDaily($id,'2026-09-12',480);
            DB::table('attendance_daily')->where('employee_id',$id)->update(['raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'is_manually_edited'=>0,'schedule_name'=>'通常','source_system'=>'HARMOS']);
            $calendar=app(\App\Services\AttendanceService::class)->listMonthlyCalendar(['targetMonth'=>'2026-09','employeeId'=>$id]);
            $indexed=collect($calendar['items'])->keyBy('targetDate');
            $this->assertSame(0,$indexed['2026-09-12']['workMinutes']);
            $this->assertSame('公休',$indexed['2026-09-12']['workStyleName']);
            $this->assertSame(0,$indexed['2026-09-19']['workMinutes']);
            $this->assertNull($indexed['2026-09-18']['workMinutes']);
            try {
                app(\App\Services\Reports\PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09');
                $this->fail('未締めの投影は給与用CSVへ出力できません。');
            } catch (\App\Services\ApiException $exception) {
                $this->assertSame('PAYROLL_NOT_READY', $exception->errorCode);
            }
            $pdf=app(\App\Services\Reports\MonthlyWorksPdfExportService::class)->exportMonthlyWorksPdf($id,'2026-09');
            $this->assertSame(0,$pdf['summary']['totalActualWorkMinutes']);
            $this->assertDatabaseHas('attendance_daily',['employee_id'=>$id,'target_date'=>'2026-09-12','work_minutes'=>480]);
            DB::table('employees')->where('id',$id)->update(['joined_on'=>'2026-09-12','retired_on'=>'2026-09-12']);
            app(\App\Services\Attendance\MonthCloseService::class)->updateMonthlyClose('2026-09','CLOSED',null,new \Illuminate\Auth\GenericUser(['id'=>1,'role'=>'ADMIN']));
            $this->assertDatabaseHas('attendance_daily',['employee_id'=>$id,'target_date'=>'2026-09-12','work_minutes'=>0,'schedule_name'=>'公休','close_status'=>'CLOSED']);
            $closedCsv=app(\App\Services\Reports\PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09');
            $this->assertSame(0,$closedCsv['summary']['totalBaseMinutes']);
        } finally { \Carbon\CarbonImmutable::setTestNow(); }
    }

    public function test_attendance_and_payroll_csv_exports_use_net_work_minutes_and_leave_fields(): void
    {
        $token = $this->adminToken();
        $this->seedDailyAttendance();

        $dailyCsv = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/daily-csv?from=2026-04-10&to=2026-04-10')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('時間有給分', $dailyCsv);
        $this->assertStringContainsString('120', $dailyCsv);
        $this->assertStringContainsString('確認済み', $dailyCsv);
        $this->assertStringContainsString('通院', $dailyCsv);

        $monthlyCsv = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/monthly-csv?targetMonth=2026-04')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('8時間00分', $monthlyCsv);
        $this->assertStringContainsString('2時間00分', $monthlyCsv);
        $this->assertStringNotContainsString('7時間00分', $monthlyCsv);

        $this->closePayrollMonths(['2026-04']);
        $payrollCsv = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/monthly-payroll-csv?targetMonth=2026-04')
            ->assertOk()
            ->getContent();
        $payrollCsv = mb_convert_encoding($payrollCsv, 'UTF-8', 'SJIS-win');

        $this->assertStringContainsString('個人Ｃ,氏名,時給１時間,前月普通残業１', $payrollCsv);
        $this->assertStringContainsString('R100,"帳票 職員",8時間00分,0時間00分', $payrollCsv);
        $this->assertStringNotContainsString('時間有給', $payrollCsv);

        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'DAILY_ATTENDANCE_CSV',
            'target_period' => '2026-04-10_2026-04-10',
            'success_count' => 1,
        ]);
        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'MONTHLY_ATTENDANCE_CSV',
            'target_period' => '2026-04',
            'success_count' => 1,
        ]);
        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'MONTHLY_PAYROLL_CSV',
            'target_period' => '2026-04',
            'success_count' => 1,
        ]);

        $historyId = (int) DB::table('import_histories')
            ->where('import_type', 'MONTHLY_ATTENDANCE_CSV')
            ->value('id');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/files/history/'.$historyId.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_attendance_pdf_exports_return_pdf_content(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->seedDailyAttendance();

        $dailyPdf = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/daily-pdf?targetMonth=2026-04')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $dailyPdf);

        $monthlyWorksPdf = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/monthly-works-pdf?employeeId='.$employeeId.'&targetMonth=2026-04')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $monthlyWorksPdf);

        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'DAILY_ATTENDANCE_PDF',
            'target_period' => '2026-04',
            'success_count' => 1,
        ]);
        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'MONTHLY_WORKS_PDF',
            'target_period' => '2026-04',
            'success_count' => 1,
        ]);

        $summary = json_decode((string) DB::table('import_histories')
            ->where('import_type', 'MONTHLY_WORKS_PDF')
            ->value('summary_json'), true);
        $this->assertSame(30, $summary['calendarDays'] ?? null);

        $historyId = (int) DB::table('import_histories')
            ->where('import_type', 'MONTHLY_WORKS_PDF')
            ->value('id');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/files/history/'.$historyId.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_payroll_csv_uses_calendar_month_for_full_time_and_21st_to_20th_for_part_time(): void
    {
        $token = $this->adminToken();
        $fullTimeId = $this->createEmployee('F001', '常勤職員', 'FULL_TIME');
        $partTimeId = $this->createEmployee('P001', '非常勤職員', 'PART_TIME');

        $this->createAttendanceDaily($fullTimeId, '2026-06-30', 480);
        $this->createAttendanceDaily($fullTimeId, '2026-05-31', 600);
        $this->createAttendanceDaily($partTimeId, '2026-05-21', 120);
        $this->createAttendanceDaily($partTimeId, '2026-06-20', 480);
        $this->createAttendanceDaily($partTimeId, '2026-06-21', 600);

        $this->closePayrollMonths(['2026-05', '2026-06']);
        $csv = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/reports/monthly-payroll-csv?targetMonth=2026-06')
            ->assertOk()
            ->getContent();
        $csv = mb_convert_encoding($csv, 'UTF-8', 'SJIS-win');

        $this->assertStringContainsString('F001,常勤職員,8時間00分,0時間00分', $csv);
        $this->assertStringContainsString('P001,非常勤職員,10時間00分,0時間00分', $csv);
        $this->assertStringNotContainsString('18時間00分', $csv);
    }

    public function test_dependent_cross_year_period_matches_calendar_csv_and_pdf(): void
    {
        $id = $this->createEmployee('D001', '期間確認', '扶養内');
        foreach (['2026-12-20' => 600, '2026-12-21' => 120, '2027-01-20' => 180, '2027-01-21' => 600] as $date => $minutes) {
            $this->createAttendanceDaily($id, $date, $minutes);
        }
        $grid = app(\App\Services\AttendanceService::class)->listDailyGrid(['targetMonth' => '2027-01', 'periodBasis' => 'PAYROLL']);
        $this->assertSame(['2026-12-21', '2027-01-20'], array_column($grid['items'], 'targetDate'));
        $this->assertSame(300, array_sum(array_column($grid['items'], 'workMinutes')));
        $this->assertSame('2026-12-21', $grid['items'][0]['periodStart']);
        $csv = app(\App\Services\Reports\AttendanceReportExportService::class)->exportMonthlyAttendanceCsv('2027-01');
        $this->assertStringContainsString('5時間00分', $csv);
        $this->closePayrollMonths(['2026-12', '2027-01']);
        $payroll = app(\App\Services\Reports\PayrollReportExportService::class)->exportMonthlyPayrollCsv('2027-01');
        $this->assertSame(300, $payroll['summary']['totalBaseMinutes']);
        $pdf = app(\App\Services\Reports\MonthlyWorksPdfExportService::class)->exportMonthlyWorksPdf($id, '2027-01');
        $this->assertSame(300, $pdf['summary']['totalActualWorkMinutes']);
        $this->assertSame(31, $pdf['summary']['calendarDays']);
        $this->assertSame('2026-12-21', $pdf['summary']['periodStart']);
        $this->assertSame('2027-01-20', $pdf['summary']['periodEnd']);
        file_put_contents(storage_path('app/standalone-year-boundary-test.pdf'), $pdf['content']);
    }

    public function test_daily_csv_keeps_missing_work_distinct_from_zero(): void
    {
        $id = $this->createEmployee('N001', '未計算確認', 'FULL_TIME');
        $this->createAttendanceDaily($id, '2026-09-03', 0);
        DB::table('attendance_daily')->where('employee_id', $id)->update(['clock_out_at' => null, 'work_minutes' => null]);
        $csv = app(\App\Services\Reports\AttendanceReportExportService::class)->exportDailyAttendanceCsv(['from' => '2026-09-03', 'to' => '2026-09-03']);
        $lines = preg_split('/\r?\n/', trim(ltrim($csv, "\xEF\xBB\xBF")));
        $header = str_getcsv($lines[0]);
        $row = str_getcsv($lines[1]);
        $this->assertSame('', $row[array_search('勤務分', $header)]);
    }

    public function test_existing_dependent_flag_is_respected_when_legacy_column_exists(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('employees', 'is_dependent_within')) {
            \Illuminate\Support\Facades\Schema::table('employees', function (\Illuminate\Database\Schema\Blueprint $table): void {
                $table->boolean('is_dependent_within')->default(false);
            });
        }
        $id = $this->createEmployee('D002', '既存フラグ確認', 'FULL_TIME');
        DB::table('employees')->where('id', $id)->update(['is_dependent_within' => true]);
        $this->createAttendanceDaily($id, '2026-12-21', 120);
        $this->createAttendanceDaily($id, '2027-01-21', 600);
        $grid = app(\App\Services\AttendanceService::class)->listDailyGrid(['targetMonth' => '2027-01', 'periodBasis' => 'PAYROLL']);
        $this->assertSame(['2026-12-21'], array_column($grid['items'], 'targetDate'));
        $this->assertSame('2027-01-20', $grid['items'][0]['periodEnd']);
        $pdf = app(\App\Services\Reports\MonthlyWorksPdfExportService::class)->exportMonthlyWorksPdf($id, '2027-01');
        $this->assertSame(120, $pdf['summary']['totalActualWorkMinutes']);
        $this->closePayrollMonths(['2026-12', '2027-01']);
        $payroll = app(\App\Services\Reports\PayrollReportExportService::class)->exportMonthlyPayrollCsv('2027-01');
        $this->assertSame(120, $payroll['summary']['totalBaseMinutes']);
    }

    public function test_monthly_works_pdf_returns_clear_error_for_invalid_employee(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/reports/monthly-works-pdf?employeeId=9999&targetMonth=2026-04')
            ->assertStatus(404)
            ->assertJsonPath('error.message', '対象職員が見つかりません。');
    }

    private function closePayrollMonths(array $months): void
    {
        foreach ($months as $month) {
            DB::table('attendance_monthly_closes')->insert(['target_year_month' => $month, 'status' => 'CLOSED', 'closed_at' => now()]);
            DB::table('attendance_daily')->where('target_date', 'like', $month.'-%')->update(['close_status' => 'CLOSED']);
        }
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

    private function seedDailyAttendance(): int
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'R100',
            'name' => '帳票 職員',
            'kana' => 'チョウヒョウ ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-10',
            'schedule_name' => '通常勤務',
            'raw_clock_in_at' => '2026-04-10 08:55:00',
            'raw_clock_out_at' => '2026-04-10 18:05:00',
            'clock_in_at' => '2026-04-10 09:00:00',
            'clock_out_at' => '2026-04-10 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => 0.5,
            'hour_paid_leave_minutes' => 120,
            'child_care_leave_minutes' => 30,
            'nursing_care_leave_minutes' => 0,
            'remark' => '通院',
            'approval_status' => 'APPROVED',
            'approval_comment' => 'OK',
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'is_manually_edited' => 1,
            'supervisor_comment' => '確認済み',
            'manual_edited_by' => null,
            'manual_edited_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }

    private function createEmployee(string $code, string $name, string $employmentType): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => $name,
            'kana' => $name,
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => $employmentType,
            'status' => 'ACTIVE',
            'joined_on' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAttendanceDaily(int $employeeId, string $targetDate, int $workMinutes): void
    {
        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => $targetDate,
            'schedule_name' => '通常勤務',
            'clock_in_at' => $targetDate.' 09:00:00',
            'clock_out_at' => $targetDate.' 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => $workMinutes,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => 0,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'approval_status' => 'APPROVED',
            'close_status' => 'OPEN',
            'is_manually_edited' => 0,
            'updated_at' => now(),
        ]);
    }
}
