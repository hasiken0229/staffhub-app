<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('card_uid', 64)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('assigned_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 50)->unique();
            $table->string('name', 100);
            $table->string('location_name', 100)->nullable();
            $table->string('os_user', 100)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('device_secret_hash', 255)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->foreignId('device_id')->constrained('attendance_devices');
            $table->string('card_uid', 64)->index();
            $table->dateTime('occurred_at')->index();
            $table->string('event_type', 20)->nullable();
            $table->string('source_type', 20);
            $table->string('receive_status', 20)->index();
            $table->string('rejection_reason', 100)->nullable();
            $table->boolean('offline_saved')->default(false);
            $table->string('dedupe_key', 100)->nullable()->unique();
            $table->dateTime('created_at');

            $table->index(['employee_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
        });

        Schema::create('attendance_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('target_date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->integer('work_minutes')->nullable();
            $table->boolean('late_flag')->default(false);
            $table->boolean('early_leave_flag')->default(false);
            $table->boolean('absence_flag')->default(false);
            $table->boolean('special_leave_flag')->default(false);
            $table->decimal('paid_leave_unit', 4, 2)->nullable();
            $table->string('remark', 255)->nullable();
            $table->dateTime('updated_at');

            $table->unique(['employee_id', 'target_date']);
            $table->index('target_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('employee_cards');
    }
};
