<?php
// Read-only business evidence. Run through CLI, never expose as an HTTP endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['app:', 'employee:', 'months:', 'output:']);
if (empty($options['employee']) || empty($options['months']) || empty($options['output'])) {
    fwrite(STDERR, "Required: --employee --months=YYYY-MM,YYYY-MM --output=private-directory\n"); exit(2);
}
$root = $options['app'] ?? dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$months = explode(',', $options['months']);
foreach ($months as $month) { if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { exit(2); } }
$directory = $options['output'];
if (!is_dir($directory)) { mkdir($directory, 0700, true); }
use Illuminate\Support\Facades\DB;
$employee = DB::table('employees')->where('employee_code', $options['employee'])->first(['id','employee_code','name','status']);
if ($employee === null) { fwrite(STDERR, "Employee not found\n"); exit(3); }
$result = ['recordedAt' => now()->toIso8601String(), 'employee' => $employee,
    'databaseDriver' => DB::connection()->getDriverName(), 'months' => []];
foreach ($months as $month) {
    $start = $month.'-01'; $end = \Carbon\CarbonImmutable::parse($start)->endOfMonth()->toDateString();
    $data = [
        'attendance' => DB::table('attendance_daily')->where('employee_id', $employee->id)->whereBetween('target_date', [$start,$end])->get(),
        'leaveRequests' => DB::table('leave_requests')->where('employee_id', $employee->id)->where('start_date','<=',$end)->where('end_date','>=',$start)->get(),
        'editRequests' => DB::table('attendance_daily_edit_requests')->where('employee_id',$employee->id)->whereBetween('target_date',[$start,$end])->get(),
        'payroll' => DB::table('payroll_statements')->where('employee_id',$employee->id)->where('target_year_month',$month)->whereNull('deleted_at')->get(),
    ];
    try { $data['monthPrecheck'] = app(\App\Services\Attendance\MonthCloseService::class)->monthClosePrecheck($month); }
    catch (\Throwable $error) { $data['monthPrecheckError'] = ['type'=>get_class($error), 'message'=>$error->getMessage()]; }
    foreach ($data['payroll'] as $statement) {
        $file = storage_path('app/private/'.ltrim($statement->file_path, '/'));
        $statement->fileExists = is_file($file);
        $statement->sha256 = is_file($file) ? hash_file('sha256',$file) : null;
        $statement->lines = DB::table('payroll_statement_lines')->where('payroll_statement_id',$statement->id)->get();
    }
    $result['months'][$month] = $data;
}
$target = $directory.'/employee-'.$options['employee'].'-snapshot.json';
file_put_contents($target, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
chmod($target,0600);
echo json_encode(['saved'=>basename($target),'months'=>$months,'driver'=>$result['databaseDriver']])."\n";
