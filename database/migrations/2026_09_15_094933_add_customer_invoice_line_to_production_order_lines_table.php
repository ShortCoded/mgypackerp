<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropUnique('production_orders_sales_order_unique');
            $table->index(['company_id', 'sales_order_id'], 'production_orders_sales_order_index');
        });

        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->foreignId('customer_invoice_line_id')
                ->nullable()
                ->after('sales_order_line_id')
                ->constrained('customer_invoice_lines')
                ->restrictOnDelete();
            $table->index(['customer_invoice_line_id', 'product_id'], 'production_order_lines_invoice_product_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->dropIndex('production_order_lines_invoice_product_index');
            $table->dropConstrainedForeignId('customer_invoice_line_id');
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropIndex('production_orders_sales_order_index');
            $table->unique(['company_id', 'sales_order_id'], 'production_orders_sales_order_unique');
        });
    }
};
