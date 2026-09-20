<?php

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\StockCountLine;
use Modules\Sales\Models\PriceList;

class InventoryReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, string>|Collection|SupportCollection>
     */
    public function report(int $companyId, int $financialPeriodId, int $branchId, array $filters = []): array
    {
        $contextFilters = [
            ...$filters,
            'financial_period_id' => $financialPeriodId,
            'branch_id' => $branchId,
        ];
        $balances = $this->balances($companyId, $contextFilters);
        $reservations = $this->reservations($companyId, [...$contextFilters, 'status' => InventoryReservation::StatusActive]);

        $movements = $this->movements($companyId, $contextFilters);
        $agingLayers = $this->agingLayers($companyId, $contextFilters);
        $expiryLayers = $this->expiryLayers($companyId, $contextFilters);

        return [
            'balances' => $balances,
            'reservations' => $reservations,
            'movements' => $movements,
            'agingLayers' => $agingLayers,
            'expiryLayers' => $expiryLayers,
            'qualityBalances' => $balances->whereIn('stock_status', [
                InventoryTransaction::StatusQcHold,
                InventoryTransaction::StatusQuarantine,
                InventoryTransaction::StatusRework,
                InventoryTransaction::StatusRejected,
            ])->values(),
            'damageAndScrap' => $this->damageAndScrap($companyId, $contextFilters),
            'stockCountVariances' => $this->stockCountVariances($companyId, $contextFilters),
            'reorder' => $this->lowStockRows($companyId, $branchId, $balances, $reservations, $contextFilters),
            'reportTotals' => [
                'on_hand' => $this->decimalTotal($balances, 'on_hand'),
                'inventory_value' => $this->decimalTotal($balances, 'inventory_value'),
                'unvalued_receipt_quantity' => $this->decimalTotal($balances, 'unvalued_receipt_quantity'),
                'quantity_in' => $this->decimalTotal($movements, 'quantity_in'),
                'quantity_out' => $this->decimalTotal($movements, 'quantity_out'),
                'aging_quantity' => $this->decimalTotal($agingLayers, 'remaining_quantity'),
                'aging_value' => $agingLayers->reduce(
                    fn (string $total, InventoryReceiptLayer $layer): string => bcadd($total, bcmul((string) $layer->remaining_quantity, (string) ($layer->unit_cost ?? 0), 8), 8),
                    '0.00000000',
                ),
                'expiry_quantity' => $this->decimalTotal($expiryLayers, 'remaining_quantity'),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function agingLayers(int $companyId, array $filters = []): Collection
    {
        $asOf = CarbonImmutable::parse($filters['as_of'] ?? $filters['to'] ?? today())->startOfDay();

        return InventoryReceiptLayer::query()
            ->where('company_id', $companyId)
            ->where('remaining_quantity', '>', 0)
            ->whereDate('original_receipt_date', '<=', $asOf)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['warehouse_location_id'] ?? null, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['classification'] ?? null, fn ($query, $classification) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('item_classification', $classification)))
            ->when($filters['stock_status'] ?? null, fn ($query, $status) => $query->where('stock_status', $status))
            ->when($filters['batch_lot'] ?? null, fn ($query, $batch) => $query->where('batch_lot', $batch))
            ->with(['product', 'branchStore', 'warehouseLocation'])
            ->orderBy('original_receipt_date')
            ->orderBy('id')
            ->get()
            ->each(function (InventoryReceiptLayer $layer) use ($asOf): void {
                $ageDays = $layer->original_receipt_date->diffInDays($asOf);
                $layer->setAttribute('age_days', $ageDays);
                $layer->setAttribute('age_bucket', $this->ageBucket($ageDays));
                $layer->setAttribute('remaining_value', bcmul((string) $layer->remaining_quantity, (string) ($layer->unit_cost ?? 0), 8));
            });
    }

    /** @param array<string, mixed> $filters */
    public function expiryLayers(int $companyId, array $filters = []): Collection
    {
        $asOf = CarbonImmutable::parse($filters['as_of'] ?? $filters['to'] ?? today())->startOfDay();
        $withinDays = (int) ($filters['expiry_within_days'] ?? 90);
        $cutoff = $asOf->copy()->addDays($withinDays);

        return InventoryReceiptLayer::query()
            ->where('company_id', $companyId)
            ->where('remaining_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $cutoff)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['warehouse_location_id'] ?? null, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['classification'] ?? null, fn ($query, $classification) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('item_classification', $classification)))
            ->when($filters['stock_status'] ?? null, fn ($query, $status) => $query->where('stock_status', $status))
            ->when($filters['batch_lot'] ?? null, fn ($query, $batch) => $query->where('batch_lot', $batch))
            ->with(['product', 'branchStore', 'warehouseLocation'])
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->each(function (InventoryReceiptLayer $layer) use ($asOf): void {
                $daysToExpiry = $asOf->diffInDays($layer->expiry_date, false);
                $layer->setAttribute('days_to_expiry', $daysToExpiry);
                $layer->setAttribute('expiry_state', $daysToExpiry < 0 ? 'expired' : 'expiring');
            });
    }

    private function ageBucket(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 30 => '0–30',
            $ageDays <= 60 => '31–60',
            $ageDays <= 90 => '61–90',
            $ageDays <= 180 => '91–180',
            $ageDays <= 365 => '181–365',
            default => '365+',
        };
    }

    /**
     * @param  list<int>  $allowedBranchIds
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection, totals: array<string, string|int>, reservations_are_hall_scoped: bool}
     */
    public function stockBalanceInquiry(int $companyId, array $allowedBranchIds, array $filters = []): array
    {
        if ($allowedBranchIds === []) {
            return [
                'rows' => new Collection,
                'totals' => $this->stockBalanceTotals(new Collection, '0.00000000'),
                'reservations_are_hall_scoped' => true,
            ];
        }

        $rows = InventoryTransaction::query()
            ->selectRaw('company_id, branch_id, branch_store_id, branch_hall_id, warehouse_location_id, product_id')
            ->selectRaw('sum(quantity_in - quantity_out) as on_hand')
            ->selectRaw('sum(case when stock_status = ? then quantity_in - quantity_out else 0 end) as available_stock', [InventoryTransaction::StatusAvailable])
            ->selectRaw('sum(case when stock_status <> ? then quantity_in - quantity_out else 0 end) as held_stock', [InventoryTransaction::StatusAvailable])
            ->selectRaw('sum(case
                when unit_cost is not null and total_cost is not null then case when quantity_in > 0 then total_cost else -total_cost end
                else 0 end) as inventory_value')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then quantity_in - quantity_out else 0 end) as unvalued_quantity')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then 1 else 0 end) as unvalued_row_count')
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $allowedBranchIds)
            ->whereDate('transaction_date', '<=', $filters['as_of'] ?? today()->toDateString())
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['branch_store_id'] ?? null, fn (Builder $query, int $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['branch_hall_id'] ?? null, fn (Builder $query, int $hallId) => $query->where('branch_hall_id', $hallId))
            ->when($filters['warehouse_location_id'] ?? null, fn (Builder $query, int $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => $query->where('stock_status', $status))
            ->whereHas('product', fn (Builder $query) => $this->applyStockBalanceProductFilters($query, $companyId, $filters))
            ->with([
                'branch:id,doc_num,name,type',
                'branchStore:id,branch_id,name,classification,deleted_at',
                'branchHall:id,branch_id,name',
                'warehouseLocation:id,branch_store_id,code,name,zone_code,deleted_at',
                'product' => fn ($query) => $query->withTrashed()->with(['unit', 'category', 'group', 'itemModel', 'size', 'color', 'decal', 'originCountry']),
            ])
            ->groupBy(['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id', 'product_id'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('branch_id')
            ->orderBy('branch_store_id')
            ->orderBy('product_id')
            ->get();

        $reservationsAreHallScoped = empty($filters['branch_hall_id']);
        $reservationRows = $reservationsAreHallScoped
            ? InventoryReservation::query()
                ->selectRaw('branch_store_id, warehouse_location_id, product_id, stock_status, batch_lot')
                ->selectRaw('sum(quantity - consumed_quantity - released_quantity) as reserved_quantity')
                ->where('company_id', $companyId)
                ->whereIn('branch_id', $allowedBranchIds)
                ->where('status', InventoryReservation::StatusActive)
                ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
                ->when($filters['branch_store_id'] ?? null, fn (Builder $query, int $storeId) => $query->where('branch_store_id', $storeId))
                ->when($filters['warehouse_location_id'] ?? null, fn (Builder $query, int $locationId) => $query->where('warehouse_location_id', $locationId))
                ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => $query->where('stock_status', $status))
                ->whereHas('product', fn (Builder $query) => $this->applyStockBalanceProductFilters($query, $companyId, $filters))
                ->groupBy(['branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot'])
                ->havingRaw('sum(quantity - consumed_quantity - released_quantity) > 0')
                ->get()
            : new Collection;

        $reservedByPosition = $reservationRows->groupBy(fn ($row): string => $this->stockPositionKey(
            (int) $row->branch_store_id,
            $row->warehouse_location_id ? (int) $row->warehouse_location_id : null,
            (int) $row->product_id,
        ))->map(fn (SupportCollection $positionRows): string => $this->decimalTotal($positionRows, 'reserved_quantity'));

        $rows->each(function (InventoryTransaction $row) use ($reservedByPosition): void {
            $key = $this->stockPositionKey(
                (int) $row->branch_store_id,
                $row->warehouse_location_id ? (int) $row->warehouse_location_id : null,
                (int) $row->product_id,
            );
            $reserved = $row->branch_hall_id === null
                ? (string) ($reservedByPosition->get($key) ?? '0.00000000')
                : '0.00000000';
            $available = bcsub((string) $row->available_stock, $reserved, 8);

            $row->setAttribute('reserved', $reserved);
            $row->setAttribute('available', bccomp($available, '0', 8) < 0 ? '0.00000000' : $available);
        });

        $rows = $this->filterStockBalanceQuantityState($rows, $filters['quantity_state'] ?? null);
        $visiblePositionKeys = $rows->map(fn (InventoryTransaction $row): string => $this->stockPositionKey(
            (int) $row->branch_store_id,
            $row->warehouse_location_id ? (int) $row->warehouse_location_id : null,
            (int) $row->product_id,
        ))->unique();
        $reservedTotal = $reservationsAreHallScoped
            ? $this->decimalTotal($reservationRows->filter(fn ($row): bool => $visiblePositionKeys->contains($this->stockPositionKey(
                (int) $row->branch_store_id,
                $row->warehouse_location_id ? (int) $row->warehouse_location_id : null,
                (int) $row->product_id,
            ))), 'reserved_quantity')
            : '0.00000000';

        return [
            'rows' => $rows->values(),
            'totals' => $this->stockBalanceTotals($rows, $reservedTotal),
            'reservations_are_hall_scoped' => $reservationsAreHallScoped,
        ];
    }

    /**
     * @param  list<int>  $allowedBranchIds
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection, totals: array<string, string|int|bool>}
     */
    public function bookValuation(
        int $companyId,
        array $allowedBranchIds,
        array $filters = [],
    ): array {
        if ($allowedBranchIds === []) {
            return [
                'rows' => new Collection,
                'totals' => $this->bookValuationTotals(new Collection),
            ];
        }

        $rows = InventoryTransaction::query()
            ->selectRaw('company_id, branch_id, branch_store_id, branch_hall_id, warehouse_location_id, product_id')
            ->selectRaw('sum(quantity_in - quantity_out) as on_hand')
            ->selectRaw('sum(case
                when unit_cost is not null and total_cost is not null then case when quantity_in > 0 then total_cost else -total_cost end
                else 0 end) as book_value')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then quantity_in - quantity_out else 0 end) as unvalued_quantity')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then 1 else 0 end) as unvalued_row_count')
            ->selectRaw('sum(case when unit_cost is not null and total_cost is not null and total_cost = 0 then 1 else 0 end) as zero_cost_row_count')
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $allowedBranchIds)
            ->whereDate('transaction_date', '<=', $filters['as_of'] ?? today()->toDateString())
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['branch_store_id'] ?? null, fn (Builder $query, int $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['branch_hall_id'] ?? null, fn (Builder $query, int $hallId) => $query->where('branch_hall_id', $hallId))
            ->when($filters['warehouse_location_id'] ?? null, fn (Builder $query, int $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => $query->where('stock_status', $status))
            ->whereHas('product', fn (Builder $query) => $this->applyStockBalanceProductFilters($query, $companyId, $filters))
            ->with([
                'branch:id,doc_num,name,type',
                'branchStore:id,branch_id,name,classification,deleted_at',
                'branchHall:id,branch_id,name',
                'warehouseLocation:id,branch_store_id,code,name,zone_code,deleted_at',
                'product' => fn ($query) => $query->withTrashed()->with(['unit', 'category', 'group']),
            ])
            ->groupBy(['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id', 'product_id'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('branch_id')
            ->orderBy('branch_store_id')
            ->orderBy('product_id')
            ->get()
            ->each(function (InventoryTransaction $row): void {
                $quantity = bcadd((string) $row->on_hand, '0', 8);
                $bookValue = bcadd((string) $row->book_value, '0', 8);
                $unvaluedQuantity = bcadd((string) $row->unvalued_quantity, '0', 8);
                $hasUnvalued = bccomp($unvaluedQuantity, '0', 8) !== 0;
                $unvaluedRows = $hasUnvalued ? (int) $row->unvalued_row_count : 0;
                $isZeroCost = ! $hasUnvalued && bccomp($bookValue, '0', 8) === 0;

                $row->setAttribute('on_hand', $quantity);
                $row->setAttribute('book_value', $bookValue);
                $row->setAttribute('unvalued_quantity', $unvaluedQuantity);
                $row->setAttribute('unvalued_row_count', $unvaluedRows);
                $row->setAttribute('zero_cost_row_count', (int) $row->zero_cost_row_count);
                $row->setAttribute('book_unit_cost', $hasUnvalued ? null : bcdiv($bookValue, $quantity, 8));
                $row->setAttribute('valuation_status', $hasUnvalued ? 'unvalued' : ($isZeroCost ? 'zero_cost' : 'valued'));
                $row->setAttribute('is_negative', bccomp($quantity, '0', 8) < 0);
            });

        $rows = match ($filters['quantity_state'] ?? null) {
            'positive' => $rows->filter(fn (InventoryTransaction $row): bool => ! $row->is_negative),
            'negative' => $rows->filter(fn (InventoryTransaction $row): bool => $row->is_negative),
            default => $rows,
        };

        return [
            'rows' => $rows->values(),
            'totals' => $this->bookValuationTotals($rows),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyStockBalanceProductFilters(Builder $query, int $companyId, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->where('company_id', $companyId)
            ->whereIn('item_classification', Product::stockableItemClassifications())
            ->when($filters['product_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->where('doc_num', $docNum))
            ->when($filters['item_classification'] ?? null, fn (Builder $productQuery, string $classification) => $productQuery->where('item_classification', $classification))
            ->when($search !== '', function (Builder $productQuery) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $productQuery->where(function (Builder $searchQuery) use ($term): void {
                    $searchQuery->where('doc_num', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('barcode', 'like', $term);
                });
            })
            ->when($filters['item_category_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('category', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_group_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('group', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_model_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('itemModel', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_size_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('size', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_color_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('color', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_decal_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('decal', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_unit_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('unit', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)))
            ->when($filters['item_origin_country_doc_num'] ?? null, fn (Builder $productQuery, string $docNum) => $productQuery->whereHas('originCountry', fn (Builder $lookupQuery) => $lookupQuery->where('doc_num', $docNum)));
    }

    private function stockPositionKey(int $storeId, ?int $locationId, int $productId): string
    {
        return implode('|', [$storeId, $locationId ?? 0, $productId]);
    }

    private function filterStockBalanceQuantityState(Collection $rows, mixed $quantityState): Collection
    {
        return match ($quantityState) {
            'positive' => $rows->filter(fn ($row): bool => bccomp((string) $row->on_hand, '0', 8) > 0),
            'negative' => $rows->filter(fn ($row): bool => bccomp((string) $row->on_hand, '0', 8) < 0),
            'held' => $rows->filter(fn ($row): bool => bccomp((string) $row->held_stock, '0', 8) > 0),
            'below_reorder' => $rows->filter(fn ($row): bool => bccomp((string) $row->available, (string) ($row->product?->reorder_point ?? 0), 8) < 0),
            default => $rows,
        };
    }

    /** @return array<string, string|int> */
    private function stockBalanceTotals(Collection $rows, string $reservedTotal): array
    {
        $availableStock = $this->decimalTotal($rows, 'available_stock');
        $netAvailable = bcsub($availableStock, $reservedTotal, 8);

        return [
            'positions' => $rows->count(),
            'products' => $rows->pluck('product_id')->unique()->count(),
            'on_hand' => $this->decimalTotal($rows, 'on_hand'),
            'available_stock' => $availableStock,
            'reserved' => $reservedTotal,
            'available' => bccomp($netAvailable, '0', 8) < 0 ? '0.00000000' : $netAvailable,
            'held_stock' => $this->decimalTotal($rows, 'held_stock'),
            'inventory_value' => $this->decimalTotal($rows, 'inventory_value'),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function balances(int $companyId, array $filters = []): Collection
    {
        $rows = InventoryTransaction::query()
            ->selectRaw('company_id, branch_store_id, warehouse_location_id, product_id, stock_status, batch_lot')
            ->selectRaw('sum(quantity_in) as quantity_in, sum(quantity_out) as quantity_out, sum(quantity_in - quantity_out) as on_hand')
            ->selectRaw('sum(case
                when unit_cost is not null and total_cost is not null then case when quantity_in > 0 then total_cost else -total_cost end
                else 0 end) as inventory_value')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then quantity_in - quantity_out else 0 end) as unvalued_receipt_quantity')
            ->selectRaw('sum(case when unit_cost is null or total_cost is null then 1 else 0 end) as unvalued_row_count')
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHas('branchStore', fn ($storeQuery) => $storeQuery->where('branch_id', $branchId)))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['warehouse_location_id'] ?? null, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['stock_status'] ?? null, fn ($query, $status) => $query->where('stock_status', $status))
            ->when($filters['as_of'] ?? $filters['to'] ?? null, fn ($query, $date) => $query->whereDate('transaction_date', '<=', $date))
            ->with(['product', 'branchStore', 'warehouseLocation'])
            ->groupBy(['company_id', 'branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('branch_store_id')
            ->orderBy('product_id')
            ->get();

        return $rows;
    }

    /** @return array<string, string|int|bool> */
    private function bookValuationTotals(Collection $rows): array
    {
        $unvaluedRows = (int) $rows->sum('unvalued_row_count');

        return [
            'positions' => $rows->count(),
            'products' => $rows->pluck('product_id')->unique()->count(),
            'quantity' => $this->decimalTotal($rows, 'on_hand'),
            'book_value' => $this->decimalTotal($rows, 'book_value'),
            'unvalued_quantity' => $this->decimalTotal($rows, 'unvalued_quantity'),
            'unvalued_rows' => $unvaluedRows,
            'zero_cost_positions' => $rows->where('valuation_status', 'zero_cost')->count(),
            'negative_positions' => $rows->where('is_negative', true)->count(),
            'has_unvalued' => $unvaluedRows > 0,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function stockCard(int $companyId, int $productId, array $filters = []): Collection
    {
        $rows = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHas('branchStore', fn ($storeQuery) => $storeQuery->where('branch_id', $branchId)))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->with(['branchStore', 'warehouseLocation', 'productionRun'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $this->hydrateMovementSources($rows);

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function reservations(int $companyId, array $filters = []): Collection
    {
        return InventoryReservation::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHas('branchStore', fn ($storeQuery) => $storeQuery->where('branch_id', $branchId)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->with(['product', 'branchStore', 'warehouseLocation', 'order', 'productionOrder', 'productionRun'])
            ->orderByDesc('id')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function movements(int $companyId, array $filters = []): Collection
    {
        $rows = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHas('branchStore', fn ($storeQuery) => $storeQuery->where('branch_id', $branchId)))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($filters['source_doc_num'] ?? null, fn ($query, $number) => $query->where('source_doc_num', $number))
            ->when($filters['transaction_type'] ?? null, fn ($query, $type) => $query->where('transaction_type', $type))
            ->when($filters['transaction_types'] ?? null, fn ($query, $types) => $query->whereIn('transaction_type', $types))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->with(['product', 'branchStore', 'warehouseLocation', 'productionRun'])
            ->latest('transaction_date')
            ->latest('id')
            ->limit(500)
            ->get();

        $this->hydrateMovementSources($rows);

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    public function damageAndScrap(int $companyId, array $filters = []): Collection
    {
        return $this->movements($companyId, [
            ...$filters,
            'transaction_types' => [InventoryDocument::TypeDamage, InventoryDocument::TypeScrap],
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function stockCountVariances(int $companyId, array $filters = []): Collection
    {
        return StockCountLine::query()
            ->whereHas('stockCount', function ($query) use ($companyId, $filters): void {
                $query->where('company_id', $companyId)
                    ->when($filters['financial_period_id'] ?? null, fn ($countQuery, $periodId) => $countQuery->where('financial_period_id', $periodId))
                    ->when($filters['branch_id'] ?? null, fn ($countQuery, $branchId) => $countQuery->where('branch_id', $branchId))
                    ->when($filters['branch_store_id'] ?? null, fn ($countQuery, $storeId) => $countQuery->where('branch_store_id', $storeId))
                    ->when($filters['from'] ?? null, fn ($countQuery, $from) => $countQuery->whereDate('count_date', '>=', $from))
                    ->when($filters['to'] ?? null, fn ($countQuery, $to) => $countQuery->whereDate('count_date', '<=', $to));
            })
            ->whereRaw('variance_quantity <> 0')
            ->with(['stockCount.branchStore', 'product'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function lowStockRows(
        int $companyId,
        int $branchId,
        Collection $balances,
        Collection $reservations,
        array $filters,
    ): SupportCollection {
        $stores = BranchStore::query()
            ->where('branch_id', $branchId)
            ->when($filters['branch_store_id'] ?? null, fn ($query, $storeId) => $query->whereKey($storeId))
            ->orderBy('name')
            ->get()
            ->keyBy('id');
        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('reorder_point')
            ->where('reorder_point', '>', 0)
            ->when($filters['product_id'] ?? null, fn ($query, $productId) => $query->whereKey($productId))
            ->with('unit')
            ->orderBy('doc_num')
            ->get()
            ->keyBy('id');

        $physical = $balances
            ->groupBy(fn ($row): string => $row->branch_store_id.'|'.$row->product_id)
            ->map(fn (SupportCollection $rows): string => $rows->reduce(
                fn (string $total, $row): string => bcadd($total, (string) $row->on_hand, 8),
                '0.00000000',
            ));
        $reserved = $reservations
            ->groupBy(fn (InventoryReservation $row): string => $row->branch_store_id.'|'.$row->product_id)
            ->map(fn (SupportCollection $rows): string => $rows->reduce(
                fn (string $total, InventoryReservation $row): string => bcadd($total, $row->remaining_quantity, 8),
                '0.00000000',
            ));
        $productionDemand = $reservations
            ->whereNotNull('production_order_id')
            ->groupBy(fn (InventoryReservation $row): string => $row->branch_store_id.'|'.$row->product_id)
            ->map(fn (SupportCollection $rows): string => $rows->reduce(
                fn (string $total, InventoryReservation $row): string => bcadd($total, $row->remaining_quantity, 8),
                '0.00000000',
            ));

        $availableStock = $balances
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->groupBy(fn ($row): string => $row->branch_store_id.'|'.$row->product_id)
            ->map(fn (SupportCollection $rows): string => $rows->reduce(
                fn (string $total, $row): string => bcadd($total, (string) $row->on_hand, 8),
                '0.00000000',
            ));

        return $stores->flatMap(function (BranchStore $store) use ($products, $physical, $availableStock, $reserved, $productionDemand): SupportCollection {
            return $products
                ->filter(fn (Product $product): bool => $store->classification === 'general' || $store->classification === $product->item_classification)
                ->map(function (Product $product) use ($store, $physical, $availableStock, $reserved, $productionDemand): object {
                    $key = $store->getKey().'|'.$product->getKey();
                    $physicalQuantity = (string) ($physical->get($key) ?? '0.00000000');
                    $availableStockQuantity = (string) ($availableStock->get($key) ?? '0.00000000');
                    $reservedQuantity = (string) ($reserved->get($key) ?? '0.00000000');
                    $availableQuantity = bcsub($availableStockQuantity, $reservedQuantity, 8);
                    $availableQuantity = bccomp($availableQuantity, '0', 8) < 0 ? '0.00000000' : $availableQuantity;
                    $shortage = bcsub((string) $product->reorder_point, $availableQuantity, 8);

                    return (object) [
                        'product' => $product,
                        'branchStore' => $store,
                        'physical' => $physicalQuantity,
                        'on_hand' => $physicalQuantity,
                        'reserved' => $reservedQuantity,
                        'available' => $availableQuantity,
                        'reorder_point' => (string) $product->reorder_point,
                        'shortage' => bccomp($shortage, '0', 8) > 0 ? $shortage : '0.00000000',
                        'production_demand' => (string) ($productionDemand->get($key) ?? '0.00000000'),
                    ];
                });
        })->filter(fn (object $row): bool => bccomp($row->shortage, '0', 8) > 0)->values();
    }

    /**
     * @param  list<int>  $allowedBranchIds
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection, totals: array<string, string>}
     */
    public function salesValuation(
        int $companyId,
        array $allowedBranchIds,
        array $filters = [],
    ): array {
        if ($allowedBranchIds === []) {
            return [
                'rows' => new Collection,
                'totals' => ['position_count' => 0, 'product_count' => 0, 'unpriced_product_count' => 0, 'quantity' => '0.00000000', 'sales_value' => '0.00000000', 'unpriced_quantity' => '0.00000000'],
            ];
        }

        $asOf = CarbonImmutable::parse($filters['as_of'] ?? today())->startOfDay();
        $priceListId = (int) ($filters['price_list_id'] ?? 0);

        $positions = InventoryTransaction::query()
            ->selectRaw('company_id, branch_id, branch_store_id, branch_hall_id, warehouse_location_id, product_id')
            ->selectRaw('sum(quantity_in - quantity_out) as on_hand')
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $allowedBranchIds)
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['branch_store_id'] ?? null, fn (Builder $query, int $storeId) => $query->where('branch_store_id', $storeId))
            ->when($filters['branch_hall_id'] ?? null, fn (Builder $query, int $hallId) => $query->where('branch_hall_id', $hallId))
            ->when($filters['warehouse_location_id'] ?? null, fn (Builder $query, int $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($filters['stock_status'] ?? null, fn (Builder $query, string $status) => $query->where('stock_status', $status))
            ->whereHas('product', fn (Builder $query) => $this->applyStockBalanceProductFilters($query, $companyId, $filters))
            ->with([
                'branch:id,doc_num,name,type',
                'branchStore:id,branch_id,name,classification,deleted_at',
                'branchHall:id,branch_id,name',
                'warehouseLocation:id,branch_store_id,code,name,zone_code,deleted_at',
                'product' => fn ($query) => $query->withTrashed()->with(['unit']),
            ])
            ->groupBy(['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id', 'product_id'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('branch_id')
            ->orderBy('branch_store_id')
            ->orderBy('product_id')
            ->get();

        $priceList = PriceList::query()
            ->forCompany($companyId)
            ->with('currency')
            ->findOrFail($priceListId);
        $priceListLines = $priceList->lines()->pluck('unit_price', 'product_id')->all();
        $priceListCurrencyCode = $priceList->currency?->code;

        $rows = $positions->map(function (InventoryTransaction $row) use ($priceListLines, $priceList, $priceListCurrencyCode): object {
            $quantity = bcadd((string) $row->on_hand, '0', 8);
            $unitSellingPrice = isset($priceListLines[$row->product_id]) ? (string) $priceListLines[$row->product_id] : null;
            $salesValue = $unitSellingPrice !== null ? bcmul($quantity, $unitSellingPrice, 8) : null;
            $isUnpriced = $unitSellingPrice === null;

            return (object) [
                'product_id' => (int) $row->product_id,
                'branch' => $row->branch,
                'branchStore' => $row->branchStore,
                'branchHall' => $row->branchHall,
                'warehouseLocation' => $row->warehouseLocation,
                'product' => $row->product,
                'on_hand' => $quantity,
                'unit_selling_price' => $unitSellingPrice,
                'sales_value' => $salesValue,
                'price_list' => $priceList,
                'price_status' => $isUnpriced ? 'unpriced' : 'priced',
                'currency_code' => $priceListCurrencyCode,
            ];
        });

        $rows = match ($filters['quantity_state'] ?? null) {
            'positive' => $rows->filter(fn (object $row): bool => bccomp((string) $row->on_hand, '0', 8) > 0),
            'negative' => $rows->filter(fn (object $row): bool => bccomp((string) $row->on_hand, '0', 8) < 0),
            default => $rows,
        };

        $totals = [
            'position_count' => $rows->count(),
            'product_count' => $rows->pluck('product_id')->unique()->count(),
            'unpriced_product_count' => $rows->where('price_status', 'unpriced')->pluck('product_id')->unique()->count(),
            'quantity' => $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->on_hand, 8), '0.00000000'),
            'sales_value' => $rows->reduce(fn (string $total, object $row): string => $row->sales_value !== null ? bcadd($total, (string) $row->sales_value, 8) : $total, '0.00000000'),
            'unpriced_quantity' => $rows->where('price_status', 'unpriced')->reduce(
                fn (string $total, object $row): string => bcadd($total, (string) $row->on_hand, 8),
                '0.00000000',
            ),
        ];

        return [
            'rows' => $rows->values(),
            'totals' => $totals,
            'priceList' => $priceList,
            'priceListCurrencyCode' => $priceListCurrencyCode,
            'asOf' => $asOf->toDateString(),
        ];
    }

    private function decimalTotal(SupportCollection $rows, string $attribute): string
    {
        return $rows->reduce(
            fn (string $total, $row): string => bcadd($total, (string) ($row->{$attribute} ?? 0), 8),
            '0.00000000',
        );
    }

    private function hydrateMovementSources(Collection $rows): void
    {
        $inventoryDocuments = InventoryDocument::query()
            ->whereIn('id', $rows->where('source_type', InventoryDocument::class)->pluck('source_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $openingStocks = OpeningStock::query()
            ->withTrashed()
            ->whereIn('id', $rows->where('source_type', OpeningStock::class)->pluck('source_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $rows->each(function (InventoryTransaction $row) use ($inventoryDocuments, $openingStocks): void {
            $row->setRelation('sourceDocument', match ($row->source_type) {
                InventoryDocument::class => $inventoryDocuments->get($row->source_id),
                OpeningStock::class => $openingStocks->get($row->source_id),
                default => null,
            });
        });
    }
}
