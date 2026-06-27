<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_type_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('work_type_settings', 'start_time')) {
                $table->time('start_time')->nullable()->after('name');
            }

            if (!Schema::hasColumn('work_type_settings', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_type_settings', function (Blueprint $table) {
            if (Schema::hasColumn('work_type_settings', 'end_time')) {
                $table->dropColumn('end_time');
            }

            if (Schema::hasColumn('work_type_settings', 'start_time')) {
                $table->dropColumn('start_time');
            }
        });
    }
};
