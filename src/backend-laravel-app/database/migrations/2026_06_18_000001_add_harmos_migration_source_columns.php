<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_daily', 'source_system')) {
                $table->string('source_system', 30)->nullable()->after('close_status')->index();
            }
            if (!Schema::hasColumn('attendance_daily', 'source_import_type')) {
                $table->string('source_import_type', 50)->nullable()->after('source_system');
            }
            if (!Schema::hasColumn('attendance_daily', 'source_file_name')) {
                $table->string('source_file_name', 255)->nullable()->after('source_import_type');
            }
            if (!Schema::hasColumn('attendance_daily', 'source_imported_at')) {
                $table->dateTime('source_imported_at')->nullable()->after('source_file_name');
            }
        });

        Schema::table('paid_leave_grants', function (Blueprint $table) {
            if (!Schema::hasColumn('paid_leave_grants', 'source_system')) {
                $table->string('source_system', 30)->nullable()->after('note')->index();
            }
            if (!Schema::hasColumn('paid_leave_grants', 'source_import_type')) {
                $table->string('source_import_type', 50)->nullable()->after('source_system');
            }
            if (!Schema::hasColumn('paid_leave_grants', 'source_file_name')) {
                $table->string('source_file_name', 255)->nullable()->after('source_import_type');
            }
            if (!Schema::hasColumn('paid_leave_grants', 'source_imported_at')) {
                $table->dateTime('source_imported_at')->nullable()->after('source_file_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grants', function (Blueprint $table) {
            $columns = [
                'source_system',
                'source_import_type',
                'source_file_name',
                'source_imported_at',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('paid_leave_grants', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('attendance_daily', function (Blueprint $table) {
            $columns = [
                'source_system',
                'source_import_type',
                'source_file_name',
                'source_imported_at',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('attendance_daily', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
