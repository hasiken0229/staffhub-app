<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('name', 50)->unique();
            $table->boolean('requires_balance');
            $table->boolean('allows_half_day');
            $table->integer('sort_order');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('leave_type_code', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('day_unit', 10);
            $table->string('half_day_type', 10)->nullable();
            $table->decimal('quantity_days', 4, 2);
            $table->text('reason')->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('approved_by')->nullable()->constrained('employees');
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
            $table->index(['status', 'start_date']);
            $table->foreign('leave_type_code')->references('code')->on('leave_types');
        });

        Schema::create('leave_request_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests');
            $table->foreignId('action_by')->constrained('employees');
            $table->string('action_type', 20);
            $table->text('comment')->nullable();
            $table->dateTime('acted_at');

            $table->index(['leave_request_id', 'acted_at']);
        });

        Schema::create('paid_leave_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('granted_on');
            $table->decimal('granted_days', 4, 2);
            $table->decimal('used_days', 4, 2)->default(0);
            $table->date('expires_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'granted_on']);
            $table->index('expires_on');
        });

        DB::table('leave_types')->insert([
            ['code' => 'PAID', 'name' => '有給休暇', 'requires_balance' => 1, 'allows_half_day' => 1, 'sort_order' => 1],
            ['code' => 'ABSENCE', 'name' => '欠勤', 'requires_balance' => 0, 'allows_half_day' => 0, 'sort_order' => 2],
            ['code' => 'SPECIAL', 'name' => '特別休暇', 'requires_balance' => 0, 'allows_half_day' => 1, 'sort_order' => 3],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grants');
        Schema::dropIfExists('leave_request_actions');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
