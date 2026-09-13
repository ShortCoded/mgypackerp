<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->foreignId('goods_receipt_inspection_id')
                ->nullable()
                ->after('supply_order_id');
            $table->foreign('goods_receipt_inspection_id', 'uir_source_inspection_fk')
                ->references('id')
                ->on('goods_receipt_inspections')
                ->restrictOnDelete();
        });

        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('goods_receipt_inspection_line_id')
                ->nullable()
                ->after('delivery_schedule_id');
            $table->foreign('goods_receipt_inspection_line_id', 'uir_line_source_inspection_fk')
                ->references('id')
                ->on('goods_receipt_inspection_lines')
                ->restrictOnDelete();
        });

        DB::table('goods_receipt_inspections')
            ->whereIn('source_type', ['purchase_order', 'supply_order'])
            ->whereNotNull('receipt_id')
            ->orderBy('id')
            ->chunkById(250, function ($inspections): void {
                foreach ($inspections as $inspection) {
                    DB::table('unpriced_inventory_receipts')
                        ->where('id', $inspection->receipt_id)
                        ->whereNull('goods_receipt_inspection_id')
                        ->update(['goods_receipt_inspection_id' => $inspection->id]);

                    $inspectionLines = DB::table('goods_receipt_inspection_lines')
                        ->where('goods_receipt_inspection_id', $inspection->id)
                        ->get();

                    foreach ($inspectionLines as $inspectionLine) {
                        $receiptLine = DB::table('unpriced_inventory_receipt_lines')
                            ->where('receipt_id', $inspection->receipt_id)
                            ->when(
                                $inspectionLine->supply_order_line_id !== null,
                                fn ($query) => $query->where('supply_order_line_id', $inspectionLine->supply_order_line_id),
                                fn ($query) => $query->where('purchase_order_line_id', $inspectionLine->purchase_order_line_id),
                            )
                            ->first();

                        if ($receiptLine !== null) {
                            DB::table('unpriced_inventory_receipt_lines')
                                ->where('id', $receiptLine->id)
                                ->update(['goods_receipt_inspection_line_id' => $inspectionLine->id]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign('uir_line_source_inspection_fk');
            $table->dropColumn('goods_receipt_inspection_line_id');
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropForeign('uir_source_inspection_fk');
            $table->dropColumn('goods_receipt_inspection_id');
        });
    }
};
