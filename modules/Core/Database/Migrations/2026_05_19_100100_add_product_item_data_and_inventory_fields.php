<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 100)->nullable()->after('product_type');
            }

            if (! Schema::hasColumn('products', 'item_classification')) {
                $table->string('item_classification', 40)->default('finished_product')->after('barcode');
            }

            if (! Schema::hasColumn('products', 'reorder_point')) {
                $table->decimal('reorder_point', 15, 4)->default(0)->after('item_classification');
            }

            if (! Schema::hasColumn('products', 'item_decal_id')) {
                $table->foreignId('item_decal_id')
                    ->nullable()
                    ->after('item_color_id')
                    ->constrained('item_decals')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('products', 'item_origin_country_id')) {
                $table->foreignId('item_origin_country_id')
                    ->nullable()
                    ->after('item_model_id')
                    ->constrained('item_origin_countries')
                    ->nullOnDelete();
            }
        });

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createBarcodeUniqueIndex(),
            default => null,
        };
    }

    public function down(): void
    {
        $this->dropIndexIfExists('products_company_barcode_unique_active');

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'item_origin_country_id')) {
                $table->dropConstrainedForeignId('item_origin_country_id');
            }

            if (Schema::hasColumn('products', 'item_decal_id')) {
                $table->dropConstrainedForeignId('item_decal_id');
            }

            foreach (['reorder_point', 'item_classification', 'barcode'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function createBarcodeUniqueIndex(): void
    {
        if (! Schema::hasColumn('products', 'barcode') || ! Schema::hasColumn('products', 'company_id')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('products_company_barcode_unique_active');
        $wrappedTable = $grammar->wrapTable('products');
        $companyId = $grammar->wrap('company_id');
        $barcode = $grammar->wrap('barcode');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$companyId}, {$barcode}) WHERE {$deletedAt} IS NULL AND {$companyId} IS NOT NULL AND {$barcode} IS NOT NULL");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }
};
