<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryLayerService
{
    /**
     * @param  Collection<int, InventoryLayerAllocation>|null  $restorationAllocations
     *                                                                                  When provided, only these specific allocations are restored (each must have its `layer` loaded).
     *                                                                                  Used by position-specific return lines to bind each transaction to its exact source allocation.
     */
    public function recordInbound(
        InventoryTransaction $transaction,
        ?InventoryTransaction $sourceIssue = null,
        ?Collection $restorationAllocations = null,
    ): void {
        if (bccomp((string) $transaction->quantity_in, '0', 8) <= 0
            || InventoryReceiptLayer::query()->where('receipt_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        if ($restorationAllocations !== null && $restorationAllocations->isNotEmpty()) {
            $remaining = (string) $transaction->quantity_in;
            foreach ($restorationAllocations as $allocation) {
                if (bccomp($remaining, '0', 8) <= 0) {
                    break;
                }
                if (bccomp((string) $allocation->quantity, $remaining, 8) < 0) {
                    throw new DomainException(__('The restoration allocation quantity is less than the inbound transaction quantity.'));
                }
                $this->createLayer($transaction, $remaining, $allocation->layer, explicitDimensions: true);
                $remaining = '0';
            }

            if (bccomp($remaining, '0', 8) > 0) {
                throw new DomainException(__('The returned or transferred quantity exceeds its original receipt-layer lineage.'));
            }

            return;
        }

        if ($sourceIssue !== null) {
            $allocations = InventoryLayerAllocation::query()
                ->with('layer')
                ->where('issue_transaction_id', $sourceIssue->getKey())
                ->orderBy('id')
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

    public function allocateIssue(InventoryTransaction $transaction, ?int $receiptTransactionId = null): void
    {
        $this->bootstrapPositionLayers($transaction);
        $this->allocateIssueFromLayers($transaction, $receiptTransactionId);
    }

    private function allocateIssueFromLayers(InventoryTransaction $transaction, ?int $receiptTransactionId = null): void
    {
        if (bccomp((string) $transaction->quantity_out, '0', 8) <= 0
            || InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        $product = Product::query()->findOrFail($transaction->product_id);
        $query = InventoryReceiptLayer::query()
            ->when($receiptTransactionId !== null, fn ($query) => $query->whereIn('receipt_transaction_id', $this->receiptLineageTransactionIds($receiptTransactionId)))
            ->where('company_id', $transaction->company_id)
            ->where('branch_store_id', $transaction->branch_store_id)
            ->where('product_id', $transaction->product_id)
            ->where('stock_status', $transaction->stock_status)
            ->where('remaining_quantity', '>', 0)
            ->when(
                $transaction->warehouse_location_id !== null,
                fn (Builder $builder) => $builder->where('warehouse_location_id', $transaction->warehouse_location_id),
                fn (Builder $builder) => $builder->whereNull('warehouse_location_id'),
            )
            ->when(
                $transaction->batch_lot !== null,
                fn (Builder $builder) => $builder->where('batch_lot', $transaction->batch_lot),
                fn (Builder $builder) => $builder->whereNull('batch_lot'),
            )
            ->when(
                $transaction->stock_status === InventoryTransaction::StatusProductionStaging,
                fn (Builder $layerQuery) => $layerQuery->whereHas(
                    'receiptTransaction',
                    fn (Builder $receiptQuery) => $transaction->production_run_id !== null
                        ? $receiptQuery->where('production_run_id', $transaction->production_run_id)
                        : $receiptQuery->whereNull('production_run_id'),
                ),
            );

        if ($product->tracks_expiry && ! $transaction->is_reversal) {
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

    /** @return array<int, int> */
    private function receiptLineageTransactionIds(int $receiptTransactionId): array
    {
        $ids = [$receiptTransactionId];
        $frontier = $ids;
        while ($frontier !== []) {
            $issueIds = InventoryLayerAllocation::query()
                ->whereHas('layer', fn ($query) => $query->whereIn('receipt_transaction_id', $frontier))
                ->pluck('issue_transaction_id');
            $reversedIds = InventoryTransaction::query()->whereIn('reversal_of_id', $issueIds)
                ->where('is_reversal', true)->where('quantity_in', '>', 0)->pluck('id')->all();
            $frontier = array_values(array_diff($reversedIds, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function bootstrapPositionLayers(InventoryTransaction $issue): void
    {
        $historicalTransactions = InventoryTransaction::query()
            ->where('company_id', $issue->company_id)
            ->where('branch_store_id', $issue->branch_store_id)
            ->where('product_id', $issue->product_id)
            ->where('stock_status', $issue->stock_status)
            ->where('transaction_type', '<>', InventoryTransaction::TypePositionReconciliation)
            ->when(
                $issue->warehouse_location_id !== null,
                fn (Builder $query) => $query->where('warehouse_location_id', $issue->warehouse_location_id),
                fn (Builder $query) => $query->whereNull('warehouse_location_id'),
            )
            ->when(
                $issue->batch_lot !== null,
                fn (Builder $query) => $query->where('batch_lot', $issue->batch_lot),
                fn (Builder $query) => $query->whereNull('batch_lot'),
            )
            ->when(
                $issue->stock_status === InventoryTransaction::StatusProductionStaging,
                fn (Builder $query) => $issue->production_run_id !== null
                    ? $query->where('production_run_id', $issue->production_run_id)
                    : $query->whereNull('production_run_id'),
            )
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

    /**
     * @param  bool  $explicitDimensions  When true, use the transaction's own location/batch/date
     *                                    dimensions for the restored layer instead of the source layer's dimensions. Cost still comes
     *                                    from the source layer to preserve original receipt lineage. Used by position-specific
     *                                    maintenance return lines where the posting service has already validated that the
     *                                    transaction dimensions agree with the allocation layer.
     */
    private function createLayer(
        InventoryTransaction $transaction,
        string $quantity,
        ?InventoryReceiptLayer $sourceLayer = null,
        bool $explicitDimensions = false,
    ): void {
        if ($explicitDimensions) {
            $warehouseLocationId = $transaction->warehouse_location_id;
            $batchLot = $transaction->batch_lot;
            $manufactureDate = $transaction->manufacture_date;
            $expiryDate = $transaction->expiry_date;
        } elseif ($transaction->transaction_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            $warehouseLocationId = $sourceLayer?->warehouse_location_id ?? $transaction->warehouse_location_id;
            $batchLot = $transaction->batch_lot ?? $sourceLayer?->batch_lot;
            $manufactureDate = $transaction->manufacture_date ?? $sourceLayer?->manufacture_date;
            $expiryDate = $transaction->expiry_date ?? $sourceLayer?->expiry_date;
        } else {
            $warehouseLocationId = $transaction->warehouse_location_id;
            $batchLot = $transaction->batch_lot;
            $manufactureDate = $transaction->manufacture_date;
            $expiryDate = $transaction->expiry_date;
        }

        InventoryReceiptLayer::query()->create([
            'company_id' => $transaction->company_id,
            'financial_period_id' => $transaction->financial_period_id,
            'branch_id' => $transaction->branch_id,
            'branch_store_id' => $transaction->branch_store_id,
            'warehouse_location_id' => $warehouseLocationId,
            'product_id' => $transaction->product_id,
            'unit_id' => $transaction->unit_id,
            'receipt_transaction_id' => $transaction->getKey(),
            'stock_status' => $transaction->stock_status,
            'batch_lot' => $batchLot,
            'receipt_date' => $transaction->transaction_date,
            'original_receipt_date' => $sourceLayer?->original_receipt_date ?? $transaction->transaction_date,
            'manufacture_date' => $manufactureDate,
            'expiry_date' => $expiryDate,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $sourceLayer?->unit_cost ?? $transaction->unit_cost,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'source_doc_num' => $transaction->source_doc_num,
            'created_by' => auth()->id(),
        ]);
    }
}
