<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily', fn (Blueprint $table) => $table->json('break_overtime_review')->nullable());
    }

    public function down(): void
    {
        Schema::table('attendance_daily', fn (Blueprint $table) => $table->dropColumn('break_overtime_review'));
    }
};
