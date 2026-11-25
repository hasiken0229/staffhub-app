<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use App\Services\Reports\AttendanceReportExportService;
use App\Services\Reports\JapaneseHolidayCalendar;
use App\Services\Reports\MonthlyWorksPdfExportService;
use App\Services\Reports\PayrollReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_and_time_quantities_agree_in_both_csv_reports_and_monthly_pdf(): void
    {
        $id = $this->employee();
        $this->day($id, '2026-09-01', ['schedule_name' => '子の看護等休暇']);
        $this->day($id, '2026-09-02', ['schedule_name' => '介護休暇']);
        $this->day($id, '2026-09-03', ['child_care_leave_minutes' => 90, 'nursing_care_leave_minutes' => 45]);
        $service = app(AttendanceReportExportService::class);
        $daily = $this->csvRows($service->exportDailyAttendanceCsv(['from' => '2026-09-01', 'to' => '2026-09-03']));
        $this->assertSame('1', $daily[0]['子の看護等日数']);
        $this->assertSame('0', $daily[0]['子の看護等分']);
        $this->assertSame('1', $daily[1]['介護休暇日数']);
        $this->assertSame('0', $daily[1]['介護休暇分']);
        $this->assertSame('90', $daily[2]['子の看護等分']);
        $this->assertSame('45', $daily[2]['介護休暇分']);
        $monthly = $this->csvRows($service->exportMonthlyAttendanceCsv('2026-09'))[0];
        $this->assertSame('1', $monthly['子の看護等日数']);
        $this->assertSame('1', $monthly['介護休暇日数']);
        $this->assertSame('1時間30分', $monthly['子の看護等']);
        $this->assertSame('0時間45分', $monthly['介護休暇']);

        $items = app(AttendanceService::class)->listMonthlyCalendar(['targetMonth' => '2026-09', 'employeeId' => $id])['items'];
        $summary = (new \ReflectionMethod(MonthlyWorksPdfExportService::class, 'buildMonthlyWorksSummary'))
            ->invoke(app(MonthlyWorksPdfExportService::class), $items)['leave'];
        $this->assertSame(1.0, $summary['子の看護等休暇（日）']);
        $this->assertSame(1.0, $summary['介護休暇（日）']);
        $this->assertSame(90, $summary['子の看護等休暇']);
        $this->assertSame(45, $summary['介護休暇']);
    }

    public function test_human_csv_escapes_formula_text_but_payroll_interchange_stays_unchanged(): void
    {
        $id = $this->employee('=1+1');
        $this->day($id, '2026-09-01', ['supervisor_comment' => '=1+1', 'remark' => "\t+1+1", 'clock_in_at' => '2026-09-01 09:00:00', 'clock_out_at' => '2026-09-01 09:00:00']);
        $daily = $this->csvRows(app(AttendanceReportExportService::class)->exportDailyAttendanceCsv(['from' => '2026-09-01', 'to' => '2026-09-01']))[0];
        $this->assertSame("'=1+1", $daily['氏名']);
        $this->assertSame("'=1+1", $daily['所属長コメント']);
        $this->assertSame("'\t+1+1", $daily['備考']);
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-09', 'status' => 'CLOSED', 'closed_at' => now()]);
        DB::table('attendance_daily')->where('employee_id', $id)->update(['close_status' => 'CLOSED']);
        $payroll = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-09');
        $rows = $this->csvRows(mb_convert_encoding($payroll['content'], 'UTF-8', 'SJIS-win'));
        $this->assertSame('=1+1', $rows[0]['氏名']);
        $this->assertCount(4, $rows[0]);
    }

    public function test_pdf_uses_the_same_authoritative_holidays_including_special_and_substitute_days(): void
    {
        $calendar = json_decode(file_get_contents(resource_path('calendar/japanese-holidays.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['2026-09-22', '2026-05-06', '2019-04-30', '2020-07-24'] as $date) {
            $this->assertSame($calendar['names'][$date], JapaneseHolidayCalendar::name($date));
            $rows = (new \ReflectionMethod(MonthlyWorksPdfExportService::class, 'buildFullMonthRows'))
                ->invoke(app(MonthlyWorksPdfExportService::class), substr($date, 0, 7), []);
            $day = collect($rows)->firstWhere('targetDate', $date);
            $this->assertSame($calendar['names'][$date], $day['_holidayName']);
            $class = (new \ReflectionMethod(MonthlyWorksPdfExportService::class, 'monthlyWorksPdfRowClass'))
                ->invoke(app(MonthlyWorksPdfExportService::class), $day);
            $this->assertSame('holiday', $class);
        }
        $this->assertNull(JapaneseHolidayCalendar::name('2020-10-12'));
        $this->assertNull(JapaneseHolidayCalendar::name('9999-01-01'));
    }

    public function test_long_note_pdf_repeats_employee_actual_cross_year_period_and_page_number(): void
    {
        $id = $this->employee('帳票確認職員', 'PART_TIME');
        $remark = mb_substr(str_repeat('長い備考の続きです。改ページしても職員と集計期間を確認できます。', 10), 0, 255);
        $this->assertSame(255, mb_strlen($remark));
        for ($date = \Carbon\CarbonImmutable::parse('2026-12-21'); $date->toDateString() <= '2027-01-20'; $date = $date->addDay()) {
            $this->day($id, $date->toDateString(), [
                'remark' => $remark,
                'clock_in_at' => $date->toDateString().' 09:00:00',
                'clock_out_at' => $date->toDateString().' 18:00:00',
                'break_minutes' => 60, 'work_minutes' => 480,
            ]);
        }
        $service = app(MonthlyWorksPdfExportService::class);
        $pdf = $service->exportMonthlyWorksPdf($id, '2027-01');
        $samples = getenv('STAFFHUB_REPORT_PDF_SAMPLES');
        if ($samples) file_put_contents($samples.'/monthly-long-notes-cross-year.pdf', $pdf['content']);
        $pages = $this->pdfPageStreams($pdf['content']);
        $this->assertGreaterThan(1, count($pages));
        foreach ($pages as $index => $page) {
            foreach (['RP001', '帳票確認職員', '2027-01', '2026/12/21', '2027/01/20', 'ページ'] as $text) {
                $this->assertStringContainsString(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'), $page);
            }
            $this->assertStringContainsString(mb_convert_encoding(($index + 1).' / '.count($pages).' ページ', 'UTF-16BE', 'UTF-8'), $page);
        }
        $this->assertSame('2026-12-21', $pdf['summary']['periodStart']);
        $this->assertSame('2027-01-20', $pdf['summary']['periodEnd']);
        if ($samples) {
            DB::table('attendance_daily')->where('employee_id', $id)->update(['remark' => '通常の備考']);
            file_put_contents($samples.'/monthly-normal-cross-year.pdf', $service->exportMonthlyWorksPdf($id, '2027-01')['content']);
            DB::table('attendance_daily')->where('employee_id', $id)->where('target_date', '2026-12-21')->update([
                'schedule_name' => '子の看護等休暇', 'clock_in_at' => null, 'clock_out_at' => null, 'work_minutes' => 0,
            ]);
            DB::table('attendance_daily')->where('employee_id', $id)->where('target_date', '2026-12-22')->update([
                'schedule_name' => '介護休暇', 'clock_in_at' => null, 'clock_out_at' => null, 'work_minutes' => 0,
            ]);
            file_put_contents($samples.'/daily-day-unit-leave.pdf', app(AttendanceReportExportService::class)->exportDailyAttendancePdf('2026-12'));
        }
    }

    /** Read each generated PDF page's content stream, independent of pagination. */
    private function pdfPageStreams(string $pdf): array
    {
        preg_match_all('/(\d+) 0 obj\b(.*?)endobj/s', $pdf, $matches, PREG_SET_ORDER);
        $streams = [];
        foreach ($matches as $match) {
            if (!preg_match('/stream\r?\n(.*)/s', $match[2], $stream) || !preg_match('/\/Length (\d+)/', $match[2], $length)) continue;
            $content = substr($stream[1], 0, (int) $length[1]);
            $streams[$match[1]] = str_contains($match[2], '/FlateDecode') ? gzuncompress($content) : $content;
        }
        preg_match_all('/\/Type \/Page\b(?:(?!endobj).)*?\/Contents (\d+) 0 R/s', $pdf, $pages);

        return array_map(static fn (string $id): string => $streams[$id], $pages[1]);
    }

    private function employee(string $name = '帳票確認職員', string $employmentType = 'FULL_TIME'): int
    {
        return DB::table('employees')->insertGetId([
            'employee_code' => 'RP001', 'name' => $name, 'kana' => 'チョウヒョウ',
            'department_name' => '保育', 'employment_type' => $employmentType,
            'status' => 'ACTIVE', 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function day(int $id, string $date, array $overrides = []): void
    {
        DB::table('attendance_daily')->insert(array_replace([
            'employee_id' => $id, 'target_date' => $date, 'schedule_name' => '通常勤務',
            'break_minutes' => 0, 'work_minutes' => 0, 'approval_status' => 'APPROVED',
            'close_status' => 'OPEN', 'is_manually_edited' => 0, 'updated_at' => now(),
        ], $overrides));
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = array_combine($header, $row);
        fclose($stream);

        return $rows;
    }
}
