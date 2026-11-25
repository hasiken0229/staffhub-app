<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PayrollFileCacheProtectionTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_admin_pdf_download_preview_and_range_are_private_and_not_stored(): void
    {
        $employeeId = $this->employee('CACHE-01');
        $statementId = $this->statement($employeeId);
        DB::table('users')->insert([
            'name' => '合成管理者', 'email' => 'cache-admin@example.invalid',
            'password' => Hash::make('SyntheticPassword!123'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->login('cache-admin@example.invalid', 'ADMIN');

        foreach (['download' => 'attachment', 'preview' => 'inline'] as $action => $disposition) {
            $url = '/api/admin/payroll/statements/'.$statementId.'/'.$action;
            $response = $this->get($url, ['Authorization' => 'Bearer '.$token]);
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith($disposition.';', $response->headers->get('Content-Disposition'));
            $this->assertNotStored($response);

            $range = $this->get($url, ['Authorization' => 'Bearer '.$token, 'Range' => 'bytes=0-9']);
            $range->assertStatus(206)->assertHeader('Content-Length', '10');
            $this->assertStringStartsWith('bytes 0-9/', $range->headers->get('Content-Range'));
            $this->assertStringStartsWith($disposition.';', $range->headers->get('Content-Disposition'));
            $this->assertNotStored($range);

            // Use a closed range beyond EOF. The installed Symfony implementation ignores
            // an open-ended range whose calculated end is less than its start.
            $invalidRange = $this->get($url, ['Authorization' => 'Bearer '.$token, 'Range' => 'bytes=99999-100000']);
            $invalidRange->assertStatus(416);
            $this->assertNotStored($invalidRange);
        }

        // The same API policy also protects authenticated CSV exports.
        $csv = $this->get('/api/admin/employees/template-csv', ['Authorization' => 'Bearer '.$token]);
        $csv->assertOk();
        $this->assertStringContainsString('attachment', $csv->headers->get('Content-Disposition'));
        $this->assertNotStored($csv);
    }

    public function test_employee_pdf_cache_policy_does_not_change_access_or_publication_rules(): void
    {
        $owner = $this->employee('CACHE-02');
        $other = $this->employee('CACHE-03');
        $visible = $this->statement($owner);
        $notOwned = $this->statement($other);
        $future = $this->statement($owner, ['target_year_month' => '2026-11', 'published_at' => now()->addDay()]);
        $unpublished = $this->statement($owner, ['target_year_month' => '2026-12', 'published_at' => null]);
        $deleted = $this->statement($owner, ['target_year_month' => '2027-01', 'deleted_at' => now()]);
        $token = $this->login('cache-02@example.invalid', 'EMPLOYEE');
        $auth = ['Authorization' => 'Bearer '.$token];

        $response = $this->get('/api/payroll/statements/'.$visible.'/download', $auth);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertNotStored($response);

        $range = $this->get('/api/payroll/statements/'.$visible.'/download', $auth + ['Range' => 'bytes=0-9']);
        $range->assertStatus(206)->assertHeader('Content-Length', '10');
        $this->assertNotStored($range);

        foreach ([$notOwned, $future, $unpublished, $deleted] as $statementId) {
            $denied = $this->get('/api/payroll/statements/'.$statementId.'/download', $auth);
            $denied->assertStatus(404);
            $this->assertNotStored($denied);
        }

        $adminDenied = $this->get('/api/admin/payroll/statements/'.$visible.'/preview', $auth);
        $adminDenied->assertStatus(403);
        $this->assertNotStored($adminDenied);

        $anonymous = $this->get('/api/payroll/statements/'.$visible.'/download');
        $anonymous->assertStatus(401);
        $this->assertNotStored($anonymous);
    }

    private function assertNotStored(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    private function login(string $loginId, string $audience): string
    {
        return $this->postJson('/api/auth/login', [
            'loginId' => $loginId, 'password' => 'SyntheticPassword!123', 'audience' => $audience,
        ])->assertOk()->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        $id = (int) DB::table('employees')->insertGetId([
            'employee_code' => $code, 'name' => '合成職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2024-04-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_auth')->insert([
            'employee_id' => $id, 'login_id' => strtolower($code).'@example.invalid',
            'password_hash' => Hash::make('SyntheticPassword!123'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function statement(int $employeeId, array $overrides = []): int
    {
        $name = 'cache-protection-'.bin2hex(random_bytes(8)).'.pdf';
        $path = storage_path('app/private/'.$name);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "%PDF-1.4\nSynthetic payroll cache protection fixture\n%%EOF\n");
        $this->createdFiles[] = $path;
        return (int) DB::table('payroll_statements')->insertGetId(array_merge([
            'employee_id' => $employeeId, 'statement_type' => 'PAYROLL', 'target_year_month' => '2026-10',
            'file_path' => $name, 'original_file_name' => 'synthetic-payroll.pdf', 'file_size_bytes' => filesize($path),
            'content_type' => 'application/pdf', 'published_at' => now()->subDay(), 'uploaded_by' => $employeeId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
