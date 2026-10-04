<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryCostPolicyTransitionBasis;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptCostBasis;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionMaterialRequirement;

class InventoryLayerService
{
    /**
     * @param  Collection<int, InventoryLayerAllocation>|null  $restorationAllocations
     * @param  Collection<int, array{allocation: InventoryLayerAllocation, quantity: string}>|null  $restorationPlan
     */
    public function recordInbound(
        InventoryTransaction $transaction,
        ?InventoryTransaction $sourceIssue = null,
        ?Collection $restorationAllocations = null,
        ?Collection $restorationPlan = null,
    ): void {
        if (bccomp((string) $transaction->quantity_in, '0', 8) <= 0
            || InventoryReceiptLayer::query()->where('receipt_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        if ($sourceIssue !== null) {
            $plan = $restorationPlan ?? $this->planRestoration($sourceIssue, (string) $transaction->quantity_in, $restorationAllocations);
            if ($plan->isNotEmpty()) {
                foreach ($plan as $slice) {
                    $sliceTotal = $this->restorationSliceTotal($slice['allocation'], $slice['quantity']);
                    $this->createLayer(
                        $transaction,
                        $slice['quantity'],
                        $slice['allocation']->layer,
                        explicitDimensions: $restorationAllocations !== null,
                        sourceAllocationId: (int) $slice['allocation']->getKey(),
                        unitCostOverride: $sliceTotal === null ? null : bcdiv($sliceTotal, $slice['quantity'], 8),
                        sourceAllocationCostSnapshot: $sliceTotal,
                    );
                }

                return;
            }
        }

        if ($restorationAllocations !== null && $restorationAllocations->isNotEmpty()) {
            throw new DomainException(__('The restoration allocation is missing its source issue.'));
        }

        if ($transaction->source_type === InventoryDocument::class && ! $transaction->is_reversal
            && preg_match('/^inventory-document:(\d+):line:(\d+):in$/D', $transaction->posting_key, $postingMatch) === 1) {
            $line = InventoryDocumentLine::query()->where('inventory_document_id', $transaction->source_id)->find((int) $postingMatch[2]);
            if (isset($line?->product_snapshot['inventory_movement_correction'])) {
                $origin = app(InventoryMovementCorrectionService::class)->correctionReceiptSource($transaction);
                if ($origin !== null) {
                    $this->createLayer($transaction, (string) $transaction->quantity_in, $origin, unitCostOverride: $transaction->unit_cost);

                    return;
                }
            }
        }

        $product = Product::query()->findOrFail($transaction->product_id);
        if ($product->tracks_serials && $transaction->inventory_serial_identity_id === null) {
            $serialService = app(InventorySerialService::class);
            $numbers = $serialService->numbers($transaction->serial_numbers);
            if (bccomp((string) count($numbers), (string) $transaction->quantity_in, 8) !== 0) {
                throw new DomainException(__('inventory_serial.count_mismatch'));
            }
            foreach ($numbers as $number) {
                $identity = $serialService->resolve($product, $number);
                $this->createLayer($transaction, '1', serialIdentityId: (int) $identity->id);
            }

            return;
        }
        $this->createLayer($transaction, (string) $transaction->quantity_in);
    }

    /**
     * @param  Collection<int, InventoryLayerAllocation>|null  $specificAllocations
     * @return Collection<int, array{allocation: InventoryLayerAllocation, quantity: string}>
     */
    public function planRestoration(
        InventoryTransaction $sourceIssue,
        string $quantity,
        ?Collection $specificAllocations = null,
        bool $limitToUnreturned = false,
    ): Collection {
        $allocations = InventoryLayerAllocation::query()
            ->with('layer')
            ->where('issue_transaction_id', $sourceIssue->getKey())
            ->when($specificAllocations !== null, fn (Builder $query) => $query->whereIn('id', $specificAllocations->pluck('id')->all()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($allocations->isEmpty()) {
            return collect();
        }

        if ($specificAllocations !== null && $allocations->count() !== $specificAllocations->count()) {
            throw new DomainException(__('The restoration allocation does not belong to the linked source issue.'));
        }

        $returned = $limitToUnreturned ? $this->returnedQuantities($allocations->pluck('id')->all()) : collect();
        $remaining = $quantity;
        $plan = collect();
        foreach ($allocations as $allocation) {
            $available = bcsub((string) $allocation->quantity, (string) $returned->get($allocation->getKey(), '0'), 8);
            if (bccomp($available, '0', 8) <= 0) {
                continue;
            }
            $sliceQuantity = bccomp($available, $remaining, 8) > 0 ? $remaining : $available;
            if (bccomp($sliceQuantity, '0', 8) <= 0) {
                break;
            }
            $plan->push(['allocation' => $allocation, 'quantity' => $sliceQuantity]);
            $remaining = bcsub($remaining, $sliceQuantity, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            throw new DomainException(__('The returned or transferred quantity exceeds its original receipt-layer lineage.'));
        }

        return $plan;
    }

    /** @param Collection<int, array{allocation: InventoryLayerAllocation, quantity: string}> $plan
     * @return array{unit_cost: string, total_cost: string}
     */
    public function restorationCost(Collection $plan, string $quantity): array
    {
        $restoredQuantity = '0.00000000';
        $total = '0.00000000';
        foreach ($plan as $slice) {
            $allocation = $slice['allocation'];
            $cost = $this->allocationUnitCost($allocation);
            if ($cost === null) {
                throw new DomainException(__('FIFO return costing requires priced original receipt layers.'));
            }
            $restoredQuantity = bcadd($restoredQuantity, $slice['quantity'], 8);
            $sliceTotal = $this->restorationSliceTotal($allocation, $slice['quantity']);
            $total = bcadd($total, $sliceTotal, 8);
        }

        if (bccomp($restoredQuantity, $quantity, 8) !== 0) {
            throw new DomainException(__('FIFO return cost did not reconcile with its original allocations.'));
        }

        return ['unit_cost' => bcdiv($total, $quantity, 8), 'total_cost' => $total];
    }

    public function remainingReturnQuantity(InventoryLayerAllocation $allocation): string
    {
        $returned = $this->returnedQuantities([(int) $allocation->getKey()]);

        return bcsub((string) $allocation->quantity, (string) $returned->get($allocation->getKey(), '0'), 8);
    }

    /** @param array<int, int> $allocationIds */
    private function returnedQuantities(array $allocationIds): Collection
    {
        return $this->returnedLayers($allocationIds)
            ->selectRaw('source_allocation_id, SUM(original_quantity) as returned_quantity')
            ->groupBy('source_allocation_id')
            ->pluck('returned_quantity', 'source_allocation_id');
    }

    /** @param array<int, int> $allocationIds */
    private function returnedLayers(array $allocationIds): Builder
    {
        return InventoryReceiptLayer::query()
            ->whereIn('source_allocation_id', $allocationIds)
            ->whereHas('receiptTransaction', fn (Builder $query) => $query
                ->whereIn('transaction_type', [InventoryDocument::TypeSalesReturnReceipt, InventoryDocument::TypeMaintenanceMaterialReturn])
                ->where('is_reversal', false)
                ->whereNotIn('id', InventoryTransaction::query()
                    ->select('reversal_of_id')->where('is_reversal', true)->whereNotNull('reversal_of_id')));
    }

    /** @param list<int> $selectedSerialLayerIds */
    public function allocateIssue(InventoryTransaction $transaction, ?int $receiptTransactionId = null, ?int $selectedLayerId = null, array $selectedSerialLayerIds = []): void
    {
        $this->bootstrapPositionLayers($transaction);
        $this->allocateIssueFromLayers($transaction, $receiptTransactionId, $selectedLayerId, $selectedSerialLayerIds);
    }

    /** @return array{unit_cost: string, total_cost: string} */
    public function allocatedIssueCost(InventoryTransaction $transaction): array
    {
        $allocations = InventoryLayerAllocation::query()
            ->with('layer')
            ->where('issue_transaction_id', $transaction->getKey())
            ->orderBy('id')
            ->get();
        $quantity = '0.00000000';
        $total = '0.00000000';

        foreach ($allocations as $allocation) {
            $unitCost = $this->allocationUnitCost($allocation);
            if ($unitCost === null) {
                throw new DomainException(__('FIFO issue costing requires a priced receipt layer.'));
            }
            $quantity = bcadd($quantity, (string) $allocation->quantity, 8);
            $allocationTotal = $allocation->completedTotalCost() === null
                ? bcmul((string) $allocation->quantity, $unitCost, 8)
                : (string) $allocation->completedTotalCost();
            $total = bcadd($total, $allocationTotal, 8);
        }

        if (bccomp($quantity, (string) $transaction->quantity_out, 8) !== 0 || bccomp($quantity, '0', 8) <= 0) {
            throw new DomainException(__('FIFO issue costing did not reconcile with its layer allocations.'));
        }

        return ['unit_cost' => bcdiv($total, $quantity, 8), 'total_cost' => $total];
    }

    /** @param list<int> $selectedSerialLayerIds */
    private function allocateIssueFromLayers(InventoryTransaction $transaction, ?int $receiptTransactionId = null, ?int $selectedLayerId = null, array $selectedSerialLayerIds = []): void
    {
        if (bccomp((string) $transaction->quantity_out, '0', 8) <= 0
            || InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->getKey())->exists()) {
            return;
        }

        [$query, $product, $selectedPurchaseReturn] = $this->issueLayerQuery($transaction, $receiptTransactionId, $selectedLayerId, $selectedSerialLayerIds);

        $layers = $query->orderBy('original_receipt_date')->orderBy('id')->lockForUpdate()->get();
        $remaining = (string) $transaction->quantity_out;
        $remainingIssueValue = $transaction->total_cost;

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
            if ($product->tracks_serials || $layer->inventory_serial_identity_id !== null) {
                app(InventorySerialService::class)->consume($transaction, $layer, $quantity, $selectedPurchaseReturn);
            }
            [$costUnitSnapshot, $costTotalSnapshot, $completionBasis, $transitionBasis] = $this->issueCostSlice($transaction, $product, $layer, $quantity, true);
            if ($transitionBasis !== null) {
                $transitionBasis->forceFill([
                    'remaining_quantity' => bcsub((string) $transitionBasis->remaining_quantity, $quantity, 8),
                    'remaining_book_value' => bcsub((string) $transitionBasis->remaining_book_value, (string) $costTotalSnapshot, 8),
                ])->save();
            }
            if ($completionBasis !== null) {
                $completionBasis->forceFill([
                    'remaining_quantity' => bcsub((string) $completionBasis->remaining_quantity, $quantity, 8),
                    'remaining_value' => bcsub((string) $completionBasis->remaining_value, (string) $costTotalSnapshot, 8),
                ])->save();
            }
            if (! InventoryCostPolicy::usesReceiptLayers($transaction->cost_method)) {
                $costTotalSnapshot = $transaction->total_cost === null ? null
                    : (bccomp($quantity, $remaining, 8) === 0 ? $remainingIssueValue
                        : bcdiv(bcmul((string) $transaction->total_cost, $quantity, 16), (string) $transaction->quantity_out, 8));
                $costUnitSnapshot = $costTotalSnapshot === null ? null : bcdiv($costTotalSnapshot, $quantity, 8);
                if ($remainingIssueValue !== null) {
                    $remainingIssueValue = bcsub((string) $remainingIssueValue, (string) $costTotalSnapshot, 8);
                }
            }
            InventoryLayerAllocation::query()->create([
                'inventory_receipt_layer_id' => $layer->getKey(),
                'issue_transaction_id' => $transaction->getKey(),
                'quantity' => $quantity,
                'inventory_cost_policy_transition_basis_id' => $transitionBasis?->getKey(),
                'cost_unit_snapshot' => $costUnitSnapshot,
                'cost_total_snapshot' => $costTotalSnapshot,
            ]);
            $layer->forceFill(['remaining_quantity' => bcsub((string) $layer->remaining_quantity, $quantity, 8)])->save();
            $remaining = bcsub($remaining, $quantity, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            throw new DomainException(__('The issue receipt-layer allocation did not reconcile.'));
        }
    }

    /** @return array{0: Builder<InventoryReceiptLayer>, 1: Product, 2: bool} */
    private function issueLayerQuery(InventoryTransaction $transaction, ?int $receiptTransactionId, ?int $selectedLayerId, array $selectedSerialLayerIds): array
    {
        $product = Product::query()->findOrFail($transaction->product_id);
        $selectedPurchaseReturn = $selectedSerialLayerIds !== [] && $product->tracks_serials
            && $transaction->transaction_type === 'purchase_return' && $receiptTransactionId !== null && $selectedLayerId === null
            && count($selectedSerialLayerIds) === count(array_unique($selectedSerialLayerIds))
            && bccomp((string) count($selectedSerialLayerIds), (string) $transaction->quantity_out, 8) === 0;
        if ($selectedSerialLayerIds !== [] && ! $selectedPurchaseReturn) {
            throw new DomainException(__('inventory_serial.selection_mismatch'));
        }
        $productionLineage = $transaction->stock_status === InventoryTransaction::StatusProductionStaging
            && $transaction->source_line_type === ProductionMaterialRequirement::class && $transaction->production_run_id !== null;
        if (! $transaction->is_reversal) {
            if (($product->tracks_serials && $selectedLayerId === null && ! $selectedPurchaseReturn)
                || ($transaction->cost_method === InventoryCostPolicy::SpecificIdentification && $selectedLayerId === null && ! $productionLineage && ! $selectedPurchaseReturn)
                || ($transaction->cost_method !== InventoryCostPolicy::SpecificIdentification && $selectedLayerId !== null && ! $product->tracks_serials)) {
                throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
            }
        }
        $query = InventoryReceiptLayer::query()
            ->when($selectedPurchaseReturn, fn ($query) => $query->whereIn('id', $selectedSerialLayerIds)->whereNotNull('inventory_serial_identity_id')
                ->withAuthoritativeCost()->whereDate('original_receipt_date', '<=', $transaction->transaction_date))
            ->when($transaction->inventory_serial_identity_id !== null, fn ($query) => $query->where('inventory_serial_identity_id', $transaction->inventory_serial_identity_id))
            ->when($selectedLayerId !== null, fn ($query) => $query->whereKey($selectedLayerId)->withAuthoritativeCost()->whereDate('original_receipt_date', '<=', $transaction->transaction_date))
            ->when($transaction->cost_method === InventoryCostPolicy::SpecificIdentification && $productionLineage && $selectedLayerId === null,
                fn ($query) => $query->whereNotNull('source_allocation_id')->whereHas('receiptTransaction', fn ($receipt) => $receipt
                    ->where('source_line_type', ProductionMaterialRequirement::class)->where('source_line_id', $transaction->source_line_id)))
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
                ->whereDate('expiry_date', '>=', $transaction->transaction_date);
            if ($transaction->cost_method !== InventoryCostPolicy::Fifo) {
                $query->orderBy('expiry_date');
            }
        }

        return [$query->orderBy('original_receipt_date')->orderBy('id'), $product, $selectedPurchaseReturn];
    }

    /** @return array{0: string|null, 1: string|null, 2: InventoryReceiptCostBasis|null, 3: InventoryCostPolicyTransitionBasis|null} */
    private function issueCostSlice(InventoryTransaction $transaction, Product $product, InventoryReceiptLayer $layer, string $quantity, bool $lockForUpdate): array
    {
        $completionBasis = $layer->activeCostCompletion($transaction->cost_policy_id);
        $transitionBasis = $completionBasis === null ? $this->transitionBasis($transaction, $layer, $lockForUpdate) : null;
        [$costUnitSnapshot, $costTotalSnapshot] = $transitionBasis === null
            ? [
                $layer->unit_cost === null ? null : (string) $layer->unit_cost,
                $layer->unit_cost === null ? null : bcmul($quantity, (string) $layer->unit_cost, 8),
            ]
            : $this->transitionSlice($transitionBasis, $quantity);
        if ($completionBasis !== null) {
            $completionBasis = InventoryReceiptCostBasis::query()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->findOrFail($completionBasis->id);
            if (bccomp((string) $completionBasis->remaining_quantity, (string) $layer->remaining_quantity, 8) !== 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_lineage_changed'));
            }
            $costTotalSnapshot = bccomp($quantity, (string) $completionBasis->remaining_quantity, 8) === 0
                ? (string) $completionBasis->remaining_value
                : bcdiv(bcmul((string) $completionBasis->remaining_value, $quantity, 16), (string) $completionBasis->remaining_quantity, 8);
            $costUnitSnapshot = bcdiv($costTotalSnapshot, $quantity, 8);
        }
        if (($transaction->cost_method === InventoryCostPolicy::SpecificIdentification
            || ($layer->inventory_serial_identity_id !== null && InventoryCostPolicy::usesReceiptLayers($transaction->cost_method)))
            && $costUnitSnapshot !== null && $transitionBasis === null && $completionBasis === null) {
            $originalValue = $layer->source_allocation_cost_snapshot ?? $layer->receiptTransaction->total_cost;
            if ($layer->inventory_serial_identity_id !== null && $layer->source_allocation_cost_snapshot === null
                && bccomp((string) $layer->receiptTransaction->quantity_in, (string) $layer->original_quantity, 8) > 0) {
                $laterSerial = InventoryReceiptLayer::query()->where('receipt_transaction_id', $layer->receipt_transaction_id)->where('id', '>', $layer->id)->exists();
                $originalValue = $laterSerial ? bcmul((string) $layer->original_quantity, (string) $layer->unit_cost, 8)
                    : bcsub((string) $originalValue, bcmul(bcsub((string) $layer->receiptTransaction->quantity_in, (string) $layer->original_quantity, 8), (string) $layer->unit_cost, 8), 8);
            }
            if ($originalValue === null) {
                throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
            }
            if (bccomp($quantity, (string) $layer->remaining_quantity, 8) === 0) {
                $usedValue = InventoryLayerAllocation::query()->where('inventory_receipt_layer_id', $layer->id)->sum('cost_total_snapshot');
                $costTotalSnapshot = bcsub((string) $originalValue, (string) $usedValue, 8);
                $costUnitSnapshot = bcdiv($costTotalSnapshot, $quantity, 8);
            }
        }

        return [$costUnitSnapshot, $costTotalSnapshot, $completionBasis, $transitionBasis];
    }

    /**
     * Plans current receipt-layer consumption without posting stock or updating value bases.
     *
     * @return array{unit_cost: string, total_cost: string, allocations: list<array<string, mixed>>}
     */
    public function previewIssueCost(InventoryTransaction $transaction, ?int $selectedLayerId = null): array
    {
        if ((! InventoryCostPolicy::usesReceiptLayers($transaction->cost_method) && $transaction->inventory_serial_identity_id === null)
            || bccomp((string) $transaction->quantity_out, '0', 8) <= 0) {
            throw new DomainException(__('FIFO issue costing did not reconcile with its layer allocations.'));
        }
        [$query, $product] = $this->issueLayerQuery($transaction, null, $selectedLayerId, []);
        $layers = $query->get();
        $remaining = (string) $transaction->quantity_out;
        $total = '0.00000000';
        $allocations = [];
        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }
            $quantity = bccomp((string) $layer->remaining_quantity, $remaining, 8) > 0 ? $remaining : (string) $layer->remaining_quantity;
            [$unit, $value, $completion, $transition] = $this->issueCostSlice($transaction, $product, $layer, $quantity, false);
            if ($unit === null || $value === null) {
                throw new DomainException(__('FIFO issue costing requires a priced receipt layer.'));
            }
            $allocations[] = [
                'layer_id' => (int) $layer->id, 'quantity' => $quantity, 'unit_cost' => $unit, 'total_cost' => $value,
                'remaining_quantity' => (string) $layer->remaining_quantity,
                'completion_basis_id' => $completion?->id, 'transition_basis_id' => $transition?->id,
            ];
            $total = bcadd($total, $value, 8);
            $remaining = bcsub($remaining, $quantity, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new DomainException(__('The issue exceeds the receipt-layer quantity available for allocation.'));
        }

        return ['unit_cost' => bcdiv($total, (string) $transaction->quantity_out, 8), 'total_cost' => $total, 'allocations' => $allocations];
    }

    /** @return array<int, int> */
    public function receiptLineageTransactionIds(int $receiptTransactionId): array
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
        ?int $sourceAllocationId = null,
        ?string $unitCostOverride = null,
        ?string $sourceAllocationCostSnapshot = null,
        ?int $serialIdentityId = null,
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

        $serial = app(InventorySerialService::class)->lockForReceipt($transaction, $sourceLayer, $quantity, $serialIdentityId);
        $layer = InventoryReceiptLayer::query()->create([
            'company_id' => $transaction->company_id,
            'financial_period_id' => $transaction->financial_period_id,
            'branch_id' => $transaction->branch_id,
            'branch_store_id' => $transaction->branch_store_id,
            'warehouse_location_id' => $warehouseLocationId,
            'product_id' => $transaction->product_id,
            'inventory_serial_identity_id' => $serial?->id,
            'unit_id' => $transaction->unit_id,
            'receipt_transaction_id' => $transaction->getKey(),
            'source_allocation_id' => $sourceAllocationId,
            'source_allocation_cost_snapshot' => $sourceAllocationCostSnapshot,
            'stock_status' => $transaction->stock_status,
            'batch_lot' => $batchLot,
            'receipt_date' => $transaction->transaction_date,
            'original_receipt_date' => $sourceLayer?->original_receipt_date ?? $transaction->transaction_date,
            'manufacture_date' => $manufactureDate,
            'expiry_date' => $expiryDate,
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $unitCostOverride ?? $sourceLayer?->unit_cost ?? $transaction->unit_cost,
            'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id,
            'source_doc_num' => $transaction->source_doc_num,
            'created_by' => auth()->id(),
        ]);
        $serial?->forceFill(['current_receipt_layer_id' => $layer->id])->save();
    }

    private function allocationUnitCost(InventoryLayerAllocation $allocation): ?string
    {
        $completed = $allocation->completedTotalCost();
        if ($completed !== null) {
            return bcdiv($completed, (string) $allocation->quantity, 8);
        }
        if ($allocation->cost_unit_snapshot !== null) {
            return (string) $allocation->cost_unit_snapshot;
        }

        return $allocation->layer?->unit_cost === null ? null : (string) $allocation->layer->unit_cost;
    }

    private function restorationSliceTotal(InventoryLayerAllocation $allocation, string $quantity): ?string
    {
        $unitCost = $this->allocationUnitCost($allocation);
        if ($unitCost === null) {
            return null;
        }
        if ($allocation->completedTotalCost() === null) {
            return bcmul($quantity, $unitCost, 8);
        }

        $returnedQuantity = (string) $this->returnedQuantities([(int) $allocation->getKey()])
            ->get($allocation->getKey(), '0');
        $remainingQuantity = bcsub((string) $allocation->quantity, $returnedQuantity, 8);
        if (bccomp($quantity, $remainingQuantity, 8) !== 0) {
            return bcmul($quantity, $unitCost, 8);
        }

        $returnedValue = (string) $this->returnedValues([(int) $allocation->getKey()])
            ->get($allocation->getKey(), '0');

        return bcsub((string) $allocation->completedTotalCost(), $returnedValue, 8);
    }

    /** @param array<int, int> $allocationIds */
    private function returnedValues(array $allocationIds): Collection
    {
        return $this->returnedLayers($allocationIds)
            ->get(['id', 'source_allocation_id', 'original_quantity', 'unit_cost', 'source_allocation_cost_snapshot'])
            ->groupBy('source_allocation_id')
            ->map(fn (Collection $layers): string => $layers->reduce(
                fn (string $total, InventoryReceiptLayer $layer): string => bcadd(
                    $total,
                    $layer->activeCostCompletion()?->completed_total_cost ?? ($layer->source_allocation_cost_snapshot === null
                        ? bcmul((string) $layer->original_quantity, (string) $layer->unit_cost, 8)
                        : (string) $layer->source_allocation_cost_snapshot),
                    8,
                ),
                '0.00000000',
            ));
    }

    private function transitionBasis(
        InventoryTransaction $transaction,
        InventoryReceiptLayer $layer,
        bool $lockForUpdate = true,
    ): ?InventoryCostPolicyTransitionBasis {
        if (! InventoryCostPolicy::usesReceiptLayers($transaction->cost_method) || $transaction->cost_policy_id === null) {
            return null;
        }

        return InventoryCostPolicyTransitionBasis::query()
            ->where('inventory_receipt_layer_id', $layer->getKey())
            ->where('remaining_quantity', '>', 0)
            ->whereHas('transition', fn (Builder $query) => $query
                ->where('status', InventoryCostPolicyTransition::StatusActivated)
                ->where('inventory_cost_policy_id', $transaction->cost_policy_id))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** @return array{0: string, 1: string} */
    private function transitionSlice(InventoryCostPolicyTransitionBasis $basis, string $quantity): array
    {
        if (bccomp($quantity, (string) $basis->remaining_quantity, 8) > 0) {
            throw new DomainException(__('inventory_cost_policy.transition_errors.reconciliation'));
        }

        $isFinalSlice = bccomp($quantity, (string) $basis->remaining_quantity, 8) === 0;
        $total = $isFinalSlice
            ? (string) $basis->remaining_book_value
            : bcmul($quantity, (string) $basis->basis_unit_cost, 8);
        $unit = bcdiv($total, $quantity, 8);

        return [$unit, $total];
    }
}
