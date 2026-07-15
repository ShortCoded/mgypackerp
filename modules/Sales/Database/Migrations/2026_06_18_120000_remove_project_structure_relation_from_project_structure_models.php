<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_structure_models')) {
            return;
        }

        foreach ([
            'project_structure_models_structure_short_name_unique_active',
            'project_structure_models_structure_code_unique_active',
            'project_structure_models_company_structure_index',
            'project_structure_models_company_short_name_unique_active',
            'project_structure_models_company_code_unique_active',
        ] as $index) {
            $this->dropIndexIfExists($index);
        }

        if (Schema::hasColumn('project_structure_models', 'project_structure_id')) {
            Schema::table('project_structure_models', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_structure_id');
            });
        }

        $this->createActiveUniqueIndex('project_structure_models_company_code_unique_active', ['company_id', 'code'], 'code');
        $this->createActiveUniqueIndex('project_structure_models_company_short_name_unique_active', ['company_id', 'short_name'], 'short_name');
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_structure_models')) {
            return;
        }

        foreach ([
            'project_structure_models_company_short_name_unique_active',
            'project_structure_models_company_code_unique_active',
        ] as $index) {
            $this->dropIndexIfExists($index);
        }

        if (! Schema::hasColumn('project_structure_models', 'project_structure_id')) {
            Schema::table('project_structure_models', function (Blueprint $table): void {
                $table->foreignId('project_structure_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('project_structures')
                    ->nullOnDelete();
            });

            Schema::table('project_structure_models', function (Blueprint $table): void {
                $table->index(['company_id', 'project_structure_id'], 'project_structure_models_company_structure_index');
            });
        }

        $this->createActiveUniqueIndex('project_structure_models_structure_code_unique_active', ['company_id', 'project_structure_id', 'code'], 'code');
        $this->createActiveUniqueIndex('project_structure_models_structure_short_name_unique_active', ['company_id', 'project_structure_id', 'short_name'], 'short_name');
    }

    /**
     * @param  list<string>  $columns
     */
    private function createActiveUniqueIndex(string $index, array $columns, string $nullableColumn): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('project_structure_models', $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('project_structure_models');
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $wrappedNullableColumn = $grammar->wrap($nullableColumn);
        $predicate = $grammar->wrap('deleted_at').' IS NULL AND '.$wrappedNullableColumn.' IS NOT NULL';

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}"),
            default => null,
        };
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
    }
};
