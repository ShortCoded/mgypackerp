<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $activeIndexes = [
        'doc_number' => 'roles_doc_number_unique_active',
        'doc_num' => 'roles_doc_num_unique_active',
    ];

    /**
     * @var array<string, string>
     */
    private array $legacyIndexes = [
        'doc_number' => 'roles_doc_number_unique',
        'doc_num' => 'roles_doc_num_unique',
    ];

    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        match (DB::getDriverName()) {
            'pgsql' => $this->updatePostgreSqlIndexes($table),
            'sqlite' => $this->updateSqliteIndexes($table),
            default => null,
        };
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($this->activeIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }
    }

    private function updatePostgreSqlIndexes(string $table): void
    {
        foreach (array_keys($this->activeIndexes) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $this->dropPostgreSqlSingleColumnUniqueIndexes($table, $column);
            $this->createActiveIndex($table, $column, $this->activeIndexes[$column]);
        }
    }

    private function updateSqliteIndexes(string $table): void
    {
        foreach ($this->activeIndexes as $column => $index) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $wrappedLegacyIndex = DB::getQueryGrammar()->wrap($this->legacyIndexes[$column]);

            DB::statement("DROP INDEX IF EXISTS {$wrappedLegacyIndex}");
            $this->createActiveIndex($table, $column, $index);
        }
    }

    private function dropPostgreSqlSingleColumnUniqueIndexes(string $table, string $column): void
    {
        $indexes = DB::select(<<<'SQL'
            SELECT
                index_class.relname AS index_name,
                pg_constraint.conname AS constraint_name
            FROM pg_index
            INNER JOIN pg_class AS index_class ON index_class.oid = pg_index.indexrelid
            INNER JOIN pg_class AS table_class ON table_class.oid = pg_index.indrelid
            INNER JOIN pg_namespace ON pg_namespace.oid = table_class.relnamespace
            LEFT JOIN pg_constraint ON pg_constraint.conindid = pg_index.indexrelid
            WHERE pg_namespace.nspname = current_schema()
                AND table_class.relname = ?
                AND pg_index.indisunique = true
                AND pg_index.indpred IS NULL
                AND (
                    SELECT array_agg(pg_attribute.attname::text ORDER BY indexed_columns.ordinality)
                    FROM unnest(pg_index.indkey) WITH ORDINALITY AS indexed_columns(attnum, ordinality)
                    INNER JOIN pg_attribute
                        ON pg_attribute.attrelid = table_class.oid
                        AND pg_attribute.attnum = indexed_columns.attnum
                ) = ARRAY[?]
        SQL, [$table, $column]);

        $wrappedTable = DB::getQueryGrammar()->wrapTable($table);

        foreach ($indexes as $index) {
            if ($index->constraint_name !== null) {
                $wrappedConstraint = DB::getQueryGrammar()->wrap($index->constraint_name);

                DB::statement("ALTER TABLE {$wrappedTable} DROP CONSTRAINT IF EXISTS {$wrappedConstraint}");
            }

            $wrappedIndex = DB::getQueryGrammar()->wrap($index->index_name);

            DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
        }
    }

    private function createActiveIndex(string $table, string $column, string $index): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL"
        );
    }
};
