<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // SQLite's TEXT affinity already supports the complete YYYY-MM-DD_YYYY-MM-DD.
        if (Schema::getConnection()->getDriverName() === 'sqlite') { return; }
        $type = strtolower(Schema::getColumnType('import_histories', 'target_period', true));
        if (str_contains($type, 'text') || (preg_match('/\((\d+)\)/', $type, $length) && (int) $length[1] >= 32)) {
            return;
        }
        Schema::table('import_histories', fn (Blueprint $table) => $table->string('target_period', 32)->nullable()->change());
    }

    public function down(): void
    {
        // YYYY-MM months and full date ranges coexist. Shrinking would truncate historical records.
    }
};
