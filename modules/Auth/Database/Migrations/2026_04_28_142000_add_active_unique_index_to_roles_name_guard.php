<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ActiveIndex = 'roles_name_guard_unique_active';

    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        match (DB::getDriverName()) {
            'pgsql' => $this->updatePostgreSqlIndex($table),
            'sqlite' => $this->updateSqliteIndex($table),
            default => null,
        };
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($table)) {
            return;
        }

        $wrappedIndex = DB::getQueryGrammar()->wrap(self::ActiveIndex);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }

    private function updatePostgreSqlIndex(string $table): void
    {
        $this->dropPostgreSqlNameGuardUniqueIndexes($table);
        $this->createActiveIndex($table);
    }

    private function updateSqliteIndex(string $table): void
    {
        $wrappedOldIndex = DB::getQueryGrammar()->wrap('roles_name_guard_name_unique');

        DB::statement("DROP INDEX IF EXISTS {$wrappedOldIndex}");
        $this->createActiveIndex($table);
    }

    private function dropPostgreSqlNameGuardUniqueIndexes(string $table): void
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
                ) = ARRAY['name', 'guard_name']
        SQL, [$table]);

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

    private function createActiveIndex(string $table): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap(self::ActiveIndex);
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedName = $grammar->wrap('name');
        $wrappedGuardName = $grammar->wrap('guard_name');
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedName}, {$wrappedGuardName}) WHERE {$wrappedDeletedAt} IS NULL"
        );
    }
};
