<?php

namespace Tests\Feature;

use App\Services\PayrollTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PayrollImportPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_payroll_software_csv_before_import(): void
    {
        DB::table('users')->insert([
            'name' => 'プレビュー管理者',
            'email' => 'payroll-preview@example.com',
            'password' => Hash::make('StrongPassword!123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'employee_code' => '970012',
            'name' => '見本姓K 職員J',
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'payroll-preview@example.com',
            'password' => 'StrongPassword!123',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $catalog = app(PayrollTemplateCatalog::class);
        $sourceHeader = $this->sourceHeader($catalog);
        $activeRow = $this->sourceRow($sourceHeader, '00970012', '見本姓K　職員J', '250000');
        $activeRow[$this->headerIndex($sourceHeader, '駐車場代')] = '6500';
        $zeroRow = $this->sourceRow($sourceHeader, '00970022', '見本姓V　職員AU', '0');
        $summaryRow = $this->sourceRow($sourceHeader, '所属小計', '', '250000');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/admin/payroll/import-batches/preview', [
                'statementType' => 'PAYROLL',
                'targetYearMonth' => '2026-07',
                'periodStartOn' => '2026-07-01',
                'periodEndOn' => '2026-07-31',
                'payDate' => '2026-07-25',
                'publishDate' => '2026-07-20',
                'file' => $this->csvFile([$sourceHeader, $activeRow, $zeroRow, $summaryRow]),
            ])
            ->assertOk()
            ->assertJsonPath('data.dryRun', true)
            ->assertJsonPath('data.importedCount', 1)
            ->assertJsonPath('data.errorCount', 0)
            ->assertJsonPath('data.preview.sourceFormat', 'PAYROLL_SOFTWARE_PAYMENT_DEDUCTION_REPORT')
            ->assertJsonPath('data.preview.sourceRowCount', 3)
            ->assertJsonPath('data.preview.candidateRowCount', 2)
            ->assertJsonPath('data.preview.skippedRowCount', 1)
            ->assertJsonPath('data.preview.normalizedRowCount', 1)
            ->assertJsonPath('data.preview.rows.0.0', '970012')
            ->assertJsonPath('data.preview.rows.0.1', '見本姓K')
            ->assertJsonPath('data.preview.rows.0.2', '職員J');
    }

    public function test_admin_can_preview_bonus_software_csv_before_import(): void
    {
        DB::table('users')->insert([
            'name' => '賞与プレビュー管理者',
            'email' => 'bonus-preview@example.com',
            'password' => Hash::make('StrongPassword!123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'employee_code' => '970021',
            'name' => '見本姓AG 職員S',
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'bonus-preview@example.com',
            'password' => 'StrongPassword!123',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $catalog = app(PayrollTemplateCatalog::class);
        $sourceHeader = $this->bonusSourceHeader($catalog);
        $activeRow = $this->sourceRow($sourceHeader, '00970021', '見本姓AG　職員S', '441943');
        $zeroRow = $this->sourceRow($sourceHeader, '00970022', '見本姓V　職員AU', '0');
        $summaryRow = $this->sourceRow($sourceHeader, '所属小計', '', '441943');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/admin/payroll/import-batches/preview', [
                'statementType' => 'BONUS',
                'targetYearMonth' => '2026-07',
                'periodStartOn' => '2026-07-01',
                'periodEndOn' => '2026-07-31',
                'payDate' => '2026-07-15',
                'publishDate' => '2026-07-15',
                'file' => $this->csvFile([$sourceHeader, $activeRow, $zeroRow, $summaryRow]),
            ])
            ->assertOk()
            ->assertJsonPath('data.dryRun', true)
            ->assertJsonPath('data.statementType', 'BONUS')
            ->assertJsonPath('data.importedCount', 1)
            ->assertJsonPath('data.errorCount', 0)
            ->assertJsonPath('data.preview.sourceFormat', 'BONUS_SOFTWARE_PAYMENT_DEDUCTION_REPORT')
            ->assertJsonPath('data.preview.sourceRowCount', 3)
            ->assertJsonPath('data.preview.candidateRowCount', 2)
            ->assertJsonPath('data.preview.skippedRowCount', 1)
            ->assertJsonPath('data.preview.normalizedRowCount', 1)
            ->assertJsonPath('data.preview.rows.0.0', '970021')
            ->assertJsonPath('data.preview.rows.0.1', '見本姓AG')
            ->assertJsonPath('data.preview.rows.0.2', '職員S');
    }

    private function sourceHeader(PayrollTemplateCatalog $catalog): array
    {
        return array_merge(
            ['部門所属', '個人コード', '氏    名'],
            array_slice($catalog->headers('PAYROLL'), 3),
            ['現金支給', '超過勤務手当*', '超過勤務手当', '普通残業合計', '休日出勤', '休日残業合計', '所定日数', '出勤日数', '前残有休', '追加有休', '当月有休', '繰越有休', '時給１時間', '時給１時間割増', '時給１時間深夜', '時給１時間休日', '時給１時間休日深夜'],
        );
    }

    private function bonusSourceHeader(PayrollTemplateCatalog $catalog): array
    {
        return array_merge(
            ['部門所属', '個人コード', '氏    名'],
            array_slice($catalog->headers('BONUS'), 3),
            ['現金支給'],
        );
    }

    private function sourceRow(array $header, string $employeeCode, string $name, string $netAmount): array
    {
        $row = array_fill(0, count($header), '0');
        $row[0] = '保育';
        $row[1] = $employeeCode;
        $row[2] = $name;
        $row[$this->headerIndex($header, '総支給額')] = '300000';
        $row[$this->headerIndex($header, '振込支給')] = $netAmount;

        return $row;
    }

    private function csvFile(array $rows): UploadedFile
    {
        $content = implode("\r\n", array_map(static fn (array $row): string => implode(',', $row), $rows))."\r\n";

        return UploadedFile::fake()->createWithContent(
            '支給控除一覧表.csv',
            mb_convert_encoding($content, 'SJIS-win', 'UTF-8'),
        );
    }

    private function headerIndex(array $header, string $target): int
    {
        $index = array_search($target, $header, true);
        if ($index === false) {
            throw new \RuntimeException("Header not found: {$target}");
        }

        return (int) $index;
    }
}
