<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'location_name')) {
                $table->string('location_name', 100)->nullable()->index();
            }
        });

        Schema::table('attendance_daily', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_daily', 'raw_clock_in_at')) {
                $table->dateTime('raw_clock_in_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_daily', 'raw_clock_out_at')) {
                $table->dateTime('raw_clock_out_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_daily', 'is_manually_edited')) {
                $table->boolean('is_manually_edited')->default(false)->index();
            }
            if (!Schema::hasColumn('attendance_daily', 'work_type_id')) {
                $table->foreignId('work_type_id')->nullable()->constrained('work_type_settings');
            }
            if (!Schema::hasColumn('attendance_daily', 'supervisor_comment')) {
                $table->text('supervisor_comment')->nullable();
            }
            if (!Schema::hasColumn('attendance_daily', 'manual_edited_by')) {
                $table->foreignId('manual_edited_by')->nullable()->constrained('employees');
            }
            if (!Schema::hasColumn('attendance_daily', 'manual_edited_at')) {
                $table->dateTime('manual_edited_at')->nullable();
            }
        });

        DB::table('attendance_daily')
            ->whereNull('raw_clock_in_at')
            ->whereNotNull('clock_in_at')
            ->update(['raw_clock_in_at' => DB::raw('clock_in_at')]);

        DB::table('attendance_daily')
            ->whereNull('raw_clock_out_at')
            ->whereNotNull('clock_out_at')
            ->update(['raw_clock_out_at' => DB::raw('clock_out_at')]);

        if (!Schema::hasTable('attendance_daily_breaks')) {
            Schema::create('attendance_daily_breaks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_daily_id')->constrained('attendance_daily')->cascadeOnDelete();
                $table->unsignedInteger('segment_no');
                $table->dateTime('break_start_at')->nullable();
                $table->dateTime('break_end_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('employees');
                $table->foreignId('updated_by')->nullable()->constrained('employees');
                $table->timestamps();

                $table->unique(['attendance_daily_id', 'segment_no']);
                $table->index(['break_start_at', 'break_end_at']);
            });
        }

        if (!Schema::hasTable('attendance_daily_histories')) {
            Schema::create('attendance_daily_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_daily_id')->constrained('attendance_daily')->cascadeOnDelete();
                $table->string('action_type', 40);
                $table->string('field_key', 60);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->string('actor_type', 20);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->foreignId('actor_employee_id')->nullable()->constrained('employees');
                $table->string('actor_role', 30)->nullable();
                $table->string('actor_employee_code', 30)->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->text('comment')->nullable();
                $table->dateTime('acted_at');

                $table->index(['attendance_daily_id', 'acted_at']);
                $table->index(['action_type', 'acted_at']);
            });
        }

        if (!Schema::hasTable('attendance_error_resolutions')) {
            Schema::create('attendance_error_resolutions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees');
                $table->date('target_date');
                $table->string('error_code', 40);
                $table->string('status', 20)->default('OPEN')->index();
                $table->text('comment')->nullable();
                $table->foreignId('handled_by')->nullable()->constrained('employees');
                $table->dateTime('handled_at')->nullable();
                $table->timestamps();

                $table->unique(['employee_id', 'target_date', 'error_code'], 'attendance_error_resolution_unique');
                $table->index(['target_date', 'error_code']);
            });
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'request_category')) {
                $table->string('request_category', 30)->default('LEAVE')->index();
            }
            if (!Schema::hasColumn('leave_requests', 'time_leave_type')) {
                $table->string('time_leave_type', 30)->nullable()->index();
            }
            if (!Schema::hasColumn('leave_requests', 'target_date')) {
                $table->date('target_date')->nullable()->index();
            }
            if (!Schema::hasColumn('leave_requests', 'start_time')) {
                $table->time('start_time')->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'end_time')) {
                $table->time('end_time')->nullable();
            }
            if (!Schema::hasColumn('leave_requests', 'quantity_minutes')) {
                $table->integer('quantity_minutes')->nullable();
            }
        });

        Schema::table('paid_leave_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('paid_leave_settings', 'standard_day_minutes')) {
                $table->integer('standard_day_minutes')->default(480);
            }
        });

        DB::table('leave_requests')
            ->whereNull('request_category')
            ->update(['request_category' => 'LEAVE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_error_resolutions');
        Schema::dropIfExists('attendance_daily_histories');
        Schema::dropIfExists('attendance_daily_breaks');

        Schema::table('paid_leave_settings', function (Blueprint $table) {
            if (Schema::hasColumn('paid_leave_settings', 'standard_day_minutes')) {
                $table->dropColumn('standard_day_minutes');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $columns = [
                'request_category',
                'time_leave_type',
                'target_date',
                'start_time',
                'end_time',
                'quantity_minutes',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('leave_requests', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('attendance_daily', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_daily', 'work_type_id')) {
                $table->dropConstrainedForeignId('work_type_id');
            }
            if (Schema::hasColumn('attendance_daily', 'manual_edited_by')) {
                $table->dropConstrainedForeignId('manual_edited_by');
            }

            $columns = [
                'raw_clock_in_at',
                'raw_clock_out_at',
                'is_manually_edited',
                'supervisor_comment',
                'manual_edited_at',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('attendance_daily', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'location_name')) {
                $table->dropColumn('location_name');
            }
        });
    }
};
