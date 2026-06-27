<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class HarmosMigrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_harmos_employee_csv_preview_and_import_upserts_employees(): void
    {
        $token = $this->adminToken();
        $file = $this->csvFile('harmos-employees.csv', "社員番号,氏名,所属,雇用形態,状態,入職日\nE9001,移行 太郎,保育,常勤,在職,2025/04/01\n");

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/harmos-migration/preview', [
                'importType' => 'HARMOS_EMPLOYEE_CSV',
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.dryRun', true)
            ->assertJsonPath('data.successCount', 1)
            ->assertJsonPath('data.summary.createdCount', 1);

        $file = $this->csvFile('harmos-employees.csv', "社員番号,氏名,所属,雇用形態,状態,入職日\nE9001,移行 太郎,保育,常勤,在職,2025/04/01\n");
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/harmos-migration/import', [
                'importType' => 'HARMOS_EMPLOYEE_CSV',
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.dryRun', false)
            ->assertJsonPath('data.successCount', 1);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'E9001',
            'name' => '移行 太郎',
            'department_name' => '保育',
        ]);
        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'HARMOS_EMPLOYEE_CSV',
            'success_count' => 1,
        ]);
    }

    public function test_harmos_attendance_daily_import_marks_rows_as_harmos_source(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E9002');
        $file = $this->csvFile('harmos-daily.csv', "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,有給\nE9002,2026/06/01,08:30,17:30,60,480,0\n");

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/harmos-migration/import', [
                'importType' => 'HARMOS_ATTENDANCE_DAILY_CSV',
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.successCount', 1);

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-06-01',
            'clock_in_at' => '2026-06-01 08:30:00',
            'clock_out_at' => '2026-06-01 17:30:00',
            'work_minutes' => 480,
            'source_system' => 'HARMOS',
            'source_import_type' => 'HARMOS_ATTENDANCE_DAILY_CSV',
        ]);
    }

    public function test_harmos_paid_leave_balance_import_creates_migration_grant_and_ledger(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E9003');
        $file = $this->csvFile('harmos-paid-leave.csv', "社員番号,有給残数,有効期限\nE9003,12.5,2028/03/31\n");

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/harmos-migration/import', [
                'importType' => 'HARMOS_PAID_LEAVE_BALANCE_CSV',
                'migrationDate' => '2026-11-30',
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.successCount', 1);

        $this->assertDatabaseHas('paid_leave_grants', [
            'employee_id' => $employeeId,
            'granted_on' => '2026-11-30',
            'granted_days' => 12.5,
            'source_system' => 'HARMOS',
        ]);
        $this->assertDatabaseHas('paid_leave_ledger', [
            'employee_id' => $employeeId,
            'entry_type' => 'GRANT',
            'days_delta' => 12.5,
        ]);
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-harmos@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-harmos@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => '移行 職員',
            'kana' => null,
            'department_name' => '保育',
            'location_name' => null,
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2025-04-01',
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function csvFile(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF" . $content);
    }
}
