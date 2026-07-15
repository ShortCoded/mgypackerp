<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OldIndex = 'products_company_doc_number_unique_active';

    private const NewIndex = 'products_company_classification_doc_number_unique_active';

    public function up(): void
    {
        if (! $this->hasRequiredColumns()) {
            return;
        }

        $this->dropIndexIfExists(self::OldIndex);
        $this->createScopedDocNumberIndex(self::NewIndex);
    }

    public function down(): void
    {
        if (! $this->hasRequiredColumns()) {
            return;
        }

        $this->dropIndexIfExists(self::NewIndex);
        $this->createCompanyDocNumberIndex(self::OldIndex);
    }

    private function hasRequiredColumns(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasColumn('products', 'company_id')
            && Schema::hasColumn('products', 'item_classification')
            && Schema::hasColumn('products', 'doc_number')
            && Schema::hasColumn('products', 'deleted_at');
    }

    private function createScopedDocNumberIndex(string $index): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('products');
        $companyId = $grammar->wrap('company_id');
        $classification = 'COALESCE('.$grammar->wrap('item_classification').", '')";
        $docNumber = $grammar->wrap('doc_number');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$companyId}, {$classification}, {$docNumber}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$docNumber} IS NOT NULL");
    }

    private function createCompanyDocNumberIndex(string $index): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('products');
        $companyId = $grammar->wrap('company_id');
        $docNumber = $grammar->wrap('doc_number');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$companyId}, {$docNumber}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$docNumber} IS NOT NULL");
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
    }
};
