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

        $requestId = $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
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

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/work-procedures/' . $requestId . '/approve', ['comment' => '承認します'])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.comment', '承認します');

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
            'days_delta' => -2,
            'balance_after' => 3,
        ]);
        foreach (['2026-04-23', '2026-04-24'] as $targetDate) {
            $this->assertDatabaseHas('attendance_daily', [
                'employee_id' => $employeeId,
                'target_date' => $targetDate,
                'paid_leave_unit' => 1,
                'approval_status' => 'APPROVED',
                'approval_comment' => '休暇承認により自動反映',
            ]);
        }

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->getJson('/api/leave/ledger')
            ->assertOk()
            ->assertJsonPath('data.currentBalance', 3)
            ->assertJsonPath('data.items.0.entryType', 'GRANT')
            ->assertJsonPath('data.items.1.entryType', 'USE');

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
            ->postJson('/api/leave/requests/' . $requestId . '/cancel', ['comment' => '予定変更'])
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
        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
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

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
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

        $this->withHeader('Authorization', 'Bearer ' . $employeeToken)
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
