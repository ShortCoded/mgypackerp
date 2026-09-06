<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const ActiveUserIndex = 'user_presence_sessions_user_status_last_seen_index';

    private const ExpirySweepIndex = 'user_presence_sessions_status_expires_at_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('user_presence_sessions')) {
            return;
        }

        $this->addIndexIfMissing(
            ['user_id', 'status', 'last_seen_at'],
            self::ActiveUserIndex,
        );
        $this->addIndexIfMissing(
            ['status', 'expires_at'],
            self::ExpirySweepIndex,
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('user_presence_sessions')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach ([self::ActiveUserIndex, self::ExpirySweepIndex] as $indexName) {
                DB::statement(sprintf(
                    'DROP INDEX CONCURRENTLY IF EXISTS %s',
                    DB::getQueryGrammar()->wrap($indexName),
                ));
            }

            return;
        }

        foreach ([self::ActiveUserIndex, self::ExpirySweepIndex] as $indexName) {
            if (! Schema::hasIndex('user_presence_sessions', $indexName)) {
                continue;
            }

            Schema::table('user_presence_sessions', function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(array $columns, string $indexName): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresIndexConcurrently($columns, $indexName);

            return;
        }

        if (Schema::hasIndex('user_presence_sessions', $columns)) {
            return;
        }

        Schema::table('user_presence_sessions', function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function createPostgresIndexConcurrently(array $columns, string $indexName): void
    {
        $targetIndexValidity = $this->postgresIndexValidity($indexName);

        if ($targetIndexValidity === false) {
            $this->dropPostgresIndexConcurrently($indexName);
        }

        if ($targetIndexValidity === true) {
            if (! $this->postgresHasValidIndex($columns, $indexName)) {
                throw new RuntimeException("Index [{$indexName}] already exists with an unexpected definition.");
            }

            return;
        }

        if ($this->postgresHasValidIndex($columns)) {
            return;
        }

        $grammar = DB::getQueryGrammar();

        DB::statement(sprintf(
            'CREATE INDEX CONCURRENTLY %s ON %s (%s)',
            $grammar->wrap($indexName),
            $grammar->wrapTable('user_presence_sessions'),
            implode(', ', $grammar->wrapArray($columns)),
        ));
    }

    private function dropPostgresIndexConcurrently(string $indexName): void
    {
        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            DB::getQueryGrammar()->wrap($indexName),
        ));
    }

    private function postgresIndexValidity(string $indexName): ?bool
    {
        $index = DB::selectOne(
            'SELECT CASE WHEN i.indisvalid AND i.indisready THEN 1 ELSE 0 END AS is_valid '
            .'FROM pg_index i '
            .'JOIN pg_class tc ON tc.oid = i.indrelid '
            .'JOIN pg_namespace tn ON tn.oid = tc.relnamespace '
            .'JOIN pg_class ic ON ic.oid = i.indexrelid '
            .'WHERE tn.nspname = current_schema() AND tc.relname = ? AND ic.relname = ? LIMIT 1',
            ['user_presence_sessions', $indexName],
        );

        return $index === null ? null : (int) $index->is_valid === 1;
    }

    /**
     * @param  list<string>  $columns
     */
    private function postgresHasValidIndex(array $columns, ?string $indexName = null): bool
    {
        $indexPredicate = $indexName === null ? '' : 'AND ic.relname = ? ';
        $bindings = array_values(array_filter([
            'user_presence_sessions',
            $indexName,
            json_encode($columns, JSON_THROW_ON_ERROR),
        ], fn (mixed $binding): bool => $binding !== null));

        return DB::selectOne(
            'SELECT 1 AS index_exists '
            .'FROM pg_index i '
            .'JOIN pg_class tc ON tc.oid = i.indrelid '
            .'JOIN pg_namespace tn ON tn.oid = tc.relnamespace '
            .'JOIN pg_class ic ON ic.oid = i.indexrelid '
            .'WHERE tn.nspname = current_schema() AND tc.relname = ? AND i.indisvalid AND i.indisready '
            ."AND i.indpred IS NULL AND i.indexprs IS NULL {$indexPredicate}"
            .'AND (SELECT jsonb_agg(a.attname ORDER BY keys.ordinality) '
            .'FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum, ordinality) '
            .'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = keys.attnum) = CAST(? AS jsonb) '
            .'LIMIT 1',
            $bindings,
        ) !== null;
    }
};
