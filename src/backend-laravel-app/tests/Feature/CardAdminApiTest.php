<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CardAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_revoke_and_delete_card_assignment(): void
    {
        $token = $this->issueAdminToken();
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E0001',
            'name' => '山田 太郎',
            'kana' => null,
            'department_name' => 'サンプル',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cardId = (int) DB::table('employee_cards')->insertGetId([
            'employee_id' => $employeeId,
            'card_uid' => '012E4CE15C908F48',
            'is_active' => 1,
            'assigned_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/cards/revoke', ['cardId' => $cardId])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertDatabaseHas('employee_cards', [
            'id' => $cardId,
            'is_active' => 0,
        ]);

        $this->withToken($token)
            ->postJson('/api/admin/cards/delete', ['cardId' => $cardId])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertDatabaseMissing('employee_cards', [
            'id' => $cardId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CARD_DELETED',
            'target_type' => 'CARD',
            'target_id' => (string) $cardId,
        ]);
    }

    private function issueAdminToken(): string
    {
        DB::table('users')->insert([
            'name' => '管理者',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ])->json('data.accessToken');
    }
}
