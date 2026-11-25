<?php

namespace Tests\Feature;

use App\Services\AttendanceService;
use App\Services\Reports\MonthlyWorksPdfExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveAccountingAndExportTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;
    private string $admin;
    private string $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'Asia/Tokyo'));
        $this->employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QA01', 'name' => "試験\n職員", 'employment_type' => 'FULL_TIME',
            'department_name' => '試験', 'status' => 'ACTIVE', 'joined_on' => '2026-04-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $hash = Hash::make('test-password');
        DB::table('users')->insert(['name'=>'管理者', 'email'=>'admin@example.test', 'password'=>$hash]);
        DB::table('employee_auth')->insert(['employee_id'=>$this->employeeId, 'login_id'=>'staff@example.test', 'password_hash'=>$hash]);
        $this->admin = $this->login('admin', 'ADMIN');
        $this->staff = $this->login('staff', 'EMPLOYEE');
        DB::table('paid_leave_grants')->insert(['employee_id'=>$this->employeeId, 'granted_on'=>'2026-04-01',
            'granted_days'=>10, 'used_days'=>0, 'expires_on'=>'2027-03-31', 'created_at'=>now(), 'updated_at'=>now()]);
    }

    private function login(string $name, string $audience): string
    {
        return $this->postJson('/api/auth/login', ['loginId'=>$name.'@example.test',
            'password'=>'test-password', 'audience'=>$audience])->assertOk()->json('data.accessToken');
    }

    private function apply(array $data)
    {
        return $this->withToken($this->staff)->postJson('/api/leave/requests', $data);
    }

    private function day(string $type, string $start, ?string $end = null): array
    {
        return ['requestCategory'=>'LEAVE', 'leaveTypeCode'=>$type, 'dayUnit'=>'FULL',
            'startDate'=>$start, 'endDate'=>$end ?? $start];
    }

    private function approve(int $id)
    {
        return $this->withToken($this->admin)->postJson('/api/admin/work-procedures/'.$id.'/approve');
    }

    private function cancel(int $id): void
    {
        $this->withToken($this->staff)->postJson('/api/leave/requests/'.$id.'/cancel')->assertOk();
    }

    private function daily(string $date, string $name, array $extras = []): void
    {
        DB::table('attendance_daily')->insert(['employee_id'=>$this->employeeId, 'target_date'=>$date,
            'schedule_name'=>$name, 'approval_status'=>'APPROVED', 'updated_at'=>now(), ...$extras]);
    }

    private function balance(): float
    {
        return (float) $this->withToken($this->staff)->getJson('/api/leave/ledger')->assertOk()->json('data.currentBalance');
    }

    public function test_period_is_confirmed_from_personal_workdays_and_cancel_uses_original_dates(): void
    {
        $this->daily('2026-10-08', '公休');
        $this->daily('2026-10-10', '休日勤務');
        $this->daily('2026-10-11', '公休');
        $dates = ['2026-10-09','2026-10-10','2026-10-12'];
        $this->withToken($this->staff)->getJson('/api/leave/balance?asOf=2026-10-08&through=2026-10-12')
            ->assertOk()->assertJsonPath('data.workdays.dates', $dates)
            ->assertJsonPath('data.workdays.excludedDates', ['2026-10-08', '2026-10-11'])
            ->assertJsonPath('data.workdays.unconfirmedDates', ['2026-10-09', '2026-10-12']);
        $this->apply($this->day('PAID','2026-10-08'))->assertStatus(422);
        $payload = $this->day('PAID','2026-10-08','2026-10-12');
        $this->apply([...$payload,'expectedDates'=>['2026-10-08']])->assertStatus(409);
        $id = $this->apply([...$payload,'expectedDates'=>$dates])->assertOk()->assertJsonPath('data.quantityDays',3)->json('data.id');
        $this->approve($id)->assertOk();
        $this->assertSame(7.0, $this->balance());
        $this->assertDatabaseHas('attendance_daily', ['target_date'=>'2026-10-08','paid_leave_unit'=>null]);
        $this->assertDatabaseHas('attendance_daily', ['target_date'=>'2026-10-10','paid_leave_unit'=>1]);
        DB::table('attendance_daily')->where('target_date','2026-10-11')->update(['schedule_name'=>'通常勤務']);
        $this->cancel($id);
        $this->assertSame(10.0, $this->balance());
        $this->assertDatabaseHas('attendance_daily', ['target_date'=>'2026-10-11','paid_leave_unit'=>null]);
        $pending = $this->apply($this->day('PAID', '2026-10-23'))->assertOk()->json('data.id');
        $this->daily('2026-10-23','公休');
        $this->approve($pending)->assertStatus(409);
        $this->assertDatabaseHas('leave_requests', ['id'=>$pending,'status'=>'PENDING']);
    }

    public function test_custom_leave_freezes_classification_label_and_balance_source(): void
    {
        $master = '/api/admin/system-masters/leave-types';
        $base = ['code'=>'BIRTHDAY','name'=>'誕生日休暇','allowsHalfDay'=>true];
        $this->withToken($this->admin)->postJson($master, $base)->assertStatus(422);
        $this->withToken($this->admin)->postJson($master, [...$base,'attendanceEffect'=>'SPECIAL','balanceSource'=>'NONE'])->assertOk();
        $free = $this->apply($this->day('BIRTHDAY','2026-10-01'))->assertOk()->json('data.id');
        $this->withToken($this->admin)->postJson($master, [...$base,'attendanceEffect'=>'SPECIAL','balanceSource'=>'PAID'])->assertOk();
        $funded = $this->apply($this->day('BIRTHDAY','2026-10-02'))->assertOk()->json('data.id');
        $this->withToken($this->admin)->postJson($master, [...$base,'name'=>'変更後','attendanceEffect'=>'ABSENCE','balanceSource'=>'NONE'])->assertOk();
        $this->approve($free)->assertOk();
        $this->assertSame(10.0, $this->balance());
        $this->approve($funded)->assertOk();
        $this->assertSame(9.0, $this->balance());
        $row = DB::table('attendance_daily')->where('target_date','2026-10-02')->first();
        $this->assertSame('誕生日休暇', $row->request_leave_name);
        $this->assertEquals(1, $row->special_leave_flag);
        $this->assertEquals(0, $row->absence_flag);
        $this->cancel($funded);
        $this->assertSame(10.0, $this->balance());
        $this->assertDatabaseHas('attendance_daily',['target_date'=>'2026-10-02','special_leave_flag'=>0,'request_leave_name'=>null]);
    }

    public function test_half_special_uses_half_in_monthly_pdf_summary_and_daily_and_monthly_csv(): void
    {
        $this->daily('2026-10-02','通常勤務',['remark'=>"上下\n\n\"確認\"\r\n済み"]);
        $half = $this->apply([...$this->day('SPECIAL','2026-10-02'),'dayUnit'=>'HALF','halfDayType'=>'AM'])->assertOk()->json('data.id');
        $this->approve($half)->assertOk();
        $rows = app(AttendanceService::class)->listMonthlyCalendar(['targetMonth'=>'2026-10','employeeId'=>$this->employeeId])['items'];
        $row = collect($rows)->firstWhere('targetDate','2026-10-02');
        $this->assertEquals(0.5, $row['requestLeaveDays']);
        $this->assertSame('MISSING', $row['dayState']);
        $this->assertStringContainsString('午前', $row['workStyleName']);
        $pdf = app(MonthlyWorksPdfExportService::class);
        $summary = (new \ReflectionMethod($pdf, 'buildMonthlyWorksSummary'))->invoke($pdf, $rows);
        $this->assertEquals(0.5, $summary['attendance']['特別休暇']);
        $this->assertEquals(1, $summary['attendance']['打刻漏れ']);
        foreach (['daily-csv?from=2026-10-02&to=2026-10-02'=>'DAILY_ATTENDANCE_CSV', 'monthly-csv?targetMonth=2026-10'=>'MONTHLY_ATTENDANCE_CSV'] as $url=>$kind) {
            $csv = $this->withToken($this->admin)->get('/api/admin/reports/'.$url)->assertOk()->getContent();
            $stream = fopen('php://memory','w+b');
            fwrite($stream, $csv);
            rewind($stream);
            $keys = fgetcsv($stream, escape:'');
            $data = array_combine($keys, fgetcsv($stream, escape:''));
            $this->assertFalse(fgetcsv($stream, escape:''));
            fclose($stream);
            $this->assertSame('0.5', $data['特休日数']);
            if ($kind === 'DAILY_ATTENDANCE_CSV') { $this->assertSame($row['workStyleName'], $data['勤務区分']); }
            $this->assertDatabaseHas('import_histories', ['import_type'=>$kind,'success_count'=>1]);
        }
        $range = '2026-10-02_2026-10-02';
        $this->assertDatabaseHas('import_histories', ['import_type'=>'DAILY_ATTENDANCE_CSV','target_period'=>$range]);
        DB::table('import_histories')->where('import_type','DAILY_ATTENDANCE_CSV')->update(['target_period'=>'2026-10']);
        $this->withToken($this->admin)->getJson('/api/admin/import-history?targetPeriod='.$range)->assertOk()
            ->assertJsonPath('meta.total',1)->assertJsonPath('data.0.targetPeriod',$range);
        $this->withToken($this->admin)->getJson('/api/admin/import-history?targetPeriod=2026-10')->assertOk()->assertJsonPath('meta.total',2);
        $this->assertDatabaseHas('import_histories',['import_type'=>'MONTHLY_ATTENDANCE_CSV','target_period'=>'2026-10']);
        $this->withToken($this->admin)->get('/api/admin/reports/daily-csv?from=2026-01-01&to=2026-01-02')->assertOk();
        $this->assertDatabaseHas('import_histories',['import_type'=>'DAILY_ATTENDANCE_CSV','success_count'=>0]);
        $this->cancel($half);
        $this->assertDatabaseHas('attendance_daily',['target_date'=>'2026-10-02','request_leave_days'=>null,'schedule_name'=>'通常勤務']);
    }

    public function test_old_bookings_are_only_listed_and_rollback_retains_new_accounting_columns(): void
    {
        $this->daily('2026-10-08','公休');
        $legacy = DB::table('leave_requests')->insertGetId(['employee_id'=>$this->employeeId, 'leave_type_code'=>'PAID',
            'start_date'=>'2026-10-08','end_date'=>'2026-10-09','day_unit'=>'FULL','quantity_days'=>2,
            'request_category'=>'LEAVE','status'=>'APPROVED','created_at'=>now(),'updated_at'=>now()]);
        $this->artisan('leave:review-accounting', ['--employee'=>(string) $this->employeeId])
            ->expectsOutputToContain('申請ID '.$legacy)->expectsOutputToContain('確認対象 1 件')->assertSuccessful();
        $this->assertSame(8.0, $this->balance());
        $request = $this->apply($this->day('PAID','2026-10-23'))->assertOk()->json('data.id');
        $original = DB::table('leave_requests')->where('id',$request)->first();
        $migration = require database_path('migrations/2026_11_23_210001_add_leave_accounting_snapshots.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($original, DB::table('leave_requests')->where('id',$request)->first());
    }

    public function test_single_workday_and_short_contract_store_their_original_minutes_and_restore_after_cancel(): void
    {
        $hourly = ['requestCategory'=>'TIME_LEAVE','timeLeaveType'=>'PAID_HOURLY','startTime'=>'09:00'];
        $whole = $this->apply([...$hourly,'targetDate'=>'2026-10-01','endTime'=>'17:00'])->assertOk()->json('data.id');
        $this->approve($whole)->assertOk();
        $this->assertSame(9.0, $this->balance());
        DB::table('employment_type_settings')->where('code','FULL_TIME')->update(['standard_day_minutes'=>360]);
        $short = $this->apply([...$hourly,'targetDate'=>'2026-10-02','endTime'=>'10:00'])->assertOk()->json('data.id');
        DB::table('employment_type_settings')->where('code','FULL_TIME')->update(['standard_day_minutes'=>480]);
        $this->approve($short)->assertOk();
        $this->assertDatabaseHas('leave_requests',['id'=>$short,'hourly_day_minutes'=>360]);
        $this->assertEqualsWithDelta(9 - 1/6, $this->balance(), .000000001);
        $this->cancel($whole);
        $this->assertEqualsWithDelta(10 - 1/6, $this->balance(), .000000001);
    }

    public function test_minute_conversion_preserves_eight_equal_hours_and_prevents_a_free_minute(): void
    {
        DB::table('paid_leave_grants')->where('employee_id',$this->employeeId)->update(['granted_days'=>1]);
        $hour = ['requestCategory'=>'TIME_LEAVE','timeLeaveType'=>'PAID_HOURLY','startTime'=>'09:00','endTime'=>'10:00'];
        foreach (['01','02','05','06','07','08','09','12'] as $day) {
            $id = $this->apply([...$hour,'targetDate'=>'2026-10-'.$day])->assertOk()->json('data.id');
            $this->approve($id)->assertOk();
        }
        $this->assertSame(0.0, $this->balance());
        $this->assertEquals(480, DB::table('attendance_daily')->sum('hour_paid_leave_minutes'));
        $this->assertEquals(-1, DB::table('paid_leave_ledger')->where('entry_type','USE')->sum('days_delta'));
        $minute = [...$hour,'targetDate'=>'2026-10-13','endTime'=>'09:01'];
        $this->apply($minute)->assertStatus(422);
        $this->cancel($id);
        $this->assertSame(.125, $this->balance());
        $this->withToken($this->staff)->getJson('/api/leave/balance?asOf=2026-10-13')->assertOk()->assertJsonPath('data.availableDays',.125);
        $id = $this->apply($minute)->assertOk()->json('data.id');
        $this->approve($id)->assertOk();
        $this->assertEqualsWithDelta(59 / 480, $this->balance(), .000000001);
    }
}
