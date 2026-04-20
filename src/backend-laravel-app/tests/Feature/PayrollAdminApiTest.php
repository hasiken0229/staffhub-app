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
}
