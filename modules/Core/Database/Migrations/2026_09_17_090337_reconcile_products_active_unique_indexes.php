<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasColumn('products', 'company_id')
            || ! Schema::hasColumn('products', 'item_classification')
            || ! Schema::hasColumn('products', 'doc_number')
            || ! Schema::hasColumn('products', 'doc_num')
            || ! Schema::hasColumn('products', 'barcode')
            || ! Schema::hasColumn('products', 'deleted_at')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('products');
        $companyId = $grammar->wrap('company_id');
        $classification = 'COALESCE('.$grammar->wrap('item_classification').", '')";
        $docNumber = $grammar->wrap('doc_number');
        $docNum = $grammar->wrap('doc_num');
        $barcode = $grammar->wrap('barcode');
        $deletedAt = $grammar->wrap('deleted_at');

        foreach ([
            'products_doc_number_unique_active',
            'products_doc_num_unique_active',
            'products_company_doc_number_unique_active',
            'products_company_classification_doc_number_unique_active',
            'products_company_doc_num_unique_active',
            'products_company_barcode_unique_active',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap($index));
        }

        DB::statement("CREATE UNIQUE INDEX {$grammar->wrap('products_company_classification_doc_number_unique_active')} ON {$table} ({$companyId}, {$classification}, {$docNumber}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$docNumber} IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX {$grammar->wrap('products_company_doc_num_unique_active')} ON {$table} ({$companyId}, {$docNum}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$docNum} IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX {$grammar->wrap('products_company_barcode_unique_active')} ON {$table} ({$companyId}, {$barcode}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$barcode} IS NOT NULL");
    }

    public function down(): void {}
};
