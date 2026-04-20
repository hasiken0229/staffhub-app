<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_monthly_closes', function (Blueprint $table) {
            $table->id();
            $table->char('target_year_month', 7)->unique();
            $table->string('status', 20)->default('OPEN');
            $table->string('note', 255)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'target_year_month'], 'attendance_monthly_closes_status_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_monthly_closes');
    }
};
