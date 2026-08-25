<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_structures')) {
            Schema::create('project_structures', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('project_structures')->nullOnDelete();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name');
                $table->string('code', 50);
                $table->string('status')->default('active')->index();
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index(['company_id', 'parent_id'], 'project_structures_company_parent_index');
                $table->index(['company_id', 'status'], 'project_structures_company_status_index');
            });
        }

        if (! Schema::hasTable('project_structure_models')) {
            Schema::create('project_structure_models', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name');
                $table->string('code', 50);
                $table->string('short_name', 50);
                $table->string('status')->default('active')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index(['company_id', 'status'], 'project_structure_models_company_status_index');
            });
        }

        $this->createActiveUniqueIndex('project_structures', 'project_structures_company_doc_number_unique_active', ['company_id', 'doc_number'], 'doc_number');
        $this->createActiveUniqueIndex('project_structures', 'project_structures_company_doc_num_unique_active', ['company_id', 'doc_num'], 'doc_num');
        $this->createActiveUniqueIndex('project_structures', 'project_structures_company_code_unique_active', ['company_id', 'code'], 'code');
        $this->createActiveUniqueIndex('project_structure_models', 'project_structure_models_company_doc_number_unique_active', ['company_id', 'doc_number'], 'doc_number');
        $this->createActiveUniqueIndex('project_structure_models', 'project_structure_models_company_doc_num_unique_active', ['company_id', 'doc_num'], 'doc_num');
        $this->createActiveUniqueIndex('project_structure_models', 'project_structure_models_company_code_unique_active', ['company_id', 'code'], 'code');
        $this->createActiveUniqueIndex('project_structure_models', 'project_structure_models_company_short_name_unique_active', ['company_id', 'short_name'], 'short_name');
    }

    public function down(): void {}

    /** @param list<string> $columns */
    private function createActiveUniqueIndex(string $tableName, string $index, array $columns, string $nullableColumn): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $wrappedNullableColumn = $grammar->wrap($nullableColumn);
        $predicate = $grammar->wrap('deleted_at').' IS NULL AND '.$wrappedNullableColumn.' IS NOT NULL';

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}"),
            default => null,
        };
    }
};
