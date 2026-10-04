<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS inventory_opening_stock_pricing_lines_line_unique_active');
            DB::statement('DROP INDEX IF EXISTS inventory_opening_stock_pricing_lines_source_unique_active');
        }

        foreach ([
            'customer_invoice_lines' => [24, false, true],
            'inventory_opening_stock_pricing_lines' => [19, false, false],
            'price_list_lines' => [24, false, false],
            'purchase_invoice_lines' => [22, false, true],
            'purchase_order_lines' => [22, false, true],
            'purchase_return_lines' => [22, false, true],
            'quotation_revision_lines' => [22, false, true],
            'sales_order_lines' => [24, false, true],
            'sales_request_lines' => [24, true, false],
            'sales_return_lines' => [24, false, true],
            'supplier_quotation_lines' => [22, false, false],
            'supplier_selection_lines' => [22, false, false],
        ] as $table => [$precision, $nullable, $zeroDefault]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN unit_price TYPE numeric({$precision}, 8)");

                continue;
            }

            Schema::table($table, function (Blueprint $schema) use ($precision, $nullable, $zeroDefault): void {
                $column = $schema->decimal('unit_price', $precision, 8);
                if ($nullable) {
                    $column->nullable();
                }
                if ($zeroDefault) {
                    $column->default(0);
                }
                $column->change();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX inventory_opening_stock_pricing_lines_line_unique_active ON inventory_opening_stock_pricing_lines (pricing_id, opening_stock_line_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX inventory_opening_stock_pricing_lines_source_unique_active ON inventory_opening_stock_pricing_lines (opening_stock_line_id) WHERE deleted_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Unit-price precision cannot be narrowed without a reviewed data migration.');
    }
};
