<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name = $argv[3] ?? '勤怠管理 管理者';

if (!is_string($email) || trim($email) === '' || !is_string($password) || trim($password) === '') {
    fwrite(STDERR, "Usage: php scripts/update_admin_credentials.php <email> <password> [name]\n");
    exit(1);
}

$email = trim($email);
$password = trim($password);
$name = trim((string) $name);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$existing = DB::table('users')
    ->orderBy('id')
    ->first();

if ($existing === null) {
    DB::table('users')->insert([
        'name' => $name,
        'email' => $email,
        'email_verified_at' => now(),
        'password' => Hash::make($password),
        'remember_token' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    fwrite(STDOUT, "Created admin user: {$email}\n");
    exit(0);
}

DB::table('users')
    ->where('id', $existing->id)
    ->update([
        'name' => $name === '' ? (string) $existing->name : $name,
        'email' => $email,
        'password' => Hash::make($password),
        'updated_at' => now(),
    ]);

fwrite(STDOUT, "Updated admin user #{$existing->id}: {$email}\n");
