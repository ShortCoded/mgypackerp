<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryValuationService
{
    public const Method = 'moving_average';

    public function movingAverageUnitCost(
        int $companyId,
        int $branchStoreId,
        int $productId,
        ?string $stockStatus = null,
        ?int $warehouseLocationId = null,
        ?string $batchLot = null,
        ?int $productionRunId = null,
        mixed $asOfDate = null,
    ): string {
        $totals = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->when($stockStatus !== null, fn (Builder $query) => $query->where('stock_status', $stockStatus))
            ->when($warehouseLocationId !== null, fn (Builder $query) => $query->where('warehouse_location_id', $warehouseLocationId))
            ->when($batchLot !== null, fn (Builder $query) => $query->where('batch_lot', $batchLot))
            ->when($productionRunId !== null, fn (Builder $query) => $query->where('production_run_id', $productionRunId))
            ->when($asOfDate !== null, fn (Builder $query) => $query->whereDate('transaction_date', '<=', $asOfDate))
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->selectRaw('coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')
            ->first();

        if ($totals === null
            || bccomp((string) $totals->quantity, '0', 8) <= 0
            || bccomp((string) $totals->value, '0', 8) <= 0) {
            return '0.00000000';
        }

        return bcdiv((string) $totals->value, (string) $totals->quantity, 8);
    }
}
