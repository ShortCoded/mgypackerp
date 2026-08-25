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
        Schema::table('inventory_accounting_mappings', function (Blueprint $table): void {
            $table->foreignId('quarantine_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('rework_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('grni_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('purchase_price_variance_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('tracks_expiry')->default(false)->index();
            $table->unsignedInteger('default_shelf_life_days')->nullable();
        });

        foreach (['inventory_transactions', 'inventory_document_lines', 'inventory_opening_stock_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->date('manufacture_date')->nullable();
                $table->date('expiry_date')->nullable()->index();
            });
        }

        Schema::create('inventory_receipt_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->foreignId('receipt_transaction_id')->constrained('inventory_transactions')->restrictOnDelete();
            $table->string('stock_status', 30)->default('available');
            $table->string('batch_lot', 100)->nullable();
            $table->date('receipt_date');
            $table->date('original_receipt_date');
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('original_quantity', 20, 8);
            $table->decimal('remaining_quantity', 20, 8);
            $table->decimal('unit_cost', 20, 8)->nullable();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_doc_num');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('receipt_transaction_id', 'inventory_receipt_layers_transaction_index');
            $table->index(['company_id', 'branch_store_id', 'product_id', 'stock_status', 'receipt_date'], 'inventory_receipt_layers_aging_index');
            $table->index(['company_id', 'product_id', 'batch_lot', 'expiry_date'], 'inventory_receipt_layers_expiry_index');
        });

        Schema::create('inventory_layer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_receipt_layer_id')->constrained('inventory_receipt_layers')->restrictOnDelete();
            $table->foreignId('issue_transaction_id')->constrained('inventory_transactions')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->timestamps();

            $table->unique(['inventory_receipt_layer_id', 'issue_transaction_id'], 'inventory_layer_allocations_unique');
            $table->index('issue_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_layer_allocations');
        Schema::dropIfExists('inventory_receipt_layers');

        foreach (['inventory_opening_stock_lines', 'inventory_document_lines', 'inventory_transactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['manufacture_date', 'expiry_date']);
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['tracks_expiry', 'default_shelf_life_days']);
        });
        Schema::table('inventory_accounting_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_price_variance_account_id');
            $table->dropConstrainedForeignId('grni_account_id');
            $table->dropConstrainedForeignId('rework_inventory_account_id');
            $table->dropConstrainedForeignId('quarantine_inventory_account_id');
        });
    }
};
