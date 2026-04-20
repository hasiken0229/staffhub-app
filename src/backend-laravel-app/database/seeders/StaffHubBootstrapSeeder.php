<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffHubBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = $this->readEnv('STAFFHUB_ADMIN_EMAIL');
        $adminPassword = $this->readEnv('STAFFHUB_ADMIN_PASSWORD');
        $adminName = $this->readEnv('STAFFHUB_ADMIN_NAME') ?: '勤怠管理 管理者';

        if ($adminEmail !== '' && $adminPassword !== '') {
            $admin = DB::table('users')
                ->where('email', $adminEmail)
                ->first();

            if ($admin === null) {
                DB::table('users')->insert([
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'email_verified_at' => now(),
                    'password' => Hash::make($adminPassword),
                    'remember_token' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('users')
                    ->where('id', $admin->id)
                    ->update([
                        'name' => $adminName,
                        'password' => Hash::make($adminPassword),
                        'updated_at' => now(),
                    ]);
            }
        }

        $deviceCode = $this->readEnv('STAFFHUB_DEVICE_CODE');
        $deviceSecret = $this->readEnv('STAFFHUB_DEVICE_SECRET');
        $deviceName = $this->readEnv('STAFFHUB_DEVICE_NAME') ?: '玄関端末';

        if ($deviceCode !== '' && $deviceSecret !== '') {
            $device = DB::table('attendance_devices')
                ->where('device_code', $deviceCode)
                ->first();

            if ($device === null) {
                DB::table('attendance_devices')->insert([
                    'device_code' => $deviceCode,
                    'name' => $deviceName,
                    'location_name' => $this->readEnv('STAFFHUB_DEVICE_LOCATION') ?: '玄関',
                    'os_user' => null,
                    'app_version' => null,
                    'device_secret_hash' => Hash::make($deviceSecret),
                    'last_seen_at' => null,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('attendance_devices')
                    ->where('id', $device->id)
                    ->update([
                        'name' => $deviceName,
                        'location_name' => $this->readEnv('STAFFHUB_DEVICE_LOCATION') ?: $device->location_name,
                        'device_secret_hash' => Hash::make($deviceSecret),
                        'is_active' => 1,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (!filter_var(env('STAFFHUB_SEED_SAMPLE', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $employeeCode = env('STAFFHUB_SAMPLE_EMPLOYEE_CODE', 'E0001');
        $employeeName = env('STAFFHUB_SAMPLE_EMPLOYEE_NAME', '山田 太郎');
        $cardUid = strtoupper((string) env('STAFFHUB_SAMPLE_CARD_UID', '012E4CE15C908F48'));

        $employee = DB::table('employees')
            ->where('employee_code', $employeeCode)
            ->first();

        if ($employee === null) {
            $employeeId = (int) DB::table('employees')->insertGetId([
                'employee_code' => $employeeCode,
                'name' => $employeeName,
                'kana' => null,
                'department_name' => env('STAFFHUB_SAMPLE_DEPARTMENT', '総務'),
                'employment_type' => 'FULL_TIME',
                'status' => 'ACTIVE',
                'joined_on' => now()->toDateString(),
                'retired_on' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $employeeId = (int) $employee->id;
        }

        $employeeAuth = DB::table('employee_auth')
            ->where('employee_id', $employeeId)
            ->first();

        $employeeLoginId = env('STAFFHUB_SAMPLE_LOGIN_ID', 'staff001');
        $employeePassword = env('STAFFHUB_SAMPLE_PASSWORD', 'Staff1234!');

        if ($employeeAuth === null) {
            DB::table('employee_auth')->insert([
                'employee_id' => $employeeId,
                'login_id' => $employeeLoginId,
                'password_hash' => Hash::make($employeePassword),
                'password_updated_at' => now(),
                'last_login_at' => null,
                'mobile_push_token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('employee_auth')
                ->where('employee_id', $employeeId)
                ->update([
                    'login_id' => $employeeLoginId,
                    'password_hash' => Hash::make($employeePassword),
                    'password_updated_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        DB::table('employee_cards')
            ->where('is_active', 1)
            ->where(function ($query) use ($employeeId, $cardUid) {
                $query->where('employee_id', $employeeId)
                    ->orWhereRaw('upper(card_uid) = ?', [$cardUid]);
            })
            ->update([
                'is_active' => 0,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        $activeCard = DB::table('employee_cards')
            ->where('employee_id', $employeeId)
            ->whereRaw('upper(card_uid) = ?', [$cardUid])
            ->where('is_active', 1)
            ->first();

        if ($activeCard === null) {
            DB::table('employee_cards')->insert([
                'employee_id' => $employeeId,
                'card_uid' => $cardUid,
                'is_active' => 1,
                'assigned_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $grant = DB::table('paid_leave_grants')
            ->where('employee_id', $employeeId)
            ->where('granted_on', now()->startOfYear()->toDateString())
            ->first();

        if ($grant === null) {
            DB::table('paid_leave_grants')->insert([
                'employee_id' => $employeeId,
                'granted_on' => now()->startOfYear()->toDateString(),
                'granted_days' => 10.0,
                'used_days' => 0,
                'expires_on' => now()->addYear()->endOfYear()->toDateString(),
                'note' => '初期サンプル付与',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function readEnv(string $key): string
    {
        return trim((string) env($key, ''));
    }
}
