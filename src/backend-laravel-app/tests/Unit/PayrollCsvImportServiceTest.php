<?php

namespace Tests\Unit;

use App\Services\PayrollCsvImportService;
use App\Services\PayrollTemplateCatalog;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class PayrollCsvImportServiceTest extends TestCase
{
    public function test_it_normalizes_payroll_software_report_and_accepts_a_legacy_definition(): void
    {
        $catalog = app(PayrollTemplateCatalog::class);
        $service = app(PayrollCsvImportService::class);
        $sourceHeader = $this->sourceHeader($catalog);

        $activeRow = $this->sourceRow($sourceHeader, '00970012', '見本姓K　職員J', '250000');
        $activeRow[$this->headerIndex($sourceHeader, '駐車場代')] = '6500';
        $zeroRow = $this->sourceRow($sourceHeader, '00970022', '見本姓V　職員AU', '0');
        $summaryRow = $this->sourceRow($sourceHeader, '所属小計', '', '250000');

        $prepared = $service->prepareRows(
            $this->csvFile([$sourceHeader, $activeRow, $zeroRow, $summaryRow]),
            'PAYROLL',
            $catalog->legacyHeaders('PAYROLL'),
        );

        $parkingIndex = $this->headerIndex($prepared['header'], '駐車場代');

        $this->assertSame('PAYROLL_SOFTWARE_PAYMENT_DEDUCTION_REPORT', $prepared['sourceFormat']);
        $this->assertSame(3, $prepared['sourceRowCount']);
        $this->assertSame(2, $prepared['candidateRowCount']);
        $this->assertSame(1, $prepared['skippedRowCount']);
        $this->assertSame(1, count($prepared['rows']) - 1);
        $this->assertSame(['line' => 3, 'employeeCode' => '970022', 'reason' => '振込支給が0円のため除外'], $prepared['skippedRows'][0]);
        $this->assertSame('970012', $prepared['rows'][1][0]);
        $this->assertSame('見本姓K', $prepared['rows'][1][1]);
        $this->assertSame('職員J', $prepared['rows'][1][2]);
        $this->assertSame('6500', $prepared['rows'][1][$parkingIndex]);
        $this->assertCount(41, $prepared['header']);
        $this->assertCount(41, $prepared['rows'][1]);
    }

    public function test_it_still_accepts_the_legacy_payroll_csv_without_parking_fee(): void
    {
        $catalog = app(PayrollTemplateCatalog::class);
        $service = app(PayrollCsvImportService::class);
        $legacyHeader = $catalog->legacyHeaders('PAYROLL');
        $row = array_fill(0, count($legacyHeader), '0');
        $row[0] = '970012';
        $row[1] = '見本姓AR';
        $row[2] = '職員F';
        $row[$this->headerIndex($legacyHeader, '振込支給')] = '162020';

        $prepared = $service->prepareRows(
            $this->csvFile([$legacyHeader, $row], 'legacy-payroll.csv'),
            'PAYROLL',
            $legacyHeader,
        );

        $this->assertNull($prepared['sourceFormat']);
        $this->assertSame($legacyHeader, $prepared['header']);
        $this->assertCount(2, $prepared['rows']);
        $this->assertNotContains('駐車場代', $prepared['header']);
    }

    public function test_it_normalizes_bonus_software_report_to_the_standard_csv(): void
    {
        $catalog = app(PayrollTemplateCatalog::class);
        $service = app(PayrollCsvImportService::class);
        $sourceHeader = $this->bonusSourceHeader($catalog);

        $activeRow = $this->sourceRow($sourceHeader, '00970021', '見本姓AG　職員S', '441943');
        $zeroRow = $this->sourceRow($sourceHeader, '00970022', '見本姓V　職員AU', '0');
        $summaryRow = $this->sourceRow($sourceHeader, '所属小計', '', '441943');

        $prepared = $service->prepareRows(
            $this->csvFile([$sourceHeader, $activeRow, $zeroRow, $summaryRow], 'bonus-software.csv'),
            'BONUS',
            $catalog->headers('BONUS'),
        );

        $this->assertSame('BONUS_SOFTWARE_PAYMENT_DEDUCTION_REPORT', $prepared['sourceFormat']);
        $this->assertSame(3, $prepared['sourceRowCount']);
        $this->assertSame(2, $prepared['candidateRowCount']);
        $this->assertSame(1, $prepared['skippedRowCount']);
        $this->assertSame(['line' => 3, 'employeeCode' => '970022', 'reason' => '振込支給が0円のため除外'], $prepared['skippedRows'][0]);
        $this->assertSame('970021', $prepared['rows'][1][0]);
        $this->assertSame('見本姓AG', $prepared['rows'][1][1]);
        $this->assertSame('職員S', $prepared['rows'][1][2]);
        $this->assertSame($catalog->headers('BONUS'), $prepared['header']);
        $this->assertCount(20, $prepared['header']);
        $this->assertCount(20, $prepared['rows'][1]);
        $this->assertNotContains('現金支給', $prepared['header']);
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

    private function csvFile(array $rows, string $name = 'payroll-software.csv'): UploadedFile
    {
        $content = implode("\r\n", array_map(static fn (array $row): string => implode(',', $row), $rows))."\r\n";

        return UploadedFile::fake()->createWithContent(
            $name,
            mb_convert_encoding($content, 'SJIS-win', 'UTF-8'),
        );
    }

    private function headerIndex(array $header, string $target): int
    {
        $index = array_search($target, $header, true);
        $this->assertNotFalse($index, "Header not found: {$target}");

        return (int) $index;
    }
}
