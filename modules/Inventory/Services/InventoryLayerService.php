<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryLayerService
{
    public function recordInbound(InventoryTransaction $transaction, ?InventoryTransaction $sourceIssue = null): void
    {
        if (bccomp((string) $transaction->quantity_in, '0', 8) <= 0
            || InventoryReceiptLayer::query()->where('receipt_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        if ($sourceIssue !== null) {
            $allocations = InventoryLayerAllocation::query()
                ->with('layer')
                ->where('issue_transaction_id', $sourceIssue->getKey())
                ->get();
            if ($allocations->isNotEmpty()) {
                $remaining = (string) $transaction->quantity_in;
                foreach ($allocations as $allocation) {
                    if (bccomp($remaining, '0', 8) <= 0) {
                        break;
                    }
                    $quantity = bccomp((string) $allocation->quantity, $remaining, 8) > 0
                        ? $remaining
                        : (string) $allocation->quantity;
                    $this->createLayer($transaction, $quantity, $allocation->layer);
                    $remaining = bcsub($remaining, $quantity, 8);
                }

                if (bccomp($remaining, '0', 8) > 0) {
                    throw new DomainException(__('The returned or transferred quantity exceeds its original receipt-layer lineage.'));
                }

                return;
            }
        }

        $this->createLayer($transaction, (string) $transaction->quantity_in);
    }

    public function allocateIssue(InventoryTransaction $transaction): void
    {
        $this->bootstrapPositionLayers($transaction);
        $this->allocateIssueFromLayers($transaction);
    }

    private function allocateIssueFromLayers(InventoryTransaction $transaction): void
    {
        if (bccomp((string) $transaction->quantity_out, '0', 8) <= 0
            || InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        $product = Product::query()->findOrFail($transaction->product_id);
        $query = InventoryReceiptLayer::query()
            ->where('company_id', $transaction->company_id)
            ->where('branch_store_id', $transaction->branch_store_id)
            ->where('product_id', $transaction->product_id)
            ->where('stock_status', $transaction->stock_status)
            ->where('remaining_quantity', '>', 0)
            ->when($transaction->warehouse_location_id !== null, fn ($builder) => $builder->where('warehouse_location_id', $transaction->warehouse_location_id))
            ->when($transaction->batch_lot !== null, fn ($builder) => $builder->where('batch_lot', $transaction->batch_lot));

        if ($product->tracks_expiry) {
            $query->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $transaction->transaction_date)
                ->orderBy('expiry_date');
        }

        $layers = $query->orderBy('original_receipt_date')->orderBy('id')->lockForUpdate()->get();
        $remaining = (string) $transaction->quantity_out;

        $eligibleQuantity = $layers->reduce(
            fn (string $carry, InventoryReceiptLayer $layer): string => bcadd($carry, (string) $layer->remaining_quantity, 8),
            '0.00000000',
        );
        if (bccomp($remaining, $eligibleQuantity, 8) > 0) {
            $message = $product->tracks_expiry
                ? __('The issue exceeds non-expired stock. Expired or undated expiry layers are blocked.')
                : __('The issue exceeds the receipt-layer quantity available for allocation.');
            throw new DomainException($message);
        }

        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }
            $quantity = bccomp((string) $layer->remaining_quantity, $remaining, 8) > 0
                ? $remaining
                : (string) $layer->remaining_quantity;
            InventoryLayerAllocation::query()->create([
                'inventory_receipt_layer_id' => $layer->getKey(),
                'issue_transaction_id' => $transaction->getKey(),
                'quantity' => $quantity,
            ]);
            $layer->forceFill(['remaining_quantity' => bcsub((string) $layer->remaining_quantity, $quantity, 8)])->save();
            $remaining = bcsub($remaining, $quantity, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            throw new DomainException(__('The issue receipt-layer allocation did not reconcile.'));
        }
    }

    private function bootstrapPositionLayers(InventoryTransaction $issue): void
    {
        $historicalTransactions = InventoryTransaction::query()
            ->where('company_id', $issue->company_id)
            ->where('branch_store_id', $issue->branch_store_id)
            ->where('product_id', $issue->product_id)
            ->where('stock_status', $issue->stock_status)
            ->where('transaction_type', '<>', InventoryTransaction::TypePositionReconciliation)
            ->when($issue->warehouse_location_id !== null, fn ($query) => $query->where('warehouse_location_id', $issue->warehouse_location_id))
            ->when($issue->batch_lot !== null, fn ($query) => $query->where('batch_lot', $issue->batch_lot))
            ->where(function ($query) use ($issue): void {
                $query->whereDate('transaction_date', '<', $issue->transaction_date)
                    ->orWhere(function ($sameDate) use ($issue): void {
                        $sameDate->whereDate('transaction_date', $issue->transaction_date)
                            ->where('id', '<', $issue->getKey());
                    });
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($historicalTransactions as $transaction) {
            if (bccomp((string) $transaction->quantity_in, '0', 8) > 0) {
                $this->recordInbound($transaction);
            }

            if (bccomp((string) $transaction->quantity_out, '0', 8) > 0
                && ! InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->getKey())->exists()) {
                $this->allocateIssueFromLayers($transaction);
            }
        }
    }

    private function createLayer(
        InventoryTransaction $transaction,
        string $quantity,
        ?InventoryReceiptLayer $sourceLayer = null,
    ): void {
        InventoryReceiptLayer::query()->create([
            'company_id' => $transaction->company_id,
            'financial_period_id' => $transaction->financial_period_id,
            'branch_id' => $transaction->branch_id,
            'branch_store_id' => $transaction->branch_store_id,
            'warehouse_location_id' => $transaction->warehouse_location_id,
            'product_id' => $transaction->product_id,
            'unit_id' => $transaction->unit_id,
            'receipt_transaction_id' => $transaction->getKey(),
            'stock_status' => $transaction->stock_status,
            'batch_lot' => $transaction->batch_lot ?? $sourceLayer?->batch_lot,
            'receipt_date' => $transaction->transaction_date,
            'original_receipt_date' => $sourceLayer?->original_receipt_date ?? $transaction->transaction_date,
            'manufacture_date' => $transaction->manufacture_date ?? $sourceLayer?->manufacture_date,
            'expiry_date' => $transaction->expiry_date ?? $sourceLayer?->expiry_date,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $transaction->unit_cost ?? $sourceLayer?->unit_cost,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'source_doc_num' => $transaction->source_doc_num,
            'created_by' => auth()->id(),
        ]);
    }
}
