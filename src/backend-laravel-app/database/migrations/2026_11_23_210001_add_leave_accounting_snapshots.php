<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $columns = [
            'leave_types' => [
                'attendance_effect' => fn (Blueprint $t) => $t->string('attendance_effect', 16),
            ],
            'leave_requests' => [
                'effective_dates' => fn (Blueprint $t) => $t->json('effective_dates'),
                'attendance_effect' => fn (Blueprint $t) => $t->string('attendance_effect', 16),
                'attendance_label' => fn (Blueprint $t) => $t->string('attendance_label', 50),
                'balance_source' => fn (Blueprint $t) => $t->string('balance_source', 16),
                'hourly_day_minutes' => fn (Blueprint $t) => $t->unsignedSmallInteger('hourly_day_minutes'),
            ],
            'attendance_daily' => [
                'request_leave_name' => fn (Blueprint $t) => $t->string('request_leave_name', 80),
                'request_leave_days' => fn (Blueprint $t) => $t->decimal('request_leave_days', 3, 2),
            ],
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $name => $define) {
                if (!Schema::hasColumn($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $define($blueprint)->nullable());
                }
            }
        }
    }

    public function down(): void
    {
        // Leave snapshots are part of the approved ledger. The previous code tolerates
        // these nullable additions; preserve them when rolling the application back.
    }
};
