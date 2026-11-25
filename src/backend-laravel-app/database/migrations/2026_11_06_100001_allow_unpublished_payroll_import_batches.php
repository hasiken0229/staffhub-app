<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_import_batches', function (Blueprint $table) {
            $table->date('publish_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_import_batches')->whereNull('publish_date')->exists()) {
            throw new RuntimeException('未公開の給与取込があるため公開日を必須に戻せません。');
        }
        Schema::table('payroll_import_batches', function (Blueprint $table) {
            $table->date('publish_date')->nullable(false)->change();
        });
    }
};
