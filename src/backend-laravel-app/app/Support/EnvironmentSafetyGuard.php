<?php

namespace App\Support;

use RuntimeException;

class EnvironmentSafetyGuard
{
    /**
     * Prevent a developer server from silently using the production database.
     * Production web requests and deliberate deployment commands are unaffected.
     */
    public static function assertSafeLocalServer(
        string $appEnvironment,
        string $databaseHost,
        array $arguments,
        bool $allowRemoteDatabase,
    ): void {
        if ($allowRemoteDatabase || strtolower($appEnvironment) !== 'production' || ! self::isServeCommand($arguments)) {
            return;
        }

        if (self::isLoopbackHost($databaseHost)) {
            return;
        }

        throw new RuntimeException(
            '安全のため、production 環境かつリモートDBを参照する php artisan serve は停止しました。'.
            ' ローカル開発では php artisan --env=local serve を使用してください。'.
            ' 意図したリモート接続の場合のみ STAFFHUB_ALLOW_REMOTE_DATABASE=true を明示してください。',
        );
    }

    private static function isServeCommand(array $arguments): bool
    {
        return in_array('serve', $arguments, true);
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['', '127.0.0.1', 'localhost', '::1'], true);
    }
}
