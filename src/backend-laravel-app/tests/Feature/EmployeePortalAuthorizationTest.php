<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class EmployeePortalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_admin_can_access_admin_and_employee_portal_api(): void
    {
        $employeeId = $this->createEmployee('132', '橋本 健次');

        DB::table('users')->insert([
            'name' => '橋本 健次',
            'email' => 'hasiken0229@gmail.com',
            'password' => Hash::make('hasiken0229'),
            'employee_id' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'hasiken0229@gmail.com',
            'password' => 'hasiken0229',
            'audience' => 'ADMIN',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.role', 'ADMIN')
            ->assertJsonPath('data.user.isAdmin', true)
            ->assertJsonPath('data.user.employeeId', $employeeId)
            ->assertJsonPath('data.user.employeeCode', '132')
            ->assertJsonPath('data.user.canUseEmployeePortal', true);

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/employees')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employeeId)
            ->assertJsonPath('data.employee.employeeCode', '132')
            ->assertJsonPath('data.employee.name', '橋本 健次');
    }

    public function test_unlinked_admin_cannot_access_employee_portal_api(): void
    {
        DB::table('users')->insert([
            'name' => '園長先生',
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'audience' => 'ADMIN',
        ]);

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/mobile/home')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_employee_cannot_access_admin_api(): void
    {
        $employeeId = $this->createEmployee('E0001', '山田 太郎');

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

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'staff001',
            'password' => 'Staff1234!',
            'audience' => 'EMPLOYEE',
        ]);

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/employees')
            ->assertStatus(403);
    }

    private function createEmployee(string $employeeCode, string $name): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => $employeeCode,
            'name' => $name,
            'kana' => null,
            'department_name' => '未設定',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => now()->toDateString(),
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
