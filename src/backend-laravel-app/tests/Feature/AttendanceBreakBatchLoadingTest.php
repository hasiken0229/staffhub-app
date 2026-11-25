<?php

namespace Tests\Feature;

use App\Services\Attendance\AttendanceServiceSupport;
use App\Services\Attendance\DailyAttendanceService;
use Carbon\CarbonImmutable as Date;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AttendanceBreakBatchLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-28 12:00:00');
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        Date::setTestNow();
        parent::tearDown();
    }

    public static function monthSizes(): array
    {
        return ['1 employee' => [1], '50 employees' => [50], '200 employees' => [200]];
    }

    #[DataProvider('monthSizes')]
    public function test_monthly_break_queries_stay_constant_and_json_matches_the_unbatched_path(int $employees): void
    {
        $this->seedMonth($employees);
        $service = app(DailyAttendanceService::class);
        [$actual, $queries, $elapsed] = $this->measured(fn () => $service->listDailyGrid(['targetMonth' => '2026-09']));
        $this->assertCount($employees * 30, $actual['items']);
        $this->assertSame(1, $this->breakQueryCount($queries));
        $this->assertLessThanOrEqual(8, count($queries), 'Complete monthly rows must not create per-day SQL.');

        $first = $actual['items'][0];
        $this->assertSame([1, 2], array_column($first['breaks'], 'segmentNo'));
        $this->assertSame(['12:00', '16:00'], array_column($first['breaks'], 'startTime'));
        $this->assertSame('IMPORTED', $first['breakReview']['source']);
        $this->assertSame('AUTOMATIC', $actual['items'][1]['breakReview']['source']);
        $this->assertTrue($actual['items'][1]['breakReview']['needsConfirmation']);
        $this->assertSame('MANUAL_OR_RETAINED', $actual['items'][2]['breakReview']['source']);
        $this->assertSame([], $actual['items'][4]['breaks']);

        $actualHash = $this->responseHash($actual);
        $gridSql = count($queries);
        $gridBreakSql = $this->breakQueryCount($queries);
        unset($actual, $queries);
        // Exercise the previous one-query-per-row mapping independently of the preloader.
        // Hash the complete JSON, then release it before constructing the next response.
        // This checks all fields without retaining three 6,000-row snapshots in the test.
        [$expected, $legacyQueries, $legacyElapsed] = $this->measured(fn () => $this->unbatchedGrid());
        $legacyBreakSql = $this->breakQueryCount($legacyQueries);
        $legacySql = count($legacyQueries);
        $expectedHash = $this->responseHash($expected);
        $this->assertSame($employees * 30, $legacyBreakSql);
        $this->assertSame($expectedHash, $actualHash);
        unset($expected, $legacyQueries);
        [$calendar, $calendarQueries] = $this->measured(fn () => $service->listMonthlyCalendar(['targetMonth' => '2026-09']));
        $calendarSql = count($calendarQueries);
        $calendarBreakSql = $this->breakQueryCount($calendarQueries);
        $this->assertSame($expectedHash, $this->responseHash($calendar));
        $this->assertSame(1, $calendarBreakSql);
        $this->assertLessThanOrEqual(10, $calendarSql);
        unset($calendar, $calendarQueries);

        fwrite(STDERR, 'ATT09 '.json_encode([
            'employees' => $employees, 'days' => 30, 'rows' => $employees * 30,
            'gridSql' => $gridSql, 'gridBreakSql' => $gridBreakSql,
            'calendarSql' => $calendarSql, 'calendarBreakSql' => $calendarBreakSql,
            'unbatchedSql' => $legacySql, 'unbatchedBreakSql' => $legacyBreakSql,
            'gridMs' => round($elapsed, 2), 'unbatchedMs' => round($legacyElapsed, 2),
            'jsonMatchesUnbatched' => $expectedHash === $actualHash,
            'jsonSha256' => $actualHash,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_paginated_list_has_one_break_query_and_a_second_call_reads_new_segments(): void
    {
        $this->seedMonth(2);
        $service = app(DailyAttendanceService::class);
        [$page, $queries] = $this->measured(fn () => $service->listDaily(['targetMonth' => '2026-09', 'perPage' => 100]));
        $this->assertCount(60, $page['items']);
        $this->assertSame(1, $this->breakQueryCount($queries));
        $empty = collect($page['items'])->firstWhere('targetDate', '2026-09-30');
        $this->assertSame([], $empty['breaks']);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id' => $empty['id'], 'segment_no' => 1, 'break_start_at' => '2026-09-30 10:00:00', 'break_end_at' => '2026-09-30 10:15:00']);
        [$next, $nextQueries] = $this->measured(fn () => $service->listDaily(['targetMonth' => '2026-09', 'perPage' => 100]));
        $nextRow = collect($next['items'])->firstWhere('id', $empty['id']);
        $this->assertSame('10:00', $nextRow['breaks'][0]['startTime']);
        $this->assertSame(1, $this->breakQueryCount($nextQueries));
        $this->assertSame([], $empty['breaks']);
    }

    public function test_schedule_projection_and_manual_overnight_breaks_keep_their_original_meaning(): void
    {
        $employee = DB::table('employees')->insertGetId(['employee_code'=>'970017','name'=>'予定補完確認','kana'=>'テスト','employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-09-01']);
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee,'standard_clock_in_time'=>'08:30','standard_clock_out_time'=>'14:30','include_before_start'=>0,'include_after_end'=>0]);
        $projected = DB::table('attendance_daily')->insertGetId(['employee_id'=>$employee,'target_date'=>'2026-09-02','raw_clock_in_at'=>'2026-09-02 08:30:00','clock_in_at'=>'2026-09-02 08:30:00','work_minutes'=>null,'break_minutes'=>0,'approval_status'=>'PENDING','close_status'=>'OPEN','updated_at'=>'2026-10-28 12:00:00']);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$projected,'segment_no'=>1,'break_start_at'=>'2026-09-02 12:00:00','break_end_at'=>'2026-09-02 12:45:00']);
        $manual = DB::table('attendance_daily')->insertGetId(['employee_id'=>$employee,'target_date'=>'2026-09-03','raw_clock_in_at'=>'2026-09-03 22:00:00','raw_clock_out_at'=>'2026-09-04 06:00:00','clock_in_at'=>'2026-09-03 22:00:00','clock_out_at'=>'2026-09-04 06:00:00','work_minutes'=>435,'break_minutes'=>45,'is_manually_edited'=>1,'manual_edited_at'=>'2026-09-04 07:00:00','approval_status'=>'APPROVED','close_status'=>'OPEN','updated_at'=>'2026-10-28 12:00:00']);
        DB::table('attendance_daily_breaks')->insert([
            ['attendance_daily_id'=>$manual,'segment_no'=>2,'break_start_at'=>'2026-09-04 03:00:00','break_end_at'=>'2026-09-04 03:15:00'],
            ['attendance_daily_id'=>$manual,'segment_no'=>1,'break_start_at'=>'2026-09-03 23:45:00','break_end_at'=>'2026-09-04 00:15:00'],
        ]);
        $before = [DB::table('attendance_daily')->get()->all(), DB::table('attendance_daily_breaks')->get()->all()];
        $result = app(DailyAttendanceService::class)->listDailyGrid(['targetMonth'=>'2026-09']);
        $this->assertSame($this->unbatchedGrid(), $result);
        $this->assertSame('CLOCK_OUT', $result['items'][0]['scheduleInferredSide']);
        $this->assertSame([], $result['items'][0]['breaks'], 'Projected no-break policy takes priority over saved segments.');
        $this->assertSame(360, $result['items'][0]['workMinutes']);
        $this->assertSame('MANUAL', $result['items'][1]['timeSource']);
        $this->assertSame([1,2], array_column($result['items'][1]['breaks'], 'segmentNo'));
        $this->assertSame([false,true], array_column($result['items'][1]['breaks'], 'startNextDay'));
        $this->assertSame([true,true], array_column($result['items'][1]['breaks'], 'endNextDay'));
        $this->assertEquals($before, [DB::table('attendance_daily')->get()->all(), DB::table('attendance_daily_breaks')->get()->all()]);
    }

    private function seedMonth(int $employees): void
    {
        $days = [];
        for ($index = 1; $index <= $employees; $index++) {
            $employee = DB::table('employees')->insertGetId(['employee_code'=>sprintf('PERF%03d',$index),'name'=>'合成職員'.$index,'kana'=>'テスト','employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-09-01']);
            for ($day = 1; $day <= 30; $day++) {
                $date = sprintf('2026-09-%02d', $day);
                $out = $day % 5 === 0 ? '12:00:00' : '18:00:00';
                $days[] = ['employee_id'=>$employee,'target_date'=>$date,'schedule_name'=>'試験勤務','raw_clock_in_at'=>$date.' 09:00:00','raw_clock_out_at'=>$date.' '.$out,'clock_in_at'=>$date.' 09:00:00','clock_out_at'=>$date.' '.$out,'break_minutes'=>$day%5===0?0:45,'work_minutes'=>$day%5===0?180:495,'is_manually_edited'=>$day%3===0,'source_system'=>$day%3===1?'TEST_IMPORT':null,'approval_status'=>'APPROVED','close_status'=>'CLOSED','updated_at'=>'2026-10-28 12:00:00'];
            }
        }
        foreach (array_chunk($days, 200) as $chunk) { DB::table('attendance_daily')->insert($chunk); }
        $breaks = [];
        foreach (DB::table('attendance_daily')->orderBy('id')->get() as $day) {
            if ((int) substr($day->target_date, -2) % 5 === 0) { continue; }
            // Reverse insertion order verifies ordering by segment_no, not auto-increment ID.
            $breaks[] = ['attendance_daily_id'=>$day->id,'segment_no'=>2,'break_start_at'=>$day->target_date.' 16:00:00','break_end_at'=>$day->target_date.' 16:15:00'];
            $breaks[] = ['attendance_daily_id'=>$day->id,'segment_no'=>1,'break_start_at'=>$day->target_date.' 12:00:00','break_end_at'=>$day->target_date.' 12:30:00'];
        }
        foreach (array_chunk($breaks, 300) as $chunk) { DB::table('attendance_daily_breaks')->insert($chunk); }
    }

    private function unbatchedGrid(): array
    {
        return (new class extends AttendanceServiceSupport {
            public function run(): array
            {
                $rows = DB::table('attendance_daily as ad')->join('employees as e','e.id','=','ad.employee_id')
                    ->select(['ad.*','e.employee_code','e.name as employee_name','e.employment_type','e.department_name','e.location_name'])
                    ->whereBetween('ad.target_date',['2026-09-01','2026-09-30'])->orderBy('e.employee_code')->orderBy('ad.target_date')->get();
                $settings = $this->resolveAttendanceAlertSettings();
                $alerts = $this->buildShortIntervalAlertMap($rows, $settings);
                return ['items'=>$rows->map(fn ($row) => $this->mapGridRow($row, $this->buildAlertsForRow($row,$alerts,$settings)))->all(),'meta'=>['targetMonth'=>'2026-09','count'=>$rows->count()]];
            }
        })->run();
    }

    private function measured(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        try {
            $response = $run();
            return [$response, DB::getQueryLog(), (microtime(true) - $start) * 1000];
        } finally { DB::disableQueryLog(); }
    }

    private function responseHash(array $response): string
    {
        return hash('sha256', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function breakQueryCount(array $queries): int
    {
        return count(array_filter($queries, static fn ($query) => str_starts_with(strtolower(ltrim($query['query'])), 'select') && str_contains($query['query'], 'attendance_daily_breaks')));
    }
}
