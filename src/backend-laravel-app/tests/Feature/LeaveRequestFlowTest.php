<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LeaveRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_leave_approval_updates_ledger_and_attendance_then_cancel_restores_them(): void
    {
        $adminToken = $this->adminToken();
        $employeeId = $this->employee('E301');
        $employeeToken = $this->employeeToken($employeeId, 'e301@example.com');
        $grantId = $this->paidLeaveGrant($employeeId, 5.0);

        $requestId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'LEAVE',
                'leaveTypeCode' => 'PAID',
                'startDate' => '2026-04-23',
                'endDate' => '2026-04-24',
                'dayUnit' => 'FULL',
                'reason' => '家庭都合',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.quantityDays', 2)
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/admin/work-procedures/'.$requestId.'/approve', ['comment' => '承認します'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.comment', '承認します');

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->getJson('/api/leave/requests')
            ->assertOk()
            ->assertJsonPath('data.0.decisionComment', '承認します');

        $this->assertDatabaseHas('paid_leave_grants', [
            'id' => $grantId,
            'employee_id' => $employeeId,
            'used_days' => 2,
        ]);
        $this->assertDatabaseHas('paid_leave_ledger', [
            'employee_id' => $employeeId,
            'entry_type' => 'USE',
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => $requestId,
            'days_delta' => -1,
            'balance_after' => 3,
        ]);
        // Consumption is now dated per day, including across expiry boundaries.
        $this->assertEquals(-2, DB::table('paid_leave_ledger')->where('source_type', 'LEAVE_REQUEST')->where('source_id', $requestId)->sum('days_delta'));
        foreach (['2026-04-23', '2026-04-24'] as $targetDate) {
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id' => $employeeId,
                'target_date' => $targetDate,
                'paid_leave_unit' => 1,
                'approval_status' => 'APPROVED',
                'approval_comment' => '休暇承認により自動反映',
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->getJson('/api/leave/ledger')
            ->assertOk()
            ->assertJsonPath('data.currentBalance', 3)
            ->assertJsonPath('data.items.0.entryType', 'GRANT')
            ->assertJsonPath('data.items.1.entryType', 'USE');

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/leave/requests/'.$requestId.'/cancel', ['comment' => '予定変更'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.comment', '予定変更');

        $this->assertDatabaseHas('paid_leave_grants', [
            'id' => $grantId,
            'employee_id' => $employeeId,
            'used_days' => 0,
        ]);
        $this->assertDatabaseMissing('paid_leave_ledger', [
            'employee_id' => $employeeId,
            'entry_type' => 'USE',
            'source_type' => 'LEAVE_REQUEST',
            'source_id' => $requestId,
        ]);
        foreach (['2026-04-23', '2026-04-24'] as $targetDate) {
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id' => $employeeId,
                'target_date' => $targetDate,
                'paid_leave_unit' => null,
            ]);
        }
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->getJson('/api/leave/ledger')
            ->assertOk()
            ->assertJsonPath('data.currentBalance', 5)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_pending_paid_leave_requests_reserve_balance_before_approval(): void
    {
        $employeeId = $this->employee('E302');
        $employeeToken = $this->employeeToken($employeeId, 'e302@example.com');
        $this->paidLeaveGrant($employeeId, 1.0);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'LEAVE',
                'leaveTypeCode' => 'PAID',
                'startDate' => '2026-04-27',
                'endDate' => '2026-04-27',
                'dayUnit' => 'FULL',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.quantityDays', 1);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/leave/requests', [
                'requestCategory' => 'LEAVE',
                'leaveTypeCode' => 'PAID',
                'startDate' => '2026-04-28',
                'endDate' => '2026-04-28',
                'dayUnit' => 'HALF',
                'halfDayType' => 'AM',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PAID_LEAVE');

        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_separate_hourly_balance_reserves_approves_and_restores_without_deducting_days(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-08'));
        $admin = $this->adminToken();
        $id = $this->employee('H001');
        $employee = $this->employeeToken($id, 'hourly@example.com');
        $this->paidLeaveGrant($id, 10);
        $this->withHeader('Authorization', 'Bearer '.$admin)->postJson('/api/admin/leave/hourly-balances', [
            'employeeId' => $id, 'effectiveOn' => '2026-09-30', 'expiresOn' => '2027-03-31',
            'remainingMinutes' => 120, 'totalMinutes' => 600,
        ])->assertOk()->assertJsonPath('data.availableMinutes', 120);
        $payload = ['requestCategory' => 'TIME_LEAVE', 'timeLeaveType' => 'PAID_HOURLY',
            'targetDate' => '2026-10-12', 'startTime' => '09:00', 'endTime' => '11:00'];
        $requestId = $this->withHeader('Authorization', 'Bearer '.$employee)->postJson('/api/leave/requests', $payload)
            ->assertOk()->assertJsonPath('data.quantityDays', 0)->json('data.id');
        $service = app(\App\Services\Leave\HourlyPaidLeaveService::class);
        $this->assertSame(0, $service->balance($id)['availableMinutes']);
        $payload['targetDate'] = '2026-10-13';
        $this->postJson('/api/leave/requests', $payload)->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_HOURLY_PAID_LEAVE');
        $this->withHeader('Authorization', 'Bearer '.$admin)->postJson('/api/admin/work-procedures/'.$requestId.'/approve')->assertOk();
        $this->assertSame(0, $service->balance($id)['remainingMinutes']);
        $this->assertSame(480, $service->balance($id)['totalRemainingMinutes']);
        $this->assertEquals(10, app(\App\Services\Leave\LeaveLedgerService::class)->balance($id)['currentBalance']);
        $this->withHeader('Authorization', 'Bearer '.$employee)->postJson('/api/leave/requests/'.$requestId.'/cancel')->assertOk();
        $this->assertSame(120, $service->balance($id)['availableMinutes']);
        $payload['targetDate'] = '2027-04-01';
        $this->postJson('/api/leave/requests', $payload)->assertStatus(422)->assertJsonPath('error.code', 'HOURLY_PAID_LEAVE_NOT_CONFIGURED');
        $this->withHeader('Authorization', 'Bearer '.$employee)->postJson('/api/admin/leave/hourly-balances', [])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'SET_HOURLY_PAID_LEAVE_BALANCE']);
    }

    public function test_balance_and_ledger_queries_are_read_only_and_expiry_is_dated(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2027-04-02'));
        $id = $this->employee('L900');
        $old = $this->paidLeaveGrant($id, 10);
        $new = $this->paidLeaveGrant($id, 10);
        DB::table('paid_leave_grants')->where('id', $new)->update(['granted_on' => '2027-04-01', 'expires_on' => '2029-03-31']);
        DB::table('leave_requests')->insert([
            'employee_id' => $id, 'leave_type_code' => 'PAID', 'start_date' => '2027-04-02', 'end_date' => '2027-04-02',
            'day_unit' => 'FULL', 'quantity_days' => 1, 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(\App\Services\Leave\LeaveLedgerService::class);
        $before = DB::table('paid_leave_grants')->get()->toJson();
        $this->assertEquals(10, $service->balance($id, '2027-03-31')['currentBalance']);
        $this->assertEquals(9, $service->balance($id)['currentBalance']);
        $ledger = $service->ledger($id);
        $this->assertEquals(9, end($ledger['items'])['balanceAfter']);
        $this->assertSame($before, DB::table('paid_leave_grants')->get()->toJson());
        $this->assertDatabaseCount('paid_leave_ledger', 0);
        $service->recalculatePaidLeaveUsage($id);
        $this->assertDatabaseHas('paid_leave_grants', ['id' => $old, 'used_days' => 0]);
        $this->assertDatabaseHas('paid_leave_grants', ['id' => $new, 'used_days' => 1]);
    }

    public function test_care_balances_keep_snapshot_usage_future_and_years_separate(): void
    {
        $admin = $this->adminToken(); $id = $this->employee('C001');
        $uri = '/api/admin/leave/care-balances';
        $query = $uri.'?fiscalYear=2026&asOf=2026-10-08';
        $this->withHeader('Authorization', 'Bearer '.$admin)->getJson($query)->assertOk()->assertJsonPath('data.0.remainingMinutes', null);
        $payload = ['employeeId' => $id, 'category' => 'CHILD', 'fiscalYear' => 2026, 'eligibleCount' => 1,
            'dayMinutes' => 360, 'effectiveOn' => '2026-09-30', 'openingMinutes' => 1440, 'note' => '原本確認済み'];
        $this->postJson($uri, $payload)->assertOk();
        foreach ([['2026-09-30', 180, null], ['2026-10-01', 60, null], ['2026-10-02', 0, '子の看護休暇'], ['2026-10-12', 120, null]] as [$date, $minutes, $name]) {
            DB::table('attendance_daily')->insert(['employee_id' => $id, 'target_date' => $date, 'child_care_leave_minutes' => $minutes, 'schedule_name' => $name, 'updated_at' => now()]);
        }
        // Only post-baseline days are deducted. Full day = this employee's six hours.
        $before = DB::table('care_leave_balances')->get()->toJson();
        $this->getJson($query)->assertOk()->assertJsonPath('data.0.remainingMinutes', 1020)->assertJsonPath('data.0.availableMinutes', 900)
            ->assertJsonPath('data.0.futureReservedMinutes', 120)->assertJsonPath('data.1.remainingMinutes', null);
        $this->assertSame($before, DB::table('care_leave_balances')->get()->toJson());
        DB::table('attendance_daily')->where('target_date', '2026-10-12')->update(['child_care_leave_minutes' => 0]);
        $this->getJson($query)->assertJsonPath('data.0.availableMinutes', 1020);
        $this->getJson($uri.'?fiscalYear=2027&asOf=2027-04-01')->assertJsonPath('data.0.remainingMinutes', null);
        $this->getJson($uri.'?fiscalYear=2026&asOf=2027-04-01')->assertStatus(422);
        $this->postJson($uri, array_replace($payload, ['openingMinutes' => 1801]))->assertStatus(422);
        $this->postJson($uri, array_replace($payload, ['eligibleCount' => 2, 'openingMinutes' => 3600]))->assertOk();
        $this->postJson($uri, array_replace($payload, ['category' => 'NURSING']))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'SET_CARE_LEAVE_BALANCE']);
        $token = $this->employeeToken($id, 'care@example.com');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson($query)->assertForbidden();
        $this->postJson($uri, $payload)->assertForbidden();
    }

    private function adminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin-leave-flow@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin-leave-flow@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }

    private function employee(string $code): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'name' => '有給 職員',
            'kana' => 'ユウキュウ ショクイン',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function employeeToken(int $employeeId, string $loginId): string
    {
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => $loginId,
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => $loginId,
            'password' => 'ChangeMe123!',
            'audience' => 'EMPLOYEE',
        ])->json('data.accessToken');
    }

    private function paidLeaveGrant(int $employeeId, float $days): int
    {
        return (int) DB::table('paid_leave_grants')->insertGetId([
            'employee_id' => $employeeId,
            'granted_on' => '2026-04-01',
            'granted_days' => $days,
            'used_days' => 0,
            'expires_on' => '2027-03-31',
            'note' => 'テスト付与',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
