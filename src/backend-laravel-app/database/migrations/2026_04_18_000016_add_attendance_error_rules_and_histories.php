<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_error_rules')) {
            Schema::create('attendance_error_rules', function (Blueprint $table) {
                $table->string('code', 40)->primary();
                $table->string('name', 80);
                $table->integer('min_work_minutes')->nullable();
                $table->integer('max_work_minutes')->nullable();
                $table->integer('required_break_minutes')->nullable();
                $table->integer('max_break_minutes')->nullable();
                $table->boolean('enabled')->default(true);
                $table->string('note', 255)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_error_resolution_histories')) {
            Schema::create('attendance_error_resolution_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_error_resolution_id')->nullable();
                $table->foreignId('employee_id');
                $table->date('target_date');
                $table->string('error_code', 40);
                $table->string('old_status', 20)->nullable();
                $table->string('new_status', 20);
                $table->text('comment')->nullable();
                $table->foreignId('handled_by')->nullable();
                $table->dateTime('handled_at');
                $table->timestamps();

                $table->foreign('attendance_error_resolution_id', 'ae_hist_resolution_fk')
                    ->references('id')
                    ->on('attendance_error_resolutions')
                    ->nullOnDelete();
                $table->foreign('employee_id', 'ae_hist_employee_fk')
                    ->references('id')
                    ->on('employees');
                $table->foreign('handled_by', 'ae_hist_handler_fk')
                    ->references('id')
                    ->on('employees')
                    ->nullOnDelete();
                $table->index(['employee_id', 'target_date', 'error_code'], 'attendance_error_history_error_idx');
                $table->index(['handled_at'], 'attendance_error_history_handled_idx');
            });
        }

        $now = now();
        foreach ($this->defaultRules($now) as $rule) {
            DB::table('attendance_error_rules')->updateOrInsert(
                ['code' => $rule['code']],
                array_merge([
                    'min_work_minutes' => null,
                    'max_work_minutes' => null,
                    'required_break_minutes' => null,
                    'max_break_minutes' => null,
                    'enabled' => 1,
                    'note' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ], $rule),
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_error_resolution_histories');
        Schema::dropIfExists('attendance_error_rules');
    }

    private function defaultRules($now): array
    {
        return [
            [
                'code' => 'MISSING_PUNCH',
                'name' => '出勤・退勤入力漏れ',
                'sort_order' => 10,
                'note' => '出勤または退勤の片方のみが入力されている日次を検知',
            ],
            [
                'code' => 'MISSING_BOTH_PUNCHES',
                'name' => '出勤・退勤未入力',
                'sort_order' => 20,
                'note' => '休暇ではない日に出退勤が未入力の日次を検知',
            ],
            [
                'code' => 'LEAVE_WITH_WORK',
                'name' => '休日・休暇の出勤',
                'sort_order' => 30,
                'note' => '休暇扱いの日に出退勤がある日次を検知',
            ],
            [
                'code' => 'MISSING_BREAK',
                'name' => '休憩入力漏れ',
                'min_work_minutes' => 360,
                'required_break_minutes' => 1,
                'sort_order' => 40,
                'note' => '6時間超勤務で休憩0分の日次を検知',
            ],
            [
                'code' => 'SHORT_BREAK_6_TO_8',
                'name' => '休憩不足（6〜8時間）',
                'min_work_minutes' => 360,
                'max_work_minutes' => 480,
                'required_break_minutes' => 45,
                'sort_order' => 50,
                'note' => '6時間超8時間以下勤務の休憩不足を検知',
            ],
            [
                'code' => 'SHORT_BREAK_OVER_8',
                'name' => '休憩不足（8時間超）',
                'min_work_minutes' => 480,
                'required_break_minutes' => 60,
                'sort_order' => 60,
                'note' => '8時間超勤務の休憩不足を検知',
            ],
            [
                'code' => 'BREAK_TOO_LONG',
                'name' => '休憩時間の超過',
                'max_break_minutes' => 240,
                'sort_order' => 70,
                'note' => '休憩が拘束時間以上または上限超過の日次を検知',
            ],
            [
                'code' => 'LONG_WORK',
                'name' => '長時間勤務',
                'min_work_minutes' => 720,
                'sort_order' => 80,
                'note' => '実働が12時間を超える日次を検知',
            ],
        ];
    }
};
