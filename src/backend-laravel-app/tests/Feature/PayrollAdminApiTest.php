<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PayrollAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_payroll_list_returns_consistent_total_with_pagination(): void
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('StrongPassword!123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeIds = [];
        foreach ([['101', '職員A'], ['102', '職員B'], ['103', '職員C']] as [$employeeCode, $name]) {
            $employeeIds[] = DB::table('employees')->insertGetId([
                'employee_code' => $employeeCode,
                'name' => $name,
                'kana' => null,
                'department_name' => '保育',
                'employment_type' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'joined_on' => '2024-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($employeeIds as $index => $employeeId) {
            $statementId = DB::table('payroll_statements')->insertGetId([
                'employee_id' => $employeeId,
                'statement_type' => 'PAYROLL',
                'target_year_month' => '2026-03',
                'pay_date' => null,
                'period_start_on' => null,
                'period_end_on' => null,
                'file_path' => "payroll/test-{$employeeId}.pdf",
                'original_file_name' => "statement-{$employeeId}.pdf",
                'file_size_bytes' => 1024,
                'content_type' => 'application/pdf',
                'published_at' => now(),
                'uploaded_by' => $employeeId,
                'payroll_data_definition_id' => null,
                'payroll_import_batch_id' => null,
                'remarks' => null,
                'deleted_at' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($index === 0) {
                DB::table('payroll_statement_views')->insert([
                    'payroll_statement_id' => $statementId,
                    'employee_id' => $employeeId,
                    'viewed_at' => now(),
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PHPUnit',
                ]);
            }
        }

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'StrongPassword!123',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/payroll/statements?perPage=2&page=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.perPage', 2);
    }

    public function test_admin_can_list_detail_and_delete_payroll_import_batch(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('P201', '取込 職員');
        $definitionId = $this->payrollDefinition();
        $batchId = $this->payrollImportBatch($definitionId, [
            'summary_json' => json_encode([
                'errors' => [[
                    'line' => 3,
                    'employeeCode' => 'UNKNOWN',
                    'message' => 'employees に該当職員が存在しません。',
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $statementId = $this->payrollStatement($employeeId, $definitionId, $batchId);
        DB::table('payroll_import_batch_items')->insert([
            'payroll_import_batch_id' => $batchId,
            'employee_id' => $employeeId,
            'employee_code' => 'P201',
            'employee_name' => '取込 職員',
            'gross_amount' => 300000,
            'deduction_amount' => 50000,
            'net_amount' => 250000,
            'statement_id' => $statementId,
            'line_no' => 2,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/payroll/import-batches?statementType=PAYROLL&targetYearMonth=2026-03')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $batchId)
            ->assertJsonPath('data.0.statementType', 'PAYROLL')
            ->assertJsonPath('data.0.sourceFileName', 'payroll_2026_03.csv');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/payroll/import-batches/' . $batchId . '?employeeCode=P201')
            ->assertOk()
            ->assertJsonPath('data.id', $batchId)
            ->assertJsonPath('data.items.0.employeeCode', 'P201')
            ->assertJsonPath('data.items.0.netAmount', 250000)
            ->assertJsonPath('data.errors.0.employeeCode', 'UNKNOWN');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/admin/payroll/import-batches/' . $batchId)
            ->assertOk()
            ->assertJsonPath('data.id', $batchId)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseHas('payroll_import_batches', [
            'id' => $batchId,
            'status' => 'DELETED',
            'deleted_by' => $employeeId,
        ]);
        $this->assertNotNull(DB::table('payroll_import_batches')->where('id', $batchId)->value('deleted_at'));
        $this->assertNotNull(DB::table('payroll_import_batch_items')->where('payroll_import_batch_id', $batchId)->value('deleted_at'));
        $this->assertDatabaseHas('payroll_statements', [
            'id' => $statementId,
            'deleted_by' => $employeeId,
        ]);
        $this->assertNotNull(DB::table('payroll_statements')->where('id', $statementId)->value('deleted_at'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'ADMIN',
            'action' => 'PAYROLL_BATCH_DELETE',
            'target_type' => 'PAYROLL_IMPORT_BATCH',
            'target_id' => (string) $batchId,
        ]);
    }

    public function test_admin_gets_not_found_when_import_batch_has_no_exportable_pdf(): void
    {
        $token = $this->adminToken();
        $definitionId = $this->payrollDefinition();
        $batchId = $this->payrollImportBatch($definitionId);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/payroll/import-batches/' . $batchId . '/export-pdf')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_admin_can_export_payroll_batch_zip_and_redownload_from_history(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('P301', 'ZIP 職員');
        $definitionId = $this->payrollDefinition();
        $batchId = $this->payrollImportBatch($definitionId);
        $statementId = $this->payrollStatement($employeeId, $definitionId, $batchId);
        DB::table('payroll_import_batch_items')->insert([
            'payroll_import_batch_id' => $batchId,
            'employee_id' => $employeeId,
            'employee_code' => 'P301',
            'employee_name' => 'ZIP 職員',
            'gross_amount' => 300000,
            'deduction_amount' => 50000,
            'net_amount' => 250000,
            'statement_id' => $statementId,
            'line_no' => 2,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pdfPath = storage_path('app/private/payroll/import-batches/P201.pdf');
        if (!is_dir(dirname($pdfPath))) {
            mkdir(dirname($pdfPath), 0775, true);
        }
        file_put_contents($pdfPath, '%PDF-1.4 test payroll statement');

        $zip = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/payroll/import-batches/' . $batchId . '/export-pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->getContent();

        $this->assertNotSame('', $zip);
        $this->assertDatabaseHas('import_histories', [
            'import_type' => 'PAYROLL_BATCH_ZIP',
            'target_period' => '2026-03',
            'success_count' => 1,
        ]);

        $historyId = (int) DB::table('import_histories')
            ->where('import_type', 'PAYROLL_BATCH_ZIP')
            ->value('id');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/files/history/' . $historyId . '/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-payroll@example.com',
            'password' => Hash::make('StrongPassword!123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-payroll@example.com',
            'password' => 'StrongPassword!123',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $employeeCode, string $name): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $employeeCode,
            'name' => $name,
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payrollDefinition(): int
    {
        return (int) DB::table('payroll_data_definitions')->insertGetId([
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
    }

    private function payrollImportBatch(int $definitionId, array $overrides = []): int
    {
        return (int) DB::table('payroll_import_batches')->insertGetId(array_merge([
            'statement_type' => 'PAYROLL',
            'payroll_data_definition_id' => $definitionId,
            'target_year_month' => '2026-03',
            'period_start_on' => '2026-03-01',
            'period_end_on' => '2026-03-31',
            'pay_date' => '2026-04-25',
            'publish_date' => '2026-04-20',
            'source_file_name' => 'payroll_2026_03.csv',
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
        ], $overrides));
    }

    private function payrollStatement(int $employeeId, int $definitionId, int $batchId): int
    {
        return (int) DB::table('payroll_statements')->insertGetId([
            'employee_id' => $employeeId,
            'statement_type' => 'PAYROLL',
            'target_year_month' => '2026-03',
            'pay_date' => '2026-04-25',
            'period_start_on' => '2026-03-01',
            'period_end_on' => '2026-03-31',
            'file_path' => 'payroll/import-batches/P201.pdf',
            'original_file_name' => 'P201_2026_03.pdf',
            'file_size_bytes' => 1024,
            'content_type' => 'application/pdf',
            'published_at' => '2026-04-20 00:00:00',
            'uploaded_by' => $employeeId,
            'payroll_data_definition_id' => $definitionId,
            'payroll_import_batch_id' => $batchId,
            'remarks' => null,
            'deleted_at' => null,
            'deleted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
