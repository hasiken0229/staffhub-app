<?php

namespace Tests\Feature;

use App\Services\NoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NotificationMailFlowTest extends TestCase
{
    use RefreshDatabase;

    private ?string $credentialsPath = null;

    protected function tearDown(): void
    {
        if ($this->credentialsPath !== null && is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    public function test_admin_notice_without_target_employee_sends_to_all_staff_space(): void
    {
        $this->configureGoogleChat();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAallStaffSpace/messages*' => Http::response([
                'name' => 'spaces/AAAAallStaffSpace/messages/msg-001',
            ]),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/notices', [
                'noticeType' => 'GENERAL',
                'title' => '4月のお知らせ',
                'body' => '新年度の書類提出をお願いします。',
                'publishStartAt' => '2026-04-01T09:00:00+09:00',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', '4月のお知らせ')
            ->assertJsonPath('data.targetEmployeeId', null);

        Http::assertNothingSent();
        (new \App\Jobs\SendNoticePublication((int) $response->json('data.id')))->handle(app(\App\Services\NotificationMailService::class));

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $text = (string) ($payload['text'] ?? '');

            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAallStaffSpace/messages?requestId=')
                && str_contains($text, '*4月のお知らせ*')
                && str_contains($text, '新年度の書類提出をお願いします。')
                && str_contains($text, '通知区分: GENERAL');
        });

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'ADMIN',
            'actor_id' => $adminId,
            'action' => 'NOTICE_CREATED',
            'target_type' => 'NOTICE',
        ]);
    }

    public function test_payroll_publication_notice_sends_chat_after_commit(): void
    {
        $this->configureGoogleChat();

        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'E010',
            'name' => '鈴木 一郎',
            'kana' => 'スズキ イチロウ',
            'department_name' => '事務',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => 'users/1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response([
                'name' => 'spaces/AAAAemployeeDm',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages*' => Http::response([
                'name' => 'spaces/AAAAemployeeDm/messages/msg-002',
            ]),
        ]);

        DB::transaction(function () use ($employeeId): void {
            app(NoticeService::class)->createPayrollPublicationNotice(
                $employeeId,
                'PAYROLL',
                '2026-03',
                55,
                null,
            );
        });

        $this->assertDatabaseHas('notifications', [
            'employee_id' => $employeeId,
            'notification_type' => 'PAYROLL_PUBLISHED',
            'related_type' => 'PAYROLL_STATEMENT',
            'related_id' => 55,
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $text = (string) ($payload['text'] ?? '');

            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages?requestId=')
                && str_contains($text, '*給与明細を公開しました*')
                && str_contains($text, '2026-03 の給与明細を確認できます。')
                && str_contains($text, '対象職員: E010 鈴木 一郎')
                && str_contains($text, '関連ID: 55');
        });
    }

    public function test_publication_job_retries_only_notification_with_the_same_event_id(): void
    {
        $this->configureGoogleChat();
        \Illuminate\Support\Facades\Storage::fake('local');
        $employeeId=DB::table('employees')->insertGetId(['employee_code'=>'VERIFY-JOB','name'=>'通知検証',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-01-01','google_chat_user_id'=>'123456789','created_at'=>now(),'updated_at'=>now()]);
        DB::table('employee_auth')->insert(['employee_id'=>$employeeId,'login_id'=>'notification@example.test',
            'password_hash'=>Hash::make('VerifyOnly-2026!'),'created_at'=>now(),'updated_at'=>now()]);
        Http::fake([
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test-token','expires_in'=>3600,'token_type'=>'Bearer']),
            'https://chat.googleapis.com/v1/spaces:findDirectMessage*'=>Http::response(['name'=>'spaces/AAAAemployeeDm']),
            'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages*'=>Http::sequence()
                ->push(['error'=>['message'=>'temporary']],503)->push(['name'=>'spaces/AAAAemployeeDm/messages/verified'],200),
        ]);
        $saved=app(\App\Services\Payroll\PayrollStatementStorageService::class)->upsertBinaryForAdmin($employeeId,'PAYROLL','2026-07',
            \Carbon\CarbonImmutable::parse('2026-07-25'),'verify.pdf','application/pdf',"%PDF-1.4\nverified",new \Illuminate\Auth\GenericUser(['id'=>1,'role'=>'ADMIN']));
        $statement=DB::table('payroll_statements')->where('id',$saved['id'])->first();
        $job=new \App\Jobs\SendPayrollPublication($statement->id,$statement->file_path,$statement->published_at);
        try { $job->handle(app(\App\Services\NotificationMailService::class)); $this->fail('Expected temporary delivery failure'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('給与は保存済み',$error->getMessage()); }
        $job->handle(app(\App\Services\NotificationMailService::class));
        $job->handle(app(\App\Services\NotificationMailService::class));
        $sent=Http::recorded(fn($request)=>str_contains($request->url(),'/messages?requestId='))->values();
        $this->assertCount(2,$sent);
        $this->assertSame($sent[0][0]->url(),$sent[1][0]->url());
        $this->assertDatabaseCount('payroll_statements',1);
        $this->assertSame(1,DB::table('audit_logs')->where('action','PAYROLL_NOTIFICATION_SENT')->count());
    }

    public function test_leave_decision_sends_chat_to_employee_direct_message(): void
    {
        $this->configureGoogleChat();

        DB::table('users')->insert([
            'name' => '承認者',
            'email' => 'approver@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'E020',
            'name' => '佐藤 次郎',
            'kana' => 'サトウ ジロウ',
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 10,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestId = DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type_code' => 'PAID',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-10',
            'day_unit' => 'FULL',
            'half_day_type' => null,
            'quantity_days' => 1,
            'reason' => '私用',
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'decision_comment' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response([
                'name' => 'spaces/AAAAemployeeDm',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages*' => Http::response([
                'name' => 'spaces/AAAAemployeeDm/messages/msg-003',
            ]),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'approver@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/leave/requests/' . $requestId . '/approve', [
                'comment' => '確認しました。',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('notifications', [
            'employee_id' => $employeeId,
            'notification_type' => 'LEAVE_APPROVED',
            'related_type' => 'LEAVE_REQUEST',
            'related_id' => $requestId,
        ]);

        Http::assertSent(function ($request) use ($requestId) {
            $payload = $request->data();
            $text = (string) ($payload['text'] ?? '');

            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages?requestId=')
                && str_contains($text, '*休暇申請が承認されました*')
                && str_contains($text, '確認しました。')
                && str_contains($text, '対象職員: E020 佐藤 次郎')
                && str_contains($text, '判定: APPROVED')
                && str_contains($text, '申請ID: ' . $requestId);
        });
    }

    public function test_leave_request_creation_sends_admin_space_notification(): void
    {
        $this->configureGoogleChat();

        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E030',
            'name' => '高橋 三郎',
            'kana' => 'タカハシ サブロウ',
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => 'users/1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'staff030',
            'password_hash' => Hash::make('Staff1234!'),
            'password_updated_at' => now(),
            'last_login_at' => null,
            'mobile_push_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => 10,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => '初期付与',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages*' => Http::response([
                'name' => 'spaces/AAAAadminSpace/messages/msg-004',
            ]),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'loginId' => 'staff030',
            'password' => 'Staff1234!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/leave/requests', [
                'leaveTypeCode' => 'PAID',
                'startDate' => '2026-04-20',
                'endDate' => '2026-04-20',
                'dayUnit' => 'FULL',
                'reason' => '家庭の都合',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PENDING');

        $requestId = (int) $response->json('data.id');

        Http::assertSent(function ($request) use ($requestId) {
            $payload = $request->data();
            $text = (string) ($payload['text'] ?? '');

            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages?requestId=')
                && str_contains($text, '*休暇申請が届きました*')
                && str_contains($text, '高橋 三郎さんから休暇申請がありました。')
                && str_contains($text, '対象職員: E030 高橋 三郎')
                && str_contains($text, '休暇区分: 有給休暇')
                && str_contains($text, '申請ID: ' . $requestId);
        });
    }

    public function test_short_interval_punch_is_rejected_without_admin_space_notification(): void
    {
        $this->configureGoogleChat();

        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E040',
            'name' => '中村 四郎',
            'kana' => 'ナカムラ シロウ',
            'department_name' => '事務',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (int) DB::table('attendance_devices')->insertGetId([
            'device_code' => 'RC-01',
            'name' => '玄関端末',
            'location_name' => '玄関',
            'os_user' => null,
            'app_version' => null,
            'device_secret_hash' => Hash::make('unused'),
            'last_seen_at' => null,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_cards')->insert([
            'employee_id' => $employeeId,
            'card_uid' => 'CARD-040',
            'is_active' => 1,
            'assigned_at' => '2024-04-01 00:00:00',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages*' => Http::response([
                'name' => 'spaces/AAAAadminSpace/messages/msg-005',
            ]),
        ]);

        $this->postJson('/api/attendance/punch', [
            'deviceCode' => 'RC-01',
            'deviceSecret' => 'unused',
            'cardUid' => 'CARD-040',
            'occurredAt' => '2026-04-18T09:00:00+09:00',
            'dedupeKey' => 'dedupe-first',
            'appVersion' => '1.0.0',
        ])->assertOk()
            ->assertJsonPath('data.resultType', 'SUCCESS');

        $response = $this->postJson('/api/attendance/punch', [
            'deviceCode' => 'RC-01',
            'deviceSecret' => 'unused',
            'cardUid' => 'CARD-040',
            'occurredAt' => '2026-04-18T09:01:00+09:00',
            'dedupeKey' => 'dedupe-second',
            'appVersion' => '1.0.0',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'SHORT_INTERVAL_PUNCH')
            ->assertJsonPath('error.message', '直前の打刻から3分以内のため、打刻を受け付けできません。');

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $employeeId,
            'device_id' => $deviceId,
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'SHORT_INTERVAL_PUNCH',
            'dedupe_key' => 'dedupe-second',
        ]);

        Http::assertNotSent(function ($request) {
            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages?requestId=');
        });
    }

    public function test_future_notice_sends_edited_content_once_at_publication_and_never_resends_after_edit(): void
    {
        $this->configureGoogleChat();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-10 10:00:00', 'Asia/Tokyo'));
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token'=>'chat-token','expires_in'=>3600,'token_type'=>'Bearer']),
            'https://chat.googleapis.com/v1/spaces/AAAAallStaffSpace/messages*' => Http::response(['name'=>'spaces/AAAAallStaffSpace/messages/future']),
        ]);
        $service=app(NoticeService::class); $actor=new \Illuminate\Auth\GenericUser(['id'=>1,'role'=>'ADMIN']);
        $payload=['noticeType'=>'GENERAL','title'=>'公開予定','body'=>'初稿','publishStartAt'=>'2026-10-11T09:00:00+09:00',
            'requestId'=>'47e16f63-7969-4bdb-87df-e252a1e0966e'];
        $notice=$service->storeForAdmin($payload,$actor);
        $job=new \App\Jobs\SendNoticePublication($notice['id']);
        $job->handle(app(\App\Services\NotificationMailService::class));
        Http::assertNothingSent();
        $service->updateForAdmin($notice['id'],[...$payload,'revision'=>1,'body'=>'公開時の本文'],$actor);
        $this->assertDatabaseCount('jobs',1);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-11 09:01:00', 'Asia/Tokyo'));
        $job->handle(app(\App\Services\NotificationMailService::class));
        $job->handle(app(\App\Services\NotificationMailService::class));
        $sent=Http::recorded(fn($request)=>str_contains($request->url(),'/messages?requestId='))->values();
        $this->assertCount(1,$sent); $this->assertStringContainsString('公開時の本文',$sent[0][0]->data()['text']);
        $this->assertStringContainsString(\Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL,$job->eventId())->toString(),$sent[0][0]->url());
        $service->updateForAdmin($notice['id'],[...$payload,'revision'=>2,'body'=>'送信後の編集'],$actor);
        $job->handle(app(\App\Services\NotificationMailService::class));
        $this->assertCount(1,Http::recorded(fn($request)=>str_contains($request->url(),'/messages?requestId=')));
        $this->assertDatabaseCount('jobs',1);
        $this->assertSame('SENT',$service->detailForAdmin($notice['id'])['notificationStatus']);
    }

    private function configureGoogleChat(): void
    {
        $this->credentialsPath = $this->createServiceAccountCredentialsFile();

        config()->set('staffhub.google_chat.enabled', true);
        config()->set('staffhub.google_chat.credentials_path', $this->credentialsPath);
        config()->set('staffhub.google_chat.bot_scope', 'https://www.googleapis.com/auth/chat.bot');
        config()->set('staffhub.google_chat.message_timeout_seconds', 10);
        config()->set('staffhub.google_chat.admin_space_id', 'spaces/AAAAadminSpace');
        config()->set('staffhub.google_chat.all_staff_space_id', 'spaces/AAAAallStaffSpace');
    }

    private function createServiceAccountCredentialsFile(): string
    {
        $path = storage_path('framework/testing-google-chat-flow-' . uniqid('', true) . '.json');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'staffhub-test',
            'private_key_id' => 'test-private-key',
            'private_key' => $privateKey,
            'client_email' => 'staffhub-test@staffhub-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
