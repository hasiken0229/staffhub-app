<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_statements', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('replaced_at')->nullable();
        });
        Schema::table('payroll_statement_views', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
            $table->index(['payroll_statement_id', 'revision'], 'payroll_views_revision_idx');
        });
        Schema::table('notices', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('stopped_at')->nullable();
            $table->dateTime('notification_sent_at')->nullable();
            $table->dateTime('notification_queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropUnique(['request_id']);
            $table->dropColumn(['request_id', 'request_fingerprint', 'revision', 'stopped_at', 'notification_sent_at', 'notification_queued_at']);
        });
        Schema::table('payroll_statement_views', function (Blueprint $table) {
            $table->dropIndex('payroll_views_revision_idx');
            $table->dropColumn('revision');
        });
        Schema::table('payroll_statements', fn (Blueprint $table) => $table->dropColumn(['revision', 'replaced_at']));
    }
};
