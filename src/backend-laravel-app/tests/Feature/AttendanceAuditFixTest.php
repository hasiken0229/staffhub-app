<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\ConfirmedAttendancePolicy;
use App\Services\Attendance\DailyEditRequestService;
use App\Services\Attendance\DailyEditService;
use App\Services\Attendance\MonthCloseService;
use App\Services\Attendance\PunchService;
use App\Services\EmployeeAdminService;
use App\Services\Reports\AttendancePeriod;
use App\Services\Reports\PayrollReportExportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceAuditFixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function employee(string $date, ?string $to = null, string $type = 'FULL_TIME'): int
    {
        return DB::table('employees')->insertGetId(['employee_code' => 'SYN'.(DB::table('employees')->count() + 1), 'name' => '架空 職員',
            'employment_type' => $type, 'joined_on' => $date, 'retired_on' => $to ?? $date, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function daily(int $employee, string $date, array $values = []): int
    {
        return DB::table('attendance_daily')->insertGetId(array_merge(['employee_id' => $employee, 'target_date' => $date, 'approval_status' => 'PENDING',
            'close_status' => 'OPEN', 'clock_in_at' => $date.' 09:00:00', 'clock_out_at' => $date.' 17:00:00', 'work_minutes' => 480, 'break_minutes' => 0, 'updated_at' => now()], $values));
    }

    private function admin(): GenericUser
    {
        return new GenericUser(['id' => 1, 'role' => 'ADMIN']);
    }

    private function rejected(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected '.$code);
        } catch (ApiException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function test_early_close_and_incomplete_approval_stop_at_month_end_but_a_confirmed_day_off_can_close_after_midnight(): void
    {
        $date = '2026-12-31';
        $employee = $this->employee($date);
        $id = $this->daily($employee, $date, ['clock_in_at' => null, 'clock_out_at' => null, 'work_minutes' => null]);
        $close = app(MonthCloseService::class);
        $approve = fn () => app(AttendanceApprovalService::class)->decideApproval($id, 'APPROVED', null, $this->admin());
        foreach (['07:00:00', '23:59:59'] as $clock) {
            CarbonImmutable::setTestNow($date.' '.$clock);
            $precheck = $close->monthClosePrecheck('2026-12');
            $this->assertSame(1, collect($precheck['blockers'])->firstWhere('code', 'PERIOD_NOT_FINISHED')['count']);
            $this->assertTrue($close->monthCloseStatus(['targetMonth'=>'2026-12'])['items'][0]['dayDetails'][0]['notElapsed']);
            $this->rejected('INCOMPLETE_DAILY', $approve);
            $this->rejected('VALIDATION_ERROR', fn () => $close->updateMonthlyClose('2026-12', 'CLOSED', null, $this->admin()));
        }
        CarbonImmutable::setTestNow('2027-01-01 00:00:00');
        DB::table('attendance_daily')->where('id', $id)->update(['approval_status' => 'APPROVED']);
        $pre = $close->monthClosePrecheck('2026-12');
        $this->assertNotContains('PERIOD_NOT_FINISHED', array_column($pre['blockers'], 'code'));
        $this->assertContains('INCOMPLETE_APPROVED_DAILY', array_column($pre['blockers'], 'code'));
        DB::table('attendance_daily')->where('id', $id)->update(['work_minutes' => 0, 'schedule_name' => '公休']);
        $close->updateMonthlyClose('2026-12', 'CLOSED', null, $this->admin());
        $this->assertTrue($close->monthClosePrecheck('2026-12')['payrollReady']);
    }

    public function test_pending_request_preserves_independent_clock_edit_and_conflicting_same_field_is_returnable(): void
    {
        $date = '2026-10-19';
        $employee = $this->employee($date);
        $id = $this->daily($employee, $date);
        $requests = app(DailyEditRequestService::class);
        $edit = app(DailyEditService::class);
        $payload = ['targetDate' => $date, 'clockInTime' => '09:00', 'clockOutTime' => '17:15'];
        $r = $requests->createDailyEditRequest($employee, $payload);
        $edit->updateDaily($id, ['clockInTime' => '08:30'], $this->admin());
        $preview = $requests->listDailyEditRequestsForAdmin(['status' => 'PENDING'])[0];
        $this->assertNull($preview['reviewConflict']);
        $this->assertSame(525, $preview['proposedWorkMinutes']);
        $this->assertSame('出勤', $preview['reviewChanges'][0]['label']);
        $requests->decideDailyEditRequest($r['id'], 'APPROVED', null, $this->admin());
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'clock_in_at' => $date.' 08:30:00', 'work_minutes' => 525, 'approval_status' => 'APPROVED']);
        $r = $requests->createDailyEditRequest($employee, ['targetDate' => $date, 'clockInTime' => '08:30', 'clockOutTime' => '17:45']);
        $edit->updateDaily($id, ['clockOutTime' => '17:30'], $this->admin());
        $this->rejected('STALE_DAILY_EDIT_REQUEST', fn () => $requests->decideDailyEditRequest($r['id'], 'APPROVED', null, $this->admin()));
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'clock_out_at' => $date.' 17:30:00']);
        $this->assertDatabaseHas('attendance_daily_edit_requests', ['id' => $r['id'], 'status' => 'PENDING']);
        $this->assertNotNull($requests->listDailyEditRequestsForAdmin(['status' => 'PENDING'])[0]['reviewConflict']);
        $requests->decideDailyEditRequest($r['id'], 'RETURNED', '打刻を確認してください', $this->admin());
        $this->assertDatabaseHas('attendance_daily_edit_requests', ['id' => $r['id'], 'status' => 'RETURNED']);
    }

    public function test_partial_request_preserves_original_seconds_existing_breaks_and_note(): void
    {
        $date = '2026-10-19';
        $employee = $this->employee($date);
        $id = $this->daily($employee, $date, ['clock_in_at' => $date.' 09:00:14', 'break_minutes' => 30, 'remark' => '元の備考']);
        DB::table('attendance_daily_breaks')->insert(['attendance_daily_id' => $id, 'segment_no' => 1,
            'break_start_at' => $date.' 12:00:10', 'break_end_at' => $date.' 12:30:10']);
        $requests = app(DailyEditRequestService::class);
        $request = $requests->createDailyEditRequest($employee, ['targetDate' => $date, 'clockOutTime' => '17:15']);
        $requests->decideDailyEditRequest($request['id'], 'APPROVED', null, $this->admin());
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'clock_in_at' => $date.' 09:00:14', 'remark' => '元の備考', 'break_minutes' => 30]);
        $this->assertDatabaseHas('attendance_daily_breaks', ['attendance_daily_id' => $id, 'break_start_at' => $date.' 12:00:10']);
    }

    public function test_legacy_request_without_snapshot_and_newly_created_daily_require_review(): void
    {
        $date = '2026-10-20';
        $employee = $this->employee($date);
        $requests = app(DailyEditRequestService::class);
        $r = $requests->createDailyEditRequest($employee, ['targetDate' => $date, 'clockInTime' => '08:00', 'clockOutTime' => '17:30']);
        $this->daily($employee, $date);
        $this->rejected('STALE_DAILY_EDIT_REQUEST', fn () => $requests->decideDailyEditRequest($r['id'], 'APPROVED', null, $this->admin()));
        DB::table('attendance_daily_edit_requests')->where('id', $r['id'])->update(['daily_baseline' => null]);
        $this->rejected('STALE_DAILY_EDIT_REQUEST', fn () => $requests->decideDailyEditRequest($r['id'], 'APPROVED', null, $this->admin()));
        $requests->decideDailyEditRequest($r['id'], 'RETURNED', '再申請', $this->admin());
    }

    public function test_payroll_preview_and_export_require_the_same_months_for_part_time(): void
    {
        $employee = $this->employee('2026-06-30', '2026-07-01', 'PART_TIME');
        $this->daily($employee, '2026-06-30', ['approval_status' => 'APPROVED', 'close_status' => 'CLOSED']);
        $this->daily($employee, '2026-07-01', ['approval_status' => 'APPROVED', 'close_status' => 'CLOSED']);
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-07', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(MonthCloseService::class);
        $check = $service->monthClosePrecheck('2026-07');
        $this->assertFalse($check['payrollReady']);
        $this->assertStringContainsString('2026-06', $check['payrollBlockers'][0]['message']);
        $this->rejected('PAYROLL_NOT_READY', fn () => app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-07'));
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-06', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($service->monthClosePrecheck('2026-07')['payrollReady']);
        $this->assertSame(960, app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-07')['summary']['totalBaseMinutes']);
    }

    public function test_saturday_policy_treats_sql_decimal_zero_as_zero_and_preserves_partial_leave(): void
    {
        CarbonImmutable::setTestNow('2026-10-09');
        $row = (object) ['target_date' => '2026-09-12', 'paid_leave_unit' => '0.00', 'work_minutes' => 480];
        $this->assertSame(0, \App\Services\Attendance\SaturdayHolidayPolicy::project($row)->work_minutes);
        $row->paid_leave_unit = '0.50';
        $this->assertSame(480, \App\Services\Attendance\SaturdayHolidayPolicy::project($row)->work_minutes);
    }

    public function test_national_holiday_and_registered_shift_remain_distinct_while_the_missing_day_blocks_close(): void
    {
        CarbonImmutable::setTestNow('2026-11-01');
        $date = '2026-09-22';
        $employee = $this->employee($date);
        DB::table('attendance_shift_schedules')->insert(['employee_id' => $employee, 'target_date' => $date, 'scheduled_clock_in_time' => '09:00:00', 'scheduled_clock_out_time' => '17:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $row = app(MonthCloseService::class)->monthCloseStatus(['targetMonth' => '2026-09'])['items'][0];
        $this->assertSame(1, $row['unsubmittedCount']);
        $this->assertSame('休日', $row['dayDetails'][0]['holidayName']);
        $this->assertSame('HOLIDAY_UNREGISTERED', $row['dayDetails'][0]['state']);
        $this->assertSame('09:00:00', $row['dayDetails'][0]['shift']['start']);
        foreach (['2026-09-21' => '敬老の日', '2026-09-23' => '秋分の日', '2026-05-06' => '休日', '2026-09-24' => null] as $day => $name) {
            $code = DB::table('employees')->where('id', $this->employee($day))->value('employee_code');
            $entry = app(MonthCloseService::class)->monthCloseStatus(['targetMonth' => substr($day, 0, 7), 'employeeCode' => $code])['items'][0]['dayDetails'][0];
            $this->assertSame($name, $entry['holidayName']);
            $this->assertSame($name === null ? 'WORKDAY_UNREGISTERED' : 'HOLIDAY_UNREGISTERED', $entry['state']);
        }
    }

    public function test_rules_are_loaded_once_per_month_precheck_as_the_same_rows_are_repeated(): void
    {
        $service = app(MonthCloseService::class);
        $ruleQueries = 0;
        DB::listen(function ($event) use (&$ruleQueries) {
            if (str_contains($event->sql, 'attendance_error_rules')) {
                $ruleQueries++;
            }
        });
        $dayCount = 0;
        foreach ([0, 1, 750, 1500] as $size) {
            while ($dayCount < $size) {
                if ($dayCount % 30 === 0) {
                    $e = $this->employee('2026-03-01', '2026-03-30');
                }
                $date = sprintf('2026-03-%02d', $dayCount % 30 + 1);
                $this->daily($e, $date, ['clock_out_at' => $date.' 18:00:00', 'break_minutes' => 60, 'approval_status' => 'APPROVED']);
                $dayCount++;
            }
            $ruleQueries = 0;
            $check = $service->monthClosePrecheck('2026-03');
            $this->assertSame(1, $ruleQueries);
            $this->assertSame(0, $check['summary']['openErrorCount']);
        }
    }

    public function test_alias_cutoff_is_canonical_for_new_csv_and_preserves_legacy_closed_periods(): void
    {
        $file = UploadedFile::fake()->createWithContent('staff.csv', "社員番号,姓,名,雇用区分,入職日\nSYN-P,架空,保育,パート,2026-01-01\n");
        $employees = app(EmployeeAdminService::class);
        $this->assertSame('PART_TIME', $employees->previewImportFromCsv([], $file)['items'][0]['employmentType']);
        $employees->importFromCsv([], $file);
        $this->assertDatabaseHas('employees', ['employee_code' => 'SYN-P', 'employment_type' => 'PART_TIME']);
        $special = UploadedFile::fake()->createWithContent('special.csv', "社員番号,姓,名,雇用区分,入職日\nSYN-DEP,架空,扶養,扶養内,2026-01-01\nSYN-MGR,架空,管理,管理監督者,2026-01-01\n");
        $this->assertTrue($employees->previewImportFromCsv([], $special)['items'][0]['isDependentWithin']);
        $employees->importFromCsv([], $special);
        $this->assertDatabaseHas('employees', ['employee_code' => 'SYN-DEP', 'employment_type' => 'PART_TIME', 'is_dependent_within' => 1]);
        $this->assertDatabaseHas('employees', ['employee_code' => 'SYN-MGR', 'employment_type' => 'FULL_TIME', 'is_management_supervisor' => 1]);
        [$s,$e] = AttendancePeriod::forEmployee('2027-01', 'パート');
        $this->assertSame(['2026-12-21', '2027-01-20'], [$s->toDateString(), $e->toDateString()]);
        $legacy = $this->employee('2026-08-01', '2026-12-31', 'パート');
        $this->daily($legacy, '2026-08-25', ['approval_status' => 'APPROVED', 'close_status' => 'CLOSED']);
        $this->daily($legacy, '2026-09-24', ['approval_status' => 'APPROVED']);
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-08', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        $this->daily($legacy, '2026-10-22', ['approval_status' => 'APPROVED', 'close_status' => 'CLOSED']);
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-10', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        (require database_path('migrations/2026_11_23_110001_preserve_historical_payroll_cutoffs.php'))->up();
        $lookup = fn ($month) => AttendancePeriod::legacyPeriods($month)->where('lp.employee_id', $legacy)->value('lp.target_month');
        $this->assertSame('2026-08', $lookup('2026-08'));
        $this->assertNull($lookup('2026-09'));
        $history = $lookup('2026-10');
        $this->assertSame('2026-10', $history);
        [$s,$e] = AttendancePeriod::forEmployee('2026-10', 'パート', false, $history);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$s->toDateString(), $e->toDateString()]);
        [$s,$e] = AttendancePeriod::forEmployee('2026-11', 'パート', false, $history);
        $this->assertSame(['2026-10-21', '2026-11-20'], [$s->toDateString(), $e->toDateString()]);
        $warnings = app(MonthCloseService::class)->monthClosePrecheck('2026-11')['payrollWarnings'];
        $this->assertContains('CUTOFF_TRANSITION_REVIEW', array_column($warnings, 'code'));
        $gap = app(MonthCloseService::class)->monthClosePrecheck('2026-10')['payrollWarnings'];
        $this->assertStringContainsString('どちらの給与期間にも含まれません', $gap[0]['message']);
        app(MonthCloseService::class)->updateMonthlyClose('2026-10', 'OPEN', '給与期間を再確認', $this->admin());
        $this->assertNull($lookup('2026-10'));
    }

    public function test_early_departure_on_an_overnight_part_time_shift_keeps_ninety_minutes(): void
    {
        $date = '2026-10-05';
        $employee = $this->employee($date, null, 'PART_TIME');
        foreach (['02:00' => '2026-10-06 02:00', '05:00' => '2026-10-06 05:00', '05:01' => '2026-10-05 05:01', '17:00' => '2026-10-05 17:00'] as $clock => $boundary) {
            $this->assertSame($boundary, ConfirmedAttendancePolicy::scheduleBoundary($date, $clock)->format('Y-m-d H:i'));
        }
        DB::table('attendance_devices')->insert(['device_code' => 'SYN-D', 'name' => '合成端末', 'device_secret_hash' => Hash::make('synthetic'), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_cards')->insert(['employee_id' => $employee, 'card_uid' => 'SYN-C', 'is_active' => 1, 'assigned_at' => $date.' 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendance_shift_schedules')->insert(['employee_id' => $employee, 'target_date' => $date, 'scheduled_clock_in_time' => '22:00:00', 'scheduled_clock_out_time' => '02:00:00', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['22:00', '23:30'] as $i => $time) {
            $r = app(PunchService::class)->punch(['deviceCode' => 'SYN-D', 'deviceSecret' => 'synthetic', 'cardUid' => 'SYN-C', 'occurredAt' => $date.'T'.$time.':00+09:00', 'dedupeKey' => 'qa-key-'.$i]);
            $this->assertSame('SUCCESS', $r['resultType']);
        }
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $employee, 'raw_clock_out_at' => $date.' 23:30:00', 'clock_out_at' => $date.' 23:30:00', 'work_minutes' => 90]);
    }
}
