<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                'loginEmail' => 'staff100@example.com',
                'googleChatUserId' => '1234567890',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.employeeCode', 'E100')
            ->assertJsonPath('data.loginEmail', 'staff100@example.com')
            ->assertJsonPath('data.googleChatUserId', 'users/1234567890');

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'E100',
            'google_chat_user_id' => 'users/1234567890',
        ]);
        $this->assertDatabaseHas('employee_auth', [
            'employee_id' => (int) $response->json('data.id'),
            'login_id' => 'staff100@example.com',
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
                'loginEmail' => 'staff101@example.com',
                'googleChatUserId' => 'users/9999999999',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.loginEmail', 'staff101@example.com')
            ->assertJsonPath('data.googleChatUserId', 'users/9999999999');

        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'google_chat_user_id' => 'users/9999999999',
        ]);
        $this->assertDatabaseHas('employee_auth', [
            'employee_id' => $employeeId,
            'login_id' => 'staff101@example.com',
        ]);
    }

    public function test_employee_csv_imports_additional_columns(): void
    {
        $token = $this->issueAdminToken();
        $file = UploadedFile::fake()->createWithContent('employees.csv', implode("\r\n", [
            '社員番号,姓,名,ふりがな,所属,勤務場所,雇用区分,状態,入職日,退職日,メールアドレス,Google Chat ID',
            'E200,山田,花子,やまだ はなこ,保育,分園,非常勤,在職,2025-04-01,,staff200@example.com,1234567890',
            '',
        ]));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/employees/import-csv', [
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.createdCount', 1)
            ->assertJsonPath('data.items.0.locationName', '分園')
            ->assertJsonPath('data.items.0.employmentType', 'PART_TIME')
            ->assertJsonPath('data.items.0.status', 'ACTIVE')
            ->assertJsonPath('data.items.0.loginEmail', 'staff200@example.com')
            ->assertJsonPath('data.items.0.googleChatUserId', 'users/1234567890');

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'E200',
            'kana' => 'やまだ はなこ',
            'department_name' => '保育',
            'location_name' => '分園',
            'employment_type' => 'PART_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2025-04-01',
            'retired_on' => null,
            'google_chat_user_id' => 'users/1234567890',
        ]);
        $employeeId = (int) DB::table('employees')->where('employee_code', 'E200')->value('id');
        $this->assertDatabaseHas('employee_auth', [
            'employee_id' => $employeeId,
            'login_id' => 'staff200@example.com',
        ]);
    }

    public function test_employee_csv_update_preserves_fields_when_optional_columns_are_missing(): void
    {
        $token = $this->issueAdminToken();

        DB::table('employees')->insert([
            'employee_code' => 'E201',
            'name' => '佐藤 太郎',
            'kana' => 'さとう たろう',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'RETIRED',
            'joined_on' => '2020-04-01',
            'retired_on' => '2025-03-31',
            'google_chat_user_id' => 'users/1111111111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = UploadedFile::fake()->createWithContent('employees-old-format.csv', implode("\r\n", [
            '社員番号,姓,名',
            'E201,佐藤,次郎',
            '',
        ]));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/admin/employees/import-csv', [
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updatedCount', 1);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'E201',
            'name' => '佐藤 次郎',
            'kana' => 'さとう たろう',
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'RETIRED',
            'joined_on' => '2020-04-01',
            'retired_on' => '2025-03-31',
            'google_chat_user_id' => 'users/1111111111',
        ]);
    }

    public function test_employee_template_csv_includes_additional_columns_and_chat_id(): void
    {
        $token = $this->issueAdminToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/admin/employees/template-csv');

        $response->assertOk();
        $content = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");

        $this->assertStringContainsString('社員番号,姓,名,ふりがな,所属,勤務場所,雇用区分,状態,入職日,退職日,メールアドレス,Google Chat ID', $content);
    }

    public function test_employee_login_email_must_be_unique(): void
    {
        $token = $this->issueAdminToken();
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E301',
            'name' => '既存 職員',
            'kana' => null,
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_auth')->insert([
            'employee_id' => $employeeId,
            'login_id' => 'duplicate@example.com',
            'password_hash' => Hash::make('ChangeMe123!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/employees', [
                'employeeCode' => 'E302',
                'name' => '新規 職員',
                'departmentName' => '保育',
                'employmentType' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'joinedOn' => '2024-04-01',
                'loginEmail' => 'duplicate@example.com',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
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
