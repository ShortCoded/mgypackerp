<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Opening stock pricing requires partial unique indexes.');
        }

        $grammar = DB::getQueryGrammar();
        $lines = $grammar->wrapTable('inventory_opening_stock_pricing_lines');
        $sourceLine = $grammar->wrap('opening_stock_line_id');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX '.$grammar->wrap('inventory_opening_stock_pricing_lines_source_unique_active').' ON '.$lines.' ('.$sourceLine.') WHERE '.$deletedAt.' IS NULL');
        DB::statement('DROP INDEX '.$grammar->wrap('inventory_opening_stock_pricings_opening_stock_unique_active'));
        DB::statement('DROP INDEX '.$grammar->wrap('inventory_opening_stock_pricing_lines_product_unique_active'));
    }

    public function down(): void
    {
        $grammar = DB::getQueryGrammar();
        $headers = $grammar->wrapTable('inventory_opening_stock_pricings');
        $lines = $grammar->wrapTable('inventory_opening_stock_pricing_lines');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('DROP INDEX '.$grammar->wrap('inventory_opening_stock_pricing_lines_source_unique_active'));
        DB::statement('CREATE UNIQUE INDEX '.$grammar->wrap('inventory_opening_stock_pricings_opening_stock_unique_active').' ON '.$headers.' ('.$grammar->wrap('opening_stock_id').') WHERE '.$deletedAt.' IS NULL');
        DB::statement('CREATE UNIQUE INDEX '.$grammar->wrap('inventory_opening_stock_pricing_lines_product_unique_active').' ON '.$lines.' ('.$grammar->wrap('pricing_id').', '.$grammar->wrap('product_id').') WHERE '.$deletedAt.' IS NULL');
    }
};
