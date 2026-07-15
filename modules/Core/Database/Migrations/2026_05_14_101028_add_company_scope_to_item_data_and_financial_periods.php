<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $itemLookupTables = [
        'item_units',
        'item_sizes',
        'item_colors',
        'item_models',
        'item_categories',
        'item_groups',
    ];

    /**
     * @var list<string>
     */
    private array $allTables = [
        'products',
        'item_units',
        'item_sizes',
        'item_colors',
        'item_models',
        'item_categories',
        'item_groups',
        'financial_periods',
    ];

    public function up(): void
    {
        $companyId = $this->defaultCompanyId();

        foreach ($this->allTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $this->addCompanyColumn($tableName);
            $this->backfillCompany($tableName, $companyId);
            $this->dropOldUniqueIndexes($tableName);
            $this->createCompanyScopedIndexes($tableName);
        }
    }

    public function down(): void
    {
        foreach ($this->allTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $this->dropCompanyScopedIndexes($tableName);
            $this->restoreOldUniqueIndexes($tableName);
            $this->dropCompanyColumn($tableName);
        }
    }

    private function addCompanyColumn(string $tableName): void
    {
        if (Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->restrictOnDelete();
        });

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->index('company_id', "{$tableName}_company_id_index");
        });
    }

    private function backfillCompany(string $tableName, ?int $companyId): void
    {
        if ($companyId === null || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        DB::table($tableName)
            ->whereNull('company_id')
            ->update(['company_id' => $companyId]);
    }

    private function dropCompanyColumn(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $this->dropIndexIfExists("{$tableName}_company_id_index");
            $this->dropForeignIfExists($tableName, "{$tableName}_company_id_foreign");
            $table->dropColumn('company_id');
        });
    }

    private function dropOldUniqueIndexes(string $tableName): void
    {
        foreach ($this->oldUniqueIndexes($tableName) as $index) {
            $this->dropIndexIfExists($index);
        }
    }

    private function restoreOldUniqueIndexes(string $tableName): void
    {
        foreach ($this->oldUniqueIndexes($tableName) as $column => $index) {
            if (! Schema::hasColumn($tableName, $column)) {
                continue;
            }

            $this->createActiveUniqueIndex($tableName, $index, [$column], $column);
        }
    }

    private function createCompanyScopedIndexes(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach ($this->companyScopedUniqueIndexes($tableName) as $column => $index) {
            if (! Schema::hasColumn($tableName, $column)) {
                continue;
            }

            $this->createActiveUniqueIndex($tableName, $index, ['company_id', $column], $column, companyScoped: true);
        }
    }

    private function dropCompanyScopedIndexes(string $tableName): void
    {
        foreach ($this->companyScopedUniqueIndexes($tableName) as $index) {
            $this->dropIndexIfExists($index);
        }
    }

    /**
     * @return array<string, string>
     */
    private function oldUniqueIndexes(string $tableName): array
    {
        $indexes = [
            'doc_number' => "{$tableName}_doc_number_unique_active",
            'doc_num' => "{$tableName}_doc_num_unique_active",
        ];

        if (in_array($tableName, $this->itemLookupTables, true) || $tableName === 'financial_periods') {
            $indexes['name'] = "{$tableName}_name_unique_active";
        }

        return $indexes;
    }

    /**
     * @return array<string, string>
     */
    private function companyScopedUniqueIndexes(string $tableName): array
    {
        $indexes = [
            'doc_number' => "{$tableName}_company_doc_number_unique_active",
            'doc_num' => "{$tableName}_company_doc_num_unique_active",
        ];

        if (in_array($tableName, $this->itemLookupTables, true) || $tableName === 'financial_periods') {
            $indexes['name'] = "{$tableName}_company_name_unique_active";
        }

        return $indexes;
    }

    /**
     * @param  list<string>  $columns
     */
    private function createActiveUniqueIndex(
        string $tableName,
        string $index,
        array $columns,
        string $nullableColumn,
        bool $companyScoped = false
    ): void {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $deletedAtPredicate = $grammar->wrap('deleted_at').' IS NULL';
        $predicate = $deletedAtPredicate;

        if ($companyScoped) {
            $predicate .= ' AND '.$grammar->wrap('company_id').' IS NOT NULL';
        }

        if (in_array($nullableColumn, ['doc_number', 'doc_num', 'code'], true)) {
            $predicate .= ' AND '.$grammar->wrap($nullableColumn).' IS NOT NULL';
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }

    private function dropForeignIfExists(string $tableName, string $constraint): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = DB::getQueryGrammar();
        DB::statement(sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
            $grammar->wrapTable($tableName),
            $grammar->wrap($constraint),
        ));
    }

    private function defaultCompanyId(): ?int
    {
        if (! Schema::hasTable('companies')) {
            return null;
        }

        $mainCompanyId = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_main', true)
            ->orderBy('id')
            ->value('id');

        if ($mainCompanyId !== null) {
            return (int) $mainCompanyId;
        }

        $firstCompanyId = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');

        return $firstCompanyId === null ? null : (int) $firstCompanyId;
    }
};
