<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;

class InventoryOpeningStockPostingService
{
    public function __construct(private readonly InventoryLayerService $layers) {}

    public function post(OpeningStock $openingStock): void
    {
        $locked = OpeningStock::query()
            ->with('lines')
            ->lockForUpdate()
            ->findOrFail($openingStock->getKey());

        if (! $locked->isApproved()) {
            throw new DomainException(__('Opening stock must be approved before it can reach the stock ledger.'));
        }

        $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

        if ($period->is_closed || ! $period->allows_opening_entries) {
            throw new DomainException(__('Opening inventory cannot be posted to this financial period.'));
        }

        if (InventoryTransaction::query()
            ->where('company_id', $locked->company_id)
            ->whereDate('transaction_date', '<', $period->from_date)
            ->exists()) {
            throw new DomainException(__('inventory.opening_stocks.messages.history_derived_opening_only'));
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
            $valuation = $this->valuationForLine((int) $line->getKey());

            $transaction = InventoryTransaction::query()->firstOrCreate(
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
                    'manufacture_date' => $line->manufacture_date,
                    'expiry_date' => $line->expiry_date,
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
                    'unit_cost' => $valuation['unit_cost'],
                    'total_cost' => $valuation['total_cost'],
                    'created_by' => auth()->id(),
                ],
            );
            $this->layers->recordInbound($transaction);
        }
    }

    public function applyPricing(OpeningStockPricing $pricing): void
    {
        $lockedPricing = OpeningStockPricing::query()
            ->with('lines.openingStockLine')
            ->lockForUpdate()
            ->findOrFail($pricing->getKey());

        foreach ($lockedPricing->lines as $pricingLine) {
            $openingLine = $pricingLine->openingStockLine;

            if (! $openingLine) {
                continue;
            }

            $movement = InventoryTransaction::query()
                ->where('posting_key', "opening-stock:{$lockedPricing->opening_stock_id}:line:{$openingLine->getKey()}")
                ->lockForUpdate()
                ->first();

            if (! $movement instanceof InventoryTransaction) {
                continue;
            }

            $this->assertNoLaterMovement($movement);

            $unitCost = bcmul((string) $pricingLine->unit_price, (string) $lockedPricing->exchange_rate, 8);
            $totalCost = bcmul((string) $pricingLine->line_total, (string) $lockedPricing->exchange_rate, 4);

            $movement->forceFill([
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
            ])->save();
        }
    }

    public function clearPricing(OpeningStockPricing $pricing): void
    {
        $lockedPricing = OpeningStockPricing::query()
            ->withTrashed()
            ->with('lines.openingStockLine')
            ->lockForUpdate()
            ->findOrFail($pricing->getKey());

        foreach ($lockedPricing->lines as $pricingLine) {
            $openingLine = $pricingLine->openingStockLine;

            if (! $openingLine) {
                continue;
            }

            $movement = InventoryTransaction::query()
                ->where('posting_key', "opening-stock:{$lockedPricing->opening_stock_id}:line:{$openingLine->getKey()}")
                ->lockForUpdate()
                ->first();

            if (! $movement instanceof InventoryTransaction) {
                continue;
            }

            $this->assertNoLaterMovement($movement);
            $movement->forceFill(['unit_cost' => null, 'total_cost' => null])->save();
        }
    }

    /** @return array{unit_cost: string|null, total_cost: string|null} */
    private function valuationForLine(int $openingStockLineId): array
    {
        $pricing = OpeningStockPricingLine::query()
            ->join('inventory_opening_stock_pricings', 'inventory_opening_stock_pricings.id', '=', 'inventory_opening_stock_pricing_lines.pricing_id')
            ->where('inventory_opening_stock_pricing_lines.opening_stock_line_id', $openingStockLineId)
            ->whereNull('inventory_opening_stock_pricing_lines.deleted_at')
            ->whereNull('inventory_opening_stock_pricings.deleted_at')
            ->select([
                'inventory_opening_stock_pricing_lines.unit_price',
                'inventory_opening_stock_pricing_lines.line_total',
                'inventory_opening_stock_pricings.exchange_rate',
            ])
            ->first();

        if (! $pricing) {
            return ['unit_cost' => null, 'total_cost' => null];
        }

        return [
            'unit_cost' => bcmul((string) $pricing->unit_price, (string) $pricing->exchange_rate, 8),
            'total_cost' => bcmul((string) $pricing->line_total, (string) $pricing->exchange_rate, 4),
        ];
    }

    private function assertNoLaterMovement(InventoryTransaction $openingMovement): void
    {
        $hasLaterMovement = InventoryTransaction::query()
            ->where('company_id', $openingMovement->company_id)
            ->where('branch_store_id', $openingMovement->branch_store_id)
            ->where('product_id', $openingMovement->product_id)
            ->where('id', '!=', $openingMovement->getKey())
            ->where(function ($query) use ($openingMovement): void {
                $query->whereDate('transaction_date', '>', $openingMovement->transaction_date)
                    ->orWhere(function ($sameDate) use ($openingMovement): void {
                        $sameDate->whereDate('transaction_date', $openingMovement->transaction_date)
                            ->where('id', '>', $openingMovement->getKey());
                    });
            })
            ->exists();

        if ($hasLaterMovement) {
            throw new DomainException(__('Opening stock pricing cannot change after a later Inventory movement exists for the same product and store.'));
        }
    }
}
