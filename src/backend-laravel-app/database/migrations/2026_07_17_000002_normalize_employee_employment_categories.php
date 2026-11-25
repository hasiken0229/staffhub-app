<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'is_management_supervisor')) {
                $table->boolean('is_management_supervisor')->default(false)->after('employment_type');
            }
            if (!Schema::hasColumn('employees', 'is_dependent_within')) {
                $table->boolean('is_dependent_within')->default(false)->after('is_management_supervisor');
            }
        });

        DB::transaction(function (): void {
            DB::table('employees')
                ->where('employment_type', '管理監督者')
                ->update([
                    'employment_type' => 'FULL_TIME',
                    'is_management_supervisor' => true,
                    'updated_at' => now(),
                ]);

            DB::table('employees')
                ->where('employment_type', '扶養内')
                ->update([
                    'employment_type' => 'PART_TIME',
                    'is_dependent_within' => true,
                    'updated_at' => now(),
                ]);

            // Preserve legacy master rows and any locally configured labels and day lengths.
            foreach (['FULL_TIME' => ['常勤', 1], 'PART_TIME' => ['非常勤', 2]] as $code => [$label, $order]) {
                $existing = DB::table('employment_type_settings')->where('code', $code);
                if ($existing->exists()) {
                    $existing->where('label', $code)->update(['label' => $label, 'updated_at' => now()]);
                } else {
                    DB::table('employment_type_settings')->insert([
                        'code' => $code, 'label' => $label, 'sort_order' => $order,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // These columns or rows can predate this migration on existing installations.
        // Keep their production data when rolling application code back.
    }
};
