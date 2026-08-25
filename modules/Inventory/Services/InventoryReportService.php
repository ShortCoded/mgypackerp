<?php

namespace Modules\Inventory\Services;

use Carbon\CarbonImmutable;
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
            'reorder' => $this->reorder($companyId, $branchId, $balances, $reservations, $contextFilters),
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

    /** @param array<string, mixed> $filters */
    public function balances(int $companyId, array $filters = []): Collection
    {
        $rows = InventoryTransaction::query()
            ->selectRaw('company_id, branch_store_id, warehouse_location_id, product_id, stock_status, batch_lot')
            ->selectRaw('sum(quantity_in) as quantity_in, sum(quantity_out) as quantity_out, sum(quantity_in - quantity_out) as on_hand')
            ->selectRaw('sum(case when quantity_out > 0 and quantity_in = 0 then -coalesce(total_cost, quantity_out * unit_cost, 0) else coalesce(total_cost, (quantity_in - quantity_out) * unit_cost, 0) end) as inventory_value')
            ->selectRaw('sum(case when transaction_type = ? and quantity_in > 0 and coalesce(unit_cost, 0) <= 0 then quantity_in else 0 end) as unvalued_receipt_quantity', ['purchase_receipt'])
            ->where('company_id', $companyId)
            ->when($filters['financial_period_id'] ?? null, fn ($query, $periodId) => $query->where('financial_period_id', $periodId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHas('branchStore', fn ($storeQuery) => $storeQuery->where('branch_id', $branchId)))
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

        return $rows;
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
    private function reorder(
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
            ->orderBy('doc_num')
            ->get()
            ->keyBy('id');

        $onHand = $balances
            ->where('stock_status', InventoryTransaction::StatusAvailable)
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

        return $stores->flatMap(function (BranchStore $store) use ($products, $onHand, $reserved, $productionDemand): SupportCollection {
            return $products->map(function (Product $product) use ($store, $onHand, $reserved, $productionDemand): object {
                $key = $store->getKey().'|'.$product->getKey();
                $onHandQuantity = (string) ($onHand->get($key) ?? '0.00000000');
                $reservedQuantity = (string) ($reserved->get($key) ?? '0.00000000');
                $availableQuantity = bcsub($onHandQuantity, $reservedQuantity, 8);
                $availableQuantity = bccomp($availableQuantity, '0', 8) < 0 ? '0.00000000' : $availableQuantity;
                $shortage = bcsub((string) $product->reorder_point, $availableQuantity, 8);

                return (object) [
                    'product' => $product,
                    'branchStore' => $store,
                    'on_hand' => $onHandQuantity,
                    'reserved' => $reservedQuantity,
                    'available' => $availableQuantity,
                    'reorder_point' => (string) $product->reorder_point,
                    'shortage' => bccomp($shortage, '0', 8) > 0 ? $shortage : '0.00000000',
                    'production_demand' => (string) ($productionDemand->get($key) ?? '0.00000000'),
                ];
            });
        })->filter(fn (object $row): bool => bccomp($row->shortage, '0', 8) > 0 || bccomp($row->production_demand, '0', 8) > 0)->values();
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
