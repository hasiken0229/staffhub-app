<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('import_histories', 'download_file_name')) {
                $table->string('download_file_name', 255)->nullable()->after('source_file_name');
            }

            if (!Schema::hasColumn('import_histories', 'file_path')) {
                $table->string('file_path', 255)->nullable()->after('summary_json');
            }

            if (!Schema::hasColumn('import_histories', 'content_type')) {
                $table->string('content_type', 120)->nullable()->after('file_path');
            }

            if (!Schema::hasColumn('import_histories', 'expires_at')) {
                $table->dateTime('expires_at')->nullable()->after('content_type');
            }

            if (!Schema::hasColumn('import_histories', 'updated_at')) {
                $table->dateTime('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $columns = [
                'download_file_name',
                'file_path',
                'content_type',
                'expires_at',
                'updated_at',
            ];

            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('import_histories', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
