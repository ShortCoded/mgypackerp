<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateProductTypeToClassification();

        if (Schema::hasColumn('products', 'reorder_point')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('reorder_point', 15, 4)->nullable()->default(null)->change();
            });
        }

        if (Schema::hasColumn('products', 'product_type')) {
            $this->dropProductTypeIndexIfExists();

            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('product_type');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'product_type')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('product_type', 20)->nullable()->after('name')->index();
            });
        }

        $this->migrateClassificationToProductType();

        if (Schema::hasColumn('products', 'reorder_point')) {
            DB::table('products')->whereNull('reorder_point')->update(['reorder_point' => 0]);

            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('reorder_point', 15, 4)->default(0)->nullable(false)->change();
            });
        }
    }

    private function migrateProductTypeToClassification(): void
    {
        if (! Schema::hasColumn('products', 'product_type') || ! Schema::hasColumn('products', 'item_classification')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE products
            SET item_classification = CASE LOWER(COALESCE(product_type, ''))
                WHEN 'material' THEN 'raw_material'
                WHEN 'raw' THEN 'raw_material'
                WHEN 'raw_material' THEN 'raw_material'
                WHEN 'خام' THEN 'raw_material'
                WHEN 'خامات' THEN 'raw_material'
                WHEN 'item' THEN 'finished_product'
                WHEN 'finished' THEN 'finished_product'
                WHEN 'finished_product' THEN 'finished_product'
                WHEN 'إنتاج تام' THEN 'finished_product'
                WHEN 'semi_finished' THEN 'semi_finished'
                WHEN 'نصف مصنع' THEN 'semi_finished'
                WHEN 'packaging' THEN 'packaging'
                WHEN 'تعبئة وتغليف' THEN 'packaging'
                WHEN 'service' THEN 'service'
                WHEN 'services' THEN 'service'
                WHEN 'خدمات' THEN 'service'
                WHEN 'other' THEN 'other'
                WHEN 'أخرى' THEN 'other'
                ELSE 'other'
            END
            WHERE (item_classification IS NULL OR item_classification = '')
                AND product_type IS NOT NULL
                AND product_type <> ''
        SQL);
    }

    private function migrateClassificationToProductType(): void
    {
        if (! Schema::hasColumn('products', 'product_type') || ! Schema::hasColumn('products', 'item_classification')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE products
            SET product_type = CASE item_classification
                WHEN 'raw_material' THEN 'MATERIAL'
                ELSE 'ITEM'
            END
            WHERE product_type IS NULL OR product_type = ''
        SQL);
    }

    private function dropProductTypeIndexIfExists(): void
    {
        try {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex('products_product_type_index');
            });
        } catch (Throwable) {
            // Older or partially migrated databases may not still have this index.
        }
    }
};
