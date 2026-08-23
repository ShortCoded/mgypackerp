<?php

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryAvailabilityService
{
    /** @return array{on_hand: string, reserved: string, available: string} */
    public function forProduct(int $companyId, int $branchStoreId, int $productId, ?int $exceptOrderLineId = null): array
    {
        $stock = InventoryTransaction::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as on_hand')->value('on_hand') ?? '0';

        $reservedQuery = InventoryReservation::query()
            ->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)->where('product_id', $productId)
            ->where('status', InventoryReservation::StatusActive);
        if ($exceptOrderLineId !== null) {
            $reservedQuery->where('sales_order_line_id', '<>', $exceptOrderLineId);
        }
        $reserved = $reservedQuery->selectRaw('coalesce(sum(quantity - consumed_quantity - released_quantity), 0) as reserved')->value('reserved') ?? '0';

        return ['on_hand' => (string) $stock, 'reserved' => (string) $reserved, 'available' => bcsub((string) $stock, (string) $reserved, 8)];
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
