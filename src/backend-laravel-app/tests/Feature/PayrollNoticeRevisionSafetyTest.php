<?php

namespace Tests\Feature;

use App\Jobs\SendNoticePublication;
use App\Services\ApiException;
use App\Services\NoticeService;
use App\Services\NotificationMailService;
use App\Services\Payroll\PayrollImportBatchImportService;
use App\Services\Payroll\PayrollStatementStorageService;
use App\Services\PayrollStatementService;
use App\Services\PayrollTemplateCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PayrollNoticeRevisionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'Asia/Tokyo'));
    }

    private function actor(): GenericUser { return new GenericUser(['id' => 1, 'role' => 'ADMIN']); }
    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId(['employee_code' => $code, 'name' => '検証 '.$code,
            'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now()]);
    }
    private function payroll(int $employeeId, string $month = '2026-10', ?CarbonImmutable $publication = null): array
    {
        return app(PayrollStatementStorageService::class)->upsertBinaryForAdmin($employeeId, 'PAYROLL', $month,
            $publication ?? CarbonImmutable::now()->subDay(), 'verify.pdf', 'application/pdf', "%PDF-1.4\nverify", $this->actor(),
            ['payDate' => '2026-10-28', 'periodStartOn' => '2026-10-01', 'periodEndOn' => '2026-10-31']);
    }
    private function payload(): array
    {
        return ['statementType' => 'PAYROLL', 'targetYearMonth' => '2026-10', 'periodStartOn' => '2026-10-01',
            'periodEndOn' => '2026-10-31', 'payDate' => '2026-10-28', 'publishDate' => '2026-10-27'];
    }
    private function csv(array $codes, ?string $invalidAmount = null): UploadedFile
    {
        $catalog = app(PayrollTemplateCatalog::class);
        $headers = $catalog->headers('PAYROLL');
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($codes as $code) {
            $row = $catalog->config('PAYROLL')['sampleRow']; $row[0] = $code; $row[1] = '検証'; $row[2] = $code;
            if ($invalidAmount !== null) $row[(int) array_search('総支給額', $headers, true)] = $invalidAmount;
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream); $content = stream_get_contents($stream); fclose($stream);
        return UploadedFile::fake()->createWithContent('verify.csv', $content);
    }
    private function adminToken(): string
    {
        DB::table('users')->insert(['name' => '検証管理者', 'email' => 'payroll-safety@example.test',
            'password' => Hash::make('VerifyOnly-2026!'), 'created_at' => now(), 'updated_at' => now()]);
        return $this->postJson('/api/auth/login', ['loginId' => 'payroll-safety@example.test',
            'password' => 'VerifyOnly-2026!', 'audience' => 'ADMIN'])->json('data.accessToken');
    }

    public function test_preview_reports_new_replacements_totals_and_refuses_a_stale_replacement(): void
    {
        $first = $this->employee('SAFETY-1'); $this->employee('SAFETY-2');
        $this->payroll($first);
        $token = $this->adminToken();
        $preview = $this->withToken($token)->post('/api/admin/payroll/import-batches/preview',
            [...$this->payload(), 'file' => $this->csv(['SAFETY-1', 'SAFETY-2'])]);
        $preview->assertOk()->assertJsonPath('data.canImport', true)->assertJsonPath('data.newCount', 1)
            ->assertJsonPath('data.replacementCount', 1)->assertJsonPath('data.items.0.action', 'REPLACE')
            ->assertJsonPath('data.items.0.revision', 2)->assertJsonPath('data.items.1.action', 'CREATE');
        $this->assertEquals(array_sum(array_column($preview->json('data.items'), 'netAmount')), $preview->json('data.totals.netAmount'));
        $this->payroll($first);
        $this->withToken($token)->post('/api/admin/payroll/import-batches', [...$this->payload(),
            'previewFingerprint' => $preview->json('data.previewFingerprint'), 'file' => $this->csv(['SAFETY-1', 'SAFETY-2'])])
            ->assertStatus(409)->assertJsonPath('error.code', 'PREVIEW_CHANGED');
        $this->assertDatabaseCount('payroll_import_batches', 0);
        $this->assertDatabaseCount('payroll_statements', 1);
        $this->assertSame(2, (int) DB::table('payroll_statements')->value('revision'));
    }

    public function test_duplicate_unknown_and_invalid_amount_csvs_write_no_batch_or_payroll(): void
    {
        $this->employee('SAFETY-1');
        $service = app(PayrollImportBatchImportService::class);
        foreach ([$this->csv(['SAFETY-1', 'SAFETY-1']), $this->csv(['SAFETY-1', 'MISSING']), $this->csv(['SAFETY-1'], '30O000')] as $file) {
            $preview = $service->previewFromCsv($this->payload(), $file);
            $this->assertFalse($preview['canImport']); $this->assertGreaterThan(0, $preview['errorCount']);
            try { $service->importFromCsv($this->payload(), $file, $this->actor()); $this->fail('Invalid CSV imported'); }
            catch (ApiException $error) { $this->assertSame('PAYROLL_IMPORT_ERRORS', $error->errorCode); }
        }
        foreach (['payroll_import_batches', 'payroll_import_batch_items', 'payroll_statements', 'notices', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_replacement_keeps_old_view_history_but_starts_current_revision_unread(): void
    {
        $employee = $this->employee('SAFETY-1'); $other = $this->employee('SAFETY-2');
        $saved = $this->payroll($employee); $id = $saved['id']; $service = app(PayrollStatementService::class);
        $service->markViewed($employee, $id, '2026-10-10T01:30:00Z', 1);
        $this->assertTrue($service->listForEmployee($employee, [])['items'][0]['viewed']);
        $replaced = $this->payroll($employee);
        $this->assertSame(2, $replaced['revision']); $this->assertNotNull($replaced['replacedAt']);
        $this->assertFalse($service->listForEmployee($employee, [])['items'][0]['viewed']);
        $this->assertSame(0, $service->detailForAdmin($id)['viewCount']);
        $this->assertDatabaseHas('payroll_statement_views', ['payroll_statement_id' => $id, 'revision' => 1, 'viewed_at' => '2026-10-10 10:30:00']);
        try { $service->markViewed($employee, $id, now()->toIso8601String(), 1); $this->fail('Stale view recorded'); }
        catch (ApiException $error) { $this->assertSame('PAYROLL_REVISION_CHANGED', $error->errorCode); }
        $service->markViewed($employee, $id, now()->toIso8601String(), 2);
        $this->assertSame(1, $service->detailForAdmin($id)['viewCount']);
        $this->assertDatabaseCount('payroll_statement_views', 2);
        foreach ([[$other, $id], [$employee, $this->payroll($employee, '2026-11', CarbonImmutable::now()->addDay())['id']]] as [$owner, $hidden]) {
            try { $service->markViewed($owner, $hidden, now()->toIso8601String(), 1); $this->fail('Hidden statement viewed'); }
            catch (ApiException $error) { $this->assertSame('NOT_FOUND', $error->errorCode); }
        }
        $this->assertSame('2026-10-28', $service->detailForAdmin($id)['payDate']);
    }

    private function noticePayload(): array
    {
        return ['noticeType' => 'GENERAL', 'title' => '予定のお知らせ', 'body' => "本文\n詳細", 'publishStartAt' => '2026-10-11T00:00:00Z',
            'publishEndAt' => '2026-10-12T09:00:00+09:00', 'requestId' => '6b244848-46ea-4bca-ae03-5151d847bb73'];
    }

    public function test_notice_retries_are_idempotent_and_stopping_hides_content_and_cancels_publication(): void
    {
        $employee = $this->employee('SAFETY-1'); $service = app(NoticeService::class); $payload = $this->noticePayload();
        $created = $service->storeForAdmin($payload, $this->actor());
        $this->assertSame('SCHEDULED', $created['publicationStatus']);
        $this->assertStringContainsString('2026-10-11T09:00:00+09:00', $created['publishStartAt']);
        $this->assertTrue($service->storeForAdmin($payload, $this->actor())['unchanged']);
        $this->assertDatabaseCount('notices', 1); $this->assertDatabaseCount('jobs', 1);
        try { $service->storeForAdmin([...$payload, 'title' => '別内容'], $this->actor()); $this->fail('Conflicting retry accepted'); }
        catch (ApiException $error) { $this->assertSame('REQUEST_ID_CONFLICT', $error->errorCode); }
        $updated = $service->updateForAdmin($created['id'], [...$payload, 'revision' => 1, 'body' => '編集した本文'], $this->actor());
        $this->assertSame(2, $updated['revision']); $this->assertDatabaseCount('jobs', 1);
        try { $service->updateForAdmin($created['id'], [...$payload, 'revision' => 1], $this->actor()); $this->fail('Stale edit accepted'); }
        catch (ApiException $error) { $this->assertSame('NOTICE_CHANGED', $error->errorCode); }
        $stopped = $service->stopForAdmin($created['id'], 2, $this->actor());
        $this->assertSame('STOPPED', $stopped['publicationStatus']); $this->assertSame('CANCELLED', $stopped['notificationStatus']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00:00', 'Asia/Tokyo'));
        $this->assertSame(0, $service->listForEmployee($employee, [])['meta']['total']);
        (new SendNoticePublication($created['id']))->handle(app(NotificationMailService::class));
        Http::assertNothingSent();
        try { $service->markRead($employee, $created['id'], 'NOTICE'); $this->fail('Stopped notice read accepted'); }
        catch (ApiException $error) { $this->assertSame('NOT_FOUND', $error->errorCode); }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NOTICE_CREATED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NOTICE_UPDATED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NOTICE_STOPPED')->count());
    }

    public function test_sent_notice_edits_do_not_queue_again_and_automatic_notices_are_read_only(): void
    {
        $employee = $this->employee('SAFETY-1'); $service = app(NoticeService::class);
        $created = $service->storeForAdmin($this->noticePayload(), $this->actor());
        DB::table('notices')->where('id', $created['id'])->update(['notification_sent_at' => now()]);
        $edited = $service->updateForAdmin($created['id'], [...$this->noticePayload(), 'revision' => 1, 'publishStartAt' => '2026-10-10T10:00:00+09:00'], $this->actor());
        $this->assertSame('SENT', $edited['notificationStatus']); $this->assertDatabaseCount('jobs', 1);
        $this->payroll($employee);
        $automatic = DB::table('notices')->where('notice_type', 'PAYROLL_PUBLISHED')->value('id');
        try { $service->stopForAdmin($automatic, 1, $this->actor()); $this->fail('Automatic notice stopped'); }
        catch (ApiException $error) { $this->assertSame('NOTICE_AUTOMATIC_READ_ONLY', $error->errorCode); }
        try { $service->storeForAdmin([...$this->noticePayload(), 'publishEndAt' => '2026-10-11T09:00:00+09:00'], $this->actor()); $this->fail('Invalid period accepted'); }
        catch (ApiException $error) { $this->assertSame('VALIDATION_ERROR', $error->errorCode); }
    }
}
