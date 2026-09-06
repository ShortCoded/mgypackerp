<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\WarehouseLocation;

class InventoryMovementService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly InventoryDocumentPostingService $posting,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createAndPost(array $header, array $lines): InventoryDocument
    {
        return DB::transaction(function () use ($header, $lines): InventoryDocument {
            if ($lines === []) {
                throw new DomainException(__('An inventory movement requires at least one line.'));
            }

            $sourceStore = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($header['branch_store_id']);
            $destinationStore = isset($header['destination_branch_store_id'])
                ? BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($header['destination_branch_store_id'])
                : null;

            if ((int) $sourceStore->branch_id !== (int) $header['branch_id']
                || ($destinationStore && (int) $destinationStore->branch_id !== (int) $header['branch_id'])) {
                throw new DomainException(__('Inventory stores must belong to the selected operating branch.'));
            }

            $this->assertLocationBelongsToStore($header['warehouse_location_id'] ?? null, (int) $sourceStore->getKey());
            $this->assertLocationBelongsToStore(
                $header['destination_warehouse_location_id'] ?? null,
                (int) ($destinationStore?->getKey() ?? $sourceStore->getKey()),
            );

            $numbers = $this->documents->nextForCompany(
                'inventory_documents',
                InventoryDocument::class,
                (int) $header['company_id'],
                fn ($query) => $query->where('financial_period_id', $header['financial_period_id']),
            );
            $document = InventoryDocument::query()->create([
                ...$numbers,
                ...collect($header)->only([
                    'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
                    'branch_hall_id', 'warehouse_location_id', 'destination_branch_store_id',
                    'destination_warehouse_location_id', 'document_type', 'document_date',
                    'purpose', 'movement_reason', 'source_stock_status', 'destination_stock_status',
                    'source_document_type', 'source_document_id', 'source_doc_num', 'customer_id',
                    'production_order_id', 'production_run_id', 'notes',
                ])->all(),
                'status' => InventoryDocument::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($lines) as $index => $input) {
                $product = Product::query()->lockForUpdate()->findOrFail($input['product_id']);
                $quantity = (string) $input['quantity'];
                $sourceLocationId = $input['warehouse_location_id'] ?? $header['warehouse_location_id'] ?? null;
                $destinationLocationId = $input['destination_warehouse_location_id'] ?? $header['destination_warehouse_location_id'] ?? null;

                if ((int) $product->company_id !== (int) $header['company_id'] || bccomp($quantity, '0', 8) <= 0) {
                    throw new DomainException(__('Inventory movement lines require a company product and a positive base quantity.'));
                }

                $this->assertLocationBelongsToStore($sourceLocationId, (int) $sourceStore->getKey());
                $this->assertLocationBelongsToStore(
                    $destinationLocationId,
                    (int) ($destinationStore?->getKey() ?? $sourceStore->getKey()),
                );

                $document->lines()->create([
                    'company_id' => $header['company_id'],
                    'financial_period_id' => $header['financial_period_id'],
                    'line_number' => $index + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $input['unit_id'] ?? $product->item_unit_id,
                    'transaction_unit_id' => $input['transaction_unit_id'] ?? $input['unit_id'] ?? $product->item_unit_id,
                    'conversion_factor' => $input['conversion_factor'] ?? 1,
                    'transaction_quantity' => $input['transaction_quantity'] ?? $quantity,
                    'base_quantity' => $quantity,
                    'quantity' => $quantity,
                    'warehouse_location_id' => $sourceLocationId,
                    'destination_warehouse_location_id' => $destinationLocationId,
                    'batch_lot' => $input['batch_lot'] ?? null,
                    'manufacture_date' => $input['manufacture_date'] ?? null,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'source_line_type' => $input['source_line_type'] ?? null,
                    'source_line_id' => $input['source_line_id'] ?? null,
                    'source_line_public_id' => $input['source_line_public_id'] ?? null,
                    'production_order_id' => $header['production_order_id'] ?? null,
                    'production_run_id' => $header['production_run_id'] ?? null,
                    'inventory_reservation_id' => $input['inventory_reservation_id'] ?? null,
                    'unit_cost' => $input['unit_cost'] ?? null,
                    'product_snapshot' => $input['product_snapshot'] ?? ['doc_num' => $product->doc_num, 'name' => $product->name],
                    'notes' => $input['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }

            return $this->posting->post($document);
        });
    }

    private function assertLocationBelongsToStore(mixed $warehouseLocationId, int $branchStoreId): void
    {
        if ($warehouseLocationId === null) {
            return;
        }

        $belongsToStore = WarehouseLocation::query()
            ->whereKey($warehouseLocationId)
            ->where('branch_store_id', $branchStoreId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists();

        if (! $belongsToStore) {
            throw new DomainException(__('Warehouse locations must be active and belong to their selected store.'));
        }
    }
}
