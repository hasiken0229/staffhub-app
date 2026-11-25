<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paid_leave_hourly_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('effective_on');
            $table->date('expires_on');
            $table->unsignedInteger('remaining_minutes');
            $table->unsignedInteger('source_total_minutes')->nullable();
            $table->string('source_system', 30)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_on'], 'paid_leave_hourly_employee_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_hourly_balances');
    }
};
