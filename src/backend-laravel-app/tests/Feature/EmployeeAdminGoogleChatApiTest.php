<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class EmployeeAdminGoogleChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_employee_with_google_chat_user_id(): void
    {
        $token = $this->issueAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/employees', [
                'employeeCode' => 'E100',
                'name' => '橋本 良孝',
                'kana' => 'ハシモト ヨシタカ',
                'departmentName' => '事務',
                'employmentType' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'joinedOn' => '2024-04-01',
                'googleChatUserId' => '1234567890',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.employeeCode', 'E100')
            ->assertJsonPath('data.googleChatUserId', 'users/1234567890');

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'E100',
            'google_chat_user_id' => 'users/1234567890',
        ]);
    }

    public function test_admin_can_update_employee_google_chat_user_id(): void
    {
        $token = $this->issueAdminToken();

        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E101',
            'name' => '鈴木 一郎',
            'kana' => 'スズキ イチロウ',
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/employees/' . $employeeId, [
                'employeeCode' => 'E101',
                'name' => '鈴木 一郎',
                'kana' => 'スズキ イチロウ',
                'departmentName' => '保育',
                'employmentType' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'joinedOn' => '2024-04-01',
                'googleChatUserId' => 'users/9999999999',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.googleChatUserId', 'users/9999999999');

        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'google_chat_user_id' => 'users/9999999999',
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
