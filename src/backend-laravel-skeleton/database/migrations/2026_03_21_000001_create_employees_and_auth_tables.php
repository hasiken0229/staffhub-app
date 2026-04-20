<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 30)->unique();
            $table->string('name', 100);
            $table->string('kana', 100)->nullable();
            $table->string('department_name', 100)->nullable()->index();
            $table->string('employment_type', 30);
            $table->string('status', 20)->index();
            $table->date('joined_on');
            $table->date('retired_on')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_auth', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->primary();
            $table->string('login_id', 100)->unique();
            $table->string('password_hash', 255);
            $table->dateTime('password_updated_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('mobile_push_token', 255)->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_auth');
        Schema::dropIfExists('employees');
    }
};
