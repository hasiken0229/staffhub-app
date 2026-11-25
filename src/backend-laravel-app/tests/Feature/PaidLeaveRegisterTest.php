<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PaidLeaveRegisterTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;
    private string $admin;
    private string $staff;
    private const FILE = 'paid-leave-register/reconciliation.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-01-03 12:00', 'Asia/Tokyo'));
        Storage::fake('local');
        $this->employee = DB::table('employees')->insertGetId(['employee_code'=>'RG01','name'=>'確認用職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2025-04-01']);
        $hash = Hash::make('test-password');
        DB::table('users')->insert(['name'=>'管理者','email'=>'admin-register@example.test','password'=>$hash]);
        DB::table('employee_auth')->insert(['employee_id'=>$this->employee,'login_id'=>'staff-register@example.test','password_hash'=>$hash]);
        $login = fn ($id,$audience) => $this->postJson('/api/auth/login',['loginId'=>$id.'-register@example.test','password'=>'test-password','audience'=>$audience])
            ->assertOk()->json('data.accessToken');
        $this->admin = $login('admin','ADMIN');
        $this->staff = $login('staff','EMPLOYEE');
    }

    private function url(string $from = '2026-12-30', string $to = '2027-01-05'): string
    {
        return '/api/admin/reports/paid-leave-register?'.http_build_query(['employeeId'=>$this->employee,'from'=>$from,'to'=>$to]);
    }

    private function report(string $from = '2026-12-30', string $to = '2027-01-05'): array
    {
        return $this->withToken($this->admin)->getJson($this->url($from,$to))->assertOk()->json('data');
    }

    private function grant(string $date, float $days, array $extra = []): int
    {
        return DB::table('paid_leave_grants')->insertGetId($extra+['employee_id'=>$this->employee,'granted_on'=>$date,'granted_days'=>$days,'used_days'=>0]);
    }

    private function requestLeave(string $date, float $days, array $extra = []): int
    {
        return DB::table('leave_requests')->insertGetId($extra+['employee_id'=>$this->employee,'leave_type_code'=>'PAID',
            'start_date'=>$date,'end_date'=>$date,'day_unit'=>$days===0.5?'HALF':'FULL','quantity_days'=>$days,'status'=>'APPROVED']);
    }

    private function daily(string $date, float $days, int $minutes = 0, string $status = 'APPROVED'): void
    {
        DB::table('attendance_daily')->insert(['employee_id'=>$this->employee,'target_date'=>$date,'paid_leave_unit'=>$days,
            'hour_paid_leave_minutes'=>$minutes,'approval_status'=>$status,'source_system'=>'HARMOS','updated_at'=>now()]);
    }

    private function adjustment(string $date, float $delta): int
    {
        return DB::table('paid_leave_adjustments')->insertGetId(['employee_id'=>$this->employee,'effective_on'=>$date,
            'days'=>$delta,'adjustment_type'=>$delta>0?'ADJUST_PLUS':'ADJUST_MINUS','note'=>'移行と同じ文面でも監査原票を照合する']);
    }

    private function history(array $years, ?int $employeeId = null): void
    {
        $sources = [];
        foreach (['fresh'=>'2026-11-24', 'old'=>'2026-09-30', 'future'=>'2027-04-01'] as $id => $asOf) {
            $sources[$id] = ['file'=>'private-fixture.csv', 'asOf'=>$asOf, 'sha256'=>str_repeat('a', 64)];
        }
        Storage::disk('local')->put('paid-leave-register/official-history.json', json_encode([
            'schemaVersion'=>1, 'sources'=>$sources, 'employees'=>['RG01'=>['employeeId'=>$employeeId ?? $this->employee,'years'=>$years]],
        ]));
    }

    private function origin(string $date, int $row, array $values): array
    {
        return $values + ['date'=>$date, 'sourceRow'=>$row, 'sourceId'=>'fresh'];
    }

    public function test_fiscal_register_uses_adjusted_april_opening_and_separates_actual_hours_from_day_balance_movement(): void
    {
        $opening = $this->origin('2026-04-01', 5, ['days'=>35, 'totalMinutes'=>4800, 'annualMinutes'=>2400]);
        $aprilUse = $this->origin('2026-04-01', 3, ['kind'=>'USE','days'=>0.5,'minutes'=>0,'afterDays'=>20]);
        $hour = $this->origin('2026-10-14', 8, ['kind'=>'USE','days'=>0,'minutes'=>120,'dayChange'=>-0.5,
            'minutesChange'=>-120,'afterDays'=>34.5,'afterTotalMinutes'=>4680,'afterAnnualMinutes'=>2280]);
        $close = $this->origin('2026-11-24', 9, ['kind'=>'USE','days'=>1,'minutes'=>0,'dayChange'=>-1,
            'minutesChange'=>-480,'afterDays'=>33.5,'afterTotalMinutes'=>4200,'afterAnnualMinutes'=>2280]);
        $saved = ['opening'=>$opening,'coveredFrom'=>'2026-04-01','coveredThrough'=>'2026-11-24','sourceId'=>'fresh',
            'entries'=>[$this->origin('2026-04-01', 2, ['kind'=>'CARRY','days'=>0,'minutes'=>0]), $aprilUse,
                $this->origin('2026-04-01', 5, ['kind'=>'ADJUSTMENT','days'=>0,'minutes'=>0]),$hour,$hour,$close]];
        $this->history([2026=>$saved]);
        $this->grant('2026-09-30', 35, ['source_system'=>'HARMOS']);
        $this->requestLeave('2026-10-14', 0, ['time_leave_type'=>'PAID_HOURLY','request_category'=>'TIME_LEAVE',
            'quantity_minutes'=>120,'balance_source'=>'NONE']);
        $this->daily('2026-10-14', 0, 120);
        $this->daily('2026-11-24', 1);
        $year = $this->report('2026-04-01','2026-11-24')['fiscalLedger']['years'][0];
        $this->assertSame('AVAILABLE',$year['status']);
        $this->assertSame(['days'=>35,'totalMinutes'=>4800,'annualMinutes'=>2400],$year['opening']);
        $this->assertCount(3,$year['entries']);
        $this->assertSame('2026-11-24',$year['closingDate']);
        $this->assertSame(['days'=>33.5,'totalMinutes'=>4200,'annualMinutes'=>2280],$year['closing']);
        [$april,$oct,$nov] = $year['entries'];
        $this->assertTrue($april['includedInOpening']);$this->assertSame(0.5,$april['usedDays']);$this->assertNull($april['daysAfter']);
        $this->assertSame('RECORDED',$oct['status']);$this->assertSame(0,$oct['usedDays']);$this->assertSame(120,$oct['usedMinutes']);
        $this->assertSame(-0.5,$oct['dayChange']);$this->assertSame(34.5,$oct['daysAfter']);
        $this->assertSame(4680,$oct['totalMinutesAfter']);$this->assertSame(2280,$oct['annualMinutesAfter']);
        $this->assertSame(0,$nov['usedMinutes']);$this->assertSame(-480,$nov['minutesChange']);$this->assertSame(1,$nov['usedDays']);
        $filtered=$this->report('2026-10-14','2026-10-14')['fiscalLedger']['years'][0];
        $this->assertSame([$oct],$filtered['entries']);$this->assertSame($year['opening'],$filtered['opening']);
        $empty=$this->report('2026-11-01','2026-11-04')['fiscalLedger']['years'][0];
        $this->assertSame([],$empty['entries']);$this->assertSame('2026-11-04',$empty['closingDate']);
        $this->assertSame(['days'=>34.5,'totalMinutes'=>4680,'annualMinutes'=>2280],$empty['closing']);
        $this->assertStringNotContainsString('private-fixture',json_encode($year));
    }

    public function test_fiscal_report_covers_multiple_years_preserves_partial_openings_and_rejects_unobserved_or_other_persons_values(): void
    {
        $saved = ['opening'=>$this->origin('2026-04-01',2,['days'=>0,'totalMinutes'=>0,'annualMinutes'=>null]),
            'sourceId'=>'old','coveredFrom'=>'2026-04-01','coveredThrough'=>'2026-11-24',
            'entries'=>[$this->origin('2026-10-15',5,['sourceId'=>'old','days'=>1,'minutes'=>0,'afterDays'=>12]),
                $this->origin('2026-04-01',6,['sourceId'=>'future','days'=>1, 'afterDays'=>88])]];
        $this->history([2026=>$saved]);
        $years = $this->report('2026-03-31','2026-04-01')['fiscalLedger']['years'];
        $this->assertSame([2025,2026],array_column($years,'fiscalYear'));
        $this->assertSame('NOT_ESTABLISHED',$years[0]['status']);$this->assertSame('PARTIAL',$years[1]['status']);
        $this->assertSame(['2026-03-31','2026-04-01'],array_column($years,'closingDate'));
        $this->assertSame(['days'=>0,'totalMinutes'=>0,'annualMinutes'=>null],$years[1]['opening']);
        $this->assertNull($years[1]['coveredThrough']);$this->assertSame([],$years[1]['entries']);
        $this->assertSame([],$this->report('2026-10-15','2026-10-15')['fiscalLedger']['years'][0]['entries']);
        $this->history([2026=>$saved],$this->employee+999);
        $year=$this->report('2026-04-01','2026-04-01')['fiscalLedger']['years'][0];
        $this->assertSame('NOT_ESTABLISHED',$year['status']);$this->assertNull($year['opening']['days']);
        $this->history([2027=>['opening'=>$this->origin('2027-04-01',3,['days'=>35,'sourceId'=>'future'])]]);
        $future=$this->report('2027-04-01','2027-04-02')['fiscalLedger']['years'][0];
        $this->assertNull($future['opening']['days']);$this->assertNull($future['closingDate']);
        $this->assertSame(['days'=>null,'totalMinutes'=>null,'annualMinutes'=>null],$future['closing']);
        Storage::disk('local')->put('paid-leave-register/official-history.json','{invalid');
        $this->assertSame('NOT_ESTABLISHED',$this->report('2026-04-01','2026-04-01')['fiscalLedger']['years'][0]['status']);
    }

    public function test_fiscal_report_preserves_distinct_same_day_uses_and_only_continues_day_balances_from_a_matching_origin(): void
    {
        $this->grant('2026-09-30', 8,['source_system'=>'HARMOS']);
        $this->requestLeave('2026-11-02',1);
        $this->requestLeave('2026-11-04',0.5);
        $this->daily('2026-11-05',1,0,'PENDING');
        $this->requestLeave('2027-01-05',1);
        $uses=[];
        foreach ([4,5] as $i) {
            $uses[]=$this->origin('2026-06-02',$i,['days'=>0.5,'minutes'=>0,'afterDays'=>($i===4?8.5:8)]);
        }
        $saved=['opening'=>null,'entries'=>$uses,'sourceId'=>'fresh','coveredFrom'=>'2026-05-01','coveredThrough'=>'2026-10-31'];
        $this->requestLeave('2026-06-02',0.5,['status'=>'PENDING']);
        $this->daily('2026-06-02',1);
        $this->history([2026=>$saved]);
        $june=$this->report('2026-06-02','2026-06-02')['fiscalLedger']['years'][0]['entries'];
        $this->assertCount(2,$june);$this->assertSame([0.5,0.5],array_column($june,'usedDays'));
        $rows=$this->report('2026-11-04','2027-01-05')['fiscalLedger']['years'][0]['entries'];
        $this->assertSame(['2026-11-04','2026-11-05','2027-01-05'],array_column($rows,'date'));
        $this->assertSame(['RECORDED','REFERENCE','FUTURE'],array_column($rows,'status'));
        $this->assertSame([6.5,null,null],array_column($rows,'daysAfter'));
        $this->assertNull($rows[0]['annualMinutesAfter']);$this->assertNull($rows[0]['totalMinutesAfter']);
        $saved['entries'][1]['afterDays']=7.5;
        $this->history([2026=>$saved]);
        $row=$this->report('2026-11-04','2026-11-04')['fiscalLedger']['years'][0]['entries'][0];
        $this->assertSame('RECORDED',$row['status']);$this->assertNull($row['daysAfter']);
    }

    public function test_monthly_checkpoint_proves_only_its_own_date_and_does_not_hide_an_approved_request(): void
    {
        $this->history([2026=>['opening'=>null,'openingReason'=>'NOT_EMPLOYED','coverageKind'=>'CHECKPOINT_ONLY',
            'sourceId'=>'fresh','coveredFrom'=>'2026-11-24','coveredThrough'=>'2026-11-24','entries'=>[
                $this->origin('2026-11-24',4,['kind'=>'BALANCE','afterDays'=>2,'afterAnnualMinutes'=>60])]]]);
        $this->requestLeave('2026-11-24',0.5);
        $year=$this->report('2026-11-24','2026-11-24')['fiscalLedger']['years'][0];
        $this->assertSame('NOT_EMPLOYED',$year['openingReason']);$this->assertNull($year['opening']['days']);
        $this->assertSame(['USE','BALANCE'],array_column($year['entries'],'kind'));
        $this->assertSame(['RECORDED','RECORDED'],array_column($year['entries'],'status'));
        $this->assertSame([0.5,null],array_column($year['entries'],'usedDays'));
        $this->assertSame([null,2],array_column($year['entries'],'daysAfter'));
        $this->assertNull($year['entries'][1]['totalMinutesAfter']);$this->assertSame(60,$year['entries'][1]['annualMinutesAfter']);
        $yesterday=$this->report('2026-11-23','2026-11-23')['fiscalLedger']['years'][0];
        $this->assertNull($yesterday['closing']['days']);$this->assertNull($yesterday['closing']['annualMinutes']);
    }

    public function test_after_official_cutoff_hourly_fields_reconcile_independently_then_use_a_new_dated_baseline(): void
    {
        $this->history([2026=>['opening'=>null,'coveredFrom'=>'2026-04-01','coveredThrough'=>'2026-11-15',
            'sourceId'=>'fresh','entries'=>[$this->origin('2026-11-15',3,['kind'=>'BALANCE','afterDays'=>8,
                'afterTotalMinutes'=>660,'afterAnnualMinutes'=>300])]]]);
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS']);
        foreach ([['2026-11-01',300,600],['2026-11-20',240,540]] as [$date,$annual,$total]) {
            DB::table('paid_leave_hourly_balances')->insert(['employee_id'=>$this->employee,'effective_on'=>$date,
                'expires_on'=>'2026-12-31','remaining_minutes'=>$annual,'source_total_minutes'=>$total]);
        }
        foreach ([['2026-11-16',45],['2026-11-16',15],['2026-11-21',30],['2026-12-01',60]] as [$date,$minutes]) {
            $this->requestLeave($date,0,['time_leave_type'=>'PAID_HOURLY','request_category'=>'TIME_LEAVE',
                'quantity_minutes'=>$minutes,'balance_source'=>'NONE']);
        }
        $rows=$this->report('2026-11-16','2026-11-21')['fiscalLedger']['years'][0]['entries'];
        $this->assertSame(['USE','USE','BALANCE','USE'],array_column($rows,'kind'));
        $this->assertSame([45,15,null,30],array_column($rows,'usedMinutes'));
        $this->assertSame([null,8,8,8],array_column($rows,'daysAfter'));
        $this->assertSame([null,240,240,210],array_column($rows,'annualMinutesAfter'));
        $this->assertSame([null,null,540,510],array_column($rows,'totalMinutesAfter'));
        $empty=$this->report('2026-11-17','2026-11-17')['fiscalLedger']['years'][0];
        $this->assertSame([],$empty['entries']);
        $this->assertSame(['days'=>8,'totalMinutes'=>null,'annualMinutes'=>240],$empty['closing']);
        $expired=$this->report('2027-01-02','2027-01-05')['fiscalLedger']['years'][0];
        $this->assertSame('2027-01-03',$expired['closingDate']);
        $this->assertSame(['days'=>8,'totalMinutes'=>null,'annualMinutes'=>null],$expired['closing']);
    }

    public function test_native_grants_and_later_fiscal_years_keep_verified_day_balances_without_a_fabricated_april_original(): void
    {
        $this->grant('2026-05-01',10);
        $this->requestLeave('2026-06-01',1);
        $native=$this->report('2026-04-01','2026-06-01')['fiscalLedger']['years'][0];
        $this->assertSame(['GRANT','USE'],array_column($native['entries'],'kind'));
        $this->assertSame([10,9],array_column($native['entries'],'daysAfter'));
        $this->assertNull($native['opening']['days']);$this->assertSame(9,$native['closing']['days']);

        DB::table('paid_leave_grants')->where('employee_id',$this->employee)->update(['source_system'=>'HARMOS']);
        $this->travelTo(CarbonImmutable::parse('2027-06-30 12:00','Asia/Tokyo'));
        $this->admin=$this->postJson('/api/auth/login',['loginId'=>'admin-register@example.test',
            'password'=>'test-password','audience'=>'ADMIN'])->assertOk()->json('data.accessToken');
        $this->requestLeave('2027-06-01',0.5);
        $this->assertNull($this->report('2027-06-01','2027-06-10')['fiscalLedger']['years'][0]['closing']['days']);
        $this->history([2026=>['opening'=>null, 'sourceId'=>'fresh','coveredFrom'=>'2026-11-24','coveredThrough'=>'2026-11-24',
            'coverageKind'=>'CHECKPOINT_ONLY','entries'=>[$this->origin('2026-11-24',8,['kind'=>'BALANCE','afterDays'=>9])]]]);
        $continued=$this->report('2027-06-01','2027-06-10')['fiscalLedger']['years'][0];
        $this->assertNull($continued['opening']['days']);$this->assertNull($continued['coveredThrough']);
        $this->assertSame(8.5,$continued['closing']['days']);$this->assertSame(8.5,$continued['entries'][0]['daysAfter']);
    }

    public function test_privacy_dates_retirees_and_absent_or_corrupt_reconciliation(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->withToken($this->staff)->getJson($this->url())->assertForbidden();
        $this->withToken($this->admin)->getJson($this->url('2027-01-01','2026-12-31'))->assertUnprocessable();
        $this->withToken($this->admin)->getJson($this->url('2026-02-30','2027-01-01'))->assertUnprocessable();
        $this->withToken($this->admin)->getJson('/api/admin/reports/paid-leave-register?from=2026-01-01&to=2026-01-10')->assertUnprocessable();
        $this->withToken($this->admin)->getJson('/api/admin/reports/paid-leave-register?employeeId=999998&from=2026-01-01&to=2026-01-10')->assertNotFound();
        DB::table('employees')->where('id',$this->employee)->update(['status'=>'RETIRED','retired_on'=>'2026-12-31']);
        $this->assertSame('RETIRED',$this->report()['employee']['status']);
        $this->assertSame('NOT_ESTABLISHED',$this->report()['review']['status']);
        $own=['employeeId'=>$this->employee,'officialDays'=>4,'appDays'=>5,'status'=>'needs_review','sourceFile'=>'test.csv','sourceRow'=>3];
        $write=fn ($rows, $asOf='2026-11-24') => Storage::disk('local')->put(self::FILE,json_encode(['schemaVersion'=>1,'asOf'=>$asOf,'employees'=>$rows]));
        $write(['RG01'=>$own,'RG02'=>['sourceFile'=>'other-person.csv']]);
        $r=$this->report();
        $this->assertSame('REVIEW_REQUIRED',$r['review']['status']);$this->assertEquals(4,$r['review']['officialDays']);
        $this->assertSame('2026-11-24',$r['review']['asOf']);$this->assertStringNotContainsString('other-person',json_encode($r));
        $write(['RG01'=>array_replace($own,['officialDays'=>5,'status'=>'balance_matched'])]);
        $this->assertSame('NO_OPEN_DIFFERENCE',$this->report()['review']['status']);
        foreach ([['RG99'=>$own],['RG01'=>array_diff_key($own,['employeeId'=>true])],['RG01'=>array_replace($own,['status'=>'balance_matched'])]] as $bad) {
            $write($bad);$this->assertSame('NOT_ESTABLISHED',$this->report()['review']['status']);
        }
        $write(['RG01'=>$own],'2026-02-30');$this->assertSame('NOT_ESTABLISHED',$this->report()['review']['status']);
        Storage::disk('local')->put(self::FILE,'{broken');$this->assertNull($this->report()['review']['asOf']);
        $write(['RG01'=>$own]);
        DB::table('employees')->where('id',$this->employee)->update(['employee_code'=>'RG01-OLD']);
        $this->assertSame('NOT_ESTABLISHED',$this->report()['review']['status']);
        $this->employee = DB::table('employees')->insertGetId(['employee_code'=>'RG01','name'=>'別の確認用職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-12-01']);
        $r=$this->report();
        $this->assertSame('NOT_ESTABLISHED',$r['review']['status']);$this->assertNull($r['review']['sourceRow']);
        $this->assertNull($r['review']['officialDays']);$this->assertNull($r['review']['appDays']);
    }

    public function test_actual_days_hours_pending_and_future_are_distinct_across_years(): void
    {
        $this->grant('2026-04-01',10,['expires_on'=>'2027-03-31']);
        $this->requestLeave('2026-12-28',2,['end_date'=>'2026-12-30','effective_dates'=>json_encode(['2026-12-28','2026-12-30'])]);
        $this->requestLeave('2027-01-01',0.5,['half_day_type'=>'PM']);
        $this->requestLeave('2027-01-02',0,['time_leave_type'=>'PAID_HOURLY','request_category'=>'TIME_LEAVE','quantity_minutes'=>60,'balance_source'=>'NONE']);
        $this->requestLeave('2027-01-03',0.5,['status'=>'PENDING']);
        $this->requestLeave('2027-01-04',1);
        $this->requestLeave('2027-01-05',0,['time_leave_type'=>'PAID_HOURLY','request_category'=>'TIME_LEAVE','quantity_minutes'=>30,'balance_source'=>'NONE']);
        DB::table('paid_leave_hourly_balances')->insert(['employee_id'=>$this->employee,'effective_on'=>'2026-09-30','expires_on'=>'2027-03-31','remaining_minutes'=>300,'source_total_minutes'=>600]);
        $this->daily('2026-12-30',1);$this->daily('2027-01-01',0.5);$this->daily('2027-01-02',0,90);$this->daily('2027-01-03',0.5,0,'PENDING');
        $this->daily('2027-01-04',1);
        $r=$this->report();$rows=collect($r['entries']);
        $this->assertSame('2026-12-29',$r['openingBalanceAsOf']);$this->assertSame('2027-01-03',$r['balanceAsOf']);
        $this->assertEquals(9,$r['openingBalanceDays']);$this->assertEquals(7.5,$r['closingBalanceDays']);
        $this->assertEquals(['annualMinutes'=>240,'totalMinutes'=>540],$r['closingHourlyBalance']);
        $this->assertEquals(['usedDays'=>1.5,'usedMinutes'=>60,'halfDayCount'=>1],$r['totals']);
        $this->assertCount(2,$rows->where('status','FUTURE'));$this->assertCount(2,$rows->where('status','REFERENCE'));
        $this->assertEquals(30,$rows->where('unit','HOUR')->where('status','REFERENCE')->first()['minutes']);
        $this->assertStringContainsString('午後半日',$rows->where('unit','HALF_DAY')->where('status','RECORDED')->first()['evidence']);
        $this->assertNull($rows->where('status','FUTURE')->first()['balanceAfterDays']);
        $this->assertSame('2026-04-01',$r['baseDates'][0]['date']);
        $one=$this->report('2027-01-01','2027-01-01');
        $this->assertCount(1,$one['entries']);$this->assertEquals(8,$one['openingBalanceDays']);$this->assertEquals(7.5,$one['closingBalanceDays']);
        $this->assertDatabaseCount('leave_requests',6);$this->assertDatabaseCount('paid_leave_adjustments',0);$this->assertDatabaseCount('paid_leave_ledger',0);
    }

    public function test_only_receipted_historical_usage_counts_and_source_duplicates_do_not_add_usage(): void
    {
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS','source_file_name'=>'opening.csv']);
        $use=$this->adjustment('2026-12-30',-1);
        $this->adjustment('2026-12-31',-0.5);
        $overlap=$this->adjustment('2027-01-01',-0.5);
        $this->requestLeave('2027-01-01',0.5);
        DB::table('audit_logs')->insert(['actor_type'=>'SYSTEM','action'=>'HARMOS_ADD_PAID_DAY_HISTORY','target_type'=>'PAID_LEAVE_ADJUSTMENT',
            'target_id'=>'test-evidence','detail_json'=>json_encode(['adjustmentIds'=>[$use,$overlap]]),'occurred_at'=>now()]);
        $this->daily('2026-12-30',1);$this->daily('2027-01-01',0.5);
        $r=$this->report();$rows=collect($r['entries']);
        $this->assertEquals(1.5,$r['totals']['usedDays']);$this->assertEquals(5.5,$r['closingBalanceDays']);
        $this->assertCount(0,$rows->where('kind','ATTENDANCE_CANDIDATE'));
        $warning=collect($r['warnings'])->where('kind','OVERLAPPING_LEDGER_DEDUCTIONS')->sole();
        $this->assertSame('2027-01-01',$warning['date']);
        $this->assertStringContainsString('重複控除',$warning['message']);
        $this->assertCount(2,$rows->where('kind','ADJUST_MINUS'));
        $this->assertNull($r['baseDates'][0]['date']);$this->assertSame('MIGRATION_OPENING',$r['baseDates'][0]['kind']);
        $early=$this->report('2026-09-01','2026-09-29');
        $this->assertNull($early['openingBalanceDays']);$this->assertNull($early['closingBalanceDays']);$this->assertSame([],$early['entries']);
    }

    public function test_legacy_hour_conversion_expiry_and_inactive_grant_lots(): void
    {
        $parent=$this->grant('2026-09-30',8,['source_system'=>'HARMOS','superseded_at'=>'2027-01-02 12:00:00']);
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS','parent_grant_id'=>$parent,'original_granted_on'=>'2025-01-01','expires_on'=>'2026-12-31','evidence_reference'=>'原票A']);
        $this->requestLeave('2026-12-30',0.17,['time_leave_type'=>'PAID_HOURLY','request_category'=>'TIME_LEAVE','quantity_minutes'=>60,'hourly_day_minutes'=>360,'balance_source'=>'PAID']);
        $this->daily('2026-12-30',0,60);
        $r=$this->report();$rows=collect($r['entries']);$hour=$rows->where('kind','USE')->sole();
        $this->assertSame('HOUR',$hour['unit']);$this->assertEqualsWithDelta(-1/6,$hour['deltaDays'],0.000000001);
        $this->assertEquals(60,$r['totals']['usedMinutes']);$this->assertEquals(0,$r['totals']['usedDays']);
        $this->assertNull($r['openingHourlyBalance']);$this->assertNull($r['closingHourlyBalance']);$this->assertEquals(0,$r['closingBalanceDays']);
        $this->assertSame('2027-01-01',$rows->where('kind','EXPIRE')->sole()['date']);
        $this->assertCount(1,$r['baseDates']);$this->assertSame('2025-01-01',$r['baseDates'][0]['date']);
        $this->assertSame('RESTATED_GRANTS',$r['warnings'][0]['kind']);
    }

    public function test_overlapping_receipts_group_by_date_preserve_half_day_requests_and_show_one_reference_difference(): void
    {
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS']);
        $ids=[$this->adjustment('2026-12-30',-0.5),$this->adjustment('2026-12-30',-0.5),$this->adjustment('2027-01-01',-0.5)];
        DB::table('audit_logs')->insert(['actor_type'=>'SYSTEM','action'=>'HARMOS_ADD_PAID_DAY_HISTORY','target_type'=>'PAID_LEAVE_ADJUSTMENT',
            'target_id'=>'grouped-overlap','detail_json'=>json_encode(['adjustmentIds'=>$ids]),'occurred_at'=>now()]);
        $this->requestLeave('2026-12-30',0.5,['half_day_type'=>'AM']);$this->daily('2026-12-30',1);
        $this->requestLeave('2027-01-01',0.5,['half_day_type'=>'AM']);$this->requestLeave('2027-01-01',0.5,['half_day_type'=>'PM']);
        $this->daily('2027-01-01',1);
        $r=$this->report();$rows=collect($r['entries']);$candidates=$rows->where('kind','ATTENDANCE_CANDIDATE')->values();
        $this->assertCount(2,$candidates);$this->assertSame(['2026-12-30','2027-01-01'],$candidates->pluck('date')->all());
        foreach ($candidates as $c) {
            $this->assertSame('RECONCILIATION',$c['sourceType']);$this->assertSame('REFERENCE',$c['status']);
            $this->assertEquals(0.5,$c['days']);$this->assertNull($c['deltaDays']);$this->assertNull($c['balanceAfterDays']);
        }
        $this->assertCount(2,$r['warnings']);$this->assertStringContainsString('#'.$ids[1],$r['warnings'][0]['message']);
        $this->assertEquals(1.5,$r['totals']['usedDays']);$this->assertSame(3,$r['totals']['halfDayCount']);
        $this->assertEquals(5,$r['closingBalanceDays']);
        $this->assertCount(1,collect($this->report('2027-01-01','2027-01-02')['entries'])->where('sourceType','RECONCILIATION'));
    }

    public function test_migration_opening_and_computed_balance_retain_pre_snapshot_evidence(): void
    {
        $this->requestLeave('2026-09-25',1);
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS']);
        $r=$this->report('2026-09-25','2026-09-30');
        $this->assertNull($r['openingBalanceDays']);$this->assertEquals(7,$r['closingBalanceDays']);
        $this->assertEquals(8,$r['baseDates'][0]['days']);
        $warning=collect($r['warnings'])->where('kind','MIGRATION_BALANCE_DIFFERENCE')->sole();
        $this->assertSame('2026-09-30',$warning['date']);$this->assertStringContainsString('8日',$warning['message']);
        $this->assertStringContainsString('7日',$warning['message']);$this->assertStringContainsString('移行前',$warning['message']);
        $this->assertDatabaseCount('paid_leave_adjustments',0);
    }

    public function test_missing_replacement_lots_leave_affected_balances_unknown(): void
    {
        $this->grant('2026-04-01',10);
        $this->grant('2026-09-30',8,['source_system'=>'HARMOS','superseded_at'=>'2026-12-01 12:00:00']);
        $this->requestLeave('2026-10-01',1);
        $before=$this->report('2026-09-01','2026-09-25');$this->assertEquals(10,$before['closingBalanceDays']);
        $r=$this->report('2026-09-29','2026-10-31');
        $this->assertEquals(10,$r['openingBalanceDays']);$this->assertNull($r['closingBalanceDays']);
        $this->assertNull(collect($r['entries'])->where('kind','USE')->sole()['balanceAfterDays']);
        $this->assertSame('MISSING_GRANT_HISTORY',$r['warnings'][0]['kind']);
        $this->assertDatabaseCount('paid_leave_grants',2);
    }
}
