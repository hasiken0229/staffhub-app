<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // No existing attendance or imported balances are guessed or rewritten.
        Schema::create('attendance_leave_projections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('target_date');
            $t->json('baseline_json');
            $t->json('applied_json');
            $t->json('expected_json');
            $t->text('evidence_reference')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'target_date'], 'attendance_leave_projection_day');
        });
        Schema::table('paid_leave_grants', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_grant_id')->nullable();
            $t->dateTime('superseded_at')->nullable();
            $t->date('original_granted_on')->nullable();
            $t->dateTime('expiry_reviewed_at')->nullable();
            $t->unsignedBigInteger('expiry_reviewed_by')->nullable();
            $t->text('evidence_reference')->nullable();
        });
        Schema::table('leave_requests', function (Blueprint $t) {
            $t->string('approved_actor_type', 20)->nullable();
            $t->unsignedBigInteger('approved_actor_id')->nullable();
        });
        Schema::table('leave_request_actions', function (Blueprint $t) {
            $t->unsignedBigInteger('action_by')->nullable()->change();
            $t->string('actor_type', 20)->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
        });
    }

    public function down(): void
    {
        // A rollback must not discard confirmed provenance or reviewed lot history.
        throw new RuntimeException('Leave provenance migration requires an explicit data-preserving rollback.');
    }
};
