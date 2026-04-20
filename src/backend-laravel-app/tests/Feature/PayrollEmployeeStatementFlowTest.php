<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PayrollEmployeeStatementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_list_detail_and_mark_published_payroll_statement_as_viewed(): void
    {
        $employeeId = $this->employee('S301', '明細 職員', 'staff301@example.com');
        $otherEmployeeId = $this->employee('S302', '別 職員', 'staff302@example.com');
        $statementId = $this->payrollStatement($employeeId, [
            'target_year_month' => '2026-03',
            'published_at' => now()->subDay(),
            'remarks' => '3月分',
        ]);
        $this->payrollStatement($employeeId, [
            'target_year_month' => '2026-04',
            'published_at' => now()->addDay(),
        ]);
        $this->payrollStatement($otherEmployeeId, [
            'target_year_month' => '2026-03',
            'published_at' => now()->subDay(),
        ]);
        DB::table('payroll_statement_lines')->insert([
            [
                'payroll_statement_id' => $statementId,
                'section_type' => 'PAY',
                'display_order' => 1,
                'item_label' => '基本給',
                'amount' => 300000,
                'raw_source_key' => 'base_salary',
            ],
            [
                'payroll_statement_id' => $statementId,
                'section_type' => 'SUMMARY',
                'display_order' => 1,
                'item_label' => '総支給額',
                'amount' => 300000,
                'raw_source_key' => 'gross',
            ],
            [
                'payroll_statement_id' => $statementId,
                'section_type' => 'SUMMARY',
                'display_order' => 2,
                'item_label' => '差引支給',
                'amount' => 250000,
                'raw_source_key' => 'net',
            ],
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'staff301@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/payroll/statements?yearMonth=2026-03')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $statementId)
            ->assertJsonPath('data.0.statementType', 'PAYROLL')
            ->assertJsonPath('data.0.viewed', false)
            ->assertJsonPath('data.0.remarks', '3月分');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/payroll/statements/' . $statementId)
            ->assertOk()
            ->assertJsonPath('data.id', $statementId)
            ->assertJsonPath('data.employeeCode', 'S301')
            ->assertJsonPath('data.legacyMode', false)
            ->assertJsonPath('data.sections.pay.0.itemLabel', '基本給')
            ->assertJsonPath('data.grossAmount', 300000)
            ->assertJsonPath('data.netAmount', 250000)
            ->assertJsonPath('data.deleteAvailable', false);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/payroll/statements/' . $statementId . '/viewed', [
                'viewedAt' => '2026-04-21T09:00:00+09:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertDatabaseHas('payroll_statement_views', [
            'payroll_statement_id' => $statementId,
            'employee_id' => $employeeId,
            'viewed_at' => '2026-04-21 09:00:00',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'EMPLOYEE',
            'actor_id' => $employeeId,
            'action' => 'PAYROLL_VIEWED',
            'target_type' => 'PAYROLL_STATEMENT',
            'target_id' => (string) $statementId,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/payroll/statements?yearMonth=2026-03')
            ->assertOk()
            ->assertJsonPath('data.0.viewed', true);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/payroll/statements?yearMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function employee(string $employeeCode, string $name, string $loginId): int
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
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

        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }

    private function payrollStatement(int $employeeId, array $overrides = []): int
    {
        return (int) DB::table('payroll_statements')->insertGetId(array_merge([
            'employee_id' => $employeeId,
            'statement_type' => 'PAYROLL',
            'target_year_month' => '2026-03',
            'pay_date' => '2026-04-25',
            'period_start_on' => '2026-03-01',
            'period_end_on' => '2026-03-31',
            'file_path' => 'payroll/employee-flow.pdf',
            'original_file_name' => 'employee-flow.pdf',
            'file_size_bytes' => 1024,
            'content_type' => 'application/pdf',
            'published_at' => now()->subDay(),
            'uploaded_by' => $employeeId,
            'payroll_data_definition_id' => null,
            'payroll_import_batch_id' => null,
            'remarks' => null,
            'deleted_at' => null,
            'deleted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
