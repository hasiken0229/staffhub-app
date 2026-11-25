<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_edit_requests', fn (Blueprint $table) => $table->json('daily_baseline')->nullable());
    }

    public function down(): void
    {
        Schema::table('attendance_daily_edit_requests', fn (Blueprint $table) => $table->dropColumn('daily_baseline'));
    }
};
