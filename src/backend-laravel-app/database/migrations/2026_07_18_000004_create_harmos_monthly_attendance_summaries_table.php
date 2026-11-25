<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('harmos_monthly_attendance_summaries')) {
            return;
        }

        Schema::create('harmos_monthly_attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('target_year_month', 7);
            $table->integer('total_work_minutes');
            $table->integer('actual_work_minutes')->nullable();
            $table->integer('hour_paid_leave_minutes')->default(0);
            $table->decimal('paid_leave_days', 6, 2)->default(0);
            $table->decimal('special_leave_days', 6, 2)->default(0);
            $table->json('leave_breakdown')->nullable();
            $table->string('source_file_name', 255)->nullable();
            $table->timestamp('source_imported_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'target_year_month'], 'harmos_monthly_employee_month_unique');
            $table->index('target_year_month', 'harmos_monthly_target_month_idx');
        });
    }

    public function down(): void
    {
        // These columns or rows can predate this migration on existing installations.
        // Keep their production data when rolling application code back.
    }
};
