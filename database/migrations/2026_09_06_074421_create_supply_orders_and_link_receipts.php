<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_doc_num', 100);
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('total_ordered_quantity', 20, 8)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'doc_num'], 'supply_orders_company_doc_num_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'supply_orders_context_status_index');
            $table->index(['source_type', 'source_id'], 'supply_orders_source_index');
        });

        Schema::create('supply_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supply_order_id')->constrained('supply_orders')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('purchase_invoice_line_id')->nullable()->constrained('purchase_invoice_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('item_units')->restrictOnDelete();
            $table->decimal('ordered_quantity', 20, 8);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supply_order_id', 'purchase_order_line_id'], 'supply_order_lines_order_line_unique');
            $table->index(['purchase_order_line_id', 'supply_order_id'], 'supply_order_lines_po_line_index');
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->foreignId('supply_order_id')->nullable()->after('purchase_order_id')->constrained('supply_orders')->restrictOnDelete();
            $table->index(['company_id', 'supply_order_id', 'status'], 'unpriced_inventory_receipts_supply_status_index');
        });

        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('supply_order_line_id')->nullable()->after('purchase_order_line_id')->constrained('supply_order_lines')->restrictOnDelete();
            $table->index(['supply_order_line_id', 'receipt_id'], 'unpriced_inventory_receipt_lines_supply_index');
        });
    }

    public function down(): void
    {
        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->dropIndex('unpriced_inventory_receipt_lines_supply_index');
            $table->dropConstrainedForeignId('supply_order_line_id');
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropIndex('unpriced_inventory_receipts_supply_status_index');
            $table->dropConstrainedForeignId('supply_order_id');
        });

        Schema::dropIfExists('supply_order_lines');
        Schema::dropIfExists('supply_orders');
    }
};
