<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('care_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('category', 20);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('eligible_count');
            $table->unsignedSmallInteger('day_minutes');
            $table->date('effective_on');
            $table->unsignedInteger('opening_minutes');
            $table->string('note', 500);
            $table->timestamps();
            $table->unique(['employee_id', 'category', 'fiscal_year'], 'care_leave_employee_year_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('care_leave_balances'); }
};
