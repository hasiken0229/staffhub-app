<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_legacy_calendar_periods')) {
            Schema::create('payroll_legacy_calendar_periods', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_id');
                $table->char('target_month', 7);
                $table->timestamp('reopened_at')->nullable();
                $table->primary(['employee_id', 'target_month']);
            });
        }
        // Only preserve months that actually contain this employee's frozen attendance.
        $months = DB::table('attendance_monthly_closes')->where('status', 'CLOSED')->pluck('target_year_month');
        foreach ($months as $month) {
            $start = CarbonImmutable::parse($month.'-01');
            $employees = DB::table('employees as e')->join('attendance_daily as ad', 'ad.employee_id', '=', 'e.id')
                ->whereIn('e.employment_type', ['非常勤', 'パート', 'アルバイト'])
                ->whereBetween('ad.target_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
                ->where('ad.close_status', 'CLOSED');
            if (Schema::hasColumn('employees', 'is_dependent_within')) {
                $employees->where(fn ($q) => $q->whereNull('e.is_dependent_within')->orWhere('e.is_dependent_within', false));
            }
            foreach ($employees->distinct()->pluck('e.id')->chunk(250) as $ids) {
                DB::table('payroll_legacy_calendar_periods')->insertOrIgnore($ids->map(fn ($id) => [
                    'employee_id' => $id, 'target_month' => $month,
                ])->values()->all());
            }
        }
    }

    public function down(): void
    {
        // Existing period snapshots must survive rolling back application code.
    }
};
