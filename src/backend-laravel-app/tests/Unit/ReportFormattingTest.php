<?php

namespace Tests\Unit;

use App\Services\Reports\ReportFormatting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportFormattingTest extends TestCase
{
    private function formatter(): object
    {
        return new class {
            use ReportFormatting;
            public function leave(array $row): string { return $this->formatLeaveSummary($row); }
            public function csv(array $rows): string { return $this->buildUtf8Csv($rows, ['自由記述', '引用符']); }
        };
    }

    public function test_day_and_time_leave_are_reported_without_fixed_day_conversion(): void
    {
        $this->assertSame('子の看護等1日 / 1時間30分 / 介護休暇0.5日 / 0時間45分', $this->formatter()->leave([
            'childCareLeaveDays' => 1, 'childCareLeaveMinutes' => 90,
            'nursingCareLeaveDays' => 0.5, 'nursingCareLeaveMinutes' => 45,
        ]));
        $this->assertSame('子の看護等1日 / 介護休暇1日', $this->formatter()->leave([
            'childCareLeaveDays' => 1, 'nursingCareLeaveDays' => 1,
        ]));
        $this->assertSame('-', $this->formatter()->leave([]));
    }

    #[DataProvider('dangerousText')]
    public function test_human_facing_csv_preserves_formula_like_text_as_text(string $input): void
    {
        $csv = $this->formatter()->csv([
            ['自由記述', '数値', '時間', '引用符'],
            [$input, '-123', '0時間00分', '通常, "確認"'],
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, null, ',', '"', '');
        $row = fgetcsv($stream, null, ',', '"', '');
        fclose($stream);
        $this->assertSame("'".$input, $row[0]);
        $this->assertSame(['-123', '0時間00分', '通常, "確認"'], array_slice($row, 1));
    }

    public static function dangerousText(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            '=1+1', '+SUM(1,1)', '-1+2', '-123', '@SUM(1,1)', " \t=1+1", "\t=1+1", "\r=1+1", "\n=1+1",
        ]);
    }
}
