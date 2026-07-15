<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $tables = [
        'hr_countries' => 'hr_countries',
        'hr_governorates' => 'hr_governorates',
        'hr_cities' => 'hr_cities',
        'hr_areas' => 'hr_areas',
    ];

    public function up(): void
    {
        foreach (array_keys($this->tables) as $table) {
            if (! Schema::hasTable($table)) {
                $this->createLookupTable($table);
            }

            $this->createActiveUniqueIndexes($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys($this->tables)) as $table) {
            foreach (['doc_number', 'doc_num', 'name'] as $column) {
                $wrappedIndex = DB::getQueryGrammar()->wrap("{$table}_{$column}_unique_active");

                match (DB::getDriverName()) {
                    'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                    default => null,
                };
            }

            Schema::dropIfExists($table);
        }
    }

    private function createLookupTable(string $tableName): void
    {
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->integer('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->string('name')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    private function createActiveUniqueIndexes(string $table): void
    {
        foreach (['doc_number', 'doc_num', 'name'] as $column) {
            if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            $this->dropPostgreSqlSingleColumnUniqueIndexes($table, $column);
            $this->createActiveUniqueIndex($table, $column, "{$table}_{$column}_unique_active");
        }
    }

    private function dropPostgreSqlSingleColumnUniqueIndexes(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

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

    private function createActiveUniqueIndex(string $table, string $column, string $index): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');
        $notNull = in_array($column, ['doc_number', 'doc_num'], true) ? " AND {$wrappedColumn} IS NOT NULL" : '';

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL{$notNull}"
            ),
            default => null,
        };
    }
};
