<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_daily', 'harmos_total_work_minutes')) {
                $table->integer('harmos_total_work_minutes')->nullable()->after('work_minutes');
            }
            if (!Schema::hasColumn('attendance_daily', 'harmos_work_type_id')) {
                $table->string('harmos_work_type_id', 50)->nullable()->after('schedule_name');
            }
            if (!Schema::hasColumn('attendance_daily', 'harmos_leave_summary')) {
                $table->json('harmos_leave_summary')->nullable()->after('nursing_care_leave_minutes');
            }
            if (!Schema::hasColumn('attendance_daily', 'paid_leave_credit_minutes')) {
                $table->integer('paid_leave_credit_minutes')->default(0)->after('paid_leave_unit');
            }
        });
    }

    public function down(): void
    {
        // These columns or rows can predate this migration on existing installations.
        // Keep their production data when rolling application code back.
    }
};
