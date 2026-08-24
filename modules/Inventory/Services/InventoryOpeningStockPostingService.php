<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;

class InventoryOpeningStockPostingService
{
    public function post(OpeningStock $openingStock): void
    {
        $locked = OpeningStock::query()
            ->with('lines')
            ->lockForUpdate()
            ->findOrFail($openingStock->getKey());

        if (! $locked->isApproved()) {
            throw new DomainException('Opening stock must be approved before it can reach the stock ledger.');
        }

        $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

        if ($period->is_closed || ! $period->allows_opening_entries) {
            throw new DomainException('Opening inventory cannot be posted to this financial period.');
        }

        if (! $locked->branch_store_id) {
            return;
        }

        foreach ($locked->lines as $line) {
            $quantity = (string) $line->quantity;

            if (bccomp($quantity, '0', 8) <= 0) {
                continue;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
            InventoryTransaction::query()->firstOrCreate(
                ['posting_key' => "opening-stock:{$locked->id}:line:{$line->id}"],
                [
                    'company_id' => $locked->company_id,
                    'financial_period_id' => $locked->financial_period_id,
                    'branch_id' => $locked->branch_id,
                    'branch_store_id' => $locked->branch_store_id,
                    'branch_hall_id' => $locked->branch_hall_id,
                    'warehouse_location_id' => $line->warehouse_location_id,
                    'stock_status' => $line->stock_status ?: InventoryTransaction::StatusAvailable,
                    'batch_lot' => $line->batch_lot,
                    'transaction_date' => $locked->document_date,
                    'transaction_type' => 'opening_stock',
                    'product_id' => $line->product_id,
                    'unit_id' => $product->item_unit_id,
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'source_type' => OpeningStock::class,
                    'source_id' => $locked->getKey(),
                    'source_doc_num' => $locked->doc_num,
                    'source_line_type' => $line::class,
                    'source_line_id' => $line->getKey(),
                    'unit_cost' => 0,
                    'total_cost' => 0,
                    'created_by' => auth()->id(),
                ],
            );
        }
    }
}
