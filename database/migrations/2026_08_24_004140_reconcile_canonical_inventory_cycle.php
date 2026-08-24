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
        Schema::create('warehouse_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('zone_code', 80)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_store_id', 'code'], 'warehouse_locations_store_code_unique');
            $table->index(['branch_store_id', 'zone_code', 'is_active'], 'warehouse_locations_store_zone_index');
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('branch_store_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('stock_status', 30)->default('available')->after('warehouse_location_id');
            $table->string('batch_lot', 100)->nullable()->after('stock_status');
            $table->index(['company_id', 'branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status'], 'inventory_transactions_position_index');
            $table->index(['company_id', 'product_id', 'batch_lot'], 'inventory_transactions_batch_index');
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable()->change();
            $table->foreignId('sales_order_line_id')->nullable()->change();
            $table->foreignId('production_order_id')->nullable()->after('sales_order_line_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('production_order_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->after('branch_store_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('stock_status', 30)->default('available')->after('warehouse_location_id');
            $table->string('batch_lot', 100)->nullable()->after('stock_status');
            $table->index(['production_order_id', 'status'], 'inventory_reservations_production_index');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('branch_store_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_branch_store_id')->nullable()->after('warehouse_location_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('destination_warehouse_location_id')->nullable()->after('destination_branch_store_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('source_stock_status', 30)->default('available')->after('destination_warehouse_location_id');
            $table->string('destination_stock_status', 30)->nullable()->after('source_stock_status');
            $table->string('movement_reason', 100)->nullable()->after('destination_stock_status');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('product_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_warehouse_location_id')->nullable()->after('warehouse_location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('batch_lot', 100)->nullable()->after('destination_warehouse_location_id');
        });

        Schema::table('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('product_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('stock_status', 30)->default('available')->after('warehouse_location_id');
            $table->string('batch_lot', 100)->nullable()->after('stock_status');
        });

        Schema::create('inventory_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->date('count_date')->index();
            $table->timestamp('snapshot_at');
            $table->string('status', 30)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('adjustment_document_id')->nullable()->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'inventory_stock_counts_context_number_unique');
        });

        Schema::create('inventory_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_stock_count_id')->constrained('inventory_stock_counts')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->string('stock_status', 30)->default('available');
            $table->string('batch_lot', 100)->nullable();
            $table->decimal('system_quantity', 20, 8);
            $table->decimal('physical_quantity', 20, 8)->nullable();
            $table->decimal('variance_quantity', 20, 8)->default(0);
            $table->string('variance_reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['inventory_stock_count_id', 'line_number'], 'inventory_stock_count_lines_number_unique');
            $table->index(['inventory_stock_count_id', 'product_id'], 'inventory_stock_count_lines_product_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_count_lines');
        Schema::dropIfExists('inventory_stock_counts');

        Schema::table('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropColumn(['stock_status', 'batch_lot']);
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_warehouse_location_id');
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropColumn('batch_lot');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_warehouse_location_id');
            $table->dropConstrainedForeignId('destination_branch_store_id');
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropColumn(['source_stock_status', 'destination_stock_status', 'movement_reason']);
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_production_index');
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('production_order_id');
            $table->dropColumn(['stock_status', 'batch_lot']);
            $table->foreignId('sales_order_id')->nullable(false)->change();
            $table->foreignId('sales_order_line_id')->nullable(false)->change();
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropIndex('inventory_transactions_position_index');
            $table->dropIndex('inventory_transactions_batch_index');
            $table->dropConstrainedForeignId('warehouse_location_id');
            $table->dropColumn(['stock_status', 'batch_lot']);
        });

        Schema::dropIfExists('warehouse_locations');
    }
};
