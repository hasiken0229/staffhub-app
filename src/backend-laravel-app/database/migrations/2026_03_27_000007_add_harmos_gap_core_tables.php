<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_daily', 'schedule_name')) {
                $table->string('schedule_name', 100)->nullable()->after('target_date');
            }

            if (!Schema::hasColumn('attendance_daily', 'break_minutes')) {
                $table->integer('break_minutes')->default(0)->after('clock_out_at');
            }

            if (!Schema::hasColumn('attendance_daily', 'hour_paid_leave_minutes')) {
                $table->integer('hour_paid_leave_minutes')->default(0)->after('paid_leave_unit');
            }

            if (!Schema::hasColumn('attendance_daily', 'child_care_leave_minutes')) {
                $table->integer('child_care_leave_minutes')->default(0)->after('hour_paid_leave_minutes');
            }

            if (!Schema::hasColumn('attendance_daily', 'nursing_care_leave_minutes')) {
                $table->integer('nursing_care_leave_minutes')->default(0)->after('child_care_leave_minutes');
            }

            if (!Schema::hasColumn('attendance_daily', 'approval_status')) {
                $table->string('approval_status', 20)->default('PENDING')->after('remark')->index();
            }

            if (!Schema::hasColumn('attendance_daily', 'approval_comment')) {
                $table->text('approval_comment')->nullable()->after('approval_status');
            }

            if (!Schema::hasColumn('attendance_daily', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('approval_comment')->constrained('employees');
            }

            if (!Schema::hasColumn('attendance_daily', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            }

            if (!Schema::hasColumn('attendance_daily', 'close_status')) {
                $table->string('close_status', 20)->default('OPEN')->after('approved_at');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('decision_comment')->constrained('employees');
            }

            if (!Schema::hasColumn('leave_requests', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            }
        });

        Schema::create('paid_leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('adjustment_type', 20);
            $table->decimal('days', 6, 2);
            $table->date('effective_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();

            $table->index(['employee_id', 'effective_on']);
            $table->index('adjustment_type');
        });

        Schema::create('paid_leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('entry_type', 30);
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('occurred_on');
            $table->decimal('days_delta', 6, 2);
            $table->decimal('balance_after', 8, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->dateTime('created_at');

            $table->index(['employee_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
            $table->index('entry_type');
        });

        Schema::create('import_histories', function (Blueprint $table) {
            $table->id();
            $table->string('import_type', 40);
            $table->string('source_file_name', 255);
            $table->char('target_period', 7)->nullable();
            $table->string('statement_type', 20)->nullable();
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('summary_json')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('employees');
            $table->dateTime('created_at');

            $table->index(['import_type', 'created_at']);
            $table->index('target_period');
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('notice_type', 30);
            $table->string('title', 120);
            $table->text('body');
            $table->dateTime('publish_start_at');
            $table->dateTime('publish_end_at')->nullable();
            $table->foreignId('target_employee_id')->nullable()->constrained('employees');
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();

            $table->index(['notice_type', 'publish_start_at']);
            $table->index(['publish_start_at', 'publish_end_at']);
            $table->index('target_employee_id');
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices');
            $table->foreignId('employee_id')->constrained('employees');
            $table->dateTime('read_at');

            $table->unique(['notice_id', 'employee_id']);
            $table->index(['employee_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('import_histories');
        Schema::dropIfExists('paid_leave_ledger');
        Schema::dropIfExists('paid_leave_adjustments');

        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            if (Schema::hasColumn('leave_requests', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });

        Schema::table('attendance_daily', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_daily', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }

            $columns = [
                'schedule_name',
                'break_minutes',
                'hour_paid_leave_minutes',
                'child_care_leave_minutes',
                'nursing_care_leave_minutes',
                'approval_status',
                'approval_comment',
                'approved_at',
                'close_status',
            ];

            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('attendance_daily', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
