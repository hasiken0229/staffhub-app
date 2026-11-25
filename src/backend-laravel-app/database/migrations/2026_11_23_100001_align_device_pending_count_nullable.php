<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The August definition defaulted unknown queue sizes to zero. The production
        // October repair accepts null until a device actually reports a queue size.
        $column = collect(Schema::getColumns('attendance_devices'))->firstWhere('name', 'pending_offline_count');
        if ($column === null || !$column['nullable'] || !in_array($column['default'], [null, 'NULL'], true)) {
            Schema::table('attendance_devices', function (Blueprint $table) use ($column) {
                $field = $table->unsignedInteger('pending_offline_count')->nullable();
                if ($column !== null) {
                    $field->change();
                }
            });
        }
    }

    public function down(): void
    {
        // A down migration must preserve unknown counts and already received telemetry.
    }
};
