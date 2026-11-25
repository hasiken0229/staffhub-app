<?php
if (PHP_SAPI !== 'cli' || getenv('STAFFHUB_E2E') !== 'true') { exit(2); }
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\HarmosMigrationService;
use App\Services\Leave\PaidLeaveGrantReviewService;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

try {
    Http::preventStrayRequests();
    if (DB::connection()->getDriverName() !== 'mysql' || !str_starts_with(DB::connection()->getDatabaseName(), 'staffhub_verification_')
        || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Only isolated local verification MySQL is accepted.');
    }
    $root = realpath(getenv('STAFFHUB_VERIFICATION_ROOT'));
    $evidence = realpath(getenv('LEAVE_RACE_EVIDENCE'));
    if (!$root || !$evidence || !str_starts_with(str_replace('\\', '/', $evidence).'/', str_replace('\\', '/', $root).'/')) {
        throw new RuntimeException('Evidence must stay inside the verification root.');
    }
    Carbon\CarbonImmutable::setTestNow('2026-10-10 12:00:00');
    Carbon\Carbon::setTestNow('2026-10-10 12:00:00');
    $options = getopt('', ['setup', 'case:', 'role:']);
    if (isset($options['setup'])) {
        $fixtures = DB::transaction(function () {
            $adminId = DB::table('users')->insertGetId(['name' => '有給競合検証', 'email' => 'leave-race-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'unused']);
            $actor = new GenericUser(['id' => $adminId, 'role' => 'ADMIN']);
            $fixtures = [];
            foreach (['confirm-first', 'review-before-import'] as $case) {
                $code = 'LEAVERACE-'.bin2hex(random_bytes(6));
                $employeeId = DB::table('employees')->insertGetId(['employee_code' => $code, 'name' => '有給競合検証',
                    'employment_type' => 'FULL_TIME', 'status' => 'RETIRED', 'joined_on' => '2026-04-01', 'retired_on' => '2026-04-01', 'created_at' => now(), 'updated_at' => now()]);
                $grantId = DB::table('paid_leave_grants')->insertGetId(['employee_id' => $employeeId, 'granted_on' => '2026-04-01', 'granted_days' => 10,
                    'used_days' => 0, 'expires_on' => null, 'source_system' => 'HARMOS', 'created_at' => now(), 'updated_at' => now()]);
                $payload = ['lots' => [['days' => 4, 'expiresOn' => '2026-12-31'], ['days' => 6, 'expiresOn' => '2027-03-31']], 'evidenceReference' => '専用競合検証原本'];
                $preview = app(PaidLeaveGrantReviewService::class)->preview($grantId, $payload);
                $fixtures[$case] = compact('adminId', 'employeeId', 'grantId', 'code') + ['payload' => [...$payload, 'fingerprint' => $preview['fingerprint']]];
            }
            return $fixtures;
        });
        file_put_contents($evidence.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
        exit;
    }
    $case = $options['case'] ?? ''; $role = $options['role'] ?? '';
    if (!in_array($case, ['confirm-first', 'review-before-import'], true) || !in_array($role, ['first', 'second'], true)) { exit(2); }
    $fixture = json_decode(file_get_contents($evidence.'/fixtures.json'), true, 512, JSON_THROW_ON_ERROR)[$case];
    $actor = new GenericUser(['id' => $fixture['adminId'], 'role' => 'ADMIN']);
    $started = microtime(true);
    if ($role === 'second') { file_put_contents($evidence.'/'.$case.'.second.ready', '1'); }
    try {
        if ($role === 'first') {
            DB::transaction(function () use ($fixture, $actor, $evidence, $case) {
                DB::table('employees')->where('id', $fixture['employeeId'])->lockForUpdate()->first();
                file_put_contents($evidence.'/'.$case.'.locked', '1');
                $deadline = microtime(true) + 20;
                while (!is_file($evidence.'/'.$case.'.release')) {
                    if (microtime(true) > $deadline) { throw new RuntimeException('Leave race barrier timeout'); }
                    usleep(20000);
                }
                app(PaidLeaveGrantReviewService::class)->confirm($fixture['grantId'], $fixture['payload'], $actor);
            });
            $result = ['status' => 'COMMITTED'];
        } elseif ($case === 'confirm-first') {
            app(PaidLeaveGrantReviewService::class)->confirm($fixture['grantId'], $fixture['payload'], $actor);
            $result = ['status' => 'COMMITTED'];
        } else {
            $file = UploadedFile::fake()->createWithContent('leave-race.csv', "\xEF\xBB\xBF社員番号,有給残数\n".$fixture['code'].",20\n");
            $import = app(HarmosMigrationService::class)->import(['importType' => 'HARMOS_PAID_LEAVE_BALANCE_CSV', 'migrationDate' => '2026-04-01'], $file, $actor);
            $result = ['status' => 'COMMITTED', 'successCount' => $import['successCount'], 'errorCount' => $import['errorCount']];
        }
    } catch (App\Services\ApiException $error) { $result = ['status' => 'REJECTED', 'code' => $error->errorCode]; }
    $active = DB::table('paid_leave_grants')->where('employee_id', $fixture['employeeId'])->whereNull('superseded_at');
    $result += ['milliseconds' => round((microtime(true) - $started) * 1000), 'activeCount' => (clone $active)->count(),
        'activeDays' => (float) (clone $active)->sum('granted_days'), 'originalDays' => (float) DB::table('paid_leave_grants')->where('id', $fixture['grantId'])->value('granted_days'),
        'reviewAuditCount' => DB::table('audit_logs')->where('action', 'PAID_LEAVE_GRANT_REVIEWED')->where('target_id', (string) $fixture['grantId'])->count()];
    file_put_contents($evidence.'/'.$case.'.'.$role.'.json', json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
