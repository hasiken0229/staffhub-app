<?php

namespace Tests\Feature;

use App\Jobs\SendPayrollPublication;
use App\Services\Attendance\MonthCloseService;
use App\Services\Attendance\MonthMutationLock;
use App\Services\BatchOperation;
use App\Services\ApiException;
use App\Services\ImportPreviewFingerprint;
use App\Services\Leave\LeaveApplyService;
use App\Services\NoticeService;
use App\Services\NotificationMailService;
use App\Services\Payroll\PayrollStatementStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BusinessWorkflowSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'VERIFY-1','name'=>'検証 職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-07-01',
            'created_at'=>now(),'updated_at'=>now()]);
    }

    private function statement(int $employeeId, string $publishedAt): array
    {
        return app(PayrollStatementStorageService::class)->upsertBinaryForAdmin($employeeId, 'PAYROLL', '2026-07',
            CarbonImmutable::parse($publishedAt), 'verification.pdf', 'application/pdf', "%PDF-1.4\nverification",
            new GenericUser(['id'=>1,'role'=>'ADMIN']));
    }

    public function test_pdf_replacement_rollback_preserves_old_file_and_all_database_rows(): void
    {
        Storage::fake('local');
        $id = $this->employee();
        $old = $this->statement($id, '2026-07-25');
        $path = DB::table('payroll_statements')->where('id',$old['id'])->value('file_path');
        $jobs = DB::table('jobs')->count();
        try {
            DB::transaction(function () use ($id): void {
                $this->statement($id, '2026-07-25');
                throw new \RuntimeException('Simulated line/batch-item failure');
            });
        } catch (\RuntimeException) {}
        $this->assertSame($path, DB::table('payroll_statements')->where('id',$old['id'])->value('file_path'));
        Storage::disk('local')->assertExists($path);
        $this->assertSame($jobs, DB::table('jobs')->count());
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_future_payroll_is_hidden_then_visible_once_in_list_and_unread_count(): void
    {
        Storage::fake('local'); Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 12:00:00'));
        $id = $this->employee();
        $statement = $this->statement($id, '2026-07-25');
        $this->assertSame('SCHEDULED',$statement['notificationStatus']);
        $this->assertDatabaseHas('jobs',['queue'=>'staffhub-notifications','available_at'=>CarbonImmutable::parse('2026-07-25')->timestamp]);
        $notices = app(NoticeService::class);
        $this->assertSame(0,$notices->listForEmployee($id,[])['meta']['total']);
        $this->assertSame(0,$notices->unreadCountForEmployee($id));
        $this->travelTo(CarbonImmutable::parse('2026-07-25 00:00:01'));
        $rows=$notices->listForEmployee($id,[]);
        $this->assertSame(1,$rows['meta']['total']);
        $this->assertSame(1,$notices->unreadCountForEmployee($id));
        $notices->markRead($id,$rows['items'][0]['id'],$rows['items'][0]['sourceType']);
        $this->assertSame(0,$notices->unreadCountForEmployee($id));
        Http::assertNothingSent();
    }

    public function test_replaced_publication_job_does_not_send_an_outdated_notice(): void
    {
        Storage::fake('local'); Http::preventStrayRequests();
        $id=$this->employee();$statement=$this->statement($id,'2026-07-25');
        (new SendPayrollPublication($statement['id'],'old-file.pdf','2026-07-25 00:00:00'))->handle(app(NotificationMailService::class));
        Http::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs',['action'=>'PAYROLL_NOTIFICATION_SENT']);
    }

    public function test_publication_waits_then_retries_only_the_notification_with_the_same_request_id(): void
    {
        Storage::fake('local'); Http::preventStrayRequests();
        $scope='https://www.googleapis.com/auth/chat.bot';
        $credentials='verification-only.credentials.json';
        config()->set('staffhub.google_chat.enabled',true);
        config()->set('staffhub.google_chat.credentials_path',$credentials);
        config()->set('staffhub.google_chat.bot_scope',$scope);
        Cache::put('staffhub_google_chat_token_'.sha1($credentials.'|'.$scope),
            ['access_token'=>'isolated-test-token','expires_at'=>time()+3600],3600);
        Http::fake([
            'https://chat.googleapis.com/v1/spaces:findDirectMessage*'=>Http::response(['name'=>'spaces/verification']),
            'https://chat.googleapis.com/v1/spaces/verification/messages*'=>Http::sequence()
                ->push(['error'=>['message'=>'simulated outage']],503)
                ->push(['name'=>'spaces/verification/messages/saved'],200),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-07-20 12:00:00'));
        $id=$this->employee();
        DB::table('employees')->where('id',$id)->update(['google_chat_user_id'=>'users/verification']);
        $saved=$this->statement($id,'2026-07-25');
        $row=DB::table('payroll_statements')->where('id',$saved['id'])->first();
        $job=new SendPayrollPublication($row->id,$row->file_path,$row->published_at);
        $service=app(NotificationMailService::class);
        $job->handle($service);
        Http::assertNothingSent();
        $this->travelTo(CarbonImmutable::parse('2026-07-25 00:00:01'));
        try { $job->handle($service); $this->fail('A failed notification must remain retryable'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('給与は保存済み',$error->getMessage()); }
        $this->assertDatabaseHas('audit_logs',['action'=>'PAYROLL_NOTIFICATION_INCOMPLETE','target_id'=>$job->eventId()]);
        $this->assertDatabaseCount('payroll_statements',1);
        Storage::disk('local')->assertExists($row->file_path);
        $job->handle($service);
        $job->handle($service);
        $sends=Http::recorded(fn($request)=>$request->method()==='POST')->map(fn($pair)=>$pair[0]->url())->values()->all();
        $this->assertCount(2,$sends);
        $this->assertSame($sends[0],$sends[1]);
        parse_str(parse_url($sends[0],PHP_URL_QUERY),$query);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($query['requestId']));
        $this->assertSame(1,DB::table('audit_logs')->where('action','PAYROLL_NOTIFICATION_SENT')->where('target_id',$job->eventId())->count());
        $this->assertSame($row->file_path,DB::table('payroll_statements')->where('id',$row->id)->value('file_path'));
    }

    public function test_cross_month_leave_cannot_touch_a_closed_second_month(): void
    {
        $id=$this->employee();
        DB::table('attendance_monthly_closes')->insert(['target_year_month'=>'2026-08','status'=>'CLOSED','created_at'=>now(),'updated_at'=>now()]);
        try {
            app(LeaveApplyService::class)->createForEmployee($id,['leaveTypeCode'=>'ABSENCE','startDate'=>'2026-07-31','endDate'=>'2026-08-01','dayUnit'=>'FULL']);
            $this->fail('closed month accepted');
        } catch (ApiException $error) { $this->assertSame('CLOSED_PERIOD',$error->errorCode); }
        $this->assertDatabaseCount('leave_requests',0);
        $this->assertDatabaseMissing('attendance_monthly_closes',['target_year_month'=>'2026-07']);
    }

    public function test_payroll_errors_block_all_rows_and_retries_preserve_no_statement_or_notice(): void
    {
        Storage::fake('local'); Http::preventStrayRequests();
        $id=$this->employee();
        $catalog=app(\App\Services\PayrollTemplateCatalog::class);
        $row=$catalog->config('PAYROLL')['sampleRow'];
        $row[0]='VERIFY-1';$row[1]='検証';$row[2]='職員';
        $unknown=$row;$unknown[0]='NO-SUCH-STAFF';
        $stream=fopen('php://temp','w+');
        foreach([$catalog->headers('PAYROLL'),$row,$unknown] as $values){fputcsv($stream,$values,',','"','');}
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        $file=UploadedFile::fake()->createWithContent('partial.csv',$csv);
        $payload=['statementType'=>'PAYROLL','targetYearMonth'=>'2026-07','periodStartOn'=>'2026-07-01',
            'periodEndOn'=>'2026-07-31','payDate'=>'2026-07-25','publishDate'=>'2026-07-25'];
        $service=app(\App\Services\Payroll\PayrollImportBatchImportService::class);
        $preview=$service->previewFromCsv($payload,$file);
        $this->assertSame(1,$preview['importedCount']);$this->assertSame(1,$preview['errorCount']);
        foreach([1,2] as $attempt){
            try { $service->importFromCsv($payload,$file,new GenericUser(['id'=>1,'role'=>'ADMIN'])); $this->fail('Error CSV was imported'); }
            catch (ApiException $error) { $this->assertSame('PAYROLL_IMPORT_ERRORS',$error->errorCode); }
            $this->assertDatabaseCount('payroll_statements',0);
            $this->assertDatabaseCount('payroll_statement_lines',0);
            $this->assertDatabaseCount('payroll_import_batches',0);
            $this->assertDatabaseCount('payroll_import_batch_items',0);
        }
        $this->assertSame(0,app(NoticeService::class)->listForEmployee($id,[])['meta']['total']);
        Http::assertNothingSent();
    }

    public function test_batch_reports_committed_and_failed_items_individually(): void
    {
        $result=BatchOperation::run([1,2,3],function ($id) {
            if ($id===2) throw new ApiException('CLOSED_PERIOD','締め済み',422);
            return ['id'=>$id,'status'=>'APPROVED'];
        });
        $this->assertSame(2,$result['updatedCount']);$this->assertSame(1,$result['errorCount']);
        $this->assertSame([1,3],array_column($result['items'],'id'));$this->assertSame(2,$result['errors'][0]['id']);
    }

    public function test_preview_rejects_changed_file_or_month(): void
    {
        $file=UploadedFile::fake()->createWithContent('test.csv',"code,name\n1,test");
        $payload=['targetYearMonth'=>'2026-07','file'=>$file];
        $fingerprint=ImportPreviewFingerprint::make($payload,$file);
        ImportPreviewFingerprint::assertMatches($fingerprint,$payload,$file);
        $this->expectException(ApiException::class);
        ImportPreviewFingerprint::assertMatches($fingerprint,['targetYearMonth'=>'2026-08'],$file);
    }

    public function test_api_errors_return_json_without_accept_header(): void
    {
        $this->get('/api/no-such-endpoint')->assertStatus(404)->assertJsonPath('error.code','HTTP_404');
    }

    public function test_daily_csv_preserves_employee_scope_and_includes_rows_after_page_fifty(): void
    {
        $id=$this->employee();
        $other=DB::table('employees')->insertGetId(['employee_code'=>'OTHER','name'=>'対象外','employment_type'=>'FULL_TIME',
            'status'=>'ACTIVE','joined_on'=>'2026-07-01','created_at'=>now(),'updated_at'=>now()]);
        foreach(\Carbon\CarbonPeriod::create('2026-07-01','2026-08-30') as $date) {
            DB::table('attendance_daily')->insert(['employee_id'=>$id,'target_date'=>$date->format('Y-m-d'),
                'approval_status'=>'APPROVED','close_status'=>'OPEN','updated_at'=>now()]);
        }
        DB::table('attendance_daily')->insert(['employee_id'=>$other,'target_date'=>'2026-07-01','approval_status'=>'APPROVED','close_status'=>'OPEN','updated_at'=>now()]);
        $csv=app(\App\Services\ReportService::class)->exportDailyAttendanceCsv(['from'=>'2026-07-01','to'=>'2026-08-30','employeeCode'=>'VERIFY-1']);
        $this->assertSame(61,substr_count($csv,'VERIFY-1'));
        $this->assertStringNotContainsString('OTHER',$csv);
    }

    public function test_file_save_failure_does_not_create_payroll_or_notifications(): void
    {
        $id=$this->employee();
        $disk=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try { $this->statement($id,'2026-07-25'); $this->fail('File failure accepted'); }
        catch(ApiException $error) { $this->assertSame('FILE_UPLOAD_ERROR',$error->errorCode); }
        $this->assertDatabaseCount('payroll_statements',0);$this->assertDatabaseCount('notices',0);$this->assertDatabaseCount('jobs',0);
    }

    public function test_close_rechecks_blocks_later_writes_and_records_a_single_history_for_retry(): void
    {
        $id=$this->employee();
        DB::table('employees')->where('id',$id)->update(['retired_on'=>'2026-07-01']);
        DB::table('attendance_daily')->insert(['employee_id'=>$id,'target_date'=>'2026-07-01',
            'clock_in_at'=>'2026-07-01 09:00:00','clock_out_at'=>'2026-07-01 18:00:00','break_minutes'=>60,
            'work_minutes'=>480,'approval_status'=>'APPROVED','close_status'=>'OPEN','updated_at'=>now()]);
        $actor=new GenericUser(['id'=>1,'role'=>'ADMIN']);$service=app(MonthCloseService::class);
        $this->assertTrue($service->monthClosePrecheck('2026-07')['canClose']);
        $service->updateMonthlyClose('2026-07','CLOSED','確認済み',$actor);
        $service->updateMonthlyClose('2026-07','CLOSED','確認済み',$actor);
        $this->assertSame(1,DB::table('audit_logs')->where('action','ATTENDANCE_MONTH_CLOSE')->count());
        try {app(\App\Services\Attendance\DailyEditService::class)->createDaily($id,'2026-07-02',$actor);$this->fail('Closed month accepted a write');}
        catch(ApiException $error){$this->assertSame('CLOSED_PERIOD',$error->errorCode);}
        $service->updateMonthlyClose('2026-07','OPEN','専用環境の解除確認',$actor);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$id,'close_status'=>'OPEN']);
    }
}
