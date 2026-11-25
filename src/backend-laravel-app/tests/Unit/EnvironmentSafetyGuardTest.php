<?php

namespace Tests\Unit;

use App\Support\EnvironmentSafetyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentSafetyGuardTest extends TestCase
{
    public function test_it_blocks_production_serve_with_a_remote_database(): void
    {
        $this->expectException(RuntimeException::class);

        EnvironmentSafetyGuard::assertSafeLocalServer('production', 'remote.example.test', ['artisan', 'serve'], false);
    }

    #[DataProvider('safeCases')]
    public function test_it_allows_safe_or_explicit_cases(
        string $environment,
        string $host,
        array $arguments,
        bool $allowRemote,
    ): void {
        EnvironmentSafetyGuard::assertSafeLocalServer($environment, $host, $arguments, $allowRemote);
        $this->addToAssertionCount(1);
    }

    public static function safeCases(): array
    {
        return [
            'local environment' => ['local', 'remote.example.test', ['artisan', 'serve'], false],
            'loopback database' => ['production', '127.0.0.1', ['artisan', 'serve'], false],
            'non-serve command' => ['production', 'remote.example.test', ['artisan', 'migrate'], false],
            'explicit override' => ['production', 'remote.example.test', ['artisan', 'serve'], true],
        ];
    }
}
