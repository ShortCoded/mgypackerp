<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
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
        private readonly CrudAuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): StockCount
    {
        return DB::transaction(function () use ($data): StockCount {
            $this->assertStoreAndLocation($data);
            $numbers = isset($data['doc_number']) && $data['doc_number']
                ? [
                    'doc_number' => (int) $data['doc_number'],
                    'doc_num' => $this->documents->format('inventory_stock_counts', (int) $data['doc_number']),
                ]
                : $this->documents->nextForCompany(
                    'inventory_stock_counts',
                    StockCount::class,
                    (int) $data['company_id'],
                    fn ($query) => $query->where('financial_period_id', $data['financial_period_id']),
                );
            $record = StockCount::query()->create([
                ...$numbers,
                'company_id' => $data['company_id'],
                'financial_period_id' => $data['financial_period_id'],
                'branch_id' => $data['branch_id'],
                'branch_store_id' => $data['branch_store_id'],
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'count_date' => $data['count_date'],
                'snapshot_at' => now(),
                'status' => StockCount::StatusCounted,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines']);
            $this->audit->clearCreationUpdateAudit($record);

            return $record->refresh()->load($this->relations());
        });
    }

    /** @param array<string, mixed> $data */
    public function update(StockCount $stockCount, array $data): StockCount
    {
        return DB::transaction(function () use ($stockCount, $data): StockCount {
            /** @var StockCount $locked */
            $locked = StockCount::query()->lockForUpdate()->findOrFail($stockCount->getKey());
            $this->assertEditable($locked);
            $this->assertStoreAndLocation($data);
            $values = [
                'branch_store_id' => $data['branch_store_id'],
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'count_date' => $data['count_date'],
                'snapshot_at' => now(),
                'status' => StockCount::StatusCounted,
                'notes' => $data['notes'] ?? null,
            ];

            if (isset($data['doc_number']) && $data['doc_number']) {
                $values['doc_number'] = (int) $data['doc_number'];
                $values['doc_num'] = $this->documents->format('inventory_stock_counts', (int) $data['doc_number']);
            }

            $this->audit->saveUpdate($locked, $values);
            $this->syncLines($locked->refresh(), $data['lines']);

            return $locked->refresh()->load($this->relations());
        });
    }

    public function delete(StockCount $stockCount): void
    {
        DB::transaction(function () use ($stockCount): void {
            $this->assertEditable($stockCount);
            $this->audit->softDelete($stockCount);
            $stockCount->lines()->get()->each(function (StockCountLine $line): void {
                $line->forceFill(['deleted_with_document' => true])->saveQuietly();
                $this->audit->softDelete($line);
            });
        });
    }

    public function restore(StockCount $stockCount): StockCount
    {
        return DB::transaction(function () use ($stockCount): StockCount {
            if (! $stockCount->trashed()) {
                throw new DomainException(__('inventory.stock_counts.messages.restore_requires_trashed'));
            }

            $this->audit->restore($stockCount);
            $stockCount->lines()
                ->withTrashed()
                ->where('deleted_with_document', true)
                ->get()
                ->each(function (StockCountLine $line): void {
                    $this->audit->restore($line);
                    $line->forceFill(['deleted_with_document' => false])->saveQuietly();
                });

            return $stockCount->refresh()->load($this->relations());
        });
    }

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

            $addedLines = $this->appendSnapshotLines(
                $count,
                $data['product_ids'] ?? [],
                $data['stock_status'] ?? null,
            );

            if ($addedLines === 0) {
                throw new DomainException(__('inventory.stock_counts.messages.empty_snapshot'));
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

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('inventory.stock_counts.messages.no_lines'));
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

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('inventory.stock_counts.messages.no_lines'));
            }

            Product::query()->whereIn('id', $locked->lines->pluck('product_id')->unique())->lockForUpdate()->get();
            $currentBalances = $this->currentBalanceMap($locked, $locked->lines->all());

            foreach ($locked->lines as $line) {
                $key = $this->lineKey((int) $line->product_id, $line->unit_id === null ? null : (int) $line->unit_id, $line->stock_status, $line->batch_lot);
                if (bccomp((string) ($currentBalances[$key] ?? '0'), (string) $line->system_quantity, 8) !== 0) {
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
                        ->map(function (StockCountLine $line) use ($locked): array {
                            return [
                                'product_id' => $line->product_id,
                                'unit_id' => $line->unit_id,
                                'quantity' => bccomp((string) $line->variance_quantity, '0', 8) < 0
                                    ? bcsub('0', (string) $line->variance_quantity, 8)
                                    : (string) $line->variance_quantity,
                                'warehouse_location_id' => $locked->warehouse_location_id,
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
                'updated_by' => auth()->id(),
            ]);

            return $documents;
        });
    }

    public function currentQuantity(
        int $companyId,
        int $branchStoreId,
        ?int $warehouseLocationId,
        int $productId,
        ?int $unitId,
        string $stockStatus,
        ?string $batchLot,
    ): string {
        $quantity = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_store_id', $branchStoreId)
            ->where('product_id', $productId)
            ->where('unit_id', $unitId)
            ->where('stock_status', $stockStatus)
            ->when($warehouseLocationId !== null, fn ($query) => $query->where('warehouse_location_id', $warehouseLocationId))
            ->where('batch_lot', $this->nullableText($batchLot))
            ->selectRaw('coalesce(sum(quantity_in - quantity_out), 0) as quantity')
            ->value('quantity');

        return bcadd((string) ($quantity ?? '0'), '0', 8);
    }

    /** @param array<string, mixed> $data */
    private function syncLines(StockCount $record, array $lines): void
    {
        $products = Product::query()
            ->active()
            ->nonService()
            ->forCompany((int) $record->company_id)
            ->whereIn('doc_num', collect($lines)->pluck('product_doc_num')->filter()->unique())
            ->lockForUpdate()
            ->get(['id', 'doc_num', 'item_unit_id'])
            ->keyBy('doc_num');
        $resolvedLines = collect($lines)->map(function (array $line) use ($products): array {
            $product = $products->get($line['product_doc_num'] ?? '');

            if (! $product instanceof Product) {
                throw new DomainException(__('inventory.stock_counts.messages.product_unavailable'));
            }

            return [...$line, 'product' => $product];
        })->all();
        $balances = $this->currentBalanceMap($record, $resolvedLines);
        $existingLines = $record->lines()->get()->keyBy('id');
        $keptLineIds = [];

        foreach (array_values($resolvedLines) as $index => $line) {
            /** @var Product $product */
            $product = $line['product'];
            $unitId = $product->item_unit_id === null ? null : (int) $product->item_unit_id;
            $stockStatus = (string) $line['stock_status'];
            $batchLot = $this->nullableText($line['batch_lot'] ?? null);
            $systemQuantity = $balances[$this->lineKey((int) $product->getKey(), $unitId, $stockStatus, $batchLot)] ?? '0.00000000';
            $physicalQuantity = bcadd((string) $line['physical_quantity'], '0', 8);
            $varianceQuantity = bcsub($physicalQuantity, $systemQuantity, 8);
            $reason = $this->nullableText($line['variance_reason'] ?? null);

            if (bccomp($varianceQuantity, '0', 8) !== 0 && $reason === null) {
                throw new DomainException(__('inventory.stock_counts.messages.variance_reason_required', ['line' => $index + 1]));
            }

            $values = [
                'line_number' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unitId,
                'stock_status' => $stockStatus,
                'batch_lot' => $batchLot,
                'system_quantity' => $systemQuantity,
                'physical_quantity' => $physicalQuantity,
                'variance_quantity' => $varianceQuantity,
                'variance_reason' => $reason,
                'notes' => $this->nullableText($line['notes'] ?? null),
            ];
            $existingLine = isset($line['line_id']) ? $existingLines->get((int) $line['line_id']) : null;

            if ($existingLine instanceof StockCountLine) {
                $this->audit->saveUpdate($existingLine, $values);
                $keptLineIds[] = $existingLine->getKey();

                continue;
            }

            $createdLine = $record->lines()->create([...$values, 'created_by' => auth()->id()]);
            $keptLineIds[] = $createdLine->getKey();
        }

        $record->lines()
            ->when($keptLineIds !== [], fn ($query) => $query->whereNotIn('id', $keptLineIds))
            ->get()
            ->each(fn (StockCountLine $line) => $this->audit->softDelete($line));
    }

    /**
     * @param  list<array<string, mixed>|StockCountLine>  $lines
     * @return array<string, string>
     */
    private function currentBalanceMap(StockCount $record, array $lines): array
    {
        $productIds = collect($lines)->map(function (array|StockCountLine $line): int {
            $product = is_array($line) ? ($line['product'] ?? null) : null;

            return $product instanceof Product ? (int) $product->getKey() : (int) $line->product_id;
        })->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        return InventoryTransaction::query()
            ->where('company_id', $record->company_id)
            ->where('branch_store_id', $record->branch_store_id)
            ->whereIn('product_id', $productIds)
            ->when($record->warehouse_location_id !== null, fn ($query) => $query->where('warehouse_location_id', $record->warehouse_location_id))
            ->selectRaw('product_id, unit_id, stock_status, batch_lot, sum(quantity_in - quantity_out) as quantity')
            ->groupBy(['product_id', 'unit_id', 'stock_status', 'batch_lot'])
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $this->lineKey((int) $row->product_id, $row->unit_id === null ? null : (int) $row->unit_id, (string) $row->stock_status, $row->batch_lot) => bcadd((string) $row->quantity, '0', 8),
            ])
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function assertStoreAndLocation(array $data): void
    {
        $store = BranchStore::query()->lockForUpdate()->findOrFail($data['branch_store_id']);

        if ((int) $store->branch_id !== (int) $data['branch_id']) {
            throw new DomainException(__('inventory.stock_counts.messages.store_invalid'));
        }

        if (($data['warehouse_location_id'] ?? null) !== null && ! WarehouseLocation::query()
            ->whereKey($data['warehouse_location_id'])
            ->where('branch_store_id', $store->getKey())
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists()) {
            throw new DomainException(__('inventory.stock_counts.messages.location_invalid'));
        }
    }

    private function assertEditable(StockCount $record): void
    {
        if (! $record->isEditable()) {
            throw new DomainException(__('inventory.stock_counts.messages.approved_edit_forbidden'));
        }
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['lines.product.unit', 'lines.unit', 'branchStore', 'warehouseLocation', 'adjustmentDocument'];
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<int|string>  $productIds
     */
    private function appendSnapshotLines(
        StockCount $stockCount,
        array $productIds,
        ?string $stockStatus = null,
        ?string $batchLot = null,
    ): int {
        $normalizedProductIds = collect($productIds)
            ->map(fn ($productId): int => (int) $productId)
            ->filter()
            ->unique()
            ->values();
        $normalizedBatchLot = filled($batchLot) ? trim((string) $batchLot) : null;
        $positions = InventoryTransaction::query()
            ->where('company_id', $stockCount->company_id)
            ->where('branch_store_id', $stockCount->branch_store_id)
            ->when($stockCount->warehouse_location_id, fn ($query, $locationId) => $query->where('warehouse_location_id', $locationId))
            ->when($stockStatus, fn ($query, $status) => $query->where('stock_status', $status))
            ->when($normalizedBatchLot !== null, fn ($query) => $query->where('batch_lot', $normalizedBatchLot))
            ->when($normalizedProductIds->isNotEmpty(), fn ($query) => $query->whereIn('product_id', $normalizedProductIds))
            ->selectRaw('product_id, unit_id, stock_status, batch_lot, sum(quantity_in - quantity_out) as system_quantity')
            ->groupBy(['product_id', 'unit_id', 'stock_status', 'batch_lot'])
            ->havingRaw('sum(quantity_in - quantity_out) <> 0')
            ->orderBy('product_id')
            ->get();
        $existingLineKeys = $stockCount->lines()
            ->get(['product_id', 'unit_id', 'stock_status', 'batch_lot'])
            ->mapWithKeys(fn (StockCountLine $line): array => [$this->lineKey(
                (int) $line->product_id,
                $line->unit_id === null ? null : (int) $line->unit_id,
                $line->stock_status,
                $line->batch_lot,
            ) => true]);
        $nextLineNumber = (int) $stockCount->lines()->max('line_number');
        $addedLines = 0;

        foreach ($positions as $position) {
            $lineKey = $this->lineKey(
                (int) $position->product_id,
                $position->unit_id === null ? null : (int) $position->unit_id,
                $position->stock_status,
                $position->batch_lot,
            );

            if ($existingLineKeys->has($lineKey)) {
                continue;
            }

            $stockCount->lines()->create([
                'line_number' => ++$nextLineNumber,
                'product_id' => $position->product_id,
                'unit_id' => $position->unit_id,
                'stock_status' => $position->stock_status,
                'batch_lot' => $position->batch_lot,
                'system_quantity' => $position->system_quantity,
            ]);
            $existingLineKeys->put($lineKey, true);
            $addedLines++;
        }

        $coveredProductIds = $positions->pluck('product_id')->map(fn ($id): int => (int) $id)->unique();
        $missingProducts = Product::query()
            ->where('company_id', $stockCount->company_id)
            ->active()
            ->nonService()
            ->whereIn('id', $normalizedProductIds)
            ->whereNotIn('id', $coveredProductIds)
            ->lockForUpdate()
            ->get();

        foreach ($missingProducts as $product) {
            $lineStatus = $stockStatus ?? InventoryTransaction::StatusAvailable;
            $lineKey = $this->lineKey(
                (int) $product->getKey(),
                $product->item_unit_id === null ? null : (int) $product->item_unit_id,
                $lineStatus,
                $normalizedBatchLot,
            );

            if ($existingLineKeys->has($lineKey)) {
                continue;
            }

            $stockCount->lines()->create([
                'line_number' => ++$nextLineNumber,
                'product_id' => $product->getKey(),
                'unit_id' => $product->item_unit_id,
                'stock_status' => $lineStatus,
                'batch_lot' => $normalizedBatchLot,
                'system_quantity' => 0,
            ]);
            $existingLineKeys->put($lineKey, true);
            $addedLines++;
        }

        return $addedLines;
    }

    private function lineKey(int $productId, ?int $unitId, string $stockStatus, ?string $batchLot): string
    {
        return json_encode([$productId, $unitId, $stockStatus, $batchLot], JSON_THROW_ON_ERROR);
    }
}
