<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->char('target_year_month', 7);
            $table->string('file_path', 255);
            $table->string('original_file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('employees');
            $table->timestamps();

            $table->unique(['employee_id', 'target_year_month']);
            $table->index('published_at');
        });

        Schema::create('payroll_statement_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_statement_id')->constrained('payroll_statements');
            $table->foreignId('employee_id')->constrained('employees');
            $table->dateTime('viewed_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['payroll_statement_id', 'viewed_at']);
            $table->index(['employee_id', 'viewed_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('notification_type', 30);
            $table->string('title', 100);
            $table->text('body');
            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('sent_at');
            $table->dateTime('read_at')->nullable();

            $table->index(['employee_id', 'is_read', 'sent_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->string('target_type', 50);
            $table->string('target_id', 100)->nullable();
            $table->json('detail_json')->nullable();
            $table->dateTime('occurred_at');
            $table->string('ip_address', 45)->nullable();

            $table->index('occurred_at');
            $table->index(['actor_type', 'actor_id']);
            $table->index(['target_type', 'target_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payroll_statement_views');
        Schema::dropIfExists('payroll_statements');
    }
};
