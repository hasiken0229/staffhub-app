<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('location_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employment_type_settings', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('label', 60);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_type_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('default_break_minutes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('request_type_settings', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('name', 60);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('paid_leave_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_name', 100)->unique();
            $table->decimal('annual_grant_days', 5, 2)->default(10);
            $table->integer('carry_forward_months')->default(24);
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_alert_settings', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('name', 60);
            $table->integer('threshold_minutes')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_daily_field_settings', function (Blueprint $table) {
            $table->string('field_key', 50)->primary();
            $table->string('label', 60);
            $table->integer('display_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $departmentNames = DB::table('employees')
            ->whereNotNull('department_name')
            ->where('department_name', '<>', '')
            ->distinct()
            ->orderBy('department_name')
            ->pluck('department_name')
            ->all();

        foreach ($departmentNames as $index => $name) {
            DB::table('department_settings')->insert([
                'name' => $name,
                'sort_order' => $index + 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $locationNames = DB::table('attendance_devices')
            ->whereNotNull('location_name')
            ->where('location_name', '<>', '')
            ->distinct()
            ->orderBy('location_name')
            ->pluck('location_name')
            ->all();

        foreach ($locationNames as $index => $name) {
            DB::table('location_settings')->insert([
                'name' => $name,
                'sort_order' => $index + 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $employmentTypes = DB::table('employees')
            ->whereNotNull('employment_type')
            ->where('employment_type', '<>', '')
            ->distinct()
            ->orderBy('employment_type')
            ->pluck('employment_type')
            ->all();

        foreach ($employmentTypes as $index => $code) {
            DB::table('employment_type_settings')->insert([
                'code' => $code,
                'label' => $code,
                'sort_order' => $index + 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('work_type_settings')->insert([
            [
                'name' => '通常勤務',
                'default_break_minutes' => 60,
                'sort_order' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '有給休暇',
                'default_break_minutes' => 0,
                'sort_order' => 2,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '特別休暇',
                'default_break_minutes' => 0,
                'sort_order' => 3,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('request_type_settings')->insert([
            ['code' => 'PAID', 'name' => '有給申請', 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'ABSENCE', 'name' => '欠勤申請', 'sort_order' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SPECIAL', 'name' => '特休申請', 'sort_order' => 3, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'HALF_DAY', 'name' => '半休申請', 'sort_order' => 4, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('paid_leave_settings')->insert([
            'setting_name' => '標準設定',
            'annual_grant_days' => 10,
            'carry_forward_months' => 24,
            'note' => '当園の初期設定',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendance_alert_settings')->insert([
            ['code' => 'LATE', 'name' => '遅刻アラート', 'threshold_minutes' => 1, 'enabled' => 1, 'note' => '定刻を過ぎた打刻を検知', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'MISSING_PUNCH', 'name' => '打刻漏れ', 'threshold_minutes' => null, 'enabled' => 1, 'note' => '出勤または退勤が欠けている日次を検知', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'LONG_OVERTIME', 'name' => '長時間勤務', 'threshold_minutes' => 120, 'enabled' => 1, 'note' => '所定超過が長い日次を検知', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('attendance_daily_field_settings')->insert([
            ['field_key' => 'work_type', 'label' => '勤務区分', 'display_order' => 1, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'clock_in', 'label' => '出勤', 'display_order' => 2, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'clock_out', 'label' => '退勤', 'display_order' => 3, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'break_minutes', 'label' => '休憩', 'display_order' => 4, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'paid_leave_minutes', 'label' => '時間有給', 'display_order' => 5, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'remark', 'label' => '備考', 'display_order' => 6, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['field_key' => 'approval_status', 'label' => '申請承認状態', 'display_order' => 7, 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_field_settings');
        Schema::dropIfExists('attendance_alert_settings');
        Schema::dropIfExists('paid_leave_settings');
        Schema::dropIfExists('request_type_settings');
        Schema::dropIfExists('work_type_settings');
        Schema::dropIfExists('employment_type_settings');
        Schema::dropIfExists('location_settings');
        Schema::dropIfExists('department_settings');
    }
};
