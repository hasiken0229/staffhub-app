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
        $employeeId = $this->createEmployee('970005', '見本姓AR 職員D');

        DB::table('users')->insert([
            'name' => '見本姓AR 職員D',
            'email' => 'demo-contact-e3b717@example.test',
            'password' => Hash::make('Example-Test-Password-92'),
            'employee_id' => $employeeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'demo-contact-e3b717@example.test',
            'password' => 'Example-Test-Password-92',
            'audience' => 'ADMIN',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.role', 'ADMIN')
            ->assertJsonPath('data.user.isAdmin', true)
            ->assertJsonPath('data.user.employeeId', $employeeId)
            ->assertJsonPath('data.user.employeeCode', '970005')
            ->assertJsonPath('data.user.canUseEmployeePortal', true);

        $token = $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/employees')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employeeId)
            ->assertJsonPath('data.employee.employeeCode', '970005')
            ->assertJsonPath('data.employee.name', '見本姓AR 職員D');
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

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/mobile/home')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_employee_cannot_access_admin_api(): void
    {
        $employeeId = $this->createEmployee('E0001', '見本姓K 職員J');

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

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/employees')
            ->assertStatus(403);
    }

    public function test_retired_employee_access_and_refresh_tokens_are_revoked(): void
    {
        $employeeId = $this->createEmployee('E0002', '退職 職員');

        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'retired-staff@example.invalid',
            'password_hash' => Hash::make('Staff1234!'),
            'password_updated_at' => now(),
            'last_login_at' => null,
            'mobile_push_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'loginId' => 'retired-staff@example.invalid',
            'password' => 'Staff1234!',
            'audience' => 'EMPLOYEE',
        ])->assertOk();

        DB::table('employees')
            ->where('id', $employeeId)
            ->update([
                'status' => 'RETIRED',
                'retired_on' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        $this->withHeader('Authorization', 'Bearer '.$login->json('data.accessToken'))
            ->getJson('/api/mobile/home')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');

        $this->assertDatabaseCount('api_access_tokens', 0);

        $this->postJson('/api/auth/refresh', ['refreshToken' => $login->json('data.refreshToken')])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
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
