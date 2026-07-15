<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_identifier_types')) {
            Schema::create('production_identifier_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->unsignedBigInteger('doc_number')->nullable();
                $table->string('doc_num')->nullable();
                $table->string('name');
                $table->text('notes')->nullable();
                $table->string('status')->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index('company_id', 'production_identifier_types_company_id_index');
            });
        }

        $this->createActiveUniqueIndex('production_identifier_types_company_doc_number_unique_active', ['company_id', 'doc_number'], 'doc_number');
        $this->createActiveUniqueIndex('production_identifier_types_company_doc_num_unique_active', ['company_id', 'doc_num'], 'doc_num');
        $this->createActiveUniqueIndex('production_identifier_types_company_name_unique_active', ['company_id', 'name'], 'name');
    }

    public function down(): void
    {
        foreach ([
            'production_identifier_types_company_name_unique_active',
            'production_identifier_types_company_doc_num_unique_active',
            'production_identifier_types_company_doc_number_unique_active',
        ] as $index) {
            $this->dropIndexIfExists($index);
        }

        Schema::dropIfExists('production_identifier_types');
    }

    /**
     * @param  list<string>  $columns
     */
    private function createActiveUniqueIndex(string $index, array $columns, string $nullableColumn): void
    {
        if (! Schema::hasTable('production_identifier_types')) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn('production_identifier_types', $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('production_identifier_types');
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $predicate = $grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('company_id').' IS NOT NULL';

        if (in_array($nullableColumn, ['doc_number', 'doc_num'], true)) {
            $predicate .= ' AND '.$grammar->wrap($nullableColumn).' IS NOT NULL';
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}"),
            default => null,
        };
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }
};
