<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_delivery_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('scheduled_date');
            $table->decimal('scheduled_quantity', 20, 8);
            $table->decimal('received_quantity', 20, 8)->default(0);
            $table->string('status', 30)->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['purchase_order_line_id', 'sequence'], 'purchase_order_delivery_schedules_line_sequence_unique');
            $table->index(['company_id', 'scheduled_date', 'status'], 'purchase_order_delivery_schedules_due_status_index');
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('supplier_delivery_note', 120)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('qc_status', 30)->default('not_required');
            $table->string('posting_status', 30)->default('unposted');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->index(['company_id', 'purchase_order_id', 'status'], 'unpriced_inventory_receipts_po_status_index');
            $table->index(['company_id', 'qc_status', 'posting_status'], 'unpriced_inventory_receipts_qc_posting_index');
        });

        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('delivery_schedule_id')->nullable()->constrained('purchase_order_delivery_schedules')->nullOnDelete();
            $table->decimal('delivered_quantity', 20, 8)->default(0);
            $table->decimal('accepted_quantity', 20, 8)->default(0);
            $table->decimal('rejected_quantity', 20, 8)->default(0);
            $table->decimal('inventory_posted_quantity', 20, 8)->default(0);
            $table->string('supplier_lot_number', 120)->nullable();

            $table->index(['purchase_order_line_id', 'receipt_id'], 'unpriced_inventory_receipt_lines_po_receipt_index');
        });

        Schema::create('goods_receipt_inspections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('unpriced_inventory_receipts')->restrictOnDelete();
            $table->timestamp('inspection_at');
            $table->string('result', 30)->default('pending');
            $table->string('status', 30)->default('draft');
            $table->text('observations')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'inspection_at'], 'goods_receipt_inspections_context_status_index');
        });

        Schema::create('goods_receipt_inspection_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('goods_receipt_inspection_id')->constrained('goods_receipt_inspections')->cascadeOnDelete();
            $table->foreignId('receipt_line_id')->constrained('unpriced_inventory_receipt_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('inspected_quantity', 20, 8);
            $table->decimal('accepted_quantity', 20, 8)->default(0);
            $table->decimal('rejected_quantity', 20, 8)->default(0);
            $table->string('result', 30);
            $table->string('disposition', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('measurements')->nullable();
            $table->timestamps();

            $table->unique('receipt_line_id', 'goods_receipt_inspection_lines_receipt_line_unique');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS goods_receipt_inspections_doc_number_unique_active ON goods_receipt_inspections (company_id, financial_period_id, doc_number) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS goods_receipt_inspections_doc_num_unique_active ON goods_receipt_inspections (company_id, doc_num) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_inspection_lines');
        Schema::dropIfExists('goods_receipt_inspections');

        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_schedule_id');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropColumn(['delivered_quantity', 'accepted_quantity', 'rejected_quantity', 'inventory_posted_quantity', 'supplier_lot_number']);
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['supplier_delivery_note', 'received_at', 'qc_status', 'posting_status', 'posted_at']);
        });

        Schema::dropIfExists('purchase_order_delivery_schedules');
    }
};
