<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const IndexName = 'role_has_permissions_role_id_permission_id_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('permission.table_names.role_has_permissions');
        $roleColumn = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $permissionColumn = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        if (! is_string($tableName) || ! Schema::hasTable($tableName)) {
            return;
        }

        $columns = [$roleColumn, $permissionColumn];

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresIndexConcurrently($tableName, $columns);

            return;
        }

        if (Schema::hasIndex($tableName, $columns)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            $table->index($columns, self::IndexName);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('permission.table_names.role_has_permissions');

        if (! is_string($tableName) || ! Schema::hasTable($tableName)) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'DROP INDEX CONCURRENTLY IF EXISTS %s',
                $this->wrappedPostgresDropIndex($tableName),
            ));

            return;
        }

        if (! Schema::hasIndex($tableName, self::IndexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropIndex(self::IndexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function createPostgresIndexConcurrently(string $tableName, array $columns): void
    {
        $targetIndexValidity = $this->postgresIndexValidity($tableName);

        if ($targetIndexValidity === false) {
            $this->dropPostgresIndexConcurrently($tableName);
        }

        if ($targetIndexValidity === true) {
            if (! $this->postgresHasValidIndex($tableName, $columns, self::IndexName)) {
                throw new RuntimeException(sprintf(
                    'Index [%s] already exists with an unexpected definition.',
                    self::IndexName,
                ));
            }

            return;
        }

        if ($this->postgresHasValidIndex($tableName, $columns)) {
            return;
        }

        $grammar = DB::getQueryGrammar();

        DB::statement(sprintf(
            'CREATE INDEX CONCURRENTLY %s ON %s (%s)',
            $grammar->wrap(self::IndexName),
            $grammar->wrapTable($tableName),
            implode(', ', $grammar->wrapArray($columns)),
        ));
    }

    private function dropPostgresIndexConcurrently(string $tableName): void
    {
        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->wrappedPostgresDropIndex($tableName),
        ));
    }

    private function postgresIndexValidity(string $tableName): ?bool
    {
        [$schema, $table] = $this->postgresTableIdentity($tableName);
        $schemaPredicate = $schema === null ? 'tn.nspname = current_schema()' : 'tn.nspname = ?';
        $bindings = $schema === null
            ? [$table, self::IndexName]
            : [$schema, $table, self::IndexName];
        $index = DB::selectOne(
            'SELECT CASE WHEN i.indisvalid AND i.indisready THEN 1 ELSE 0 END AS is_valid '
            .'FROM pg_index i '
            .'JOIN pg_class tc ON tc.oid = i.indrelid '
            .'JOIN pg_namespace tn ON tn.oid = tc.relnamespace '
            .'JOIN pg_class ic ON ic.oid = i.indexrelid '
            ."WHERE {$schemaPredicate} AND tc.relname = ? AND ic.relname = ? LIMIT 1",
            $bindings,
        );

        return $index === null ? null : (int) $index->is_valid === 1;
    }

    /**
     * @param  list<string>  $columns
     */
    private function postgresHasValidIndex(string $tableName, array $columns, ?string $indexName = null): bool
    {
        [$schema, $table] = $this->postgresTableIdentity($tableName);
        $schemaPredicate = $schema === null ? 'tn.nspname = current_schema()' : 'tn.nspname = ?';
        $indexPredicate = $indexName === null ? '' : 'AND ic.relname = ? ';
        $bindings = array_values(array_filter([
            $schema,
            $table,
            $indexName,
            json_encode($columns, JSON_THROW_ON_ERROR),
        ], fn (mixed $binding): bool => $binding !== null));

        return DB::selectOne(
            'SELECT 1 AS index_exists '
            .'FROM pg_index i '
            .'JOIN pg_class tc ON tc.oid = i.indrelid '
            .'JOIN pg_namespace tn ON tn.oid = tc.relnamespace '
            .'JOIN pg_class ic ON ic.oid = i.indexrelid '
            ."WHERE {$schemaPredicate} AND tc.relname = ? AND i.indisvalid AND i.indisready "
            ."AND i.indpred IS NULL AND i.indexprs IS NULL {$indexPredicate}"
            .'AND (SELECT jsonb_agg(a.attname ORDER BY keys.ordinality) '
            .'FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum, ordinality) '
            .'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = keys.attnum) = CAST(? AS jsonb) '
            .'LIMIT 1',
            $bindings,
        ) !== null;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function postgresTableIdentity(string $tableName): array
    {
        $separatorPosition = strrpos($tableName, '.');

        if ($separatorPosition === false) {
            return [null, $tableName];
        }

        return [
            substr($tableName, 0, $separatorPosition),
            substr($tableName, $separatorPosition + 1),
        ];
    }

    private function wrappedPostgresDropIndex(string $tableName): string
    {
        $grammar = DB::getQueryGrammar();
        [$schema] = $this->postgresTableIdentity($tableName);
        $qualifiedIndexName = $schema === null
            ? self::IndexName
            : $schema.'.'.self::IndexName;

        return $grammar->wrap($qualifiedIndexName);
    }
};
