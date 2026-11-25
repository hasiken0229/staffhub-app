<?php

namespace Tests\Feature;

use App\Services\Attendance\MonthApprovalService;
use App\Services\Attendance\MonthCloseService;
use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MonthCloseReturnedRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); CarbonImmutable::setTestNow('2026-10-10 12:00:00'); }
    protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }
    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'RETURN001', 'name'=>'差戻し確認',
            'employment_type'=>'FULL_TIME', 'status'=>'ACTIVE', 'joined_on'=>'2026-09-01', 'retired_on'=>'2026-09-01',
            'created_at'=>now(), 'updated_at'=>now()]);
    }
    private function returned(int $employee): void
    {
        DB::table('leave_requests')->insert(['employee_id'=>$employee, 'leave_type_code'=>'PAID',
            'start_date'=>'2026-09-01', 'end_date'=>'2026-09-01', 'day_unit'=>'FULL', 'request_category'=>'LEAVE',
            'quantity_days'=>1, 'status'=>'RETURNED', 'created_at'=>now(), 'updated_at'=>now()]);
        DB::table('attendance_daily_edit_requests')->insert(['employee_id'=>$employee, 'target_date'=>'2026-09-01',
            'clock_in_at'=>'2026-09-01 09:00:00', 'clock_out_at'=>'2026-09-01 18:00:00', 'breaks_json'=>'[]',
            'status'=>'RETURNED', 'created_at'=>now(), 'updated_at'=>now()]);
    }
    public function test_returned_leave_and_correction_requests_block_month_close_until_resolved(): void
    {
        $employee = $this->employee();
        DB::table('attendance_daily')->insert(['employee_id'=>$employee, 'target_date'=>'2026-09-01',
            'schedule_name'=>'公休', 'work_minutes'=>0, 'break_minutes'=>0, 'approval_status'=>'APPROVED', 'updated_at'=>now()]);
        $this->returned($employee);
        $service = app(MonthCloseService::class);
        $precheck = $service->monthClosePrecheck('2026-09');
        $this->assertFalse($precheck['canClose']);
        $this->assertEqualsCanonicalizing(['PENDING_DAILY_EDIT_REQUESTS', 'PENDING_LEAVE_REQUESTS'], array_column($precheck['blockers'], 'code'));
        try { $service->updateMonthlyClose('2026-09', 'CLOSED', null, new GenericUser(['id'=>$employee, 'role'=>'EMPLOYEE'])); $this->fail('Returned requests must block close'); }
        catch (ApiException $error) { $this->assertSame('VALIDATION_ERROR', $error->errorCode); }
        DB::table('leave_requests')->update(['status'=>'CANCELLED']);
        DB::table('attendance_daily_edit_requests')->update(['status'=>'CANCELLED']);
        $this->assertTrue($service->monthClosePrecheck('2026-09')['canClose']);
    }
    public function test_returned_requests_are_excluded_from_safe_month_bulk_approval(): void
    {
        $employee = $this->employee();
        DB::table('attendance_daily')->insert(['employee_id'=>$employee, 'target_date'=>'2026-09-01',
            'clock_in_at'=>'2026-09-01 09:00:00', 'clock_out_at'=>'2026-09-01 18:00:00',
            'raw_clock_in_at'=>'2026-09-01 09:00:00', 'raw_clock_out_at'=>'2026-09-01 18:00:00',
            'work_minutes'=>480, 'break_minutes'=>60, 'is_manually_edited'=>1, 'approval_status'=>'PENDING', 'updated_at'=>now()]);
        $this->returned($employee);
        $item = app(MonthApprovalService::class)->preview('2026-09')['items'][0];
        $this->assertFalse($item['eligible']);
        $this->assertContains('未処理の修正申請あり', $item['reasons']);
        $this->assertContains('未処理の届出・休暇申請あり', $item['reasons']);
    }

    public function test_month_close_status_api_returns_all_fifty_one_employees_when_the_ui_requests_all_rows(): void
    {
        for ($index=1; $index<=51; $index++) {
            DB::table('employees')->insert(['employee_code'=>sprintf('ALL%03d', $index), 'name'=>'全職員確認'.$index,
                'employment_type'=>'FULL_TIME', 'status'=>'ACTIVE', 'joined_on'=>'2026-09-01', 'retired_on'=>'2026-09-01',
                'created_at'=>now(), 'updated_at'=>now()]);
        }
        DB::table('users')->insert(['name'=>'一覧検証管理者', 'email'=>'all-month-review@example.test',
            'password'=>Hash::make('VerifyOnly-2026!'), 'created_at'=>now(), 'updated_at'=>now()]);
        $token = $this->postJson('/api/auth/login', ['loginId'=>'all-month-review@example.test',
            'password'=>'VerifyOnly-2026!', 'audience'=>'ADMIN'])->assertOk()->json('data.accessToken');
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/attendance/month-close-status?targetMonth=2026-09&page=1&perPage=10000')
            ->assertOk()->assertJsonCount(51, 'data')->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.perPage', 10000)->assertJsonPath('data.50.employee.employeeCode', 'ALL051');
        $this->assertCount(51, array_unique(array_column(array_column($response->json('data'), 'employee'), 'id')));
        $this->assertSame('2026-09-01', $response->json('data.50.dayDetails.0.targetDate'));
    }
}
