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
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'staffhub-test',
            'private_key_id' => 'test-private-key',
            'private_key' => <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEArWtWsZL+mOuOPm/wA+qfNlbJPNFJ+YT+i5lMPs60krKRlYSu
IlFsjfHdazB20tVP1N8n4Nf6Ht5wkK6eR5hNj7NyXvxxGuwbIdJVKCw3USmhMbaK
XlJqwSCfy5qJhRezeEyiMMd4zu8Ikspk3LaCUUGBKs8P5DNqeSstKm3RDwrvF7Lg
GhtE3vxRJx6wrw8ujPzpH1SR93Bsnk13WujN6XTugLV0N1R571GRFf2UV+1Ezydi
tM2imwa6LEKgYNiQCW7iKb0OaNpBtB6dJn/cnxInxDbMWqXRTf1sWJLH7MXlj68+
bX/VAIVLEHfWl0WaHtHdZggUpxmRjBCvChnI2QIDAQABAoIBACpu7VOeCDYazT9x
3GTY8AQ30B3ViChJ5o75/7IOmhibIQxY3tL+4XUKDYfA4BJOp64KvJNDxavv+dMt
JwWVusTCv+WGF5bi1vC7qqKdzxtI+GxVoh3aRMzk0rTbJ3MtjXiOJ9GPvXvE+XAR
ngRzlAeV46k56UWJXzAu5GpRXKo1N4Mll4KDK6cvf4yqL1hZETqMWpDhHrI0HiXx
Va9CwH/J1Ox6dgXU9TRfTEGPENRSVm5WsPF8dJMtKkPoqQL4HhAxF6HHCTIMjCqB
52nPrxCoaiHHpOUZVjW9f0Dti4caQ9knmVTIyga23DsTFLVt/nGMixxZFoLHcCt0
vET8F1ECgYEA2vcQw/6nOur5qAM3xOuOw4C4TaXCAmk5Xb8K+PWJttOn+q2W+h+9
g7xkeHXAVZdwBIIm/rE2zgDiQZDxDtrqdglnYzb8+Ym21+YpOD4vIN5XE4X6YOzW
Nbkbt2jZ6jT/qFKCXm9OmCO35XyhDaFIeYQSbGHfxo2W7oEz63h8678CgYEAysAx
TA/J5BzUAH47JbRsygInR4xJlGteUvlSRa6bkOHpfB79fLSg8E2L1T5sE9mpdayD
PmZaQwP9DHsWnUdVNon48IC5ZJxhcj+nVMGiAi5RCHVhKH7jirz89SMdHP04r74c
KzWmTiztiIo1Kwk68Tr6wOLil5mz4wtLfsSg0WcCgYATa/izucGxiygLzAVFVTN7
ic5PLNWxiw3Ij+p0PKszaUCsDnumwev4ZFxxBtjBfsYz0CuPSb63tQJcmHOZQrer
MphWB4mWxK1QJx0e3P0nKDGHDMoxkLBLYZjgws8ZZAwNLQxdPfg1rG1iUJSkddrM
1Hch3+iOXv61NwaY6z4BVwKBgQCR2tUb7LfGXF6+xBB0vWkNoaL0O52rIdpHQojH
DCdpgLtgyUJ99ctZU8/mZfOGDC12M9Zui18fmrztv5azKl/IzlTBXzj/gegwMk6E
EJAllYBB3383jKDQa8hl6Q7Gjfu7ob3N79hloLh4Y0SAYzF93HoLTKzJdo8MJFH5
LiaqvQKBgB3fjSXon417iCwi8Aej+9W8PSLyNpct/15VRUkhMoa0u23TDHatJEs8
B3NweT1kBiwodGQPTDgS4RQB11RaPHliqDuP4iNKKsPVoYQ7FD3YvWdVwRNU5Hnr
4S4bfT2ELnt4mYKMUIJfXO9thees52FMeS4np6ZuxlrjyZnmws3z
-----END RSA PRIVATE KEY-----
PEM,
            'client_email' => 'staffhub-test@staffhub-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
