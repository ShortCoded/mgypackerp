<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionMaterialRequirement;

class InventoryReservationService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function reserveForProduction(
        ProductionMaterialRequirement $requirement,
        int $branchStoreId,
        ?string $quantity = null,
        ?int $warehouseLocationId = null,
        bool $allowBeyondRequirement = false,
        ?int $materialRequestLineId = null,
    ): InventoryReservation {
        return DB::transaction(function () use ($requirement, $branchStoreId, $quantity, $warehouseLocationId, $allowBeyondRequirement, $materialRequestLineId): InventoryReservation {
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

    /** @return array{warehouse_location_id: int|null, batch_lot: string|null}|null */
    private function availableStockPosition(
        int $companyId,
        int $branchStoreId,
        int $productId,
        string $quantity,
        ?int $warehouseLocationId,
    ): ?array {
        $positions = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
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

    /** @return list<array{reservation: InventoryReservation, quantity: string}> */
    public function consumeForRequirement(
        ProductionMaterialRequirement $requirement,
        string $quantity,
        ?int $branchStoreId = null,
        ?int $materialRequestLineId = null,
        bool $unlinkedOnly = false,
    ): array {
        return DB::transaction(function () use ($requirement, $quantity, $branchStoreId, $materialRequestLineId, $unlinkedOnly): array {
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

            foreach ($reservations as $reservation) {
                if (bccomp($remaining, '0', 8) <= 0) {
                    break;
                }

                $consume = bccomp($remaining, (string) $reservation->remaining_quantity, 8) > 0
                    ? (string) $reservation->remaining_quantity
                    : $remaining;
                $reservation->increment('consumed_quantity', $consume);
                $reservation->refresh();

                if (bccomp((string) $reservation->remaining_quantity, '0', 8) <= 0) {
                    $reservation->update(['status' => InventoryReservation::StatusConsumed]);
                }

                $consumed[] = ['reservation' => $reservation->refresh(), 'quantity' => $consume];
                $remaining = bcsub($remaining, $consume, 8);
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
