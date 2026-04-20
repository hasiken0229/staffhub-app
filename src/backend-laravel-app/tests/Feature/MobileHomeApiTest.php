<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MobileHomeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_home_includes_leave_types_for_request_form(): void
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E0001',
            'name' => '山田 太郎',
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => now()->toDateString(),
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'staff001',
            'password_hash' => Hash::make('Staff1234!'),
            'password_updated_at' => now(),
            'last_login_at' => null,
            'mobile_push_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('paid_leave_grants')->insert([
            'employee_id' => $employeeId,
            'granted_on' => now()->startOfYear()->toDateString(),
            'granted_days' => 10,
            'used_days' => 0,
            'expires_on' => now()->endOfYear()->toDateString(),
            'note' => '初期付与',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'staff001',
            'password' => 'Staff1234!',
            'audience' => 'EMPLOYEE',
        ]);

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employeeId)
            ->assertJsonPath('data.leaveTypes.0.code', 'PAID')
            ->assertJsonPath('data.leaveTypes.0.name', '有給休暇')
            ->assertJsonPath('data.leaveTypes.0.requiresBalance', true)
            ->assertJsonPath('data.leaveTypes.0.allowsHalfDay', true)
            ->assertJsonPath('data.leaveTypes.1.code', 'ABSENCE')
            ->assertJsonPath('data.leaveTypes.2.code', 'SPECIAL');
    }
}
