<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountLine;
use Modules\Inventory\Models\WarehouseLocation;

class StockCountService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly InventoryMovementService $movements,
    ) {}

    /** @param array<string, mixed> $data */
    public function createSnapshot(array $data): StockCount
    {
        return DB::transaction(function () use ($data): StockCount {
            $branchStore = BranchStore::query()->lockForUpdate()->findOrFail($data['branch_store_id']);

            if ((int) $branchStore->branch_id !== (int) $data['branch_id']) {
                throw new DomainException(__('A stock count store must belong to the selected operating branch.'));
            }

            if (($data['warehouse_location_id'] ?? null) !== null && ! WarehouseLocation::query()
                ->whereKey($data['warehouse_location_id'])
                ->where('branch_store_id', $branchStore->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(__('A stock count location must be active and belong to the selected store.'));
            }

            $numbers = $this->documents->nextForCompany(
                'inventory_stock_counts',
                StockCount::class,
                (int) $data['company_id'],
                fn ($query) => $query->where('financial_period_id', $data['financial_period_id']),
            );
            $count = StockCount::query()->create([
                ...$numbers,
                'company_id' => $data['company_id'],
                'financial_period_id' => $data['financial_period_id'],
                'branch_id' => $data['branch_id'],
                'branch_store_id' => $data['branch_store_id'],
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'count_date' => $data['count_date'] ?? now()->toDateString(),
                'snapshot_at' => now(),
                'status' => StockCount::StatusDraft,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $positions = InventoryTransaction::query()
                ->where('company_id', $data['company_id'])
                ->where('branch_store_id', $data['branch_store_id'])
                ->when($data['warehouse_location_id'] ?? null, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
                ->when($data['stock_status'] ?? null, fn ($query, $status) => $query->where('stock_status', $status))
                ->when($data['product_ids'] ?? [], fn ($query, $productIds) => $query->whereIn('product_id', $productIds))
                ->selectRaw('product_id, unit_id, stock_status, batch_lot, sum(quantity_in - quantity_out) as system_quantity')
                ->groupBy(['product_id', 'unit_id', 'stock_status', 'batch_lot'])
                ->havingRaw('sum(quantity_in - quantity_out) <> 0')
                ->orderBy('product_id')
                ->get();

            foreach ($positions as $index => $position) {
                $count->lines()->create([
                    'line_number' => $index + 1,
                    'product_id' => $position->product_id,
                    'unit_id' => $position->unit_id,
                    'stock_status' => $position->stock_status,
                    'batch_lot' => $position->batch_lot,
                    'system_quantity' => $position->system_quantity,
                ]);
            }

            $coveredProductIds = $positions->pluck('product_id')->map(fn ($id): int => (int) $id)->unique();
            $missingProducts = Product::query()
                ->where('company_id', $data['company_id'])
                ->whereIn('id', $data['product_ids'] ?? [])
                ->whereNotIn('id', $coveredProductIds)
                ->lockForUpdate()
                ->get();

            foreach ($missingProducts as $product) {
                $count->lines()->create([
                    'line_number' => $count->lines()->count() + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $product->item_unit_id,
                    'stock_status' => $data['stock_status'] ?? InventoryTransaction::StatusAvailable,
                    'system_quantity' => 0,
                ]);
            }

            return $count->load(['lines.product', 'branchStore', 'warehouseLocation']);
        });
    }

    /** @param array<int, array{physical_quantity: string|int|float, variance_reason?: string|null, notes?: string|null}> $valuesByLineId */
    public function recordCount(StockCount $stockCount, array $valuesByLineId): StockCount
    {
        return DB::transaction(function () use ($stockCount, $valuesByLineId): StockCount {
            $locked = StockCount::query()->with('lines')->lockForUpdate()->findOrFail($stockCount->getKey());

            if ($locked->status !== StockCount::StatusDraft) {
                throw new DomainException(__('Only a draft stock count can receive physical quantities.'));
            }

            foreach ($locked->lines as $line) {
                $input = $valuesByLineId[$line->getKey()] ?? null;

                if (! is_array($input) || bccomp((string) $input['physical_quantity'], '0', 8) < 0) {
                    throw new DomainException(__('Every stock count line requires a non-negative physical quantity.'));
                }

                $variance = bcsub((string) $input['physical_quantity'], (string) $line->system_quantity, 8);
                $reason = trim((string) ($input['variance_reason'] ?? ''));

                if (bccomp($variance, '0', 8) !== 0 && $reason === '') {
                    throw new DomainException(__('A variance reason is required for every stock difference.'));
                }

                $line->update([
                    'physical_quantity' => $input['physical_quantity'],
                    'variance_quantity' => $variance,
                    'variance_reason' => $reason ?: null,
                    'notes' => $input['notes'] ?? null,
                ]);
            }

            $locked->update(['status' => StockCount::StatusCounted]);

            return $locked->refresh()->load('lines.product');
        });
    }

    /** @return list<InventoryDocument> */
    public function approve(StockCount $stockCount): array
    {
        return DB::transaction(function () use ($stockCount): array {
            $locked = StockCount::query()->with('lines')->lockForUpdate()->findOrFail($stockCount->getKey());

            if ($locked->status === StockCount::StatusApproved) {
                return InventoryDocument::query()
                    ->where('source_document_type', StockCount::class)
                    ->where('source_document_id', $locked->getKey())
                    ->get()
                    ->all();
            }

            if ($locked->status !== StockCount::StatusCounted) {
                throw new DomainException(__('A stock count must be completed before approval.'));
            }

            foreach ($locked->lines as $line) {
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $currentQuantity = InventoryTransaction::query()
                    ->where('company_id', $locked->company_id)
                    ->where('branch_store_id', $locked->branch_store_id)
                    ->where('product_id', $line->product_id)
                    ->where('stock_status', $line->stock_status)
                    ->when($locked->warehouse_location_id, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
                    ->where('batch_lot', $line->batch_lot)
                    ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
                    ->value('quantity');

                if (bccomp((string) $currentQuantity, (string) $line->system_quantity, 8) !== 0) {
                    throw new DomainException(__('Stock changed after the count snapshot. Create a new count before approval.'));
                }
            }

            $documents = [];

            $stockStatuses = $locked->lines->pluck('stock_status')->unique();

            foreach ($stockStatuses as $stockStatus) {
                foreach ([InventoryDocument::TypeAdjustmentIn, InventoryDocument::TypeAdjustmentOut] as $type) {
                    $isIncrease = $type === InventoryDocument::TypeAdjustmentIn;
                    $lines = $locked->lines
                        ->filter(fn (StockCountLine $line): bool => $line->stock_status === $stockStatus && ($isIncrease
                            ? bccomp((string) $line->variance_quantity, '0', 8) > 0
                            : bccomp((string) $line->variance_quantity, '0', 8) < 0))
                        ->map(function (StockCountLine $line): array {
                            Product::query()->lockForUpdate()->findOrFail($line->product_id);

                            return [
                                'product_id' => $line->product_id,
                                'unit_id' => $line->unit_id,
                                'quantity' => bccomp((string) $line->variance_quantity, '0', 8) < 0
                                    ? bcsub('0', (string) $line->variance_quantity, 8)
                                    : (string) $line->variance_quantity,
                                'warehouse_location_id' => $line->stockCount->warehouse_location_id,
                                'batch_lot' => $line->batch_lot,
                                'source_line_type' => StockCountLine::class,
                                'source_line_id' => $line->getKey(),
                                'notes' => $line->variance_reason,
                            ];
                        })
                        ->values()
                        ->all();

                    if ($lines === []) {
                        continue;
                    }

                    $documents[] = $this->movements->createAndPost([
                        'company_id' => $locked->company_id,
                        'financial_period_id' => $locked->financial_period_id,
                        'branch_id' => $locked->branch_id,
                        'branch_store_id' => $locked->branch_store_id,
                        'warehouse_location_id' => $locked->warehouse_location_id,
                        'document_type' => $type,
                        'document_date' => $locked->count_date,
                        'purpose' => 'Approved stock count variance',
                        'movement_reason' => 'Stock count '.$locked->doc_num,
                        'source_stock_status' => $stockStatus,
                        'destination_stock_status' => $stockStatus,
                        'source_document_type' => StockCount::class,
                        'source_document_id' => $locked->getKey(),
                        'source_doc_num' => $locked->doc_num,
                    ], $lines);
                }
            }

            $locked->update([
                'status' => StockCount::StatusApproved,
                'adjustment_document_id' => isset($documents[0]) ? $documents[0]->getKey() : null,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            return $documents;
        });
    }
}
