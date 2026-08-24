<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryReportService
{
    /** @param array<string, mixed> $filters */
    public function balances(int $companyId, array $filters = []): Collection
    {
        return InventoryTransaction::query()
            ->selectRaw('company_id, branch_store_id, warehouse_location_id, product_id, stock_status, batch_lot')
            ->selectRaw('sum(quantity_in) as quantity_in, sum(quantity_out) as quantity_out, sum(quantity_in - quantity_out) as on_hand')
            ->selectRaw('sum((quantity_in - quantity_out) * coalesce(unit_cost, 0)) as inventory_value')
            ->where('company_id', $companyId)
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['warehouse_location_id'] ?? null, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['stock_status'] ?? null, fn ($query, $status) => $query->where('stock_status', $status))
            ->with(['product', 'branchStore', 'warehouseLocation'])
            ->groupBy(['company_id', 'branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('branch_store_id')
            ->orderBy('product_id')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function stockCard(int $companyId, int $productId, array $filters = []): Collection
    {
        return InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->with(['branchStore', 'warehouseLocation', 'productionRun'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function reservations(int $companyId, array $filters = []): Collection
    {
        return InventoryReservation::query()
            ->where('company_id', $companyId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->with(['product', 'branchStore', 'warehouseLocation', 'order', 'productionOrder', 'productionRun'])
            ->orderByDesc('id')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function movements(int $companyId, array $filters = []): Collection
    {
        return InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['transaction_type'] ?? null, fn ($query, $type) => $query->where('transaction_type', $type))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->with(['product', 'branchStore', 'warehouseLocation', 'productionRun'])
            ->latest('transaction_date')
            ->latest('id')
            ->limit(500)
            ->get();
    }
}
