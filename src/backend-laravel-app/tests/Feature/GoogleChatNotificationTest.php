<?php

namespace Tests\Feature;

use App\Services\GoogleChatNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GoogleChatNotificationTest extends TestCase
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

    public function test_it_can_send_direct_message_to_employee_chat_user(): void
    {
        $this->configureGoogleChat();

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
                'name' => 'spaces/AAAAemployeeDm/messages/msg-001',
            ]),
        ]);

        $result = app(GoogleChatNotificationService::class)->sendDirectMessage(
            '123456789',
            '休暇申請が承認されました',
            ['確認しました。'],
            ['申請ID' => '55']
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('sent', $result['status']);
        $this->assertSame('spaces/AAAAemployeeDm', $result['space']);
        $this->assertSame('spaces/AAAAemployeeDm/messages/msg-001', $result['message_name']);

        Http::assertSentCount(3);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://chat.googleapis.com/v1/spaces:findDirectMessage?name=users%2F123456789'
                && $request->hasHeader('Authorization', 'Bearer chat-token');
        });

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAemployeeDm/messages?requestId=')
                && ($payload['text'] ?? null) === "*休暇申請が承認されました*\n\n確認しました。\n\n申請ID: 55";
        });
    }

    public function test_it_can_send_admin_space_message(): void
    {
        $this->configureGoogleChat([
            'staffhub.google_chat.admin_space_id' => 'AAAAadminSpace',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'chat-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages*' => Http::response([
                'name' => 'spaces/AAAAadminSpace/messages/msg-002',
            ]),
        ]);

        $result = app(GoogleChatNotificationService::class)->sendAdminSpaceMessage(
            '休暇申請が届きました',
            ['E001 山田 花子さんから申請がありました。'],
            ['申請ID' => '81']
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('sent', $result['status']);
        $this->assertSame('spaces/AAAAadminSpace', $result['space']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://chat.googleapis.com/v1/spaces/AAAAadminSpace/messages?requestId=');
        });
    }

    public function test_it_skips_when_google_chat_is_disabled(): void
    {
        config()->set('staffhub.google_chat.enabled', false);
        Http::fake();

        $result = app(GoogleChatNotificationService::class)->sendAdminSpaceMessage(
            'テスト通知',
            ['これは送られません。']
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('admin_space_not_configured', $result['reason']);

        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureGoogleChat(array $overrides = []): void
    {
        $this->credentialsPath = $this->createServiceAccountCredentialsFile();

        config()->set('staffhub.google_chat.enabled', true);
        config()->set('staffhub.google_chat.credentials_path', $this->credentialsPath);
        config()->set('staffhub.google_chat.bot_scope', 'https://www.googleapis.com/auth/chat.bot');
        config()->set('staffhub.google_chat.message_timeout_seconds', 10);
        config()->set('staffhub.google_chat.admin_space_id', 'spaces/AAAAadminSpace');
        config()->set('staffhub.google_chat.all_staff_space_id', 'spaces/AAAAallStaffSpace');

        foreach ($overrides as $key => $value) {
            config()->set($key, $value);
        }
    }

    private function createServiceAccountCredentialsFile(): string
    {
        $path = storage_path('framework/testing-google-chat-' . uniqid('', true) . '.json');
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
