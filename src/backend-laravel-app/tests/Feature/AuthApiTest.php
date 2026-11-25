<?php

namespace Tests\Feature;

use App\Mail\SystemNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_fetch_profile_and_logout(): void
    {
        DB::table('users')->insert([
            'name' => '園長先生',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.role', 'ADMIN');

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_refresh_rotates_the_token_pair_and_keeps_the_session_active(): void
    {
        DB::table('users')->insert([
            'name' => '園長先生',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->assertOk();

        $accessToken = (string) $login->json('data.accessToken');
        $refreshToken = (string) $login->json('data.refreshToken');

        $refresh = $this->postJson('/api/auth/refresh', ['refreshToken' => $refreshToken])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'ADMIN');

        $nextAccessToken = (string) $refresh->json('data.accessToken');
        $nextRefreshToken = (string) $refresh->json('data.refreshToken');
        $this->assertNotSame($accessToken, $nextAccessToken);
        $this->assertNotSame($refreshToken, $nextRefreshToken);

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        $this->postJson('/api/auth/refresh', ['refreshToken' => $refreshToken])
            ->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$nextAccessToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@example.com');
    }

    public function test_employee_can_login_with_email_and_reset_password(): void
    {
        Mail::fake();
        config()->set('app.url', 'https://trusted.example.test/dakoku');

        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E900',
            'name' => '職員 太郎',
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'staff@example.com',
            'password_hash' => Hash::make('OldPass123!'),
            'password_updated_at' => now(),
            'last_login_at' => null,
            'mobile_push_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'loginId' => 'staff@example.com',
            'password' => 'OldPass123!',
            'audience' => 'EMPLOYEE',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'EMPLOYEE')
            ->assertJsonPath('data.user.employeeCode', 'E900');

        $this->withHeader('Origin', 'https://untrusted.example.invalid')
            ->postJson('/api/auth/password/forgot', [
                'email' => 'staff@example.com',
            ])->assertOk()
            ->assertJsonPath('data.success', true);

        $resetUrl = null;
        Mail::assertSent(SystemNotificationMail::class, function (SystemNotificationMail $mail) use (&$resetUrl): bool {
            $resetUrl = collect($mail->lines)->first(fn (string $line): bool => str_contains($line, 'resetToken='));

            return $mail->hasTo('staff@example.com') && str_starts_with((string) $resetUrl, 'https://trusted.example.test/dakoku/login/?');
        });

        parse_str((string) parse_url((string) $resetUrl, PHP_URL_QUERY), $query);
        $token = (string) ($query['resetToken'] ?? '');

        $this->postJson('/api/auth/password/reset', [
            'email' => 'staff@example.com',
            'token' => $token,
            'password' => 'NewPass123!',
            'passwordConfirmation' => 'NewPass123!',
        ])->assertOk()
            ->assertJsonPath('data.success', true);

        $this->postJson('/api/auth/login', [
            'loginId' => 'staff@example.com',
            'password' => 'OldPass123!',
            'audience' => 'EMPLOYEE',
        ])->assertStatus(401);

        $this->postJson('/api/auth/login', [
            'loginId' => 'staff@example.com',
            'password' => 'NewPass123!',
            'audience' => 'EMPLOYEE',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'EMPLOYEE');
    }
}
