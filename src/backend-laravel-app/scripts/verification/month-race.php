<?php
if (PHP_SAPI !== 'cli' || getenv('STAFFHUB_E2E') !== 'true') { exit(2); }
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\Attendance\MonthMutationLock;
use App\Services\Attendance\DailyEditService;
use App\Services\Attendance\MonthCloseService;
use App\Services\Attendance\MonthApprovalService;
use App\Services\Attendance\DailyEditRequestService;
use App\Http\Controllers\Api\Admin\AttendanceAdminController;

try {
if (DB::connection()->getDriverName() !== 'mysql') { throw new RuntimeException('Concurrency verification requires isolated MySQL.'); }
if (!str_starts_with((string) DB::connection()->getDatabaseName(), 'staffhub_verification_')) { throw new RuntimeException('Only a dedicated verification database is accepted.'); }
$options=getopt('', ['setup','case:','role:']);
$root=getenv('STAFFHUB_VERIFICATION_ROOT');
$months=['close-first'=>'2026-01','write-first'=>'2026-02','candidate-close-first'=>'2025-01','candidate-approve-first'=>'2025-02','candidate-edit-first'=>'2025-03','candidate-request-first'=>'2025-04','shift-close-first'=>'2025-05','shift-save-first'=>'2025-06'];
function raceFixture(string $case, string $month, string $approval): int {
    $date=$month.'-10';
    DB::table('attendance_monthly_closes')->updateOrInsert(['target_year_month'=>$month],['status'=>'OPEN','closed_at'=>null,'closed_by'=>null,'created_at'=>now(),'updated_at'=>now()]);
    $employee=DB::table('employees')->where('employee_code','RACE-'.$case)->first();
    $values=['name'=>'候補競合検証','employment_type'=>'FULL_TIME','status'=>'RETIRED','joined_on'=>$date,'retired_on'=>$date,'updated_at'=>now()];
    if($employee) { $employeeId=(int)$employee->id;DB::table('employees')->where('id',$employeeId)->update($values); }
    else { $employeeId=DB::table('employees')->insertGetId(['employee_code'=>'RACE-'.$case,'created_at'=>now(),...$values]); }
    DB::table('attendance_daily_edit_requests')->where('employee_id',$employeeId)->delete();
    DB::table('attendance_error_resolutions')->where('employee_id',$employeeId)->delete();
    DB::table('attendance_shift_schedules')->where('employee_id',$employeeId)->delete();
    if(str_starts_with($case,'shift-')) {
        DB::table('attendance_shift_schedules')->insert(['employee_id'=>$employeeId,'target_date'=>$date,
            'scheduled_clock_in_time'=>'09:00:00','scheduled_clock_out_time'=>'18:00:00','note'=>'before race',
            'created_at'=>now(),'updated_at'=>now()]);
    }
    $daily=DB::table('attendance_daily')->where('employee_id',$employeeId)->where('target_date',$date)->first();
    $values=['schedule_name'=>'通常勤務','clock_in_at'=>$date.' 09:00:00','clock_out_at'=>$date.' 18:00:00','raw_clock_in_at'=>null,'raw_clock_out_at'=>null,'break_minutes'=>60,'work_minutes'=>480,'approval_status'=>$approval,'close_status'=>'OPEN','remark'=>null,'is_manually_edited'=>0,'approved_by'=>null,'approved_at'=>null,'updated_at'=>now()];
    if($daily) { $id=(int)$daily->id;DB::table('attendance_daily')->where('id',$id)->update($values); }
    else { $id=DB::table('attendance_daily')->insertGetId(['employee_id'=>$employeeId,'target_date'=>$date,...$values]); }
    DB::table('attendance_daily_breaks')->where('attendance_daily_id',$id)->delete();
    return $id;
}
if (isset($options['setup'])) {
    $ids=DB::transaction(function() use($months) { $ids=[];foreach($months as $case=>$month) { $ids[$case]=raceFixture($case,$month,str_starts_with($case,'candidate-')?'PENDING':'APPROVED'); }return $ids; });
    $previews=[];
    foreach (array_filter($months,fn($case)=>str_starts_with($case,'candidate-'),ARRAY_FILTER_USE_KEY) as $case=>$month) {
        $candidate=collect(app(MonthApprovalService::class)->preview($month)['items'])->first(fn($item)=>$item['daily']['id']===$ids[$case]);
        if (!$candidate['eligible']) { throw new RuntimeException('Race candidate is not eligible'); }
        $previews[$case]=['id'=>$ids[$case],'fingerprint'=>$candidate['fingerprint']];
        if ($case==='candidate-close-first') { DB::table('attendance_daily')->where('id',$ids[$case])->update(['approval_status'=>'APPROVED']); }
    }
    file_put_contents($root.'/race-candidate-previews.json',json_encode($previews,JSON_THROW_ON_ERROR));
    file_put_contents($root.'/race-fixtures.json',json_encode($ids,JSON_THROW_ON_ERROR));exit;
}
$case=$options['case'] ?? ''; $role=$options['role'] ?? '';
$firstRoles=['close-first'=>'close','write-first'=>'write','candidate-close-first'=>'close','candidate-approve-first'=>'approve','candidate-edit-first'=>'edit','candidate-request-first'=>'request','shift-close-first'=>'close','shift-save-first'=>'shift'];
if (!isset($months[$case])||!in_array($role,['close','write','approve','edit','request','shift'],true)) exit(2);
$ids=json_decode(file_get_contents($root.'/race-fixtures.json'),true,512,JSON_THROW_ON_ERROR);
$month=$months[$case];
$previews=json_decode(file_get_contents($root.'/race-candidate-previews.json'),true,512,JSON_THROW_ON_ERROR);
$actor=new Illuminate\Auth\GenericUser(['id'=>1,'role'=>'ADMIN']);
$first=$firstRoles[$case]===$role;
$started=microtime(true);
$bulk=null;
try {
    DB::transaction(function() use($month,$role,$first,$root,$case,$ids,$actor,$previews,&$bulk) {
        MonthMutationLock::acquire([$month], false);
        if ($first) {
            file_put_contents($root.'/'.$case.'.locked','1');
            $deadline=microtime(true)+20;
            while(!is_file($root.'/'.$case.'.release')) { if(microtime(true)>$deadline) throw new RuntimeException('Race barrier timeout'); usleep(20000); }
        }
        if($role==='close') app(MonthCloseService::class)->updateMonthlyClose($month,'CLOSED','専用環境',$actor);
        elseif($role==='approve') $bulk=app(MonthApprovalService::class)->approve($month,[$previews[$case]],'候補競合検証',$actor);
        elseif($role==='edit') app(DailyEditService::class)->updateDaily($ids[$case],['remark'=>'確認後の変更'],$actor);
        elseif($role==='request') {
            $employee=DB::table('attendance_daily')->where('id',$ids[$case])->value('employee_id');
            app(DailyEditRequestService::class)->createDailyEditRequest((int)$employee,['targetDate'=>$month.'-10','clockInTime'=>'09:05','clockOutTime'=>'18:00','breaks'=>[['startTime'=>'12:00','endTime'=>'13:00']],'employeeComment'=>'競合中の修正申請']);
        } elseif($role==='shift') {
            $employee=DB::table('attendance_daily')->where('id',$ids[$case])->value('employee_id');
            $request=Illuminate\Http\Request::create('/api/admin/attendance/shift-schedules','POST',[
                'employeeId'=>(int)$employee,'targetDate'=>$month.'-10','scheduledClockInTime'=>'10:00',
                'scheduledClockOutTime'=>'18:00','note'=>'race saved']);
            $request->setUserResolver(fn()=>$actor);
            $response=app(AttendanceAdminController::class)->saveShiftSchedule($request);
            if($response->getStatusCode()>=400) {
                $error=$response->getData(true)['error'];
                throw new App\Services\ApiException($error['code'],$error['message'],$response->getStatusCode(),$error['details']);
            }
        } else app(DailyEditService::class)->updateDaily($ids[$case],['approvalStatus'=>'PENDING','clockInTime'=>'09:05','clockOutTime'=>'18:00','breaks'=>[['startTime'=>'12:00','endTime'=>'13:00']]],$actor);
    });
    $result=['status'=>'COMMITTED'];
} catch (App\Services\ApiException $error) { $result=['status'=>'REJECTED','code'=>$error->errorCode]; }
$result['milliseconds']=round((microtime(true)-$started)*1000);
$result['monthStatus']=DB::table('attendance_monthly_closes')->where('target_year_month',$month)->value('status');
$result['dailyApproval']=DB::table('attendance_daily')->where('id',$ids[$case])->value('approval_status');
$result['dailyClockIn']=DB::table('attendance_daily')->where('id',$ids[$case])->value('clock_in_at');
$result['dailyRawClockIn']=DB::table('attendance_daily')->where('id',$ids[$case])->value('raw_clock_in_at');
$employee=DB::table('attendance_daily')->where('id',$ids[$case])->value('employee_id');
$result['shiftClockIn']=DB::table('attendance_shift_schedules')->where('employee_id',$employee)->where('target_date',$month.'-10')->value('scheduled_clock_in_time');
$result['bulk']=$bulk;
file_put_contents($root.'/'.$case.'.'.$role.'.json',json_encode($result,JSON_THROW_ON_ERROR));
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
