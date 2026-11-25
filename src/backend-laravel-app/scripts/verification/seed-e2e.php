<?php
// CLI-only fixtures. No public test endpoint is installed.
if (PHP_SAPI !== 'cli' || getenv('STAFFHUB_E2E') !== 'true') { exit(2); }
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (config('database.default') === 'mysql') {
    $db = config('database.connections.mysql');
    $pdo = new PDO('mysql:host='.$db['host'].';port='.$db['port'], $db['username'], $db['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $check->execute([$db['database']]);
    if ((int) $check->fetchColumn() !== 0) { throw new RuntimeException('Existing database is never reused by E2E.'); }
    $pdo->exec('CREATE DATABASE `'.$db['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
if (DB::getSchemaBuilder()->hasTable('employees')) { throw new RuntimeException('Fixtures require a new empty database.'); }
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
$employeeId = DB::table('employees')->insertGetId([
    'employee_code'=>'V132','name'=>'検証 職員','kana'=>'ケンショウ ショクイン',
    'department_name'=>'検証部門','employment_type'=>'FULL_TIME','status'=>'ACTIVE',
    'joined_on'=>'2026-07-01','created_at'=>now(),'updated_at'=>now(),
]);
DB::table('employee_auth')->insert(['employee_id'=>$employeeId,'login_id'=>'verify-employee@example.test',
    'password_hash'=>Hash::make('VerifyOnly-2026!'),'created_at'=>now(),'updated_at'=>now()]);
DB::table('users')->insert(['name'=>'検証管理者','email'=>'verify-admin@example.test',
    'password'=>Hash::make('VerifyOnly-2026!'),'created_at'=>now(),'updated_at'=>now()]);
// A complete, unapproved fixture used to verify that ordinary punches require no human decision.
DB::table('attendance_daily')->insert([
    'employee_id'=>$employeeId, 'target_date'=>'2026-08-10', 'approval_status'=>'PENDING',
    'raw_clock_in_at'=>'2026-08-10 08:30:00', 'raw_clock_out_at'=>'2026-08-10 14:00:00',
    'clock_in_at'=>'2026-08-10 08:30:00', 'clock_out_at'=>'2026-08-10 14:00:00',
    'break_minutes'=>0, 'work_minutes'=>330, 'updated_at'=>now(),
]);
$breakReviewId = DB::table('attendance_daily')->insertGetId([
    'employee_id'=>$employeeId, 'target_date'=>'2026-10-16', 'approval_status'=>'PENDING',
    'raw_clock_in_at'=>'2026-10-16 07:56:00', 'raw_clock_out_at'=>'2026-10-16 16:58:00',
    'clock_in_at'=>'2026-10-16 07:56:00', 'clock_out_at'=>'2026-10-16 16:58:00',
    'break_minutes'=>45, 'work_minutes'=>497, 'updated_at'=>now(),
]);
DB::table('paid_leave_grants')->insert(['employee_id'=>$employeeId,'granted_on'=>'2026-07-01',
    'granted_days'=>5,'used_days'=>0,'expires_on'=>'2027-06-30','note'=>'専用環境', 'created_at'=>now(),'updated_at'=>now()]);
// Dedicated closed month for shift rejection checks; July and later remain available to workflow tests.
DB::table('attendance_monthly_closes')->insert(['target_year_month'=>'2026-05','status'=>'CLOSED',
    'note'=>'専用環境・シフト保存拒否の確認用','closed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
$root = getenv('STAFFHUB_VERIFICATION_ROOT');
$catalog = app(App\Services\PayrollTemplateCatalog::class);
foreach (['PAYROLL','BONUS'] as $type) {
    $row = $catalog->config($type)['sampleRow']; $row[0]='V132'; $row[1]='検証'; $row[2]='職員';
    $handle = fopen($root.'/'.strtolower($type).'.csv','wb');
    fwrite($handle,"\xEF\xBB\xBF");
    foreach ([$catalog->headers($type),$row] as $values) { fputcsv($handle,$values,',','"',''); }
    fclose($handle);
}
file_put_contents($root.'/fixtures.json', json_encode(['employeeId'=>$employeeId,'breakReviewId'=>$breakReviewId], JSON_THROW_ON_ERROR));
echo "Isolated fixtures ready.\n";
