<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryAvailabilityService
{
    /** @return array{on_hand: string, reserved: string, available: string, physical_on_hand: string} */
    public function forProduct(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?int $exceptOrderLineId = null,
        ?int $warehouseLocationId = null,
        string $stockStatus = InventoryTransaction::StatusAvailable,
    ): array {
        $positionQuery = InventoryTransaction::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId));
        $physicalOnHand = (clone $positionQuery)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as on_hand')->value('on_hand') ?? '0';
        $stock = $positionQuery->where('stock_status', $stockStatus)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as on_hand')->value('on_hand') ?? '0';

        $reservedQuery = InventoryReservation::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->where('stock_status', $stockStatus)
            ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
            ->where('status', InventoryReservation::StatusActive);
        if ($exceptOrderLineId !== null) {
            $reservedQuery->where('sales_order_line_id', '<>', $exceptOrderLineId);
        }
        $reserved = $reservedQuery->selectRaw('coalesce(sum(quantity - consumed_quantity - released_quantity), 0) as reserved')->value('reserved') ?? '0';

        return [
            'on_hand' => bcadd((string) $stock, '0', 8),
            'reserved' => bcadd((string) $reserved, '0', 8),
            'available' => bcsub((string) $stock, (string) $reserved, 8),
            'physical_on_hand' => bcadd((string) $physicalOnHand, '0', 8),
        ];
    }

    /** @return array<string, string> */
    public function statusPosition(int $companyId, int $branchStoreId, int $productId): array
    {
        return InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->groupBy('stock_status')
            ->selectRaw('stock_status, coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->pluck('quantity', 'stock_status')
            ->map(fn (mixed $quantity): string => bcadd((string) $quantity, '0', 8))
            ->all();
    }

    public function averageCost(int $companyId, int $branchStoreId, int $productId): string
    {
        $totals = InventoryTransaction::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->selectRaw('coalesce(sum(quantity_in), 0) as quantity, coalesce(sum(case when quantity_in > 0 then total_cost else 0 end), 0) as cost')
            ->first();
        if (! $totals || bccomp((string) $totals->quantity, '0', 8) <= 0) {
            return '0.00000000';
        }

        return bcdiv((string) $totals->cost, (string) $totals->quantity, 8);
    }
}
