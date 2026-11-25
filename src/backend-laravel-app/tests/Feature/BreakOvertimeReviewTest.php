<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Attendance\BreakOvertimeReviewService;
use App\Services\Attendance\DailyAttendanceService;
use Carbon\CarbonImmutable as Date;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BreakOvertimeReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;
    private GenericUser $actor;
    private BreakOvertimeReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-24 16:00:00');
        $this->employee = DB::table('employees')->insertGetId(['employee_code'=>'T1','name'=>'確認','kana'=>'カクニン',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2025-01-01','created_at'=>now(),'updated_at'=>now()]);
        $this->actor = new GenericUser(['id'=>31,'role'=>'ADMIN','name'=>'確認担当']);
        $this->service = app(BreakOvertimeReviewService::class);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function daily(array $overrides = []): int
    {
        return DB::table('attendance_daily')->insertGetId(array_merge(['employee_id'=>$this->employee,
            'target_date'=>'2026-10-22','schedule_name'=>'確認勤務','raw_clock_in_at'=>'2026-10-22 09:58:00',
            'raw_clock_out_at'=>'2026-10-22 18:58:00','clock_in_at'=>'2026-10-22 09:58:00','clock_out_at'=>'2026-10-22 18:58:00',
            'break_minutes'=>58,'work_minutes'=>482,'approval_status'=>'PENDING','close_status'=>'OPEN','updated_at'=>now()], $overrides));
    }

    private function preview(int $id, array $input = []): array
    {
        return $this->service->preview($id, $input, $this->actor);
    }

    public function test_raw_pair_alone_selects_sixty_minutes_at_nine_hours_one_minute(): void
    {
        $id = $this->daily(['raw_clock_in_at' => '2026-10-22 08:55:00', 'raw_clock_out_at' => '2026-10-22 17:55:59',
            'break_minutes' => 45, 'work_minutes' => 495]);
        $under = $this->preview($id);
        $this->assertNull($under['plannedStart']);
        $this->assertNull($under['workMinutes']);
        DB::table('attendance_daily')->where('id', $id)->update(['raw_clock_out_at' => '2026-10-22 17:56:00']);
        $initial = $this->preview($id);
        $this->assertSame(['REGULAR_PUNCH', null, 'RAW', 60, 481, 1], [$initial['defaultBreakBasis'],
            $initial['plannedStart'], $initial['branch'], $initial['breakMinutes'], $initial['workMinutes'], $initial['overtimeMinutes']]);
        $inputs = ['startBasis' => 'RULE', 'breakBasis' => 'REGULAR_PUNCH'];
        $p = $this->preview($id, $inputs);
        $this->assertTrue($p['canApply']);
        $this->service->apply($id, $inputs + ['confirmed' => true, 'token' => $p['token']], $this->actor);
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'clock_in_at' => '2026-10-22 08:55:00',
            'clock_out_at' => '2026-10-22 17:56:00', 'break_minutes' => 60, 'work_minutes' => 481]);
    }

    public function test_regular_day_with_only_staff_standard_start_uses_that_start_for_partial_break(): void
    {
        $id = $this->daily(['raw_clock_in_at' => '2026-10-22 09:26:00', 'raw_clock_out_at' => '2026-10-22 18:16:00',
            'break_minutes' => 45, 'work_minutes' => 485]);
        DB::table('employee_attendance_settings')->insert(['employee_id' => $this->employee, 'standard_clock_in_time' => '09:30:00']);
        $p = $this->preview($id);
        $this->assertSame(['09:30', '18:15', 46, 480, 0], [$p['plannedStart'], $p['additionalBreakStart'],
            $p['breakMinutes'], $p['workMinutes'], $p['overtimeMinutes']]);
    }

    public function test_scheduled_start_uses_recorded_break_and_exact_eight_hours(): void
    {
        $id = $this->daily();
        DB::table('work_type_settings')->insert(['name'=>'確認勤務','start_time'=>'09:00:00','default_break_minutes'=>45]);
        DB::table('attendance_shift_schedules')->insert(['employee_id'=>$this->employee,'target_date'=>'2026-10-22',
            'scheduled_clock_in_time'=>'10:00:00','created_at'=>now(),'updated_at'=>now()]);
        $preview = $this->preview($id);
        $this->assertTrue($preview['eligible']);
        $this->assertFalse($preview['canApply']);
        $this->assertSame('SCHEDULED', $preview['branch']);
        $this->assertSame('日別シフト', $preview['plannedStartSource']);
        $this->assertSame(58, $preview['breakMinutes']);
        $this->assertSame(480, $preview['workMinutes']);
        $this->assertSame(0, $preview['overtimeMinutes']);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'work_minutes'=>482,'break_overtime_review'=>null]);
    }

    public function test_raw_branch_total_and_replay_preserve_original_punches_and_intervals(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 09:50:00','raw_clock_out_at'=>'2026-10-22 19:10:00']);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,'break_start_at'=>'2026-10-22 12:00:00','break_end_at'=>'2026-10-22 12:45:00']);
        $intervals = DB::table('attendance_daily_breaks')->get()->all();
        $input = ['startBasis'=>'RULE','plannedStart'=>'10:00','actualBreakMinutes'=>60];
        $p = $this->preview($id, $input);
        $this->assertSame(['RAW',60,500,20], [$p['branch'],$p['breakMinutes'],$p['workMinutes'],$p['overtimeMinutes']]);
        $input += ['confirmed'=>true,'token'=>$p['token']];
        $first = $this->service->apply($id, $input, $this->actor);
        $n = DB::table('attendance_daily_histories')->count();
        $this->assertSame($first, $this->service->apply($id, $input, $this->actor));
        $this->assertSame($n, DB::table('attendance_daily_histories')->count());
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'raw_clock_in_at'=>'2026-10-22 09:50:00','raw_clock_out_at'=>'2026-10-22 19:10:00',
            'clock_in_at'=>'2026-10-22 09:50:00','work_minutes'=>500,'break_minutes'=>60,'approval_status'=>'PENDING']);
        $this->assertEquals($intervals, DB::table('attendance_daily_breaks')->get()->all());
        $this->assertDatabaseHas('attendance_daily_histories',['actor_id'=>31,'field_key'=>'breakOvertimeReview']);
        $this->assertDatabaseCount('audit_logs',1);
        $this->assertSame('NOT_REQUIRED', app(DailyAttendanceService::class)->detail($id)['approvalStatus']);
        DB::table('attendance_daily')->where('id',$id)->update(['work_minutes'=>499]);
        $this->expectException(ApiException::class);
        $this->service->apply($id, $input, $this->actor);
    }

    public function test_floor_to_minutes_at_the_boundary_and_late_start(): void
    {
        $id = $this->daily(['raw_clock_out_at'=>'2026-10-22 18:58:59']);
        $this->assertSame('SCHEDULED',$this->preview($id,['plannedStart'=>'10:00'])['branch']);
        DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_out_at'=>'2026-10-22 18:59:00']);
        $this->assertSame('RAW',$this->preview($id,['plannedStart'=>'10:00'])['branch']);
        DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_in_at'=>'2026-10-22 10:01:00']);
        $p = $this->preview($id,['plannedStart'=>'10:00']);
        $this->assertSame('2026-10-22T10:01:00+09:00',$p['adoptedStart']);
    }

    public function test_confirmed_early_work_and_actual_short_or_long_break_take_precedence(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 09:45:15']);
        $choices = ['plannedStart'=>'10:00','startBasis'=>'ACTUAL','actualStart'=>'09:45','actualBreakMinutes'=>45];
        $p = $this->preview($id, $choices);
        $this->assertSame('2026-10-22T09:45:15+09:00',$p['adoptedStart']);
        $this->assertSame([507,27,45], [$p['workMinutes'],$p['overtimeMinutes'],$p['breakMinutes']]);
        $this->assertStringContainsString('要確認', $p['message']);
        $long = $this->preview($id,array_replace($choices,['actualBreakMinutes'=>75]));
        $this->assertSame([477,0,75],[$long['workMinutes'],$long['overtimeMinutes'],$long['breakMinutes']]);
        $this->service->apply($id,$choices+['confirmed'=>true,'token'=>$p['token']],$this->actor);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'work_minutes'=>507,'break_minutes'=>45]);
        $this->assertStringContainsString('休憩不足', app(DailyAttendanceService::class)->detail($id)['approvalReviewReason']);
    }

    public function test_night_shift_missing_schedule_and_guards(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 21:40:00','raw_clock_out_at'=>'2026-10-23 07:00:00']);
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$this->employee,'standard_clock_in_time'=>'09:00:00']);
        $missing = $this->preview($id);
        $this->assertTrue($missing['eligible']);
        $this->assertNull($missing['plannedStart']);
        $this->assertFalse($missing['canApply']);
        $known = $this->preview($id,['startBasis'=>'ACTUAL','actualStart'=>'21:50','actualBreakMinutes'=>65]);
        $this->assertTrue($known['canApply']);
        $this->assertNull($known['plannedStart']);
        $this->assertSame([485,5],[$known['workMinutes'],$known['overtimeMinutes']]);
        $midnight = $this->preview($id,['startBasis'=>'ACTUAL','actualStart'=>'00:15','actualStartNextDay'=>true,'actualBreakMinutes'=>45]);
        $this->assertSame('2026-10-23T00:15:00+09:00',$midnight['adoptedStart']);
        $night = $this->preview($id,['plannedStart'=>'22:00','startBasis'=>'RULE','actualBreakMinutes'=>60]);
        $this->assertSame(['RAW',500,20],[$night['branch'],$night['workMinutes'],$night['overtimeMinutes']]);
        DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_out_at'=>null]);
        $this->assertFalse($this->preview($id)['eligible']);
        DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_out_at'=>'2026-10-23 07:00:00','approval_status'=>'APPROVED']);
        $this->assertFalse($this->preview($id)['eligible']);
    }

    public function test_stale_choices_and_changed_day_cannot_write(): void
    {
        $id = $this->daily();
        $choices = ['plannedStart'=>'10:00','startBasis'=>'RULE','actualBreakMinutes'=>58];
        $p = $this->preview($id,$choices);
        foreach ([array_replace($choices,['actualBreakMinutes'=>45]), $choices] as $index => $input) {
            if ($index === 1) { DB::table('attendance_daily')->where('id',$id)->update(['supervisor_comment'=>'更新']); }
            try {
                $this->service->apply($id,$input+['confirmed'=>true,'token'=>$p['token']],$this->actor);
                $this->fail('Outdated preview accepted');
            } catch (ApiException $e) { $this->assertSame('STALE_PREVIEW',$e->errorCode); }
        }
        $this->assertDatabaseCount('attendance_daily_histories',0);
        $this->assertDatabaseHas('attendance_daily',['id'=>$id,'work_minutes'=>482]);
    }

    public function test_a_confirmed_fiscal_year_and_protected_staff_are_excluded(): void
    {
        $id = $this->daily();
        foreach (['970017','970005'] as $code) {
            DB::table('employees')->where('id',$this->employee)->update(['employee_code'=>$code]);
            $this->assertFalse($this->preview($id)['eligible']);
        }
        DB::table('employees')->where('id',$this->employee)->update(['employee_code'=>'T1']);
        foreach ([['is_manually_edited'=>1],['close_status'=>'CLOSED'],['target_date'=>'2026-03-31']] as $state) {
            DB::table('attendance_daily')->where('id',$id)->update($state + ['is_manually_edited'=>0,'close_status'=>'OPEN','target_date'=>'2026-10-22']);
            $this->assertFalse($this->preview($id)['eligible']);
        }
        $this->expectException(ApiException::class);
        $this->service->preview($id,[],new GenericUser(['id'=>31,'role'=>'EMPLOYEE']));
    }

    public function test_exact_work_type_and_manual_override_preserve_conflict_checks(): void
    {
        $id = $this->daily();
        $typeId = DB::table('work_type_settings')->insertGetId(['name'=>'確認勤務','start_time'=>'10:00:00']);
        $this->assertSame('10:00',$this->preview($id)['plannedStart']);
        DB::table('attendance_daily')->where('id',$id)->update(['schedule_name'=>'確認勤務（別）']);
        $this->assertNull($this->preview($id)['plannedStart']);
        DB::table('attendance_daily')->where('id',$id)->update(['work_type_id'=>$typeId]);
        $choices = ['startBasis'=>'RULE','actualBreakMinutes'=>58];
        $p = $this->preview($id,$choices);
        $this->assertSame('10:00',$p['plannedStart']);
        DB::table('work_type_settings')->where('id',$typeId)->update(['start_time'=>'10:05:00']);
        $this->expectException(ApiException::class);
        $this->service->apply($id,$choices+['confirmed'=>true,'token'=>$p['token']],$this->actor);
    }

    public function test_two_employees_get_the_nearest_shift_suggestion_from_the_original_punch(): void
    {
        $id = $this->daily(['raw_clock_out_at' => '2026-10-22 19:30:00']);
        $daily = DB::table('attendance_daily')->where('id', $id);
        $employee = DB::table('employees')->where('id', $this->employee);
        foreach (['970045', '970008'] as $code) {
            $employee->update(['employee_code' => $code]);
            foreach ([
                '07:39:00' => '07:45', '08:22:29' => '07:45', '08:22:30' => '09:00',
                '08:58:00' => '09:00', '09:14:59' => '09:00', '09:15:00' => '09:30',
                '09:30:00' => '09:30', '09:55:00' => '09:30',
            ] as $punch => $expected) {
                $daily->update(['raw_clock_in_at' => '2026-10-22 '.$punch]);
                $p = $this->preview($id);
                $this->assertSame($expected, $p['plannedStart'], "$code at $punch");
                $this->assertSame('原出勤打刻に最も近い予定始業の候補（7:45・9:00・9:30）', $p['plannedStartSource']);
                $this->assertFalse($p['canApply']);
            }
        }
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'clock_in_at' => '2026-10-22 09:58:00',
            'break_minutes' => 58, 'work_minutes' => 482, 'break_overtime_review' => null]);
        $this->assertDatabaseCount('attendance_shift_schedules', 0);
        $this->assertDatabaseCount('attendance_daily_histories', 0);

        $employee->update(['employee_code' => '970046']);
        $this->assertNull($this->preview($id)['plannedStart']);
        $employee->update(['employee_code' => '970045']);
        $daily->update(['raw_clock_in_at' => null]);
        $missing = $this->preview($id);
        $this->assertFalse($missing['eligible']);
        $this->assertNull($missing['plannedStart']);
    }

    public function test_nearest_suggestion_preserves_actual_start_and_yields_to_day_specific_plans(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['employee_code' => '970008']);
        $id = $this->daily(['raw_clock_in_at' => '2026-10-22 08:55:00']);
        $choices = ['startBasis' => 'RULE', 'actualBreakMinutes' => 60];
        $suggested = $this->preview($id, $choices);
        $this->assertSame('09:00', $suggested['plannedStart']);
        $actual = $this->preview($id, ['startBasis' => 'ACTUAL', 'actualStart' => '08:48', 'actualBreakMinutes' => 45]);
        $this->assertSame('2026-10-22T08:48:00+09:00', $actual['adoptedStart']);
        $this->assertTrue($actual['canApply']);

        $namedType = DB::table('work_type_settings')->insertGetId(['name' => '確認勤務', 'start_time' => '08:30:00']);
        $this->assertSame('08:30', $this->preview($id)['plannedStart']);
        DB::table('attendance_shift_schedules')->insert(['employee_id' => $this->employee, 'target_date' => '2026-10-22',
            'work_type_id' => $namedType, 'scheduled_clock_in_time' => '08:15:00']);
        $roster = $this->preview($id);
        $this->assertSame(['08:15', '日別シフト'], [$roster['plannedStart'], $roster['plannedStartSource']]);
        $overridden = $this->preview($id, $choices + ['plannedStart' => '08:40']);
        $this->assertSame(['08:40', '管理者が入力した当日の予定'], [$overridden['plannedStart'], $overridden['plannedStartSource']]);

        $this->expectException(ApiException::class);
        $this->service->apply($id, $choices + ['confirmed' => true, 'token' => $suggested['token']], $this->actor);
    }

    public function test_two_employee_defaults_use_raw_elapsed_minutes_and_keep_actual_input_editable(): void
    {
        $id = $this->daily(['raw_clock_in_at' => '2026-10-22 08:55:07', 'break_minutes' => 45]);
        $daily = DB::table('attendance_daily')->where('id', $id);
        $employee = DB::table('employees')->where('id', $this->employee);
        foreach (['970045', '970008'] as $code) {
            $employee->update(['employee_code' => $code]);
            foreach ([
                '17:55:07' => ['SCHEDULED', '09:00:00', 480, 0],
                '17:56:06' => ['SCHEDULED', '09:00:00', 480, 0],
                '17:56:07' => ['RAW', '08:55:07', 481, 1],
                '18:00:07' => ['RAW', '08:55:07', 485, 5],
            ] as $out => [$branch, $start, $minutes, $overtime]) {
                $daily->update(['raw_clock_out_at' => '2026-10-22 '.$out]);
                $p = $this->preview($id);
                $this->assertSame(['RULE', null, false, $branch === 'RAW' ? 60 : (substr($out, 3, 2) === '55' ? 55 : 56), $branch, $minutes, $overtime],
                    [$p['defaultStartBasis'], $p['defaultBreakMinutes'], $p['canApply'], $p['breakMinutes'],
                     $p['branch'], $p['workMinutes'], $p['overtimeMinutes']]);
                $this->assertSame('2026-10-22T'.$start.'+09:00', $p['adoptedStart']);
            }
            $late = $this->preview($id, ['plannedStart' => '10:00', 'startBasis' => 'ACTUAL',
                'actualStart' => '09:10', 'actualBreakMinutes' => 45]);
            $this->assertSame(['2026-10-22T09:10:00+09:00', 45, 485, 5],
                [$late['adoptedStart'], $late['breakMinutes'], $late['workMinutes'], $late['overtimeMinutes']]);
            $daily->update(['raw_clock_out_at' => '2026-10-22 17:55:07']);
            $lateArrival = $this->preview($id, ['plannedStart' => '08:45']);
            $this->assertSame('2026-10-22T08:55:07+09:00', $lateArrival['adoptedStart']);
        }
        $employee->update(['employee_code' => '970046']);
        $other = $this->preview($id, ['plannedStart' => '09:00']);
        $this->assertSame('RULE', $other['defaultStartBasis']);
        $this->assertNull($other['defaultBreakMinutes']);
        $this->assertSame('SCHEDULED', $other['branch']);
        $this->assertDatabaseCount('attendance_daily_histories', 0);
    }

    public function test_scheduled_end_clips_additional_break_and_keeps_hrmos_intervals_and_actual_override(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 07:55:00', 'raw_clock_out_at'=>'2026-10-22 16:52:00',
            'work_minutes'=>487, 'break_minutes'=>45, 'source_system'=>'HRMOS']);
        $workType = DB::table('work_type_settings')->insertGetId(['name'=>'確認勤務','start_time'=>'08:00:00','end_time'=>'18:15:00']);
        DB::table('attendance_shift_schedules')->insert(['employee_id'=>$this->employee,'target_date'=>'2026-10-22',
            'work_type_id'=>$workType,'scheduled_clock_out_time'=>'16:45:00']);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id'=>$id,'segment_no'=>1,
            'break_start_at'=>'2026-10-22 12:00:00','break_end_at'=>'2026-10-22 12:45:00']);
        $initial = $this->preview($id);
        $this->assertSame(['REGULAR_PUNCH','16:45',7,52,false], [$initial['defaultBreakBasis'], $initial['scheduledEnd'],
            $initial['additionalBreakMinutes'], $initial['breakMinutes'], $initial['canApply']]);
        $usingRule = $this->preview($id, ['startBasis'=>'RULE', 'breakBasis'=>'SCHEDULE_END']);
        $this->assertSame(['SCHEDULED',52,480,0], [$usingRule['branch'], $usingRule['breakMinutes'],
            $usingRule['workMinutes'], $usingRule['overtimeMinutes']]);
        $choices = ['startBasis'=>'ACTUAL', 'actualStart'=>'08:00', 'breakBasis'=>'SCHEDULE_END'];
        $preview = $this->preview($id, $choices);
        $this->assertSame(['SCHEDULED',52,480,0,true], [$preview['branch'], $preview['breakMinutes'],
            $preview['workMinutes'], $preview['overtimeMinutes'], $preview['canApply']]);
        $actual = $this->preview($id,['startBasis'=>'ACTUAL', 'actualStart'=>'07:55','actualBreakMinutes'=>45]);
        $this->assertSame([45,492,12],[$actual['breakMinutes'],$actual['workMinutes'],$actual['overtimeMinutes']]);
        $this->service->apply($id, $choices + ['confirmed'=>true, 'token'=>$preview['token']], $this->actor);
        $detail = app(DailyAttendanceService::class)->detail($id);
        $this->assertSame('管理者指定の休憩52分', $detail['breakReview']['sourceLabel']);
        $this->assertSame('UNCONFIRMED', $detail['breakReview']['actualBreakStatus']);
        $this->assertSame('SCHEDULE_END', $detail['breakOvertimeReview']['ruleKind']);
        $this->assertStringContainsString('予定終業16:45からの追加休憩7分', implode(' ', $detail['adoptionReasons']));
        $this->assertDatabaseHas('attendance_daily_breaks', ['attendance_daily_id'=>$id, 'segment_no'=>1, 'break_end_at'=>'2026-10-22 12:45:00']);
    }

    public function test_regular_shift_uses_the_planned_start_window_in_trial_replay_and_detail(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 09:26:00', 'raw_clock_out_at'=>'2026-10-22 18:16:00',
            'work_minutes'=>484, 'break_minutes'=>46, 'source_system'=>'HRMOS']);
        DB::table('work_type_settings')->insert(['name'=>'確認勤務','start_time'=>'09:30','end_time'=>'18:30','standard_day_minutes'=>480]);
        $choices = ['startBasis'=>'RULE', 'breakBasis'=>'REGULAR_PUNCH'];
        $p = $this->preview($id, $choices);
        $this->assertSame(['REGULAR_PUNCH','REGULAR_BREAK_WINDOW','SCHEDULED','18:30','18:15','PLANNED_START_8H45',1,46,480,0,true],
            [$p['defaultBreakBasis'],$p['ruleKind'],$p['branch'],$p['scheduledEnd'],$p['additionalBreakStart'],$p['additionalBreakStartSource'],
             $p['additionalBreakMinutes'],$p['breakMinutes'],$p['workMinutes'],$p['overtimeMinutes'],$p['canApply']]);
        $this->assertSame('2026-10-22T09:30:00+09:00', $p['adoptedStart']);
        $oldChoices = ['startBasis'=>'RULE', 'breakBasis'=>'SCHEDULE_END'];
        $old = $this->preview($id, $oldChoices);
        $this->assertFalse($old['canApply']);
        try {
            $this->service->apply($id,$oldChoices + ['confirmed'=>true,'token'=>$old['token']],$this->actor);
            $this->fail('Older screen confirmed an obsolete calculation');
        } catch (ApiException $e) { $this->assertSame('STALE_PREVIEW', $e->errorCode); }
        $actual = $this->preview($id,['startBasis'=>'ACTUAL','actualStart'=>'09:26','actualBreakMinutes'=>45]);
        $this->assertSame([45,485,5],[$actual['breakMinutes'],$actual['workMinutes'],$actual['overtimeMinutes']]);
        $input = $choices + ['confirmed'=>true, 'token'=>$p['token']];
        $saved = $this->service->apply($id,$input,$this->actor);
        $this->assertSame($saved,$this->service->apply($id,$input,$this->actor));
        $this->assertDatabaseCount('audit_logs',1);
        $detail = app(DailyAttendanceService::class)->detail($id);
        $this->assertSame('REGULAR_BREAK_WINDOW', $detail['breakOvertimeReview']['ruleKind']);
        $this->assertSame('18:15', $detail['breakOvertimeReview']['additionalBreakStart']);
        $this->assertStringContainsString('18:15を起点に追加休憩1分', implode(' ', $detail['adoptionReasons']));
    }

    public function test_regular_rule_boundary_is_raw_elapsed_time_and_late_arrivals_keep_the_punch(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 09:26:10','break_minutes'=>45]);
        DB::table('work_type_settings')->insert(['name'=>'確認勤務','start_time'=>'09:30','end_time'=>'18:00','standard_day_minutes'=>480]);
        $choices = ['startBasis'=>'RULE', 'breakBasis'=>'REGULAR_PUNCH'];
        foreach ([
            '18:15:00'=>['SCHEDULED',45,480,0,0],
            '18:26:10'=>['SCHEDULED',56,480,0,11],
            '18:27:09'=>['SCHEDULED',57,480,0,12],
            '18:27:10'=>['RAW',60,481,1,null],
            '18:28:10'=>['RAW',60,482,2,null],
        ] as $end=> $expected) {
            DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_out_at'=>'2026-10-22 '.$end]);
            $p = $this->preview($id,$choices);
            $this->assertSame($expected,[$p['branch'],$p['breakMinutes'],$p['workMinutes'],$p['overtimeMinutes'],$p['additionalBreakMinutes']]);
            $this->assertTrue($p['canApply']);
        }
        DB::table('attendance_daily')->where('id',$id)->update(['raw_clock_in_at'=>'2026-10-22 09:31:05']);
        $p = $this->preview($id,$choices);
        $this->assertSame('2026-10-22T09:31:05+09:00',$p['adoptedStart']);
        $this->assertSame([58,479,0],[$p['breakMinutes'],$p['workMinutes'],$p['overtimeMinutes']]);
    }

    public function test_new_automatic_rule_requires_an_ordinary_shift_and_a_supported_break_window(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 14:00:00','raw_clock_out_at'=>'2026-10-22 23:00:00','break_minutes'=>45]);
        DB::table('work_type_settings')->insert(['name'=>'確認勤務','start_time'=>'14:00','end_time'=>'23:00','standard_day_minutes'=>480]);
        $choices = ['startBasis'=>'RULE','breakBasis'=>'REGULAR_PUNCH'];
        $trial = $this->preview($id,$choices);
        $this->assertFalse($trial['canApply']);
        $this->assertStringContainsString('実際の始業・休憩時間', $trial['message']);
        DB::table('work_type_settings')->where('name','確認勤務')->update(['standard_day_minutes'=>360]);
        $this->expectException(ApiException::class);
        $this->preview($id,$choices);
    }

    public function test_identified_day_shift_can_offer_automatic_calculation_before_a_start_is_given(): void
    {
        $id = $this->daily(['schedule_name'=>null]);
        $typeId = DB::table('work_type_settings')->insertGetId(['name'=>'検証用の日勤', 'standard_day_minutes'=>480]);
        DB::table('attendance_shift_schedules')->insert(['employee_id'=>$this->employee, 'target_date'=>'2026-10-22', 'work_type_id'=>$typeId]);
        $p = $this->preview($id);
        $this->assertSame(['RULE','REGULAR_PUNCH',null,false],[$p['defaultStartBasis'],$p['defaultBreakBasis'],$p['plannedStart'],$p['canApply']]);
        $choices = ['startBasis'=>'RULE', 'breakBasis'=>'REGULAR_PUNCH'];
        try {
            $this->preview($id,$choices);
            $this->fail('Accepted a regular calculation without a start');
        } catch (ApiException $e) { $this->assertSame('VALIDATION_ERROR',$e->errorCode); }
        $entered = $this->preview($id,$choices + ['plannedStart'=>'10:00']);
        $this->assertSame([true,58,480],[$entered['canApply'],$entered['breakMinutes'],$entered['workMinutes']]);
        foreach (['検証用の公休','検証用の夜勤'] as $name) {
            DB::table('work_type_settings')->where('id',$typeId)->update(['name'=>$name]);
            $initial = $this->preview($id);
            $this->assertArrayNotHasKey('defaultBreakBasis',$initial);
            $actual = $this->preview($id,['startBasis'=>'ACTUAL','actualStart'=>'09:50','actualBreakMinutes'=>45]);
            $this->assertSame(['ACTUAL',true,45],[$actual['branch'],$actual['canApply'],$actual['breakMinutes']]);
        }
    }

    public function test_explicit_raw_rules_keep_seconds_and_require_correct_break_and_nine_hour_threshold(): void
    {
        $id = $this->daily(['raw_clock_in_at'=>'2026-10-22 09:57:14', 'raw_clock_out_at'=>'2026-10-22 18:59:14',
            'work_minutes'=>483, 'break_minutes'=>59]);
        $choices = ['startBasis'=>'ACTUAL', 'actualStart'=>'09:57', 'actualBreakMinutes'=>60, 'breakBasis'=>'RAW_NINE_HOURS'];
        $preview = $this->preview($id, $choices);
        $this->assertSame([60,482,2], [$preview['breakMinutes'], $preview['workMinutes'], $preview['overtimeMinutes']]);
        foreach ([['actualBreakMinutes'=>59], ['actualStart'=>'10:00']] as $invalid) {
            try {
                $this->preview($id, array_replace($choices, $invalid));
                $this->fail('Inconsistent raw rule accepted');
            } catch (ApiException $error) { $this->assertSame('VALIDATION_ERROR', $error->errorCode); }
        }
        DB::table('attendance_daily')->where('id', $id)->update(['raw_clock_out_at'=>'2026-10-22 18:57:14', 'break_minutes'=>45]);
        try {
            $this->preview($id, $choices);
            $this->fail('Nine-hour boundary accepted');
        } catch (ApiException $error) { $this->assertSame('VALIDATION_ERROR', $error->errorCode); }
        $choices['breakBasis'] = 'RAW_FIXED_60';
        $saved = $this->service->applyDayRule($id, $choices + ['confirmed'=>true, 'token'=>$this->preview($id, $choices)['token']], $this->actor);
        $this->assertSame(480, $saved['workMinutes']);
        $this->assertDatabaseHas('attendance_daily', ['id'=>$id,'clock_in_at'=>'2026-10-22 09:57:14']);
    }

    public function test_admin_rule_settlement_is_idempotent_and_retains_its_basis_in_reports_and_history(): void
    {
        $employee = DB::table('employees')->where('id', $this->employee);
        $employee->update(['employee_code' => '970045']);
        $id = $this->daily(['raw_clock_in_at' => '2026-10-22 08:55:00', 'raw_clock_out_at' => '2026-10-22 18:00:00']);
        $choices = ['startBasis' => 'RULE', 'breakBasis' => 'REGULAR_PUNCH'];
        $p = $this->preview($id, $choices);
        $this->assertTrue($p['canApply']);
        $saved = $this->service->applyAdminRule($id, $p['token'], $this->actor);
        $this->assertSame(['dailyId' => $id, 'workMinutes' => 485, 'overtimeMinutes' => 5], $saved);
        $this->assertSame($saved, $this->service->applyAdminRule($id, $p['token'], $this->actor));
        $this->assertDatabaseCount('audit_logs', 1);
        $detail = app(DailyAttendanceService::class)->detail($id);
        $this->assertSame('RULE', $detail['timeSource']);
        $this->assertSame('ADMIN_RULE', $detail['breakOvertimeReview']['confirmationSource']);
        $this->assertSame('UNCONFIRMED', $detail['breakReview']['actualBreakStatus']);
        $this->assertSame('管理者指定の休憩60分', $detail['breakReview']['sourceLabel']);
        $this->assertStringContainsString('管理者指定', implode(' ', $detail['adoptionReasons']));
        $this->assertDatabaseHas('attendance_daily_histories', ['actor_id' => 31, 'field_key' => 'breakOvertimeReview',
            'comment' => '原打刻間が9時間1分以上のため原打刻と休憩60分で計上']);
        $employee->update(['employee_code' => '970046']);
        $this->expectException(ApiException::class);
        $this->service->applyAdminRule($id, $p['token'], $this->actor);
    }
}
