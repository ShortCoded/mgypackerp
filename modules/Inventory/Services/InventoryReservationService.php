<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\ActivityLogger;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionMaterialRequirement;

class InventoryReservationService
{
    public function __construct(private readonly InventoryAvailabilityService $availability, private readonly ActivityLogger $activities) {}

    public function reserveForProduction(
        ProductionMaterialRequirement $requirement,
        int $branchStoreId,
        ?string $quantity = null,
        ?int $warehouseLocationId = null,
        bool $allowBeyondRequirement = false,
        ?int $materialRequestLineId = null,
        ?string $batchLot = null,
        bool $matchBatch = false,
    ): InventoryReservation {
        return DB::transaction(function () use ($requirement, $branchStoreId, $quantity, $warehouseLocationId, $allowBeyondRequirement, $materialRequestLineId, $batchLot, $matchBatch): InventoryReservation {
            $locked = ProductionMaterialRequirement::query()
                ->with('run.order.salesOrder')
                ->lockForUpdate()
                ->findOrFail($requirement->getKey());
            $run = $locked->run;
            $order = $run->order;
            $remainingRequirement = bcsub((string) $locked->planned_quantity, (string) $locked->reserved_quantity, 8);
            $reserveQuantity = $quantity ?? $remainingRequirement;

            if (bccomp($reserveQuantity, '0', 8) <= 0
                || (! $allowBeyondRequirement && bccomp($reserveQuantity, $remainingRequirement, 8) > 0)) {
                throw new DomainException(__('Production reservation must be positive and cannot exceed the unreserved requirement.'));
            }

            $branchStore = BranchStore::query()->lockForUpdate()->findOrFail($branchStoreId);

            if ((int) $branchStore->branch_id !== (int) $order->branch_id) {
                throw new DomainException(__('Production reservations must use a store in the production branch.'));
            }

            if ($materialRequestLineId !== null && ! ProductionMaterialRequestLine::query()
                ->whereKey($materialRequestLineId)
                ->where('production_material_requirement_id', $locked->getKey())
                ->whereHas('request', fn ($query) => $query
                    ->where('production_run_id', $run->getKey())
                    ->where('branch_store_id', $branchStoreId))
                ->exists()) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }

            if ($warehouseLocationId !== null && ! WarehouseLocation::query()
                ->whereKey($warehouseLocationId)
                ->where('branch_store_id', $branchStoreId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(__('Production reservations require an active location in the selected store.'));
            }
            $product = Product::query()->lockForUpdate()->findOrFail($locked->product_id);
            $stockPosition = $this->availableStockPosition(
                (int) $order->company_id,
                $branchStoreId,
                (int) $locked->product_id,
                $reserveQuantity,
                $warehouseLocationId,
                $batchLot,
                $matchBatch,
            );

            if ($stockPosition === null) {
                throw new DomainException(__('The production reservation exceeds available stock.'));
            }

            $reservation = InventoryReservation::query()->create([
                'company_id' => $order->company_id,
                'financial_period_id' => $order->financial_period_id,
                'branch_id' => $order->branch_id,
                'branch_store_id' => $branchStoreId,
                'warehouse_location_id' => $stockPosition['warehouse_location_id'],
                'batch_lot' => $stockPosition['batch_lot'],
                'production_order_id' => $order->getKey(),
                'production_run_id' => $run->getKey(),
                'production_material_requirement_id' => $locked->getKey(),
                'production_material_request_line_id' => $materialRequestLineId,
                'customer_id' => $order->salesOrder?->customer_id,
                'product_id' => $locked->product_id,
                'unit_id' => $product->item_unit_id,
                'transaction_unit_id' => $product->item_unit_id,
                'conversion_factor' => 1,
                'transaction_quantity' => $reserveQuantity,
                'quantity' => $reserveQuantity,
                'stock_status' => InventoryTransaction::StatusAvailable,
                'status' => InventoryReservation::StatusActive,
                'created_by' => auth()->id(),
            ]);
            $locked->increment('reserved_quantity', $reserveQuantity);

            return $reservation->refresh();
        });
    }

    public function reserveForProductionAcrossPositions(
        ProductionMaterialRequirement $requirement,
        int $branchStoreId,
        string $quantity,
        ?int $warehouseLocationId = null,
        bool $allowBeyondRequirement = false,
        ?int $materialRequestLineId = null,
        bool $allowPartial = false,
    ): string {
        return DB::transaction(function () use ($requirement, $branchStoreId, $quantity, $warehouseLocationId, $allowBeyondRequirement, $materialRequestLineId, $allowPartial): string {
            if (bccomp($quantity, '0', 8) <= 0) {
                throw new DomainException(__('Production reservation must be positive and cannot exceed the unreserved requirement.'));
            }
            $companyId = (int) $requirement->run->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $positions = InventoryTransaction::query()->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)
                ->where('product_id', $requirement->product_id)->where('stock_status', InventoryTransaction::StatusAvailable)
                ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
                ->groupBy(['warehouse_location_id', 'batch_lot'])->havingRaw('sum(quantity_in - quantity_out) > 0')
                ->orderByRaw('min(transaction_date), min(id)')->get(['warehouse_location_id', 'batch_lot']);
            $remaining = $quantity;
            foreach ($positions as $position) {
                if (bccomp($remaining, '0', 8) <= 0) {
                    break;
                }
                $available = $this->availability->forProduct($companyId, $branchStoreId, (int) $requirement->product_id, null,
                    $position->warehouse_location_id, InventoryTransaction::StatusAvailable, $position->batch_lot, true)['available'];
                if (bccomp($available, '0', 8) <= 0) {
                    continue;
                }
                $slice = bccomp($remaining, $available, 8) > 0 ? $available : $remaining;
                $this->reserveForProduction($requirement, $branchStoreId, $slice, $position->warehouse_location_id,
                    $allowBeyondRequirement, $materialRequestLineId, $position->batch_lot, true);
                $remaining = bcsub($remaining, $slice, 8);
            }
            if (! $allowPartial && bccomp($remaining, '0', 8) > 0) {
                throw new DomainException(__('The production reservation exceeds available stock.'));
            }

            return bcsub($quantity, $remaining, 8);
        });
    }

    /** @param list<array{layer_id: int, quantity: string}> $selections */
    public function reserveSelectedForProduction(ProductionMaterialRequirement $requirement, int $branchStoreId, array $selections, bool $additional = false): string
    {
        return DB::transaction(function () use ($requirement, $branchStoreId, $selections, $additional): string {
            $companyId = (int) $requirement->run->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $locked = ProductionMaterialRequirement::query()->with('run')->lockForUpdate()->findOrFail($requirement->id);
            $plan = [];
            $seen = [];
            $total = '0';
            foreach ($selections as $selection) {
                $id = (int) ($selection['layer_id'] ?? 0);
                $quantity = (string) ($selection['quantity'] ?? '0');
                $layer = InventoryReceiptLayer::query()->whereKey($id)->where('company_id', $companyId)
                    ->where('branch_store_id', $branchStoreId)->where('product_id', $locked->product_id)
                    ->where('stock_status', InventoryTransaction::StatusAvailable)->whereNotNull('unit_cost')->lockForUpdate()->first();
                if (isset($seen[$id]) || $layer === null || ! preg_match('/^\d+(?:\.\d{1,8})?$/D', $quantity)
                    || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, (string) $layer->remaining_quantity, 8) > 0) {
                    throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                }
                $seen[$id] = true;
                $total = bcadd($total, $quantity, 8);
                $plan[] = ['layer' => $layer, 'quantity' => $quantity];
            }
            if ($plan === [] || (! $additional && bccomp($total, bcsub((string) $locked->planned_quantity, (string) $locked->issued_quantity, 8), 8) > 0)) {
                throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
            }
            if (! $additional) {
                $reservations = InventoryReservation::query()->where('company_id', $companyId)->where('production_material_requirement_id', $locked->id)
                    ->whereNull('production_material_request_line_id')->where('status', InventoryReservation::StatusActive)->orderBy('id')->lockForUpdate()->get();
                foreach ($reservations as $reservation) {
                    if ((int) $reservation->branch_store_id !== $branchStoreId) {
                        throw new DomainException(__('production_execution.messages.batch_reservations_same_store'));
                    }
                    $remaining = $reservation->remaining_quantity;
                    if (bccomp($remaining, '0', 8) <= 0) {
                        continue;
                    }
                    $reservation->update(['released_quantity' => bcadd((string) $reservation->released_quantity, $remaining, 8),
                        'status' => InventoryReservation::StatusReleased, 'released_by' => auth()->id(), 'released_at' => now(),
                        'release_reason' => __('inventory_cost_policy.selected_reservation_reason')]);
                    $locked->decrement('reserved_quantity', $remaining);
                    $this->activities->log(request(), 'production', 'production_reservation.receipt_layer_selection', 'success', [
                        'subject' => $reservation, 'company_id' => $companyId, 'properties_only' => true,
                        'properties' => ['released_base_quantity' => $remaining, 'requirement_id' => $locked->id, 'selected_layer_ids' => array_keys($seen)],
                    ]);
                }
            }
            foreach ($plan as $slice) {
                $layer = $slice['layer'];
                $this->reserveForProduction($locked, $branchStoreId, $slice['quantity'], $layer->warehouse_location_id,
                    $additional, null, $layer->batch_lot, true);
            }

            return $total;
        });
    }

    /** @return array{warehouse_location_id: int|null, batch_lot: string|null}|null */
    private function availableStockPosition(
        int $companyId,
        int $branchStoreId,
        int $productId,
        string $quantity,
        ?int $warehouseLocationId,
        ?string $batchLot = null,
        bool $matchBatch = false,
    ): ?array {
        $positions = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
            ->when($matchBatch, fn ($query) => $batchLot === null ? $query->whereNull('batch_lot') : $query->where('batch_lot', $batchLot))
            ->groupBy(['warehouse_location_id', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) > 0')
            ->orderByRaw('min(transaction_date), min(id)')
            ->get(['warehouse_location_id', 'batch_lot']);

        foreach ($positions as $position) {
            $available = $this->availability->forProduct(
                $companyId,
                $branchStoreId,
                $productId,
                null,
                $position->warehouse_location_id,
                InventoryTransaction::StatusAvailable,
                $position->batch_lot,
                true,
            );

            if (bccomp($quantity, $available['available'], 8) <= 0) {
                return [
                    'warehouse_location_id' => $position->warehouse_location_id === null ? null : (int) $position->warehouse_location_id,
                    'batch_lot' => $position->batch_lot,
                ];
            }
        }

        return null;
    }

    /** @return list<array{reservation: InventoryReservation, quantity: string, selected_receipt_layer_id: int|null}> */
    public function consumeForRequirement(
        ProductionMaterialRequirement $requirement,
        string $quantity,
        ?int $branchStoreId = null,
        ?int $materialRequestLineId = null,
        bool $unlinkedOnly = false,
        array $selectedLayers = [],
    ): array {
        return DB::transaction(function () use ($requirement, $quantity, $branchStoreId, $materialRequestLineId, $unlinkedOnly, $selectedLayers): array {
            $remaining = $quantity;
            $consumed = [];

            if (bccomp($remaining, '0', 8) <= 0) {
                throw new DomainException(__('A consumed reservation quantity must be positive.'));
            }

            $reservations = InventoryReservation::query()
                ->where('production_material_requirement_id', $requirement->getKey())
                ->when($branchStoreId !== null, fn ($query) => $query->where('branch_store_id', $branchStoreId))
                ->when($materialRequestLineId !== null, fn ($query) => $query->where('production_material_request_line_id', $materialRequestLineId))
                ->when($unlinkedOnly, fn ($query) => $query->whereNull('production_material_request_line_id'))
                ->where('status', InventoryReservation::StatusActive)
                ->oldest()
                ->lockForUpdate()
                ->get();

            $selectionPlan = $selectedLayers === [] ? [['quantity' => $quantity, 'layer' => null]] : [];
            $selectedTotal = '0';
            $seen = [];
            foreach ($selectedLayers as $selection) {
                $id = (int) ($selection['layer_id'] ?? 0);
                $slice = (string) ($selection['quantity'] ?? '0');
                $layer = InventoryReceiptLayer::query()->whereKey($id)->where('company_id', $requirement->run->company_id)
                    ->where('branch_store_id', $branchStoreId)->where('product_id', $requirement->product_id)->where('stock_status', InventoryTransaction::StatusAvailable)
                    ->whereNotNull('unit_cost')->lockForUpdate()->first();
                if (isset($seen[$id]) || $layer === null || ! preg_match('/^\d+(?:\.\d{1,8})?$/D', $slice)
                    || bccomp($slice, '0', 8) <= 0 || bccomp($slice, (string) $layer->remaining_quantity, 8) > 0) {
                    throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                }
                $seen[$id] = true;
                $selectedTotal = bcadd($selectedTotal, $slice, 8);
                $selectionPlan[] = ['quantity' => $slice, 'layer' => $layer];
            }
            if ($selectedLayers !== [] && bccomp($selectedTotal, $quantity, 8) !== 0) {
                throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
            }
            foreach ($selectionPlan as $selection) {
                $sliceRemaining = $selection['quantity'];
                foreach ($reservations as $reservation) {
                    $layer = $selection['layer'];
                    if ($layer !== null && ($layer->batch_lot !== $reservation->batch_lot || $layer->warehouse_location_id !== $reservation->warehouse_location_id)) {
                        continue;
                    }
                    if (bccomp($sliceRemaining, '0', 8) <= 0 || bccomp((string) $reservation->remaining_quantity, '0', 8) <= 0) {
                        continue;
                    }
                    if (bccomp($remaining, '0', 8) <= 0) {
                        break;
                    }

                    $consume = bccomp($sliceRemaining, (string) $reservation->remaining_quantity, 8) > 0
                        ? (string) $reservation->remaining_quantity
                        : $sliceRemaining;
                    $reservation->increment('consumed_quantity', $consume);
                    $reservation->refresh();

                    if (bccomp((string) $reservation->remaining_quantity, '0', 8) <= 0) {
                        $reservation->update(['status' => InventoryReservation::StatusConsumed]);
                    }

                    $consumed[] = ['reservation' => $reservation->refresh(), 'quantity' => $consume, 'selected_receipt_layer_id' => $layer?->id];
                    $remaining = bcsub($remaining, $consume, 8);
                    $sliceRemaining = bcsub($sliceRemaining, $consume, 8);
                }
                if (bccomp($sliceRemaining, '0', 8) > 0) {
                    throw new DomainException($selectedLayers === [] ? __('The material issue exceeds active production reservations.') : __('inventory_cost_policy.errors.layer_selection'));
                }
            }

            if (bccomp($remaining, '0', 8) > 0) {
                throw new DomainException(__('The material issue exceeds active production reservations.'));
            }

            return $consumed;
        });
    }

    public function releaseRun(int $productionRunId, string $reason): void
    {
        DB::transaction(function () use ($productionRunId, $reason): void {
            if (trim($reason) === '') {
                throw new DomainException(__('A reservation release reason is required.'));
            }

            $reservations = InventoryReservation::query()
                ->where('production_run_id', $productionRunId)
                ->whereNotNull('production_material_requirement_id')
                ->where('status', InventoryReservation::StatusActive)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $remaining = (string) $reservation->remaining_quantity;
                $reservation->update([
                    'released_quantity' => bcadd((string) $reservation->released_quantity, $remaining, 8),
                    'status' => InventoryReservation::StatusReleased,
                    'released_by' => auth()->id(),
                    'released_at' => now(),
                    'release_reason' => trim($reason),
                ]);
            }
        });
    }

    /** @return list<array{reservation_id: int, quantity: string}> */
    public function linkUntouchedLegacyForMaterialRequestLine(ProductionMaterialRequestLine $line, string $reason): array
    {
        return DB::transaction(function () use ($line, $reason): array {
            $line->loadMissing('request');
            if (trim($reason) === '' || $line->request->approved_at === null) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_invalid'));
            }

            ProductionMaterialRequirement::query()->lockForUpdate()->findOrFail($line->production_material_requirement_id);
            $expected = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8);
            $linked = InventoryReservation::query()
                ->where('production_material_request_line_id', $line->getKey())
                ->where('status', InventoryReservation::StatusActive)
                ->lockForUpdate()
                ->get()
                ->reduce(fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, $reservation->remaining_quantity, 8), '0.00000000');
            $missing = bcsub($expected, $linked, 8);
            if (bccomp($missing, '0', 8) === 0) {
                return [];
            }
            if (bccomp($missing, '0', 8) < 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            $otherLines = ProductionMaterialRequestLine::query()
                ->where('production_material_requirement_id', $line->production_material_requirement_id)
                ->whereKeyNot($line->getKey())
                ->whereColumn('reserved_quantity', '>', 'issued_quantity')
                ->lockForUpdate()
                ->get();
            foreach ($otherLines as $otherLine) {
                $otherExpected = bcsub((string) $otherLine->reserved_quantity, (string) $otherLine->issued_quantity, 8);
                $otherLinked = InventoryReservation::query()
                    ->where('production_material_request_line_id', $otherLine->getKey())
                    ->where('status', InventoryReservation::StatusActive)
                    ->lockForUpdate()
                    ->get()
                    ->reduce(fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, $reservation->remaining_quantity, 8), '0.00000000');
                if (bccomp($otherLinked, $otherExpected, 8) !== 0) {
                    throw new DomainException(__('production_execution.messages.material_request_reservation_repair_unsafe'));
                }
            }

            $request = $line->request;
            $unlinked = InventoryReservation::query()
                ->where('company_id', $request->company_id)
                ->where('financial_period_id', $request->financial_period_id)
                ->where('branch_id', $request->branch_id)
                ->where('production_order_id', $request->production_order_id)
                ->where('production_run_id', $request->production_run_id)
                ->where('production_material_requirement_id', $line->production_material_requirement_id)
                ->where('product_id', $line->product_id)
                ->whereNull('production_material_request_line_id')
                ->where('status', InventoryReservation::StatusActive)
                ->where('created_at', '>=', $request->created_at)
                ->where('created_at', '<=', $request->approved_at)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $unlinkedQuantity = $unlinked->reduce(
                fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, $reservation->remaining_quantity, 8),
                '0.00000000',
            );
            if ($unlinked->isEmpty()) {
                return [];
            }
            if (bccomp($unlinkedQuantity, $missing, 8) !== 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            $changes = [];
            foreach ($unlinked as $reservation) {
                if (bccomp((string) $reservation->consumed_quantity, '0', 8) !== 0
                    || bccomp((string) $reservation->released_quantity, '0', 8) !== 0
                    || InventoryDocumentLine::query()->where('inventory_reservation_id', $reservation->getKey())->exists()) {
                    throw new DomainException(__('production_execution.messages.material_request_reservation_repair_unsafe'));
                }
                $reservation->forceFill(['production_material_request_line_id' => $line->getKey()])->save();
                $changes[] = ['reservation_id' => (int) $reservation->getKey(), 'quantity' => (string) $reservation->remaining_quantity];
            }

            return $changes;
        });
    }

    /** @return list<array{new_reservation_id: int, quantity: string}> */
    public function rebuildMissingForMaterialRequestLine(ProductionMaterialRequestLine $line, string $reason): array
    {
        return DB::transaction(function () use ($line, $reason): array {
            $line->loadMissing('request');
            $request = $line->request;
            if (trim($reason) === '' || $request->approved_at === null) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_invalid'));
            }

            $requirement = ProductionMaterialRequirement::query()->lockForUpdate()->findOrFail($line->production_material_requirement_id);
            $reservations = InventoryReservation::query()
                ->where('production_material_requirement_id', $requirement->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($reservations->contains(fn (InventoryReservation $reservation): bool => (int) $reservation->company_id !== (int) $request->company_id
                || (int) $reservation->branch_id !== (int) $request->branch_id
                || (int) $reservation->production_run_id !== (int) $request->production_run_id
                || (int) $reservation->product_id !== (int) $line->product_id
                || ($reservation->status === InventoryReservation::StatusActive
                    && $reservation->production_material_request_line_id === null))) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_unsafe'));
            }

            $expected = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8);
            $linked = $reservations
                ->filter(fn (InventoryReservation $reservation): bool => (int) $reservation->production_material_request_line_id === (int) $line->getKey()
                    && $reservation->status === InventoryReservation::StatusActive)
                ->reduce(fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, (string) $reservation->remaining_quantity, 8), '0.00000000');
            $missing = bcsub($expected, $linked, 8);
            if (bccomp($missing, '0', 8) === 0) {
                return [];
            }
            if (bccomp($missing, '0', 8) < 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            $accounted = $reservations->reduce(fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, bcsub((string) $reservation->quantity, (string) $reservation->released_quantity, 8), 8),
                '0.00000000');
            if (bccomp(bcsub((string) $requirement->reserved_quantity, $accounted, 8), $missing, 8) !== 0
                || bccomp((string) $requirement->reserved_quantity, $missing, 8) < 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            $originalCounter = (string) $requirement->reserved_quantity;
            $requirement->forceFill(['reserved_quantity' => bcsub($originalCounter, $missing, 8)])->save();
            $replacement = $this->reserveForProduction(
                $requirement,
                (int) $request->branch_store_id,
                $missing,
                null,
                $request->request_type === 'additional',
                (int) $line->getKey(),
            );
            if (bccomp((string) $requirement->fresh()->reserved_quantity, $originalCounter, 8) !== 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            return [['new_reservation_id' => (int) $replacement->getKey(), 'quantity' => $missing]];
        });
    }

    /** @return list<array{old_reservation_id: int, new_reservation_id: int, quantity: string}> */
    public function relocateMisplacedForMaterialRequestLine(ProductionMaterialRequestLine $line, int $targetStoreId, string $reason): array
    {
        return DB::transaction(function () use ($line, $targetStoreId, $reason): array {
            $line->loadMissing('request');
            if (trim($reason) === '' || (int) $line->request->branch_store_id !== $targetStoreId) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_invalid'));
            }

            $requirement = ProductionMaterialRequirement::query()->lockForUpdate()->findOrFail($line->production_material_requirement_id);
            $reservations = InventoryReservation::query()
                ->where('production_material_request_line_id', $line->getKey())
                ->where('status', InventoryReservation::StatusActive)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $expectedRemaining = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8);
            $actualRemaining = $reservations->reduce(
                fn (string $sum, InventoryReservation $reservation): string => bcadd($sum, $reservation->remaining_quantity, 8),
                '0.00000000',
            );
            if (bccomp($expectedRemaining, '0', 8) < 0 || bccomp($actualRemaining, $expectedRemaining, 8) !== 0) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
            }

            $misplaced = $reservations->filter(fn (InventoryReservation $reservation): bool => (int) $reservation->branch_store_id !== $targetStoreId);
            if ($misplaced->isEmpty()) {
                return [];
            }

            $repaired = [];
            foreach ($misplaced as $reservation) {
                if ((int) $reservation->company_id !== (int) $line->request->company_id
                    || (int) $reservation->branch_id !== (int) $line->request->branch_id
                    || (int) $reservation->production_run_id !== (int) $line->request->production_run_id
                    || (int) $reservation->production_material_requirement_id !== (int) $requirement->getKey()
                    || (int) $reservation->product_id !== (int) $line->product_id
                    || bccomp((string) $reservation->consumed_quantity, '0', 8) !== 0
                    || bccomp((string) $reservation->released_quantity, '0', 8) !== 0
                    || InventoryDocumentLine::query()->where('inventory_reservation_id', $reservation->getKey())->exists()
                ) {
                    throw new DomainException(__('production_execution.messages.material_request_reservation_repair_unsafe'));
                }
            }

            foreach ($misplaced as $reservation) {
                $quantity = (string) $reservation->remaining_quantity;
                if (bccomp((string) $requirement->reserved_quantity, $quantity, 8) < 0) {
                    throw new DomainException(__('production_execution.messages.material_request_reservation_repair_mismatch'));
                }
                $reservation->forceFill([
                    'released_quantity' => $quantity,
                    'status' => InventoryReservation::StatusReleased,
                    'released_by' => auth()->id(),
                    'released_at' => now(),
                    'release_reason' => trim($reason),
                ])->save();
                $requirement->forceFill([
                    'reserved_quantity' => bcsub((string) $requirement->reserved_quantity, $quantity, 8),
                ])->save();
                $replacement = $this->reserveForProduction(
                    $requirement,
                    $targetStoreId,
                    $quantity,
                    null,
                    $line->request->request_type === 'additional',
                    (int) $line->getKey(),
                );
                $requirement->refresh();
                $repaired[] = [
                    'old_reservation_id' => (int) $reservation->getKey(),
                    'new_reservation_id' => (int) $replacement->getKey(),
                    'quantity' => $quantity,
                ];
            }

            return $repaired;
        });
    }

    public function releaseForMaterialRequestLine(ProductionMaterialRequestLine $line, string $reason): void
    {
        DB::transaction(function () use ($line, $reason): void {
            if (trim($reason) === '') {
                throw new DomainException(__('open_documents.validation.reason_required'));
            }

            $requirement = ProductionMaterialRequirement::query()->lockForUpdate()
                ->findOrFail($line->production_material_requirement_id);
            $reservations = InventoryReservation::query()
                ->where('production_material_request_line_id', $line->getKey())
                ->lockForUpdate()
                ->get();
            $reserved = '0.00000000';
            foreach ($reservations as $reservation) {
                if ((int) $reservation->production_material_requirement_id !== (int) $line->production_material_requirement_id
                    || (int) $reservation->production_run_id !== (int) $line->request->production_run_id
                    || bccomp((string) $reservation->consumed_quantity, '0', 8) !== 0) {
                    throw new DomainException(__('open_documents.messages.skipped_blocked', ['count' => 1]));
                }
                if ($reservation->status === InventoryReservation::StatusReleased
                    && bccomp((string) $reservation->remaining_quantity, '0', 8) === 0) {
                    continue;
                }
                if ($reservation->status !== InventoryReservation::StatusActive
                    || bccomp((string) $reservation->released_quantity, '0', 8) !== 0) {
                    throw new DomainException(__('open_documents.messages.skipped_blocked', ['count' => 1]));
                }
                $reserved = bcadd($reserved, (string) $reservation->quantity, 8);
            }

            if (bccomp($reserved, (string) $line->reserved_quantity, 8) !== 0
                || bccomp((string) $requirement->reserved_quantity, $reserved, 8) < 0) {
                throw new DomainException(__('open_documents.messages.skipped_blocked', ['count' => 1]));
            }

            foreach ($reservations as $reservation) {
                if ($reservation->status === InventoryReservation::StatusReleased) {
                    continue;
                }
                $reservation->forceFill([
                    'released_quantity' => $reservation->quantity,
                    'status' => InventoryReservation::StatusReleased,
                    'released_by' => auth()->id(),
                    'released_at' => now(),
                    'release_reason' => trim($reason),
                ])->save();
            }
            $requirement->forceFill([
                'reserved_quantity' => bcsub((string) $requirement->reserved_quantity, $reserved, 8),
            ])->save();
        }, 3);
    }
}
