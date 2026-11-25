<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_by_login_id_and_ip(): void
    {
        $payload = [
            'loginId' => 'unknown@example.invalid',
            'password' => 'incorrect-password',
            'audience' => 'ADMIN',
        ];

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(401);
        }

        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_password_reset_requests_are_rate_limited_by_email_and_ip(): void
    {
        $payload = ['email' => 'unknown@example.invalid'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/password/forgot', $payload)->assertOk();
        }

        $this->postJson('/api/auth/password/forgot', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_refresh_requests_are_rate_limited_by_token_and_ip(): void
    {
        $payload = ['refreshToken' => 'invalid-refresh-token'];

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/auth/refresh', $payload)->assertStatus(401);
        }

        $this->postJson('/api/auth/refresh', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }
}
