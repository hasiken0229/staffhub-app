<?php

namespace Tests\Feature;

use App\Services\GoogleChatRecipientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GoogleChatRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_employee_google_chat_user_id_with_normalization(): void
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E001',
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'department_name' => '保育',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(GoogleChatRecipientResolver::class)->resolveEmployeeUserId($employeeId);

        $this->assertSame('users/1234567890', $resolved);
    }

    public function test_it_resolves_admin_and_all_staff_space_ids_with_normalization(): void
    {
        config()->set('staffhub.google_chat.admin_space_id', 'AAAAadminSpace');
        config()->set('staffhub.google_chat.all_staff_space_id', 'spaces/AAAAallStaffSpace');

        $resolver = app(GoogleChatRecipientResolver::class);

        $this->assertSame('spaces/AAAAadminSpace', $resolver->resolveAdminSpaceId());
        $this->assertSame('spaces/AAAAallStaffSpace', $resolver->resolveAllStaffSpaceId());
    }

    public function test_it_returns_null_when_employee_chat_user_id_is_missing(): void
    {
        $employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E002',
            'name' => '佐藤 次郎',
            'kana' => 'サトウ ジロウ',
            'department_name' => '事務',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2024-04-01',
            'retired_on' => null,
            'google_chat_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(GoogleChatRecipientResolver::class)->resolveEmployeeUserId($employeeId);

        $this->assertNull($resolved);
    }
}
