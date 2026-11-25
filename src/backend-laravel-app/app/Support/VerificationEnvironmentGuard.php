<?php

namespace App\Support;

use RuntimeException;

final class VerificationEnvironmentGuard
{
    public static function assertSafe(array $settings): void
    {
        $root = realpath((string) ($settings['root'] ?? ''));
        $inside = static function (string $path) use ($root): bool {
            $resolved = realpath($path);
            if ($resolved === false || $root === false) { return false; }
            return str_starts_with(strtolower(str_replace('\\', '/', $resolved)).'/', strtolower(str_replace('\\', '/', $root)).'/');
        };
        $host = parse_url((string) ($settings['url'] ?? ''), PHP_URL_HOST);
        $safe = ($settings['environment'] ?? '') === 'testing'
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            && $root !== false && is_file($root.'/.staffhub-verification')
            && !preg_match('~(?:^|[/\\\\])OneDrive(?:[/\\\\]|$)~i', $root)
            && $inside((string) ($settings['storage'] ?? ''))
            && !($settings['chatEnabled'] ?? true)
            && empty($settings['chatCredentials'])
            && in_array($settings['mailer'] ?? '', ['array', 'log'], true);
        $db = $settings['database'] ?? [];
        $safe = $safe && match ($settings['driver'] ?? '') {
            'sqlite' => $inside((string) ($db['database'] ?? '')),
            'mysql' => in_array($db['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
                && preg_match('/^staffhub_verification_[a-z0-9_]+$/D', (string) ($db['database'] ?? ''))
                && empty($db['url']) && empty($db['read']) && empty($db['write']),
            default => false,
        };
        if (!$safe) {
            throw new RuntimeException('結合テストは停止しました。専用DB・保存領域・通知無効・localhost の testing 環境だけ実行できます。');
        }
    }
}
