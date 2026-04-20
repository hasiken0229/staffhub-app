<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_data_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('statement_type', 20);
            $table->string('definition_name', 100);
            $table->unsignedInteger('template_version')->default(1);
            $table->unsignedInteger('field_count')->default(0);
            $table->string('sample_file_name', 255)->nullable();
            $table->json('sample_headers_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['statement_type', 'is_active'], 'payroll_definitions_type_active_idx');
        });

        Schema::create('payroll_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('statement_type', 20);
            $table->foreignId('payroll_data_definition_id')->constrained('payroll_data_definitions');
            $table->char('target_year_month', 7);
            $table->date('period_start_on');
            $table->date('period_end_on');
            $table->date('pay_date');
            $table->date('publish_date');
            $table->string('source_file_name', 255);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('status', 30)->default('PENDING');
            $table->json('summary_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['statement_type', 'target_year_month'], 'payroll_import_batches_type_month_idx');
            $table->index(['deleted_at', 'created_at'], 'payroll_import_batches_deleted_created_idx');
        });

        Schema::create('payroll_import_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_import_batch_id')->constrained('payroll_import_batches');
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('employee_code', 20);
            $table->string('employee_name', 100);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->foreignId('statement_id')->nullable()->constrained('payroll_statements')->nullOnDelete();
            $table->unsignedInteger('line_no');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['payroll_import_batch_id', 'employee_code'], 'payroll_batch_items_batch_employee_idx');
            $table->index(['statement_id', 'deleted_at'], 'payroll_batch_items_statement_deleted_idx');
        });

        Schema::create('payroll_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_statement_id')->constrained('payroll_statements');
            $table->string('section_type', 20);
            $table->unsignedInteger('display_order');
            $table->string('item_label', 100);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('raw_source_key', 100)->nullable();

            $table->index(['payroll_statement_id', 'section_type', 'display_order'], 'payroll_lines_statement_section_order_idx');
        });

        Schema::table('payroll_statements', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_statements', 'pay_date')) {
                $table->date('pay_date')->nullable()->after('target_year_month');
            }
            if (!Schema::hasColumn('payroll_statements', 'period_start_on')) {
                $table->date('period_start_on')->nullable()->after('pay_date');
            }
            if (!Schema::hasColumn('payroll_statements', 'period_end_on')) {
                $table->date('period_end_on')->nullable()->after('period_start_on');
            }
            if (!Schema::hasColumn('payroll_statements', 'payroll_data_definition_id')) {
                $table->foreignId('payroll_data_definition_id')->nullable()->after('uploaded_by')->constrained('payroll_data_definitions')->nullOnDelete();
            }
            if (!Schema::hasColumn('payroll_statements', 'payroll_import_batch_id')) {
                $table->foreignId('payroll_import_batch_id')->nullable()->after('payroll_data_definition_id')->constrained('payroll_import_batches')->nullOnDelete();
            }
            if (!Schema::hasColumn('payroll_statements', 'remarks')) {
                $table->text('remarks')->nullable()->after('payroll_import_batch_id');
            }
            if (!Schema::hasColumn('payroll_statements', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('remarks');
            }
            if (!Schema::hasColumn('payroll_statements', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('employees')->nullOnDelete();
            }
        });

        Schema::table('payroll_statements', function (Blueprint $table) {
            if (!$this->hasIndex('payroll_statements', 'payroll_statements_deleted_published_idx')) {
                $table->index(['deleted_at', 'published_at'], 'payroll_statements_deleted_published_idx');
            }
            if (!$this->hasIndex('payroll_statements', 'payroll_statements_batch_deleted_idx')) {
                $table->index(['payroll_import_batch_id', 'deleted_at'], 'payroll_statements_batch_deleted_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_statements', function (Blueprint $table) {
            if ($this->hasIndex('payroll_statements', 'payroll_statements_deleted_published_idx')) {
                $table->dropIndex('payroll_statements_deleted_published_idx');
            }
            if ($this->hasIndex('payroll_statements', 'payroll_statements_batch_deleted_idx')) {
                $table->dropIndex('payroll_statements_batch_deleted_idx');
            }
            if (Schema::hasColumn('payroll_statements', 'deleted_by')) {
                $table->dropConstrainedForeignId('deleted_by');
            }
            if (Schema::hasColumn('payroll_statements', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
            if (Schema::hasColumn('payroll_statements', 'remarks')) {
                $table->dropColumn('remarks');
            }
            if (Schema::hasColumn('payroll_statements', 'payroll_import_batch_id')) {
                $table->dropConstrainedForeignId('payroll_import_batch_id');
            }
            if (Schema::hasColumn('payroll_statements', 'payroll_data_definition_id')) {
                $table->dropConstrainedForeignId('payroll_data_definition_id');
            }
            if (Schema::hasColumn('payroll_statements', 'period_end_on')) {
                $table->dropColumn('period_end_on');
            }
            if (Schema::hasColumn('payroll_statements', 'period_start_on')) {
                $table->dropColumn('period_start_on');
            }
            if (Schema::hasColumn('payroll_statements', 'pay_date')) {
                $table->dropColumn('pay_date');
            }
        });

        Schema::dropIfExists('payroll_statement_lines');
        Schema::dropIfExists('payroll_import_batch_items');
        Schema::dropIfExists('payroll_import_batches');
        Schema::dropIfExists('payroll_data_definitions');
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('$tableName')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
