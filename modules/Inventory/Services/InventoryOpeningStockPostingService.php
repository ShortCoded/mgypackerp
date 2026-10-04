<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Finance\Services\OpeningInventoryValuationService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;

class InventoryOpeningStockPostingService
{
    public function __construct(
        private readonly InventoryLayerService $layers,
        private readonly InventoryCostPolicyService $costPolicies,
    ) {}

    public function post(OpeningStock $openingStock): void
    {
        Company::query()->whereKey($openingStock->company_id)->lockForUpdate()->firstOrFail();
        FinancialPeriod::query()->whereKey($openingStock->financial_period_id)->lockForUpdate()->firstOrFail();
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

        $this->costPolicies->assertPostingDateAllowed(
            (int) $locked->company_id,
            (int) $locked->branch_store_id,
            $locked->document_date->toDateString(),
        );
        $costPolicy = $this->costPolicies->resolve((int) $locked->company_id, (int) $locked->branch_store_id, $locked->document_date->toDateString());

        foreach ($locked->lines as $line) {
            $quantity = (string) $line->quantity;

            if (bccomp($quantity, '0', 8) <= 0) {
                continue;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
            if (! InventoryTransaction::query()->where('posting_key', "opening-stock:{$locked->id}:line:{$line->id}")->exists()) {
                app(OpeningInventoryValuationService::class)->assertSourceMayBePosted((int) $locked->company_id, (int) $locked->financial_period_id,
                    (int) $locked->branch_id, $product, $line->stock_status ?: InventoryTransaction::StatusAvailable);
            }
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
                    'cost_method' => $costPolicy['method'],
                    'cost_policy_id' => $costPolicy['policy_id'],
                    'cost_basis' => 'opening_stock',
                    'serial_numbers' => $line->product_snapshot['serial_numbers'] ?? null,
                    'created_by' => auth()->id(),
                ],
            );
            $this->layers->recordInbound($transaction);
        }
    }

    public function applyPricing(OpeningStockPricing $pricing): void
    {
        Company::query()->whereKey($pricing->company_id)->lockForUpdate()->firstOrFail();
        FinancialPeriod::query()->whereKey($pricing->financial_period_id)->lockForUpdate()->firstOrFail();
        $lockedPricing = OpeningStockPricing::query()
            ->with('lines.openingStockLine')
            ->lockForUpdate()
            ->findOrFail($pricing->getKey());

        if ($lockedPricing->pricing_basis === OpeningStockPricing::BasisEstimate && ! $lockedPricing->isClosed()) {
            throw new DomainException(__('inventory.opening_stock_pricings.messages.estimate_approval_unavailable'));
        }

        foreach ($lockedPricing->lines as $pricingLine) {
            $openingLine = $pricingLine->openingStockLine;

            if (! $openingLine) {
                continue;
            }

            Product::query()->lockForUpdate()->findOrFail($openingLine->product_id);
            $movement = InventoryTransaction::query()
                ->where('posting_key', "opening-stock:{$lockedPricing->opening_stock_id}:line:{$openingLine->getKey()}")
                ->lockForUpdate()
                ->first();

            if (! $movement instanceof InventoryTransaction) {
                continue;
            }

            app(OpeningInventoryValuationService::class)->assertSourceMayBePosted((int) $movement->company_id, (int) $movement->financial_period_id,
                (int) $movement->branch_id, $openingLine->product, $movement->stock_status);
            $this->assertNoLaterMovement($movement);

            $unitCost = bcmul((string) $pricingLine->unit_price, (string) $lockedPricing->exchange_rate, 8);
            $totalCost = bcmul((string) $pricingLine->line_total, (string) $lockedPricing->exchange_rate, 4);
            if (bccomp($unitCost, '999999999999.99999999', 8) > 0 || bccomp($totalCost, '999999999999.99999999', 8) > 0) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_amount_too_large'));
            }

            $movement->forceFill([
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
            ])->save();
            $this->syncReceiptLayerCost($movement, $unitCost);
        }
    }

    public function clearPricing(OpeningStockPricing $pricing): void
    {
        Company::query()->whereKey($pricing->company_id)->lockForUpdate()->firstOrFail();
        FinancialPeriod::query()->whereKey($pricing->financial_period_id)->lockForUpdate()->firstOrFail();
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

            Product::query()->lockForUpdate()->findOrFail($openingLine->product_id);
            $movement = InventoryTransaction::query()
                ->where('posting_key', "opening-stock:{$lockedPricing->opening_stock_id}:line:{$openingLine->getKey()}")
                ->lockForUpdate()
                ->first();

            if (! $movement instanceof InventoryTransaction) {
                continue;
            }

            app(OpeningInventoryValuationService::class)->assertSourceMayBePosted((int) $movement->company_id, (int) $movement->financial_period_id,
                (int) $movement->branch_id, $openingLine->product, $movement->stock_status);
            $this->assertNoLaterMovement($movement);
            $movement->forceFill(['unit_cost' => null, 'total_cost' => null])->save();
            $this->syncReceiptLayerCost($movement, null);
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
            ->where(function ($query): void {
                $query->where('inventory_opening_stock_pricings.pricing_basis', '!=', OpeningStockPricing::BasisEstimate)
                    ->orWhere(function ($approved): void {
                        $approved->where('inventory_opening_stock_pricings.status', OpeningStockPricing::StatusClosed)
                            ->where('inventory_opening_stock_pricings.is_closed', true);
                    });
            })
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
        $laterMovements = InventoryTransaction::query()
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
            ->lockForUpdate()
            ->get();

        $reversedIds = $laterMovements->where('is_reversal', true)->pluck('reversal_of_id')->filter()->map(fn ($id): int => (int) $id)->all();
        if (count($reversedIds) !== count(array_unique($reversedIds))) {
            throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_layer_consumed'));
        }
        $laterById = $laterMovements->keyBy('id');
        foreach ($laterMovements->where('is_reversal', true) as $reversal) {
            $original = $laterById->get($reversal->reversal_of_id);
            if (! $original instanceof InventoryTransaction
                || $original->is_reversal
                || $original->company_id !== $reversal->company_id
                || $original->branch_store_id !== $reversal->branch_store_id
                || $original->product_id !== $reversal->product_id
                || $original->stock_status !== $reversal->stock_status
                || $original->warehouse_location_id !== $reversal->warehouse_location_id
                || $original->batch_lot !== $reversal->batch_lot
                || bccomp((string) $original->quantity_in, (string) $reversal->quantity_out, 8) !== 0
                || bccomp((string) $original->quantity_out, (string) $reversal->quantity_in, 8) !== 0) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_layer_consumed'));
            }
        }
        $hasActiveLaterMovement = $laterMovements->contains(fn (InventoryTransaction $transaction): bool => ! $transaction->is_reversal
            && ! in_array((int) $transaction->getKey(), $reversedIds, true));

        if ($hasActiveLaterMovement || $laterMovements->where('is_reversal', true)->count() !== count($reversedIds)) {
            throw new DomainException(__('Opening stock pricing cannot change after a later Inventory movement exists for the same product and store.'));
        }
    }

    private function syncReceiptLayerCost(InventoryTransaction $movement, ?string $unitCost): void
    {
        $layers = InventoryReceiptLayer::query()
            ->where('receipt_transaction_id', $movement->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($layers->isEmpty()) {
            return;
        }

        $allocations = InventoryLayerAllocation::query()
            ->whereIn('inventory_receipt_layer_id', $layers->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $reversedIssues = collect();

        foreach ($allocations as $allocation) {
            $issue = InventoryTransaction::query()->lockForUpdate()->findOrFail($allocation->issue_transaction_id);
            $reversal = InventoryTransaction::query()
                ->where('reversal_of_id', $issue->getKey())
                ->where('is_reversal', true)
                ->lockForUpdate()
                ->first();
            $document = $issue->source_type === InventoryDocument::class
                ? InventoryDocument::query()->withTrashed()->find($issue->source_id)
                : null;
            $restoredLayers = $reversal
                ? InventoryReceiptLayer::query()->where('receipt_transaction_id', $reversal->getKey())->lockForUpdate()->get()
                : collect();
            $restoredQuantity = $restoredLayers->reduce(
                fn (string $total, InventoryReceiptLayer $layer): string => bcadd($total, (string) $layer->original_quantity, 8),
                '0.00000000',
            );

            if ($document?->status !== InventoryDocument::StatusReversed
                || $document->journal_entry_id !== null
                || $document->reversal_journal_entry_id !== null
                || InventoryLayerAllocation::query()->where('issue_transaction_id', $issue->getKey())->count() !== 1
                || bccomp((string) $allocation->quantity, (string) $issue->quantity_out, 8) !== 0
                || ! $reversal
                || InventoryTransaction::query()->where('reversal_of_id', $issue->getKey())->where('is_reversal', true)->count() !== 1
                || bccomp((string) $reversal->quantity_in, (string) $issue->quantity_out, 8) !== 0
                || bccomp($restoredQuantity, (string) $issue->quantity_out, 8) !== 0
                || InventoryLayerAllocation::query()->whereIn('inventory_receipt_layer_id', $restoredLayers->modelKeys())->exists()) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_layer_consumed'));
            }

            if (! preg_match('/^inventory-document:'.preg_quote((string) $document->getKey(), '/').':line:(\d+):out$/', (string) $issue->posting_key, $matches)) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_layer_consumed'));
            }
            $documentLine = InventoryDocumentLine::withTrashed()
                ->where('inventory_document_id', $document->getKey())
                ->whereKey((int) $matches[1])
                ->lockForUpdate()
                ->first();
            if (! $documentLine
                || (int) $documentLine->product_id !== (int) $issue->product_id
                || bccomp((string) $documentLine->quantity, (string) $issue->quantity_out, 8) !== 0) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_layer_consumed'));
            }

            $reversedIssues->push([$issue, $reversal, $restoredLayers, $documentLine]);
        }

        foreach ($reversedIssues as [$issue, $reversal, $restoredLayers, $documentLine]) {
            $totalCost = $unitCost === null ? null : bcmul((string) $issue->quantity_out, $unitCost, 8);
            $issue->forceFill(['unit_cost' => $unitCost, 'total_cost' => $totalCost])->save();
            $reversal->forceFill(['unit_cost' => $unitCost, 'total_cost' => $totalCost])->save();
            $documentLine->forceFill(['unit_cost' => $unitCost, 'total_cost' => $totalCost])->save();
            $restoredLayers->each(fn (InventoryReceiptLayer $layer) => $layer->forceFill(['unit_cost' => $unitCost])->save());
        }

        foreach ($layers as $layer) {
            $layer->forceFill(['unit_cost' => $unitCost])->save();
        }
    }
}
