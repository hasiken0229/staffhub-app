<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Attendance\MonthCloseService;
use App\Services\HarmosMigrationService;
use App\Services\Reports\PayrollReportExportService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HarmosMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();Storage::fake('local');Http::preventStrayRequests();
    }

    private function employee(string $code='IMPORT-1'): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>$code,'name'=>'取込確認 職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-04-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function file(string $csv): UploadedFile { return UploadedFile::fake()->createWithContent('safety.csv',"\xEF\xBB\xBF".$csv); }
    private function service(): HarmosMigrationService { return app(HarmosMigrationService::class); }
    private function actor(): GenericUser { return new GenericUser(['id'=>1,'role'=>'ADMIN']); }
    private function dailyCsv(string $rows): string { return "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間\n".$rows; }

    public function test_daily_import_validates_the_stored_remark_in_preview_and_commit(): void
    {
        $employee = $this->employee();
        $payload = ['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'];
        $remark = str_repeat('備', 255 - mb_strlen('HARMOS移行 '));
        $csv = "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,備考\n"
            ."IMPORT-1,2026/10/01,09:00,18:00,60,480,{$remark}\n"
            ."IMPORT-1,2026/10/02,09:00,18:00,60,480,{$remark}考\n";
        $preview = $this->service()->preview($payload, $this->file($csv));
        $this->assertSame(1, $preview['errorCount']);
        $this->assertStringContainsString('255', $preview['errors'][0]['message']);
        $result = $this->service()->import($payload, $this->file($csv), $this->actor());
        $this->assertSame(1, $result['successCount']);
        $this->assertSame(1, $result['errorCount']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-10-01','remark'=>'HARMOS移行 '.$remark]);
        $this->assertDatabaseMissing('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-10-02']);
    }

    public function test_duplicate_daily_is_skipped_before_write_and_preview_and_history_counts_agree(): void
    {
        $employee=$this->employee();$payload=['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'];
        $csv=$this->dailyCsv("IMPORT-1,2026/10/01,09:00,18:00,60,480\nIMPORT-1,2026/10/01,10:00,18:00,60,420\n");
        $preview=$this->service()->preview($payload,$this->file($csv));
        $this->assertSame(1,$preview['successCount']);$this->assertSame(1,$preview['errorCount']);
        $this->assertSame('SKIP',$preview['items'][1]['action']);$this->assertDatabaseCount('attendance_daily',0);
        $result=$this->service()->import($payload,$this->file($csv),$this->actor());
        $this->assertSame(1,$result['successCount']);$this->assertSame(1,$result['summary']['duplicateCount']);
        $this->assertDatabaseCount('attendance_daily',1);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'clock_in_at'=>'2026-10-01 09:00:00','work_minutes'=>480]);
        $this->assertDatabaseHas('import_histories',['success_count'=>1,'error_count'=>1]);Http::assertNothingSent();
    }

    public function test_duplicate_employees_work_type_names_and_balances_never_overwrite_the_first_row(): void
    {
        $result=$this->service()->import(['importType'=>'HARMOS_EMPLOYEE_CSV'],
            $this->file("社員番号,氏名,所属,入職日\nIMPORT-1,最初の氏名,保育,2026/04/01\nIMPORT-1,重複の氏名,事務,2026/04/01\n"),$this->actor());
        $this->assertSame(1,$result['errorCount']);$this->assertDatabaseHas('employees',['employee_code'=>'IMPORT-1','name'=>'最初の氏名']);
        $result=$this->service()->import(['importType'=>'HARMOS_WORK_TYPE_CSV'],
            $this->file("勤務区分ID,勤務区分名,勤務/休日・休暇,勤務時間_開始_,勤務時間_終了_\n99,原本早番,勤務,07:00,16:00\n100,原本早番,勤務,08:00,17:00\n"),$this->actor());
        $this->assertSame(1,$result['summary']['duplicateCount']);$this->assertDatabaseHas('work_type_settings',['name'=>'原本早番','start_time'=>'07:00:00']);
        $result=$this->service()->import(['importType'=>'HARMOS_PAID_LEAVE_BALANCE_CSV','migrationDate'=>'2026-10-01'],
            $this->file("社員番号,有給残数,有効期限\nIMPORT-1,12.5,2028/03/31\nIMPORT-1,2,2028/03/31\n"),$this->actor());
        $this->assertSame(1,$result['errorCount']);$this->assertDatabaseHas('paid_leave_grants',['granted_days'=>12.5]);
        $this->assertDatabaseCount('paid_leave_grants',1);$this->assertDatabaseCount('paid_leave_ledger',1);Http::assertNothingSent();
    }

    public function test_multiple_validation_messages_are_counted_as_one_failed_row_and_never_negative_success(): void
    {
        $csv=$this->dailyCsv(",bad-date,09:00,18:00,60,480\n");
        foreach ([false,true] as $commit) {
            $result=$commit ? $this->service()->import(['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'],$this->file($csv),$this->actor())
                : $this->service()->preview(['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'],$this->file($csv));
            $this->assertSame(1,$result['processedCount']);$this->assertSame(0,$result['successCount']);$this->assertSame(1,$result['errorCount']);
            $this->assertGreaterThanOrEqual(3,count($result['errors']));
        }
        $this->assertDatabaseCount('attendance_daily',0);
    }

    public function test_invalid_dates_times_and_durations_are_not_silently_normalized_but_valid_overnight_seconds_are_preserved(): void
    {
        $employee=$this->employee();$payload=['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'];
        $csv=$this->dailyCsv("IMPORT-1,20260230,09:00,18:00,60,480\n"
            ."IMPORT-1,2026/02/30,09:00,18:00,60,480\n"
            ."IMPORT-1,2026/10/01,09:00,17:99,60,480\n"
            ."IMPORT-1,2026/10/02,09:00,49:00,60,480\n"
            ."IMPORT-1,2026/10/03,09:00,18:00,1:99,480\n"
            ."IMPORT-1,2026/10/04,18:00,09:00,60,480\n"
            ."IMPORT-1,2026/10/05,09:00,18:00,-60,480\n"
            ."IMPORT-1,20260228,22:00:37,26:00:41,30,210\n"
            ."IMPORT-1,2024/02/29,,,,\n");
        $preview=$this->service()->preview($payload,$this->file($csv));
        $this->assertSame(2,$preview['successCount']);$this->assertSame(7,$preview['errorCount']);
        $this->assertDatabaseCount('attendance_daily',0);
        $result=$this->service()->import($payload,$this->file($csv),$this->actor());
        $this->assertSame(2,$result['successCount']);$this->assertSame(7,$result['errorCount']);
        $this->assertDatabaseCount('attendance_daily',2);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-02-28',
            'clock_in_at'=>'2026-02-28 22:00:37','raw_clock_in_at'=>null,'clock_out_at'=>'2026-03-01 02:00:41','work_minutes'=>210]);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2024-02-29',
            'clock_in_at'=>null,'clock_out_at'=>null,'work_minutes'=>null]);
        $this->assertDatabaseMissing('attendance_daily',['target_date'=>'2026-03-02']);Http::assertNothingSent();
    }

    public function test_only_untouched_harmos_rows_can_be_reimported_while_local_changes_stay_identical(): void
    {
        $employee=$this->employee();$payload=['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'];$rows='';
        for ($day=1;$day<=7;$day++) { $rows.="IMPORT-1,2026/10/0{$day},09:00,18:00,60,480\n"; }
        $this->service()->import($payload,$this->file($this->dailyCsv($rows)),$this->actor());
        $base=DB::table('attendance_daily')->where('target_date','2026-10-07')->first();
        DB::table('attendance_daily')->where('target_date','2026-10-01')->update(['source_system'=>null]);
        DB::table('attendance_daily')->where('target_date','2026-10-02')->update(['is_manually_edited'=>1]);
        DB::table('attendance_daily')->where('target_date','2026-10-03')->update(['approved_by'=>$employee,'approved_at'=>'2026-10-09 09:00:00']);
        DB::table('attendance_daily')->where('target_date','2026-10-04')->update(['close_status'=>'CLOSED']);
        $device=DB::table('attendance_devices')->insertGetId(['device_code'=>'SHIFT-VERIFY','name'=>'検証','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('attendance_events')->insert(['employee_id'=>$employee,'device_id'=>$device,'card_uid'=>'TEST','event_type'=>'CLOCK_IN',
            'occurred_at'=>'2026-10-05 08:59:37','source_type'=>'DEVICE','created_at'=>now(),'receive_status'=>'ACCEPTED','offline_saved'=>0,'dedupe_key'=>'local-punch']);
        DB::table('attendance_leave_projections')->insert(['employee_id'=>$employee,'target_date'=>'2026-10-06',
            'baseline_json'=>'{}','applied_json'=>'{}','expected_json'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $protected=DB::table('attendance_daily')->where('target_date','<','2026-10-07')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $replacement=str_replace('09:00,18:00,60,480','10:00,18:00,60,420',$rows);
        $preview=$this->service()->preview($payload,$this->file($this->dailyCsv($replacement)));
        $this->assertSame(1,$preview['successCount']);$this->assertSame(6,$preview['errorCount']);
        $this->assertSame($protected,DB::table('attendance_daily')->where('target_date','<','2026-10-07')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
        for ($attempt=0;$attempt<2;$attempt++) {
            $result=$this->service()->import($payload,$this->file($this->dailyCsv($replacement)),$this->actor());
            $this->assertSame(1,$result['successCount']);$this->assertSame(6,$result['errorCount']);
            $this->assertSame($protected,DB::table('attendance_daily')->where('target_date','<','2026-10-07')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all());
            $this->assertDatabaseHas('attendance_daily',['id'=>$base->id,'target_date'=>'2026-10-07','clock_in_at'=>'2026-10-07 10:00:00','work_minutes'=>420,'approval_status'=>'PENDING','raw_clock_in_at'=>null]);
        }
        $this->assertDatabaseCount('attendance_daily',7);Http::assertNothingSent();
    }

    public function test_japanese_work_and_break_hours_reach_the_closed_payroll_csv_in_minutes(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-05-01 12:00:00', 'Asia/Tokyo'));
        $employee = $this->employee();
        DB::table('employees')->where('id', $employee)->update(['joined_on' => '2026-04-08', 'retired_on' => '2026-04-08']);
        $payload = ['importType' => 'HARMOS_ATTENDANCE_DAILY_CSV'];
        $csv = "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,出勤時刻_打刻_,退勤時刻_打刻_\nIMPORT-1,2026/04/08,09:00,18:00,1時間,8時間,08:57,18:02\n";

        $preview = $this->service()->preview($payload, $this->file($csv));
        $this->assertSame(0, $preview['errorCount']);
        $this->assertStringContainsString('実働 480分', $preview['items'][0]['detail']);
        $this->assertDatabaseCount('attendance_daily', 0);
        $result = $this->service()->import($payload, $this->file($csv), $this->actor());
        $this->assertSame($preview['items'], $result['items']);
        $this->assertDatabaseHas('attendance_daily', [
            'employee_id' => $employee, 'target_date' => '2026-04-08', 'work_minutes' => 480, 'break_minutes' => 60,
        ]);

        app(MonthCloseService::class)->updateMonthlyClose('2026-04', 'CLOSED', null, $this->actor());
        $payroll = app(PayrollReportExportService::class)->exportMonthlyPayrollCsv('2026-04');
        $this->assertSame(480, $payroll['summary']['totalBaseMinutes']);
        $csvRows = explode("\n", trim(mb_convert_encoding($payroll['content'], 'UTF-8', 'SJIS-win')));
        $this->assertSame(['IMPORT-1', '取込確認 職員', '8時間00分', '0時間00分'], str_getcsv($csvRows[1]));
    }

    public function test_duration_forms_share_validation_in_preview_and_commit_and_invalid_rows_keep_the_old_values(): void
    {
        $employee = $this->employee();
        $payload = ['importType' => 'HARMOS_ATTENDANCE_DAILY_CSV'];
        $csv = "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,時間有給\n"
            ."IMPORT-1,2026/10/01,09:00,18:00,３０分,８時間３０分,1時間15分\n"
            ."IMPORT-1,2026/10/02,09:00,18:00,0:30,8:30,75\n"
            ."IMPORT-1,2026/10/03,09:00,18:00,30,8.5時間,75分\n";
        $preview = $this->service()->preview($payload, $this->file($csv));
        $commit = $this->service()->import($payload, $this->file($csv), $this->actor());
        $this->assertSame(3, $preview['successCount']);
        $this->assertStringContainsString('実働 510分 / 休憩 30分 / 時間有給 75分', $preview['items'][0]['detail']);
        $this->assertSame($preview['items'], $commit['items']);
        foreach (['01', '02', '03'] as $day) {
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id' => $employee, 'target_date' => '2026-10-'.$day,
                'break_minutes' => 30, 'work_minutes' => 510, 'hour_paid_leave_minutes' => 75,
            ]);
        }

        $bad = "社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,時間有給\n"
            ."IMPORT-1,2026/10/01,09:00,17:00,１時間７５分,8時間x,12:99\n"
            ."IMPORT-1,2026/10/04,09:00,17:00,30,2147483648,-1分\n"
            ."IMPORT-1,2026/10/05,09:00,17:00,30,٨時間,0\n";
        $before = DB::table('attendance_daily')->orderBy('id')->get()->toJson();
        $preview = $this->service()->preview($payload, $this->file($bad));
        $commit = $this->service()->import($payload, $this->file($bad), $this->actor());
        $this->assertSame(3, $preview['errorCount']);
        $this->assertSame(0, $commit['successCount']);
        $this->assertCount(6, $preview['errors']);
        $this->assertSame($preview['errors'], $commit['errors']);
        $this->assertSame($before, DB::table('attendance_daily')->orderBy('id')->get()->toJson());
    }

    public function test_work_type_preview_shows_converted_standard_minutes_and_skips_only_invalid_values(): void
    {
        $payload = ['importType' => 'HARMOS_WORK_TYPE_CSV'];
        $csv = "勤務区分ID,勤務区分名,勤務/休日・休暇,所定時間\n"
            ."41,合成A,勤務,７時間３０分\n"
            ."42,合成B,勤務,\"1,200.5\"\n"
            ."43,合成C,勤務,1時間90分\n"
            ."44,合成D,勤務,\"1,20分\"\n";
        $preview = $this->service()->preview($payload, $this->file($csv));
        $this->assertSame(2, $preview['successCount']);
        $this->assertSame(2, $preview['errorCount']);
        $this->assertStringContainsString('所定450分', $preview['items'][0]['detail']);
        $this->assertStringContainsString('所定1201分', $preview['items'][1]['detail']);
        $commit = $this->service()->import($payload, $this->file($csv), $this->actor());
        $this->assertSame($preview['items'], $commit['items']);
        $this->assertSame($preview['errors'], $commit['errors']);
        $this->assertDatabaseHas('work_type_settings', ['name' => '合成A', 'standard_day_minutes' => 450]);
        $this->assertDatabaseHas('work_type_settings', ['name' => '合成B', 'standard_day_minutes' => 1201]);
        $this->assertDatabaseMissing('work_type_settings', ['name' => '合成C']);
        $this->assertDatabaseMissing('work_type_settings', ['name' => '合成D']);
    }

    public function test_preview_explains_closed_month_and_import_cannot_write_another_open_month_partially(): void
    {
        $this->employee();DB::table('attendance_monthly_closes')->insert(['target_year_month'=>'2026-10','status'=>'CLOSED','created_at'=>now(),'updated_at'=>now()]);
        $csv=$this->dailyCsv("IMPORT-1,2026/09/01,09:00,18:00,60,480\nIMPORT-1,2026/10/01,09:00,18:00,60,480\n");
        $preview=$this->service()->preview(['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'],$this->file($csv));
        $this->assertSame(1,$preview['errorCount']);$this->assertSame('SKIP',$preview['items'][1]['action']);
        try { $this->service()->import(['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'],$this->file($csv),$this->actor());$this->fail('Closed month accepted'); }
        catch (ApiException $error) { $this->assertSame('CLOSED_PERIOD',$error->errorCode); }
        $this->assertDatabaseCount('attendance_daily',0);$this->assertDatabaseCount('import_histories',0);
        $this->assertDatabaseMissing('attendance_monthly_closes',['target_year_month'=>'2026-09']);
    }

    public function test_official_csv_keeps_adopted_and_observed_times_day_offsets_and_source_approval_separate(): void
    {
        $employee=$this->employee();
        $csv="社員番号,日付,出勤時刻,前日/翌日,出勤時刻_打刻_,前日/翌日,退勤時刻,前日/翌日,退勤時刻_打刻_,前日/翌日,休憩時間,実働時間,申請承認\n"
            ."IMPORT-1,2026/04/08,22:00,0,21:59,0,02:00,1,02:03,1,0,4時間,未申請\n"
            ."IMPORT-1,2026/04/09,09:00,0,,0,17:00,0,,0,60,7時間,承認済み\n"
            ."IMPORT-1,2026/04/10,09:00,0,09:01,2,17:00,0,17:01,0,60,7時間,未申請\n";
        $payload=['importType'=>'HARMOS_ATTENDANCE_DAILY_CSV'];
        $preview=$this->service()->preview($payload,$this->file($csv));
        $commit=$this->service()->import($payload,$this->file($csv),$this->actor());
        $this->assertSame($preview['items'],$commit['items']);
        $this->assertSame(1,$preview['errorCount']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-04-08','clock_in_at'=>'2026-04-08 22:00:00',
            'clock_out_at'=>'2026-04-09 02:00:00','raw_clock_in_at'=>'2026-04-08 21:59:00','raw_clock_out_at'=>'2026-04-09 02:03:00','approval_status'=>'PENDING']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$employee,'target_date'=>'2026-04-09','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,
            'approval_status'=>'APPROVED','approval_comment'=>'HARMOS原票: 承認済み']);
        $this->assertDatabaseMissing('attendance_daily',['target_date'=>'2026-04-10']);
    }
}
