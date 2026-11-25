<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmployeeCsvImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('encodings')]
    public function test_server_preview_handles_quoted_headers_commas_multiline_and_encoding(string $encoding): void
    {
        $this->signIn();
        $csv = "\"社員番号\",\"姓\",\"名\",\"メールアドレス\"\r\nE1,\"確認,姓\",\"改行\n名前\",quoted@example.com\r\n\r\nE2,次,職員,next@example.com\r\n";
        if ($encoding === 'CP932') {
            $csv = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');
        }
        $this->post('/api/admin/employees/import-csv/preview', ['file' => $this->csv($csv)])
            ->assertOk()->assertJsonPath('data.rowCount', 2)->assertJsonPath('data.processedCount', 2)
            ->assertJsonPath('data.hasLoginEmail', true)->assertJsonPath('data.createdCount', 2)
            ->assertJsonPath('data.errors', [])->assertJsonPath('data.items.0.name', "確認,姓 改行\n名前");
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('import_histories', 0);
    }

    public static function encodings(): array
    {
        return [['UTF-8'], ['CP932']];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_second_row_rejects_whole_import(string $header, string $badRow): void
    {
        $this->signIn();
        $csv = $header."\nE1,有効,職員\n".$badRow;
        $preview = $this->post('/api/admin/employees/import-csv/preview', ['file' => $this->csv($csv)]);
        $preview->assertOk()->assertJsonPath('data.rowCount', 2)->assertJsonCount(1, 'data.errors');
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv($csv)])
            ->assertStatus(422)->assertJsonPath('error.code', 'CSV_VALIDATION_ERROR');
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_auth', 0);
        $this->assertDatabaseHas('import_histories', ['processed_count' => 2, 'success_count' => 0, 'error_count' => 2]);
        $this->assertSame('FAILED', $this->historySummary()['status']);
    }

    public static function invalidRows(): array
    {
        return [
            'duplicate code' => ['社員番号,姓,名', 'E1,重複,職員'],
            'missing surname' => ['社員番号,姓,名', 'E2,,名前'],
            'invalid email' => ['社員番号,姓,名,メールアドレス', 'E2,無効,職員,invalid-email'],
            'invalid date' => ['社員番号,姓,名,入職日', 'E2,無効,職員,2026-02-30'],
            'oversized code' => ['社員番号,姓,名', str_repeat('E', 31).',無効,職員'],
        ];
    }

    #[DataProvider('duplicateIdentifiers')]
    public function test_duplicate_login_or_chat_identity_in_file_is_rejected(string $column, string $first, string $second): void
    {
        $this->signIn();
        $csv = "社員番号,姓,名,$column\nE1,有効,職員,$first\nE2,重複,職員,$second";
        $this->post('/api/admin/employees/import-csv/preview', ['file' => $this->csv($csv)])
            ->assertOk()->assertJsonCount(1, 'data.errors');
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv($csv)])->assertStatus(422);
        $this->assertDatabaseCount('employees', 0);
    }

    public static function duplicateIdentifiers(): array
    {
        return [
            ['メールアドレス', 'SAME@example.com', 'same@example.com'],
            ['Google Chat ID', '12345', 'users/12345'],
        ];
    }

    public function test_valid_preview_is_revalidated_when_database_changes(): void
    {
        $this->signIn();
        $csv = "社員番号,姓,名,メールアドレス\nE1,先頭,職員,first@example.com\nE2,後続,職員,later@example.com";
        $preview = $this->post('/api/admin/employees/import-csv/preview', ['file' => $this->csv($csv)])->assertOk();
        $preview->assertJsonPath('data.errors', []);
        $id = $this->employee('OTHER');
        DB::table('employee_auth')->insert(['employee_id' => $id, 'login_id' => 'later@example.com', 'password_hash' => Hash::make('test'), 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv($csv), 'previewFingerprint' => $preview->json('data.previewFingerprint')])
            ->assertStatus(422)->assertJsonPath('error.code', 'CSV_VALIDATION_ERROR');
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseMissing('employee_auth', ['login_id' => 'first@example.com']);
    }

    public function test_database_failure_on_second_row_rolls_back_first_update_and_login_change(): void
    {
        $this->signIn();
        $id = $this->employee('E1');
        DB::table('employee_auth')->insert(['employee_id' => $id, 'login_id' => 'original@example.com', 'password_hash' => Hash::make('test'), 'created_at' => now(), 'updated_at' => now()]);
        $this->failNextInsert('employees', 'E2');
        $csv = "社員番号,姓,名,メールアドレス\nE1,変更,職員,changed@example.com\nE2,後続,職員,later@example.com";
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv($csv)])->assertStatus(500);
        $this->assertDatabaseHas('employees', ['id' => $id, 'name' => '元の 職員']);
        $this->assertDatabaseHas('employee_auth', ['employee_id' => $id, 'login_id' => 'original@example.com']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'E2']);
        $this->assertDatabaseHas('import_histories', ['processed_count' => 2, 'success_count' => 0, 'error_count' => 2]);
        $this->assertSame('FAILED', $this->historySummary()['status']);
    }

    public function test_success_history_and_data_commit_together(): void
    {
        $this->signIn();
        $csv = "社員番号,姓,名\nE1,有効,職員\nE2,次,職員";
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv($csv)])
            ->assertOk()->assertJsonPath('data.createdCount', 2);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseHas('import_histories', ['processed_count' => 2, 'success_count' => 2, 'error_count' => 0]);
        $this->assertSame('SUCCEEDED', $this->historySummary()['status']);
    }

    public function test_success_audit_failure_also_rolls_back_employee_writes(): void
    {
        $this->signIn();
        $this->failNextInsert('import_histories');
        $this->post('/api/admin/employees/import-csv', ['file' => $this->csv("社員番号,姓,名\nE1,有効,職員")])->assertStatus(500);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseHas('import_histories', ['processed_count' => 1, 'success_count' => 0, 'error_count' => 1]);
        $this->assertSame('FAILED', $this->historySummary()['status']);
    }

    private function signIn(): void
    {
        DB::table('users')->insert(['name' => '管理者', 'email' => 'csv-admin@example.com', 'password' => Hash::make('test-password'), 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->postJson('/api/auth/login', ['loginId' => 'csv-admin@example.com', 'password' => 'test-password', 'audience' => 'ADMIN'])->json('data.accessToken');
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('employees.csv', $content);
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId(['employee_code' => $code, 'name' => '元の 職員', 'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2024-04-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function historySummary(): array
    {
        return json_decode(DB::table('import_histories')->orderByDesc('id')->value('summary_json'), true);
    }

    private function failNextInsert(string $table, ?string $employeeCode = null): void
    {
        $enabled = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use ($table, $employeeCode, &$enabled): void {
            if ($enabled && preg_match('/^insert into ["`]?'.preg_quote($table, '/').'["`]? /i', $sql)
                && ($employeeCode === null || in_array($employeeCode, $bindings, true))) {
                $enabled = false;
                // Raise a real driver exception without DDL, which would commit implicitly on MySQL.
                $connection->select('SELECT * FROM staffhub_csv_injected_missing_table');
            }
        });
    }
}
