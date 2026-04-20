<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payroll_statements', 'statement_type')) {
            Schema::table('payroll_statements', function (Blueprint $table) {
                $table->string('statement_type', 20)->default('PAYROLL')->after('employee_id');
            });
        }

        DB::table('payroll_statements')
            ->whereNull('statement_type')
            ->update(['statement_type' => 'PAYROLL']);

        if ($this->usesSqlite()) {
            Schema::table('payroll_statements', function (Blueprint $table) {
                if (!$this->indexExists('payroll_statements', 'payroll_statements_employee_id_idx')) {
                    $table->index(['employee_id'], 'payroll_statements_employee_id_idx');
                }
                if ($this->indexExists('payroll_statements', 'payroll_statements_employee_id_target_year_month_unique')) {
                    $table->dropUnique('payroll_statements_employee_id_target_year_month_unique');
                }
                if (!$this->indexExists('payroll_statements', 'payroll_statements_employee_type_month_unique')) {
                    $table->unique(['employee_id', 'statement_type', 'target_year_month'], 'payroll_statements_employee_type_month_unique');
                }
                if (!$this->indexExists('payroll_statements', 'payroll_statements_type_published_idx')) {
                    $table->index(['statement_type', 'published_at'], 'payroll_statements_type_published_idx');
                }
            });

            return;
        }

        $this->ensureIndex('payroll_statements', 'payroll_statements_employee_id_idx', 'ALTER TABLE `payroll_statements` ADD INDEX `payroll_statements_employee_id_idx` (`employee_id`)');

        if ($this->indexExists('payroll_statements', 'payroll_statements_employee_id_target_year_month_unique')) {
            DB::statement('ALTER TABLE `payroll_statements` DROP INDEX `payroll_statements_employee_id_target_year_month_unique`');
        }

        $this->ensureIndex(
            'payroll_statements',
            'payroll_statements_employee_type_month_unique',
            'ALTER TABLE `payroll_statements` ADD UNIQUE `payroll_statements_employee_type_month_unique` (`employee_id`, `statement_type`, `target_year_month`)'
        );

        $this->ensureIndex(
            'payroll_statements',
            'payroll_statements_type_published_idx',
            'ALTER TABLE `payroll_statements` ADD INDEX `payroll_statements_type_published_idx` (`statement_type`, `published_at`)'
        );
    }

    public function down(): void
    {
        if ($this->usesSqlite()) {
            Schema::table('payroll_statements', function (Blueprint $table) {
                if ($this->indexExists('payroll_statements', 'payroll_statements_employee_type_month_unique')) {
                    $table->dropUnique('payroll_statements_employee_type_month_unique');
                }
                if ($this->indexExists('payroll_statements', 'payroll_statements_type_published_idx')) {
                    $table->dropIndex('payroll_statements_type_published_idx');
                }
                if (!$this->indexExists('payroll_statements', 'payroll_statements_employee_id_target_year_month_unique')) {
                    $table->unique(['employee_id', 'target_year_month'], 'payroll_statements_employee_id_target_year_month_unique');
                }
            });

            if (Schema::hasColumn('payroll_statements', 'statement_type')) {
                Schema::table('payroll_statements', function (Blueprint $table) {
                    $table->dropColumn('statement_type');
                });
            }

            return;
        }

        if ($this->indexExists('payroll_statements', 'payroll_statements_employee_type_month_unique')) {
            DB::statement('ALTER TABLE `payroll_statements` DROP INDEX `payroll_statements_employee_type_month_unique`');
        }

        if ($this->indexExists('payroll_statements', 'payroll_statements_type_published_idx')) {
            DB::statement('ALTER TABLE `payroll_statements` DROP INDEX `payroll_statements_type_published_idx`');
        }

        $this->ensureIndex(
            'payroll_statements',
            'payroll_statements_employee_id_target_year_month_unique',
            'ALTER TABLE `payroll_statements` ADD UNIQUE `payroll_statements_employee_id_target_year_month_unique` (`employee_id`, `target_year_month`)'
        );

        if (Schema::hasColumn('payroll_statements', 'statement_type')) {
            Schema::table('payroll_statements', function (Blueprint $table) {
                $table->dropColumn('statement_type');
            });
        }
    }

    private function ensureIndex(string $table, string $indexName, string $sql): void
    {
        if (!$this->indexExists($table, $indexName)) {
            DB::statement($sql);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if ($this->usesSqlite()) {
            $indexes = DB::select("PRAGMA index_list('$table')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $databaseName = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $databaseName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function usesSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }
};
