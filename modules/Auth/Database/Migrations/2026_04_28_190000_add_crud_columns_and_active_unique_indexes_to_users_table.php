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
    private array $activeIndexes = [
        'username' => 'users_username_unique_active',
        'email' => 'users_email_unique_active',
        'phone' => 'users_phone_unique_active',
        'doc_number' => 'users_doc_number_unique_active',
        'doc_num' => 'users_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'doc_number')) {
                $table->integer('doc_number')->nullable()->after('id');
            }

            if (! Schema::hasColumn('users', 'doc_num')) {
                $table->string('doc_num')->nullable()->after('doc_number');
            }

            if (! Schema::hasColumn('users', 'notes')) {
                $table->text('notes')->nullable()->after('locale');
            }

            if (! Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->index();
            }
        });

        if (! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        $this->backfillDocumentNumbers();

        match (DB::getDriverName()) {
            'pgsql' => $this->updatePostgreSqlIndexes(),
            'sqlite' => $this->updateSqliteIndexes(),
            default => null,
        };
    }

    private function backfillDocumentNumbers(): void
    {
        $users = DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('doc_number')
                    ->orWhereNull('doc_num');
            })
            ->orderBy('id')
            ->get(['id']);
        $nextNumber = (int) DB::table('users')->max('doc_number');

        foreach ($users as $user) {
            $nextNumber++;

            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'doc_number' => $nextNumber,
                    'doc_num' => 'User-'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
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

    private function updatePostgreSqlIndexes(): void
    {
        foreach ($this->activeIndexes as $column => $index) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            $this->dropPostgreSqlSingleColumnUniqueIndexes($column);
            $this->createActiveIndex($column, $index);
        }
    }

    private function updateSqliteIndexes(): void
    {
        foreach ($this->activeIndexes as $column => $index) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            foreach ($this->sqliteUniqueIndexNames($column) as $oldIndex) {
                $wrappedOldIndex = DB::getQueryGrammar()->wrap($oldIndex);

                DB::statement("DROP INDEX IF EXISTS {$wrappedOldIndex}");
            }

            $this->createActiveIndex($column, $index);
        }
    }

    private function dropPostgreSqlSingleColumnUniqueIndexes(string $column): void
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
                AND table_class.relname = 'users'
                AND pg_index.indisunique = true
                AND pg_index.indpred IS NULL
                AND (
                    SELECT array_agg(pg_attribute.attname::text ORDER BY indexed_columns.ordinality)
                    FROM unnest(pg_index.indkey) WITH ORDINALITY AS indexed_columns(attnum, ordinality)
                    INNER JOIN pg_attribute
                        ON pg_attribute.attrelid = table_class.oid
                        AND pg_attribute.attnum = indexed_columns.attnum
                ) = ARRAY[?]
        SQL, [$column]);

        $wrappedTable = DB::getQueryGrammar()->wrapTable('users');

        foreach ($indexes as $index) {
            if ($index->constraint_name !== null) {
                $wrappedConstraint = DB::getQueryGrammar()->wrap($index->constraint_name);

                DB::statement("ALTER TABLE {$wrappedTable} DROP CONSTRAINT IF EXISTS {$wrappedConstraint}");
            }

            $wrappedIndex = DB::getQueryGrammar()->wrap($index->index_name);

            DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
        }
    }

    private function createActiveIndex(string $column, string $index): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('users');
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL"
        );
    }

    /**
     * @return list<string>
     */
    private function sqliteUniqueIndexNames(string $column): array
    {
        return match ($column) {
            'email' => ['users_email_unique'],
            'username' => ['users_username_unique'],
            'phone' => ['users_phone_unique'],
            default => [],
        };
    }
};
