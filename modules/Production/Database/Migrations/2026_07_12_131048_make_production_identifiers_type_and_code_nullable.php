<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('production_identifiers')) {
            return;
        }

        $this->dropIndexIfExists('production_identifiers_company_type_code_unique_active');

        if (Schema::hasColumn('production_identifiers', 'production_identifier_type_id')) {
            $this->dropNotNull('production_identifier_type_id');
        }

        if (Schema::hasColumn('production_identifiers', 'identifier_code')) {
            $this->dropNotNull('identifier_code');
        }

        $this->createIndexIfMissing('production_identifiers_company_parent_index', ['company_id', 'parent_id']);
        $this->createIndexIfMissing('production_identifiers_company_status_group_index', ['company_id', 'status', 'is_group']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left as a safe no-op. Requiring identifier type/code again
        // would fail or rewrite existing identifiers created after this migration.
    }

    private function dropNotNull(string $column): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('production_identifiers');
        $wrappedColumn = $grammar->wrap($column);

        match (DB::getDriverName()) {
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$wrappedColumn} DROP NOT NULL"),
            'mysql', 'mariadb' => $column === 'production_identifier_type_id'
                ? DB::statement("ALTER TABLE {$table} MODIFY {$wrappedColumn} BIGINT UNSIGNED NULL")
                : DB::statement("ALTER TABLE {$table} MODIFY {$wrappedColumn} VARCHAR(50) NULL"),
            default => null,
        };
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE production_identifiers DROP INDEX {$wrappedIndex}"),
            default => null,
        };
    }

    /**
     * @param  list<string>  $columns
     */
    private function createIndexIfMissing(string $index, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('production_identifiers', $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('production_identifiers');
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns})"),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$wrappedTable} ADD INDEX {$wrappedIndex} ({$wrappedColumns})"),
            default => null,
        };
    }
};
