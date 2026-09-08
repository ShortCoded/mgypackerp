<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_inspections', function (Blueprint $table): void {
            $table->foreignId('receipt_id')->nullable()->change();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supply_order_id')->nullable()->constrained('supply_orders')->restrictOnDelete();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_doc_num', 100)->nullable();

            $table->index(['company_id', 'branch_id', 'status', 'result'], 'goods_receipt_inspections_branch_status_result_index');
            $table->index(['source_type', 'source_id'], 'goods_receipt_inspections_source_index');
            $table->unique('receipt_id', 'goods_receipt_inspections_receipt_unique');
        });

        Schema::table('goods_receipt_inspection_lines', function (Blueprint $table): void {
            $table->foreignId('receipt_line_id')->nullable()->change();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('supply_order_line_id')->nullable()->constrained('supply_order_lines')->restrictOnDelete();
            $table->foreignId('delivery_schedule_id')->nullable()->constrained('purchase_order_delivery_schedules')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->string('supplier_lot_number', 120)->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();

            $table->unique(
                ['goods_receipt_inspection_id', 'purchase_order_line_id'],
                'goods_receipt_inspection_lines_inspection_po_line_unique',
            );
            $table->index(
                ['supply_order_line_id', 'goods_receipt_inspection_id'],
                'goods_receipt_inspection_lines_supply_line_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_inspection_lines', function (Blueprint $table): void {
            $table->dropUnique('goods_receipt_inspection_lines_inspection_po_line_unique');
            $table->dropIndex('goods_receipt_inspection_lines_supply_line_index');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('delivery_schedule_id');
            $table->dropConstrainedForeignId('supply_order_line_id');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropColumn(['supplier_lot_number', 'manufacture_date', 'expiry_date', 'notes']);
        });

        Schema::table('goods_receipt_inspections', function (Blueprint $table): void {
            $table->dropIndex('goods_receipt_inspections_branch_status_result_index');
            $table->dropIndex('goods_receipt_inspections_source_index');
            $table->dropUnique('goods_receipt_inspections_receipt_unique');
            $table->dropConstrainedForeignId('supply_order_id');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['source_type', 'source_id', 'source_doc_num']);
        });

        if (! DB::table('goods_receipt_inspections')->whereNull('receipt_id')->exists()) {
            Schema::table('goods_receipt_inspections', function (Blueprint $table): void {
                $table->foreignId('receipt_id')->nullable(false)->change();
            });
        }

        if (! DB::table('goods_receipt_inspection_lines')->whereNull('receipt_line_id')->exists()) {
            Schema::table('goods_receipt_inspection_lines', function (Blueprint $table): void {
                $table->foreignId('receipt_line_id')->nullable(false)->change();
            });
        }
    }
};
