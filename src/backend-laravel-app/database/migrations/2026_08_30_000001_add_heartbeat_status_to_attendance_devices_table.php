<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missing = array_diff(
            ['client_time_at', 'clock_drift_seconds', 'pending_offline_count', 'reader_status'],
            Schema::getColumnListing('attendance_devices'),
        );
        if (!$missing) {
            return;
        }

        Schema::table('attendance_devices', function (Blueprint $table) use ($missing) {
            if (in_array('client_time_at', $missing, true)) {
                $table->dateTime('client_time_at')->nullable();
            }
            if (in_array('clock_drift_seconds', $missing, true)) {
                $table->integer('clock_drift_seconds')->nullable();
            }
            if (in_array('pending_offline_count', $missing, true)) {
                $table->unsignedInteger('pending_offline_count')->nullable();
            }
            if (in_array('reader_status', $missing, true)) {
                $table->string('reader_status', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        // These columns or rows can predate this migration on existing installations.
        // Keep their production data when rolling application code back.
    }
};
