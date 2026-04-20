<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ReportExportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_and_payroll_csv_exports_use_net_work_minutes_and_leave_fields(): void
    {
        $token = $this->adminToken();
        $this->seedDailyAttendance();

        $dailyCsv = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/daily-csv?from=2026-04-10&to=2026-04-10')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('時間有給分', $dailyCsv);
        $this->assertStringContainsString('120', $dailyCsv);
        $this->assertStringContainsString('確認済み', $dailyCsv);
        $this->assertStringContainsString('通院', $dailyCsv);

        $monthlyCsv = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/monthly-csv?targetMonth=2026-04')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('8時間00分', $monthlyCsv);
        $this->assertStringContainsString('2時間00分', $monthlyCsv);
        $this->assertStringNotContainsString('7時間00分', $monthlyCsv);

        $payrollCsv = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/monthly-payroll-csv?targetMonth=2026-04')
            ->assertOk()
            ->getContent();
        $payrollCsv = mb_convert_encoding($payrollCsv, 'UTF-8', 'SJIS-win');

        $this->assertStringContainsString('8時間00分', $payrollCsv);
        $this->assertStringContainsString('2時間00分', $payrollCsv);
        $this->assertStringNotContainsString('7時間00分', $payrollCsv);
    }

    public function test_attendance_pdf_exports_return_pdf_content(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->seedDailyAttendance();

        $dailyPdf = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/daily-pdf?targetMonth=2026-04')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $dailyPdf);

        $monthlyWorksPdf = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/reports/monthly-works-pdf?employeeId=' . $employeeId . '&targetMonth=2026-04')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $this->assertStringStartsWith('%PDF', $monthlyWorksPdf);
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
}
