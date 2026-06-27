<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_break_rules')) {
            Schema::create('attendance_break_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->default('園標準');
                $table->unsignedSmallInteger('base_break_minutes')->default(45);
                $table->unsignedSmallInteger('threshold_work_minutes')->default(480);
                $table->unsignedSmallInteger('threshold_break_minutes')->default(60);
                $table->boolean('is_active')->default(true);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (!DB::table('attendance_break_rules')->where('is_active', true)->exists()) {
            DB::table('attendance_break_rules')->insert([
                'name' => '園標準',
                'base_break_minutes' => 45,
                'threshold_work_minutes' => 480,
                'threshold_break_minutes' => 60,
                'is_active' => true,
                'note' => '8時間以上の勤務は1時間休憩、それ未満は45分休憩。',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('employee_attendance_settings')) {
            Schema::create('employee_attendance_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
                $table->time('standard_clock_in_time')->nullable();
                $table->time('standard_clock_out_time')->nullable();
                $table->boolean('include_before_start')->default(false);
                $table->boolean('include_after_end')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_shift_schedules')) {
            Schema::create('attendance_shift_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->date('target_date');
                $table->foreignId('work_type_id')->nullable()->constrained('work_type_settings')->nullOnDelete();
                $table->time('scheduled_clock_in_time')->nullable();
                $table->time('scheduled_clock_out_time')->nullable();
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
                $table->timestamps();
                $table->unique(['employee_id', 'target_date'], 'attendance_shift_employee_date_unique');
                $table->index('target_date');
                $table->index('work_type_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_schedules');
        Schema::dropIfExists('employee_attendance_settings');
        Schema::dropIfExists('attendance_break_rules');
    }
};
