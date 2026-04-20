<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_type_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('work_type_settings', 'standard_day_minutes')) {
                $table->integer('standard_day_minutes')->nullable();
            }
        });

        Schema::table('employment_type_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('employment_type_settings', 'standard_day_minutes')) {
                $table->integer('standard_day_minutes')->nullable();
            }
        });

        if (!Schema::hasTable('attendance_daily_edit_requests')) {
            Schema::create('attendance_daily_edit_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees');
                $table->date('target_date')->index();
                $table->foreignId('work_type_id')->nullable()->constrained('work_type_settings');
                $table->dateTime('clock_in_at')->nullable();
                $table->dateTime('clock_out_at')->nullable();
                $table->json('breaks_json')->nullable();
                $table->text('remark')->nullable();
                $table->text('employee_comment')->nullable();
                $table->string('status', 20)->default('PENDING')->index();
                $table->foreignId('approved_by')->nullable()->constrained('employees');
                $table->dateTime('approved_at')->nullable();
                $table->text('decision_comment')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'target_date']);
                $table->index(['status', 'target_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_edit_requests');

        Schema::table('employment_type_settings', function (Blueprint $table) {
            if (Schema::hasColumn('employment_type_settings', 'standard_day_minutes')) {
                $table->dropColumn('standard_day_minutes');
            }
        });

        Schema::table('work_type_settings', function (Blueprint $table) {
            if (Schema::hasColumn('work_type_settings', 'standard_day_minutes')) {
                $table->dropColumn('standard_day_minutes');
            }
        });
    }
};
