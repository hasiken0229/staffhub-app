<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Leave\LeaveApplyService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LeaveDateAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00'));
        config(['staffhub.google_chat.enabled' => false, 'mail.default' => 'array']);
        $this->employee = DB::table('employees')->insertGetId(['employee_code'=>'DATE001', 'name'=>'日付検証',
            'employment_type'=>'FULL_TIME', 'status'=>'ACTIVE', 'joined_on'=>'2026-04-01', 'created_at'=>now(), 'updated_at'=>now()]);
    }
    private function grant(string $on, string $expires, float $days): void
    {
        DB::table('paid_leave_grants')->insert(['employee_id'=>$this->employee, 'granted_on'=>$on,
            'expires_on'=>$expires, 'granted_days'=>$days, 'used_days'=>0, 'created_at'=>now(), 'updated_at'=>now()]);
    }
    private function payload(string $date, ?string $end = null): array
    {
        return ['requestCategory'=>'LEAVE', 'leaveTypeCode'=>'PAID', 'startDate'=>$date, 'endDate'=>$end??$date, 'dayUnit'=>'FULL'];
    }
    private function reserve(string $date, string $status = 'APPROVED', float $days = 1): int
    {
        return DB::table('leave_requests')->insertGetId(['employee_id'=>$this->employee, 'leave_type_code'=>'PAID',
            'start_date'=>$date, 'end_date'=>$date, 'day_unit'=>'FULL', 'request_category'=>'LEAVE',
            'quantity_days'=>$days, 'status'=>$status, 'created_at'=>now(), 'updated_at'=>now()]);
    }
    private function expectInsufficient(callable $operation): void
    {
        try { $operation(); $this->fail('Expired or already reserved days must not be used'); }
        catch (ApiException $error) { $this->assertSame('INSUFFICIENT_PAID_LEAVE', $error->errorCode); }
    }
    public function test_future_leave_cannot_use_a_grant_expired_on_its_leave_date(): void
    {
        $this->grant('2026-04-01', '2026-10-31', 10);
        $this->assertEquals(0, app(LeaveLedgerService::class)->balance($this->employee, '2026-11-01')['availableDays']);
        $this->expectInsufficient(fn()=>app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-11-01')));
        $this->assertDatabaseCount('leave_requests', 0);
    }
    public function test_a_known_future_grant_can_cover_leave_after_its_grant_date_but_not_before(): void
    {
        $this->grant('2026-11-01', '2027-10-31', 10);
        $this->expectInsufficient(fn()=>app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-10-31')));
        $request = app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-11-05'));
        $this->assertSame('PENDING', $request['status']);
        $this->assertEquals(9, app(LeaveLedgerService::class)->balance($this->employee, '2026-11-05')['availableDays']);
    }
    public function test_current_request_cannot_take_days_reserved_for_later_approved_or_pending_leave(): void
    {
        $this->grant('2026-04-01', '2027-03-31', 1);
        foreach (['APPROVED', 'PENDING', 'RETURNED'] as $status) {
            DB::table('leave_requests')->delete();
            $this->reserve('2026-11-05', $status);
            $this->assertEquals(0, app(LeaveLedgerService::class)->balance($this->employee, '2026-10-12')['availableDays']);
            $this->expectInsufficient(fn()=>app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-10-12')));
            $this->assertDatabaseCount('leave_requests', 1);
        }
    }
    public function test_future_reservations_covered_by_a_future_grant_do_not_block_current_leave(): void
    {
        $this->grant('2026-04-01', '2026-10-31', 1);
        $this->grant('2026-11-01', '2027-10-31', 10);
        $this->reserve('2026-11-05', 'APPROVED', 10);
        $this->assertEquals(1, app(LeaveLedgerService::class)->balance($this->employee, '2026-10-12')['availableDays']);
        $this->assertSame('PENDING', app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-10-12'))['status']);
    }
    public function test_a_multi_day_request_is_checked_on_each_side_of_an_expiry(): void
    {
        $this->grant('2026-04-01', '2026-10-31', 10);
        $this->expectInsufficient(fn()=>app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-10-31', '2026-11-01')));
        $this->assertDatabaseCount('leave_requests', 0);
    }
    public function test_consuming_a_longer_lived_fifo_lot_cannot_leave_only_expired_days_for_a_later_reservation(): void
    {
        $this->grant('2026-04-01', '2027-03-31', 1);
        $this->grant('2026-05-01', '2026-10-31', 1);
        $this->reserve('2026-11-05');
        $this->assertEquals(2, app(LeaveLedgerService::class)->balance($this->employee, '2026-10-12')['currentBalance']);
        $this->assertEquals(0, app(LeaveLedgerService::class)->balance($this->employee, '2026-10-12')['availableDays']);
        $this->expectInsufficient(fn()=>app(LeaveApplyService::class)->createForEmployee($this->employee, $this->payload('2026-10-12')));
    }
    public function test_approval_rechecks_the_request_dates_and_does_not_double_reserve_itself(): void
    {
        $this->grant('2026-04-01', '2026-10-31', 1);
        $id = $this->reserve('2026-11-05', 'PENDING');
        $actor = new GenericUser(['id'=>1, 'role'=>'ADMIN']);
        $this->expectInsufficient(fn()=>app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $actor));
        $this->assertDatabaseHas('leave_requests', ['id'=>$id, 'status'=>'PENDING']);
        DB::table('leave_requests')->where('id', $id)->update(['start_date'=>'2026-10-12', 'end_date'=>'2026-10-12']);
        $this->assertSame('APPROVED', app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $actor)['status']);
    }
}
