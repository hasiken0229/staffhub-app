<?php

namespace Tests\Feature;

use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class HarmosGapApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_credits_imported_and_requested_birthdays_and_all_hourly_leave_once(): void
    {
        $employee = $this->employee('970019');
        $base = ['employee_id' => $employee, 'approval_status' => 'APPROVED', 'updated_at' => now()];
        foreach ([
            '2026-10-01' => ['schedule_name' => '誕生日休暇5.5', 'source_system' => 'HARMOS', 'work_minutes' => 0, 'harmos_total_work_minutes' => 330],
            '2026-10-02' => ['request_leave_name' => '誕生日休暇（午後）', 'request_leave_days' => 0.5, 'work_minutes' => 200],
            '2026-10-05' => ['work_minutes' => 240, 'hour_paid_leave_minutes' => 60, 'child_care_leave_minutes' => 90, 'nursing_care_leave_minutes' => 30],
            '2026-10-06' => ['schedule_name' => '誕生日休暇', 'source_system' => 'HARMOS', 'work_minutes' => 425, 'harmos_total_work_minutes' => 905],
            '2026-10-07' => ['request_leave_name' => '誕生日休暇', 'paid_leave_unit' => 1, 'paid_leave_credit_minutes' => 405, 'work_minutes' => 0],
        ] as $date => $values) {
            DB::table('attendance_daily')->insert($base + ['target_date' => $date] + $values);
        }
        $rows = collect(app(DailyAttendanceService::class)->listMonthlyCalendar(['targetMonth' => '2026-10'])['items'])->keyBy('targetDate');
        foreach (['01'=>330, '02'=>403, '05'=>420, '06'=>905, '07'=>405] as $day => $minutes) {
            $this->assertSame($minutes, $rows['2026-10-'.$day]['reportWorkMinutes']);
        }
        $this->assertSame(240, $rows['2026-10-05']['workMinutes']);
    }

    public function test_missing_punch_exceptions_keep_schedules_and_independent_review_reasons(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23));
        $duty = DB::table('work_type_settings')->insertGetId(['name'=>'打刻任意の試験勤務','start_time'=>'08:00','end_time'=>'17:00']);
        foreach (['970012','970005','970019','970026'] as $code) {
            $employee = $this->employee($code);
            DB::table('attendance_shift_schedules')->insert(['employee_id'=>$employee,'target_date'=>'2026-10-19','work_type_id'=>$duty]);
            DB::table('attendance_daily')->insert(['employee_id'=>$employee, 'target_date'=>'2026-10-20','raw_clock_in_at'=>'2026-10-20 08:00:00','updated_at'=>now()]);
            DB::table('attendance_daily')->insert(['employee_id'=>$employee, 'target_date'=>'2026-10-21',
                'raw_clock_in_at'=>'2026-10-21 17:00:00','raw_clock_out_at'=>'2026-10-21 08:00:00','updated_at'=>now()]);
        }
        $review = app(\App\Services\Attendance\DailyApprovalReview::class)->pending();
        $this->assertEqualsCanonicalizing(['970026'], $review->where('target_date','2026-10-19')->pluck('employee_code')->all());
        $this->assertEqualsCanonicalizing(['970026'], $review->where('target_date','2026-10-20')->pluck('employee_code')->all());
        $this->assertCount(4, $review->where('target_date','2026-10-21'));
        $calendar = collect(app(DailyAttendanceService::class)->listMonthlyCalendar(['targetMonth'=>'2026-10','employeeCode'=>'970005'])['items'])->keyBy('targetDate');
        $this->assertSame('打刻任意の試験勤務', $calendar['2026-10-19']['scheduleName']);
        $this->assertSame('NOT_REQUIRED', $calendar['2026-10-19']['approvalStatus']);
    }

    public function test_individual_shift_keeps_daily_end_then_work_type_then_personal_end_for_the_additional_break(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        $employeeId = $this->employee('970019');
        $this->assignPunchCard($employeeId, 'CARD290');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId, 'standard_clock_in_time' => '08:00', 'standard_clock_out_time' => '17:00',
        ]);
        $workTypeId = DB::table('work_type_settings')->insertGetId([
            'name' => '早番試験', 'start_time' => '07:45', 'end_time' => '16:45',
        ]);
        DB::table('attendance_shift_schedules')->insert([
            ['employee_id' => $employeeId, 'target_date' => '2026-10-19', 'work_type_id' => $workTypeId, 'scheduled_clock_out_time' => null],
            ['employee_id' => $employeeId, 'target_date' => '2026-10-20', 'work_type_id' => $workTypeId, 'scheduled_clock_out_time' => '16:50'],
        ]);

        $cases = [
            ['2026-10-19', '16:52', '16:45', 52, 480],
            ['2026-10-20', '16:52', '16:50', 47, 485],
            ['2026-10-21', '17:07', '17:00', 52, 495],
        ];
        foreach ($cases as [$date, $end, $breakStart, $breakMinutes, $workMinutes]) {
            $this->punch('CARD290', $date.' 07:55:00', $date.'-in')->assertOk();
            if ($date === '2026-10-19') {
                // The daily work type supplies both reference times for a single punch.
                $this->assertDatabaseHas('attendance_daily', [
                    'employee_id' => $employeeId, 'target_date' => $date, 'raw_clock_out_at' => null,
                    'clock_out_at' => $date.' 16:45:00', 'break_minutes' => 45,
                ]);
            }
            $this->punch('CARD290', $date.' '.$end.':00', $date.'-out')->assertOk();
            $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->first();
            $this->assertSame([$date.' 08:00:00', $date.' '.$end.':00', $breakMinutes, $workMinutes],
                [$daily->clock_in_at, $daily->clock_out_at, (int) $daily->break_minutes, (int) $daily->work_minutes]);
            $this->assertDatabaseHas('attendance_daily_breaks', [
                'attendance_daily_id' => $daily->id, 'segment_no' => 2,
                'break_start_at' => $date.' '.$breakStart.':00', 'break_end_at' => $date.' '.$end.':00',
            ]);
        }
    }

    public function test_regular_preview_and_saved_punches_apply_the_same_nine_hour_threshold(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26));
        $employeeId = $this->employee('E291');
        $this->assignPunchCard($employeeId, 'CARD291');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId, 'standard_clock_in_time' => '08:00', 'standard_clock_out_time' => '17:00',
        ]);
        $workTypeId = DB::table('work_type_settings')->insertGetId([
            'name' => '通常勤務試験', 'start_time' => '09:30', 'end_time' => '18:30', 'standard_day_minutes' => 480,
        ]);
        $service = app(PunchService::class);
        $cases = [
            // Use the work type's start even when the roster has an earlier end than the 18:15 window.
            ['2026-10-20', null, '18:05', '09:26:00', '18:16:00', '09:30:00', 46, 480],
            // The explicit daily start supersedes the work type.
            ['2026-10-21', '09:00', null, '08:58:00', '17:55:00', '09:00:00', 55, 480],
            ['2026-10-22', null, null, '09:26:00', '18:26:59', '09:30:00', 56, 480],
            ['2026-10-23', null, null, '09:26:00', '18:27:00', '09:26:00', 60, 481],
        ];
        foreach ($cases as [$date, $plannedIn, $plannedOut, $in, $out, $acceptedIn, $break, $work]) {
            DB::table('attendance_shift_schedules')->insert([
                'employee_id' => $employeeId, 'target_date' => $date, 'work_type_id' => $workTypeId,
                'scheduled_clock_in_time' => $plannedIn, 'scheduled_clock_out_time' => $plannedOut,
            ]);
            $preview = $service->previewAutomaticPunches($employeeId, $date, "$date $in", "$date $out");
            $this->assertSame(["$date $acceptedIn", "$date $out", $break, $work],
                [$preview['clockInAt'], $preview['clockOutAt'], $preview['breakMinutes'], $preview['workMinutes']]);
            $this->punch('CARD291', "$date $in", $date.'-threshold-in')->assertOk();
            $this->punch('CARD291', "$date $out", $date.'-threshold-out')->assertOk();
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id' => $employeeId, 'target_date' => $date,
                'raw_clock_in_at' => "$date $in", 'raw_clock_out_at' => "$date $out",
                'clock_in_at' => $preview['clockInAt'], 'clock_out_at' => $preview['clockOutAt'],
                'break_minutes' => $preview['breakMinutes'], 'work_minutes' => $preview['workMinutes'],
            ]);
            $dailyId = DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date)->value('id');
            $detail = app(DailyAttendanceService::class)->detail($dailyId);
            $reason = implode(' ', $detail['adoptionReasons']);
            $this->assertStringContainsString($work > 480 ? '原打刻の始業・退勤と休憩60分' : 'から追加休憩', $reason);
            $this->assertStringNotContainsString('予定まで切り上げ', $reason);
        }
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'break_start_at' => '2026-10-20 18:15:00', 'break_end_at' => '2026-10-20 18:16:00',
        ]);
        $recorded = $service->previewAutomaticPunches($employeeId, $date, "$date $in", "$date $out", 35);
        $this->assertSame(["$date $in", 35, 506], [$recorded['clockInAt'], $recorded['breakMinutes'], $recorded['workMinutes']]);
    }

    public function test_individual_review_days_preserve_their_rule_and_late_punches_cannot_rebuild_a_confirmed_day(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        $employeeId = $this->employee('E292');
        $this->assignPunchCard($employeeId, 'CARD292');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId, 'standard_clock_in_time' => '09:30', 'standard_clock_out_time' => '18:30',
        ]);
        foreach (['2026-10-21' => '宿直応援', '2026-10-22' => '夜勤当番'] as $date => $label) {
            $type = DB::table('work_type_settings')->insertGetId([
                'name' => $label, 'start_time' => '09:30', 'end_time' => '18:30', 'standard_day_minutes' => 480,
            ]);
            if ($date === '2026-10-21') {
                DB::table('attendance_shift_schedules')->insert(['employee_id' => $employeeId, 'target_date' => $date, 'work_type_id' => $type]);
            }
            $this->punch('CARD292', "$date 09:26:00", $date.'-in')->assertOk();
            $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)->where('target_date', $date);
            if ($date === '2026-10-22') {
                $daily->update(['work_type_id' => $type, 'schedule_name' => $label]);
            }
            $preview = app(PunchService::class)->previewAutomaticPunches($employeeId, $date, "$date 09:26:00", "$date 18:27:00");
            $this->assertSame(["$date 09:30:00", 45, 492], [$preview['clockInAt'], $preview['breakMinutes'], $preview['workMinutes']]);
            $this->punch('CARD292', "$date 18:27:00", $date.'-out')->assertOk();
            $saved = $daily->first();
            $this->assertSame([$label, "$date 09:30:00", 45, 492], [$saved->schedule_name, $saved->clock_in_at, (int) $saved->break_minutes, (int) $saved->work_minutes]);
            $detail = app(DailyAttendanceService::class)->detail($saved->id);
            $this->assertStringNotContainsString('原打刻の始業・退勤と休憩60分', implode(' ', $detail['adoptionReasons']));
        }

        // Older records may carry the confirmation receipt without the newer edit flag.
        $daily->update(['break_overtime_review' => json_encode(['confirmationSource' => 'ACTUAL']), 'is_manually_edited' => false]);
        $before = (array) $daily->first();
        $this->punch('CARD292', "$date 18:40:00", 'confirmed-extra')->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->assertSame($before, (array) $daily->first());
        $this->assertDatabaseHas('attendance_events', ['dedupe_key' => 'confirmed-extra', 'rejection_reason' => 'CONFIRMED_DAY_PUNCH']);
    }

    public function test_complete_punches_on_three_shift_days_use_day_specific_plans_or_the_nearest_candidate(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $type = DB::table('work_type_settings')->insertGetId([
            'name' => '当日早番', 'start_time' => '08:30', 'standard_day_minutes' => 480,
        ]);
        foreach (['970045','970008'] as $code) {
            $employee = $this->employee($code);
            $this->assignPunchCard($employee, 'three-'.$code);
            DB::table('employee_attendance_settings')->insert(['employee_id' => $employee, 'standard_clock_in_time' => '07:45:00']);
            $cases = [
                ['2026-11-09','08:55:00','17:55:59','09:00:00',55,480],
                ['2026-11-10','08:55:00','17:56:00','08:55:00',60,481],
                ['2026-11-11','08:55:00','18:00:00','08:55:00',60,485],
                ['2026-11-12','08:55:00','12:10:00','09:00:00',10,180],
                ['2026-11-13','08:27:00','17:27:00','08:30:00',57,480],
            ];
            foreach ($cases as [$date,$in,$out,$start,$rest,$work]) {
                $this->punch('three-'.$code, "$date $in", "$date-$code-in")->assertOk();
                if ($date === '2026-11-13') {
                    DB::table('attendance_daily')->where('employee_id',$employee)->where('target_date',$date)
                        ->update(['work_type_id'=>$type,'schedule_name'=>'当日早番']);
                }
                $this->punch('three-'.$code, "$date $out", "$date-$code-out")->assertOk();
                $daily = DB::table('attendance_daily')->where('employee_id',$employee)->where('target_date',$date)->first();
                $this->assertSame(["$date $start", "$date $out", $rest, $work],
                    [$daily->clock_in_at, $daily->clock_out_at, (int) $daily->break_minutes, (int) $daily->work_minutes], "$code $date");
                $detail = app(DailyAttendanceService::class)->detail($daily->id);
                $this->assertSame(max(0, $work - 480), $detail['overtimeMinutes']);
                $this->assertStringContainsString($work > 480 ? '原打刻の始業・退勤と休憩60分' : '個別勤務ルールの休憩',
                    implode(' ', $detail['adoptionReasons']));
            }
        }
    }

    public function test_regular_clock_out_uses_raw_pair_without_a_plan_and_day_type_precedes_personal_default(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $employee = $this->employee('E236');
        $this->assignPunchCard($employee, 'any-plan');
        $type = DB::table('work_type_settings')->insertGetId(['name'=>'日別通常','start_time'=>'09:30','standard_day_minutes'=>480]);
        $cases = [
            ['2026-11-09','08:55:00','17:56:00','08:55:00',60,481],
            ['2026-11-10','09:26:00','18:16:00','09:30:00',46,480],
            ['2026-11-11','09:26:00','18:16:00','09:30:00',46,480],
        ];
        foreach ($cases as [$date,$in,$out,$start,$rest,$work]) {
            if ($date === '2026-11-10') {
                DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee,'standard_clock_in_time'=>'10:00']);
            }
            $this->punch('any-plan', "$date $in", "$date-in")->assertOk();
            if ($date !== '2026-11-09') {
                DB::table('attendance_daily')->where('employee_id',$employee)->where('target_date',$date)
                    ->update(['work_type_id'=>$date === '2026-11-10' ? $type : null, 'schedule_name'=>'日別通常']);
            }
            $this->punch('any-plan', "$date $out", "$date-out")->assertOk();
            $daily=DB::table('attendance_daily')->where('employee_id',$employee)->where('target_date',$date)->first();
            $this->assertSame(["$date $start","$date $out",$rest,$work],[$daily->clock_in_at,$daily->clock_out_at,(int)$daily->break_minutes,(int)$daily->work_minutes]);
            $detail = app(DailyAttendanceService::class)->detail($daily->id);
            $this->assertSame(max(0, $work - 480), $detail['overtimeMinutes']);
            $this->assertStringContainsString($work > 480 ? '原打刻の始業・退勤と休憩60分' : '18:15から追加休憩1分', implode(' ', $detail['adoptionReasons']));
        }
    }

    public function test_early_start_exception_keeps_its_short_day_and_applies_sixty_to_a_long_day(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $employee = $this->employee('970035');
        $this->assignPunchCard($employee, 'early-160');
        DB::table('employee_attendance_settings')->insert(['employee_id'=>$employee,'standard_clock_in_time'=>'09:00','standard_clock_out_time'=>'18:00']);
        foreach ([
            ['2026-11-09','17:55:59',45,495],
            ['2026-11-10','17:56:00',60,481],
        ] as [$date,$out,$rest,$work]) {
            $this->punch('early-160', "$date 08:55:00", "$date-in")->assertOk();
            $this->punch('early-160', "$date $out", "$date-out")->assertOk();
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id'=>$employee,'target_date'=>$date,'clock_in_at'=>"$date 08:55:00",'clock_out_at'=>"$date $out",
                'break_minutes'=>$rest,'work_minutes'=>$work,
            ]);
        }

        $date = '2026-11-11';
        $this->punch('early-160', "$date 08:55:00", "$date-in")->assertOk();
        DB::table('attendance_daily')->where('employee_id',$employee)->where('target_date',$date)->update(['hour_paid_leave_minutes'=>60]);
        $this->punch('early-160', "$date 17:56:00", "$date-out")->assertOk();
        $this->assertDatabaseHas('attendance_daily', ['employee_id'=>$employee,'target_date'=>$date,'break_minutes'=>45,'work_minutes'=>496,'hour_paid_leave_minutes'=>60]);
    }

    public function test_late_device_event_does_not_rebuild_an_imported_historical_punch_pair(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20));
        $employee = $this->employee('E237');
        $this->assignPunchCard($employee, 'imported-original');
        $date = '2026-11-16';
        $id = DB::table('attendance_daily')->insertGetId([
            'employee_id'=>$employee, 'target_date'=>$date, 'source_system'=>'HARMOS',
            'raw_clock_in_at'=>"$date 08:55:00", 'raw_clock_out_at'=>"$date 18:00:00",
            'clock_in_at'=>"$date 09:00:00", 'clock_out_at'=>"$date 18:00:00",
            'break_minutes'=>60, 'work_minutes'=>480, 'remark'=>'出勤時刻は業務記録で再確認', 'updated_at'=>now(),
        ]);
        $query = DB::table('attendance_daily')->where('id',$id);
        $before = (array) $query->first();
        foreach ([1,2] as $attempt) {
            $this->punch('imported-original', "$date 18:09:00", 'imported-extra')
                ->assertOk()->assertJsonPath('data.resultType','WARNING');
        }
        $this->assertSame($before,(array) $query->first());
        $this->assertDatabaseHas('attendance_events',['employee_id'=>$employee,'dedupe_key'=>'imported-extra',
            'occurred_at'=>"$date 18:09:00",'rejection_reason'=>'IMPORTED_DAY_PUNCH','receive_status'=>'REJECTED']);
        $this->assertSame(1,DB::table('attendance_events')->where('dedupe_key','imported-extra')->count());
        $this->assertTrue(app(\App\Services\Attendance\DailyApprovalReview::class)->pending([])->contains('id',$id));
        $query->update(['raw_clock_out_at'=>null, 'source_import_type'=>'HARMOS_SINGLE_PUNCH_RECONCILIATION']);
        $reconciled=(array)$query->first();
        $this->punch('imported-original', "$date 18:15:00", 'reconciled-extra')->assertOk()->assertJsonPath('data.resultType','WARNING');
        $this->assertSame($reconciled,(array) $query->first());
        $this->assertDatabaseHas('attendance_events',['dedupe_key'=>'reconciled-extra','rejection_reason'=>'IMPORTED_DAY_PUNCH']);
    }

    public function test_single_punch_uses_the_observed_side_and_the_scheduled_shift_break(): void
    {
        $this->travelTo(now()->setDate(2026,11,24));
        $service=app(PunchService::class);
        foreach (['970008','E296'] as $code) {
            $employee=$this->employee($code);
            DB::table('attendance_shift_schedules')->insert([
                'employee_id'=>$employee,'target_date'=>'2026-11-17','scheduled_clock_in_time'=>'07:45','scheduled_clock_out_time'=>'16:30',
            ]);
            $date='2026-11-17';
            $morning=$service->previewAutomaticPunches($employee,$date,"$date 07:37:56",null);
            $expected=[45,480];
            $this->assertSame(['CLOCK_OUT',"$date 07:45:00","$date 16:30:00",...$expected],[$morning['inferredSide'],$morning['clockInAt'],$morning['clockOutAt'],$morning['breakMinutes'],$morning['workMinutes']]);
            $evening=$service->previewAutomaticPunches($employee,$date,"$date 16:38:00",null);
            $this->assertSame(['CLOCK_IN',"$date 07:45:00","$date 16:38:00"],[$evening['inferredSide'],$evening['clockInAt'],$evening['clockOutAt']]);
            $this->assertSame(53,$evening['breakMinutes']);
            $actual=$service->previewAutomaticPunches($employee,$date,"$date 16:38:00",null,50);
            $this->assertSame(50,$actual['breakMinutes']);
        }
    }

    public function test_punch_rebuild_adds_post_schedule_break_up_to_sixty_minutes(): void
    {
        $employeeId = $this->employee('E090');
        $this->assignPunchCard($employeeId, 'CARD090');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId,
            'standard_clock_in_time' => '09:00',
            'standard_clock_out_time' => '17:45',
            'include_before_start' => 0,
            'include_after_end' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch('CARD090', '2026-04-20 08:55:00', 'round-in')->assertOk();
        $this->punch('CARD090', '2026-04-20 17:50:00', 'round-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-20',
            'raw_clock_in_at' => '2026-04-20 08:55:00',
            'raw_clock_out_at' => '2026-04-20 17:50:00',
            'clock_in_at' => '2026-04-20 09:00:00',
            'clock_out_at' => '2026-04-20 17:50:00',
            'break_minutes' => 50,
            'work_minutes' => 480,
        ]);
        $dailyId = (int) DB::table('attendance_daily')->where('employee_id', $employeeId)->value('id');
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 1,
            'break_start_at' => '2026-04-20 13:00:00',
            'break_end_at' => '2026-04-20 13:45:00',
        ]);
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 2,
            'break_start_at' => '2026-04-20 17:45:00',
            'break_end_at' => '2026-04-20 17:50:00',
        ]);
    }

    public function test_punch_rebuild_increases_break_to_keep_effective_work_at_eight_hours(): void
    {
        $employeeId = $this->employee('E093');
        $this->assignPunchCard($employeeId, 'CARD093');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId,
            'standard_clock_in_time' => '09:00',
            'standard_clock_out_time' => '17:45',
            'include_before_start' => 0,
            'include_after_end' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch('CARD093', '2026-04-23 08:55:00', 'cap-in')->assertOk();
        $this->punch('CARD093', '2026-04-23 18:00:00', 'cap-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-23',
            'clock_in_at' => '2026-04-23 09:00:00',
            'clock_out_at' => '2026-04-23 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
        ]);
        $dailyId = (int) DB::table('attendance_daily')->where('employee_id', $employeeId)->value('id');
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 2,
            'break_start_at' => '2026-04-23 17:45:00',
            'break_end_at' => '2026-04-23 18:00:00',
        ]);
    }

    public function test_punch_rebuild_caps_break_at_sixty_minutes_and_counts_extra_as_overtime(): void
    {
        $employeeId = $this->employee('E094');
        $this->assignPunchCard($employeeId, 'CARD094');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId,
            'standard_clock_in_time' => '09:00',
            'standard_clock_out_time' => '17:45',
            'include_before_start' => 0,
            'include_after_end' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch('CARD094', '2026-04-24 08:55:00', 'ot-in')->assertOk();
        $this->punch('CARD094', '2026-04-24 18:10:00', 'ot-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-24',
            'clock_in_at' => '2026-04-24 09:00:00',
            'clock_out_at' => '2026-04-24 18:10:00',
            'break_minutes' => 60,
            'work_minutes' => 490,
        ]);
        $dailyId = (int) DB::table('attendance_daily')->where('employee_id', $employeeId)->value('id');
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 2,
            'break_start_at' => '2026-04-24 17:55:00',
            'break_end_at' => '2026-04-24 18:10:00',
        ]);
    }

    public function test_punch_rebuild_increases_break_without_schedule_to_keep_effective_work_at_eight_hours(): void
    {
        $employeeId = $this->employee('E089');
        $this->assignPunchCard($employeeId, 'CARD089');

        $this->punch('CARD089', '2026-04-19 09:26:00', 'no-schedule-in')->assertOk();
        $this->punch('CARD089', '2026-04-19 18:22:00', 'no-schedule-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-19',
            'clock_in_at' => '2026-04-19 09:26:00',
            'clock_out_at' => '2026-04-19 18:22:00',
            'break_minutes' => 56,
            'work_minutes' => 480,
        ]);
        $dailyId = (int) DB::table('attendance_daily')->where('employee_id', $employeeId)->value('id');
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 2,
            'break_start_at' => '2026-04-19 18:11:00',
            'break_end_at' => '2026-04-19 18:22:00',
        ]);
    }

    public function test_punch_rebuild_uses_only_required_extra_break_after_scheduled_clock_out(): void
    {
        $employeeId = $this->employee('E088');
        $this->assignPunchCard($employeeId, 'CARD088');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId,
            'standard_clock_in_time' => '09:00',
            'standard_clock_out_time' => '18:00',
            'include_before_start' => 1,
            'include_after_end' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch('CARD088', '2026-04-18 09:26:00', 'scheduled-in')->assertOk();
        $this->punch('CARD088', '2026-04-18 18:22:00', 'scheduled-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-18',
            'clock_in_at' => '2026-04-18 09:26:00',
            'clock_out_at' => '2026-04-18 18:22:00',
            'break_minutes' => 56,
            'work_minutes' => 480,
        ]);
        $dailyId = (int) DB::table('attendance_daily')->where('employee_id', $employeeId)->value('id');
        $this->assertDatabaseHas('attendance_daily_breaks', [
            'attendance_daily_id' => $dailyId,
            'segment_no' => 2,
            'break_start_at' => '2026-04-18 18:11:00',
            'break_end_at' => '2026-04-18 18:22:00',
        ]);
    }

    public function test_employee_setting_controls_whether_before_schedule_punch_is_included(): void
    {
        $roundEmployeeId = $this->employee('E095');
        $includeEmployeeId = $this->employee('E096');
        $this->assignPunchCard($roundEmployeeId, 'CARD095');
        $this->assignPunchCard($includeEmployeeId, 'CARD096');
        foreach ([[$roundEmployeeId, 0], [$includeEmployeeId, 1]] as [$employeeId, $includeBeforeStart]) {
            DB::table('employee_attendance_settings')->insert([
                'employee_id' => $employeeId,
                'standard_clock_in_time' => '09:00',
                'standard_clock_out_time' => '17:45',
                'include_before_start' => $includeBeforeStart,
                'include_after_end' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->punch('CARD095', '2026-04-25 08:55:00', 'round-before-in')->assertOk();
        $this->punch('CARD095', '2026-04-25 17:45:00', 'round-before-out')->assertOk();
        $this->punch('CARD096', '2026-04-25 08:55:00', 'include-before-in')->assertOk();
        $this->punch('CARD096', '2026-04-25 17:45:00', 'include-before-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $roundEmployeeId,
            'target_date' => '2026-04-25',
            'clock_in_at' => '2026-04-25 09:00:00',
            'work_minutes' => 480,
        ]);
        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $includeEmployeeId,
            'target_date' => '2026-04-25',
            'clock_in_at' => '2026-04-25 08:55:00',
            'break_minutes' => 50,
            'work_minutes' => 480,
        ]);
    }

    public function test_shift_schedule_overrides_employee_setting_for_corrected_time_and_work_type(): void
    {
        $employeeId = $this->employee('E091');
        $this->assignPunchCard($employeeId, 'CARD091');
        $workTypeId = (int) DB::table('work_type_settings')->where('name', '通常勤務')->value('id');
        DB::table('employee_attendance_settings')->insert([
            'employee_id' => $employeeId,
            'standard_clock_in_time' => '09:00',
            'standard_clock_out_time' => '18:00',
            'include_before_start' => 0,
            'include_after_end' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_shift_schedules')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-21',
            'work_type_id' => $workTypeId,
            'scheduled_clock_in_time' => '10:00',
            'scheduled_clock_out_time' => '17:00',
            'note' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch('CARD091', '2026-04-21 08:55:00', 'shift-in')->assertOk();
        $this->punch('CARD091', '2026-04-21 18:10:00', 'shift-out')->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-21',
            'work_type_id' => $workTypeId,
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-21 10:00:00',
            'clock_out_at' => '2026-04-21 18:10:00',
            'break_minutes' => 45,
            'work_minutes' => 445,
        ]);
    }

    public function test_manual_daily_edit_is_preserved_and_new_punches_are_saved_for_review(): void
    {
        $employeeId = $this->employee('E092');
        $this->assignPunchCard($employeeId, 'CARD092');
        $dailyId = (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-22',
            'schedule_name' => '通常勤務',
            'raw_clock_in_at' => '2026-04-22 08:30:00',
            'raw_clock_out_at' => '2026-04-22 18:30:00',
            'clock_in_at' => '2026-04-22 09:15:00',
            'clock_out_at' => '2026-04-22 17:45:00',
            'break_minutes' => 30,
            'work_minutes' => 480,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'APPROVED',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'is_manually_edited' => 1,
            'updated_at' => now(),
        ]);

        $this->punch('CARD092', '2026-04-22 08:10:00', 'manual-in')->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->punch('CARD092', '2026-04-22 18:50:00', 'manual-out')->assertOk()->assertJsonPath('data.resultType', 'WARNING');

        $this->assertDatabaseHas('attendance_daily', [
            'id' => $dailyId,
            'raw_clock_in_at' => '2026-04-22 08:30:00',
            'raw_clock_out_at' => '2026-04-22 18:30:00',
            'clock_in_at' => '2026-04-22 09:15:00',
            'clock_out_at' => '2026-04-22 17:45:00',
            'break_minutes' => 30,
            'work_minutes' => 480,
            'approval_status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('attendance_events', ['dedupe_key' => 'manual-in', 'occurred_at' => '2026-04-22 08:10:00', 'event_type' => null, 'rejection_reason' => 'CONFIRMED_DAY_PUNCH']);
        $this->assertDatabaseHas('attendance_events', ['dedupe_key' => 'manual-out', 'occurred_at' => '2026-04-22 18:50:00', 'event_type' => null, 'rejection_reason' => 'CONFIRMED_DAY_PUNCH']);
    }

    public function test_admin_can_edit_daily_attendance_without_changing_raw_punches_and_history_is_recorded(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E100');
        $workTypeId = (int) DB::table('work_type_settings')->where('name', '通常勤務')->value('id');
        $dailyId = (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-10',
            'schedule_name' => '通常勤務',
            'work_type_id' => $workTypeId,
            'raw_clock_in_at' => '2026-04-10 09:00:00',
            'raw_clock_out_at' => '2026-04-10 18:00:00',
            'clock_in_at' => '2026-04-10 09:00:00',
            'clock_out_at' => '2026-04-10 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'is_manually_edited' => 0,
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/admin/attendance/daily/' . $dailyId, [
                'workTypeId' => $workTypeId,
                'clockInTime' => '10:00',
                'clockOutTime' => '18:00',
                'breaks' => [
                    ['startTime' => '12:00', 'endTime' => '13:00'],
                    ['startTime' => '15:00', 'endTime' => '15:30'],
                ],
                'remark' => '打刻漏れ補正',
                'supervisorComment' => '確認済み',
                'approvalStatus' => 'APPROVED',
                'approvalComment' => 'OK',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.rawClockInAt', '2026-04-10T09:00:00+09:00')
            ->assertJsonPath('data.clockInAt', '2026-04-10T10:00:00+09:00')
            ->assertJsonPath('data.breakMinutes', 90)
            ->assertJsonPath('data.workMinutes', 390)
            ->assertJsonPath('data.isManuallyEdited', true);

        $this->assertDatabaseHas('attendance_daily_histories', [
            'attendance_daily_id' => $dailyId,
            'action_type' => 'MANUAL_EDITED',
            'field_key' => 'clockInAt',
        ]);
    }

    public function test_attendance_error_and_month_close_status_reports_are_returned(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E101');
        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-02',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-02 09:00:00',
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.0.errorCode', 'MISSING_PUNCH');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close-status?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.0.pendingCount', 1);
    }

    public function test_time_paid_leave_request_approval_updates_daily_and_paid_leave_ledger(): void
    {
        $adminToken = $this->adminToken();
        $employeeId = $this->employee('E102');
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e102@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 1,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e102@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $requestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-12',
                'startTime' => '09:00',
                'endTime' => '11:00',
                'quantityMinutes' => 120,
                'reason' => '通院',
            ])
            ->assertOk()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/work-procedures/' . $requestId . '/approve', ['comment' => 'OK'])
            ->assertOk();

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-12',
            'hour_paid_leave_minutes' => 120,
        ]);
        $this->assertDatabaseHas('paid_leave_ledger', [
            'employee_id' => $employeeId,
            'entry_type' => 'USE',
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => $requestId,
            'days_delta' => -0.25,
        ]);
    }

    public function test_employee_daily_edit_request_is_approved_and_reflected_to_daily_attendance(): void
    {
        $adminToken = $this->adminToken();
        $employeeId = $this->employee('E103');
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e103@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e103@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $requestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/attendance/daily-edit-requests', [
                'targetDate' => '2026-04-14',
                'clockInTime' => '09:15',
                'clockOutTime' => '18:15',
                'breaks' => [
                    ['startTime' => '12:00', 'endTime' => '13:00'],
                ],
                'employeeComment' => '打刻修正をお願いします',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/attendance/daily-edit-requests?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId);

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/attendance/daily-edit-requests/' . $requestId . '/approve', ['comment' => '承認'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-14',
            'clock_in_at' => '2026-04-14 09:15:00',
            'clock_out_at' => '2026-04-14 18:15:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'approval_status' => 'APPROVED',
            'is_manually_edited' => 1,
        ]);
    }

    public function test_month_close_precheck_blocks_pending_items_and_daily_edit_auto_resolves_cleared_errors(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E104');
        $dailyId = (int) DB::table('attendance_daily')->insertGetId([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-15 09:00:00',
            'clock_out_at' => null,
            'break_minutes' => 0,
            'work_minutes' => null,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'PENDING',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'updated_at' => now(),
        ]);
        DB::table('attendance_error_resolutions')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'error_code' => 'MISSING_PUNCH',
            'status' => 'OPEN',
            'comment' => null,
            'handled_by' => null,
            'handled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.canClose', false);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/admin/attendance/daily/' . $dailyId, [
                'clockInTime' => '09:00',
                'clockOutTime' => '18:00',
                'breaks' => [['startTime' => '12:00', 'endTime' => '13:00']],
                'approvalStatus' => 'APPROVED',
                'approvalComment' => 'OK',
            ])
            ->assertOk();

        $this->assertDatabaseHas('attendance_error_resolutions', [
            'employee_id' => $employeeId,
            'target_date' => '2026-04-15',
            'error_code' => 'MISSING_PUNCH',
            'status' => 'RESOLVED',
        ]);
    }

    public function test_month_close_precheck_reports_payroll_readiness_after_close(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E106');
        DB::table('employees')->where('id', $employeeId)->update(['joined_on' => '2026-04-30']);

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-30',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-30 09:00:00',
            'clock_out_at' => '2026-04-30 18:00:00',
            'break_minutes' => 60,
            'work_minutes' => 480,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'APPROVED',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.canClose', true)
            ->assertJsonPath('data.payrollReady', false)
            ->assertJsonPath('data.payrollBlockers.0.code', 'MONTH_NOT_CLOSED')
            ->assertJsonPath('data.summary.monthCloseStatus', 'OPEN');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/month-close', [
                'targetMonth' => '2026-04',
                'status' => 'CLOSED',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.payrollReady', true)
            ->assertJsonCount(0, 'data.payrollBlockers');

        $definitionId = DB::table('payroll_data_definitions')->insertGetId([
            'statement_type' => 'PAYROLL',
            'definition_name' => '給与CSV',
            'template_version' => 1,
            'field_count' => 0,
            'sample_file_name' => null,
            'sample_headers_json' => json_encode([]),
            'is_active' => 1,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payroll_import_batches')->insert([
            'statement_type' => 'PAYROLL',
            'payroll_data_definition_id' => $definitionId,
            'target_year_month' => '2026-04',
            'period_start_on' => '2026-04-01',
            'period_end_on' => '2026-04-30',
            'pay_date' => '2026-05-25',
            'publish_date' => '2026-05-20',
            'source_file_name' => 'payroll.csv',
            'processed_count' => 1,
            'success_count' => 1,
            'error_count' => 0,
            'status' => 'COMPLETED',
            'summary_json' => null,
            'created_by' => null,
            'deleted_at' => null,
            'deleted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/month-close/precheck?targetMonth=2026-04')
            ->assertOk()
            ->assertJsonPath('data.payrollReady', true)
            ->assertJsonPath('data.payrollWarnings.0.code', 'PAYROLL_BATCH_EXISTS')
            ->assertJsonPath('data.summary.payrollBatchCount', 1);
    }

    public function test_hourly_leave_uses_employee_standard_day_minutes_limit(): void
    {
        $employeeId = $this->employee('E105');
        DB::table('employment_type_settings')->updateOrInsert(
            ['code' => 'FULL_TIME'],
            [
                'label' => '常勤',
                'standard_day_minutes' => 240,
                'sort_order' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'e105@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 2,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeToken = $this->postJson('/api/auth/login', [
            'loginId' => 'e105@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-16',
                'startTime' => '09:00',
                'endTime' => '14:00',
                'quantityMinutes' => 300,
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'TIME_LEAVE',
                'timeLeaveType' => 'PAID_HOURLY',
                'targetDate' => '2026-04-16',
                'startTime' => '09:00',
                'endTime' => '11:00',
                'quantityMinutes' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.quantityDays', 0.5);
    }

    public function test_work_procedure_detail_and_attendance_error_rule_history_are_available(): void
    {
        $token = $this->adminToken();
        $employeeId = $this->employee('E107');
        $requestId = (int) DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type_code' => 'PAID',
            'start_date' => '2026-04-20',
            'end_date' => '2026-04-20',
            'day_unit' => 'FULL',
            'half_day_type' => null,
            'quantity_days' => 1,
            'request_category' => 'LEAVE',
            'time_leave_type' => null,
            'target_date' => null,
            'start_time' => null,
            'end_time' => null,
            'quantity_minutes' => null,
            'reason' => '私用',
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'decision_comment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('leave_request_actions')->insert([
            'leave_request_id' => $requestId,
            'action_by' => $employeeId,
            'action_type' => 'APPLIED',
            'comment' => '申請します',
            'acted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/work-procedures/' . $requestId)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.employee.departmentName', '保育')
            ->assertJsonPath('data.actions.0.actionType', 'APPLIED');

        DB::table('attendance_daily')->insert([
            'employee_id' => $employeeId,
            'target_date' => '2026-04-20',
            'schedule_name' => '通常勤務',
            'clock_in_at' => '2026-04-20 09:00:00',
            'clock_out_at' => '2026-04-20 19:10:00',
            'break_minutes' => 50,
            'work_minutes' => 560,
            'late_flag' => 0,
            'early_leave_flag' => 0,
            'absence_flag' => 0,
            'special_leave_flag' => 0,
            'paid_leave_unit' => null,
            'hour_paid_leave_minutes' => 0,
            'child_care_leave_minutes' => 0,
            'nursing_care_leave_minutes' => 0,
            'remark' => null,
            'approval_status' => 'APPROVED',
            'approval_comment' => null,
            'approved_by' => null,
            'approved_at' => null,
            'close_status' => 'OPEN',
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&errorCode=SHORT_BREAK_OVER_8')
            ->assertOk()
            ->assertJsonPath('data.0.errorCode', 'SHORT_BREAK_OVER_8');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/attendance/errors/resolve', [
                'employeeId' => $employeeId,
                'targetDate' => '2026-04-20',
                'errorCode' => 'SHORT_BREAK_OVER_8',
                'status' => 'IGNORED',
                'comment' => '園長確認済み',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'IGNORED');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&handlingStatus=IGNORED')
            ->assertOk()
            ->assertJsonPath('data.0.histories.0.newStatus', 'IGNORED')
            ->assertJsonPath('data.0.histories.0.comment', '園長確認済み');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/system-masters/attendance-error-rules', [
                'code' => 'SHORT_BREAK_OVER_8',
                'name' => '休憩不足（8時間超）',
                'minWorkMinutes' => 480,
                'requiredBreakMinutes' => 45,
                'enabled' => true,
                'sortOrder' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.5.requiredBreakMinutes', 45);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/attendance/errors?fromMonth=2026-04&toMonth=2026-04&errorCode=SHORT_BREAK_OVER_8')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => 'テスト 職員',
            'kana' => 'テスト ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignPunchCard(int $employeeId, string $cardUid): void
    {
        DB::table('attendance_devices')->updateOrInsert(
            ['device_code' => 'TEST_DEVICE'],
            [
                'name' => 'テスト端末',
                'location_name' => '本園',
                'device_secret_hash' => Hash::make('secret'),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('employee_cards')->insert([
            'employee_id' => $employeeId,
            'card_uid' => $cardUid,
            'is_active' => 1,
            'assigned_at' => '2026-04-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function punch(string $cardUid, string $occurredAt, string $dedupeKey)
    {
        return $this->postJson('/api/attendance/punch', [
            'deviceCode' => 'TEST_DEVICE',
            'deviceSecret' => 'secret',
            'cardUid' => $cardUid,
            'occurredAt' => $occurredAt,
            'dedupeKey' => $dedupeKey,
        ]);
    }
}
