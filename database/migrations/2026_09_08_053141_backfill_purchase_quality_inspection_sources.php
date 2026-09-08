<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('goods_receipt_inspections')
            ->whereNotNull('receipt_id')
            ->orderBy('id')
            ->chunkById(250, function ($inspections): void {
                $receipts = DB::table('unpriced_inventory_receipts')
                    ->whereIn('id', $inspections->pluck('receipt_id'))
                    ->get(['id', 'purchase_order_id', 'supply_order_id', 'doc_num'])
                    ->keyBy('id');

                foreach ($inspections as $inspection) {
                    $receipt = $receipts->get($inspection->receipt_id);
                    if ($receipt === null) {
                        continue;
                    }

                    DB::table('goods_receipt_inspections')->where('id', $inspection->id)->update([
                        'purchase_order_id' => $receipt->purchase_order_id,
                        'supply_order_id' => $receipt->supply_order_id,
                        'source_type' => 'goods_receipt',
                        'source_id' => $receipt->id,
                        'source_doc_num' => $receipt->doc_num,
                    ]);
                }
            });

        DB::table('goods_receipt_inspection_lines')
            ->whereNotNull('receipt_line_id')
            ->orderBy('id')
            ->chunkById(250, function ($inspectionLines): void {
                $receiptLines = DB::table('unpriced_inventory_receipt_lines')
                    ->whereIn('id', $inspectionLines->pluck('receipt_line_id'))
                    ->get(['id', 'purchase_order_line_id', 'supply_order_line_id', 'delivery_schedule_id', 'unit_id', 'supplier_lot_number', 'manufacture_date', 'expiry_date', 'notes'])
                    ->keyBy('id');

                foreach ($inspectionLines as $inspectionLine) {
                    $receiptLine = $receiptLines->get($inspectionLine->receipt_line_id);
                    if ($receiptLine === null) {
                        continue;
                    }

                    DB::table('goods_receipt_inspection_lines')->where('id', $inspectionLine->id)->update([
                        'purchase_order_line_id' => $receiptLine->purchase_order_line_id,
                        'supply_order_line_id' => $receiptLine->supply_order_line_id,
                        'delivery_schedule_id' => $receiptLine->delivery_schedule_id,
                        'unit_id' => $receiptLine->unit_id,
                        'supplier_lot_number' => $receiptLine->supplier_lot_number,
                        'manufacture_date' => $receiptLine->manufacture_date,
                        'expiry_date' => $receiptLine->expiry_date,
                        'notes' => $receiptLine->notes,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('goods_receipt_inspection_lines')->update([
            'purchase_order_line_id' => null,
            'supply_order_line_id' => null,
            'delivery_schedule_id' => null,
            'unit_id' => null,
            'supplier_lot_number' => null,
            'manufacture_date' => null,
            'expiry_date' => null,
            'notes' => null,
        ]);
        DB::table('goods_receipt_inspections')->update([
            'purchase_order_id' => null,
            'supply_order_id' => null,
            'source_type' => null,
            'source_id' => null,
            'source_doc_num' => null,
        ]);
    }
};
