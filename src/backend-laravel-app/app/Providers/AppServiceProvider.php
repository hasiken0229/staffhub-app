<?php

namespace App\Providers;

use App\Services\ApiResponse;
use App\Support\EnvironmentSafetyGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\TransactionFileGuard::class);
        if (filter_var(env('STAFFHUB_E2E', false), FILTER_VALIDATE_BOOL)) {
            \App\Support\VerificationEnvironmentGuard::assertSafe([
                'environment' => config('app.env'), 'url' => config('app.url'),
                'root' => env('STAFFHUB_VERIFICATION_ROOT'), 'storage' => storage_path(),
                'driver' => config('database.default'),
                'database' => config('database.connections.'.config('database.default')),
                'chatEnabled' => config('staffhub.google_chat.enabled'),
                'chatCredentials' => config('staffhub.google_chat.credentials_path'),
                'mailer' => config('mail.default'),
            ]);
            // Every outbound Laravel HTTP call is rejected, including accidental live Chat calls.
            \Illuminate\Support\Facades\Http::preventStrayRequests();
        }
        EnvironmentSafetyGuard::assertSafeLocalServer(
            (string) env('APP_ENV', 'production'),
            (string) env('DB_HOST', ''),
            $_SERVER['argv'] ?? [],
            filter_var(env('STAFFHUB_ALLOW_REMOTE_DATABASE', false), FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tooManyRequests = static fn (Request $request, array $headers) => ApiResponse::error(
            'RATE_LIMITED',
            'アクセスが集中しています。しばらくしてから再試行してください。',
            429,
        )->withHeaders($headers);

        RateLimiter::for('auth-login', static function (Request $request) use ($tooManyRequests) {
            $loginId = mb_strtolower(trim((string) $request->input('loginId')));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(8)->by("login:{$loginId}|{$ip}")->response($tooManyRequests),
                Limit::perMinute(60)->by("login-ip:{$ip}")->response($tooManyRequests),
            ];
        });

        RateLimiter::for('auth-refresh', static function (Request $request) use ($tooManyRequests) {
            $refreshToken = trim((string) $request->input('refreshToken'));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(30)->by("refresh-token:{$refreshToken}|{$ip}")->response($tooManyRequests),
                Limit::perMinute(120)->by("refresh-ip:{$ip}")->response($tooManyRequests),
            ];
        });

        RateLimiter::for('password-forgot', static function (Request $request) use ($tooManyRequests) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinutes(15, 5)->by("password-forgot:{$email}|{$ip}")->response($tooManyRequests),
                Limit::perMinutes(15, 20)->by("password-forgot-ip:{$ip}")->response($tooManyRequests),
            ];
        });

        RateLimiter::for('password-reset', static function (Request $request) use ($tooManyRequests) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(10)->by("password-reset:{$email}|{$ip}")->response($tooManyRequests),
                Limit::perMinute(60)->by("password-reset-ip:{$ip}")->response($tooManyRequests),
            ];
        });

        RateLimiter::for('attendance-device', static function (Request $request) use ($tooManyRequests) {
            $deviceCode = trim((string) $request->input('deviceCode'));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(60)->by("attendance-device:{$deviceCode}|{$ip}")->response($tooManyRequests),
                Limit::perMinute(600)->by("attendance-ip:{$ip}")->response($tooManyRequests),
            ];
        });
    }
}
