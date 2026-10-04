<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
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
            Company::query()->whereKey($header['company_id'])->lockForUpdate()->firstOrFail();
            FinancialPeriod::query()->lockForUpdate()->findOrFail($header['financial_period_id']);

            return $this->posting->post($this->createDraft($header, $lines));
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(array $header, array $lines): InventoryDocument
    {
        return DB::transaction(function () use ($header, $lines): InventoryDocument {
            [$sourceStore, $destinationStore] = $this->validatedStores($header);

            $numbers = $this->documents->nextForCompany(
                'inventory_documents',
                InventoryDocument::class,
                (int) $header['company_id'],
            );
            $document = InventoryDocument::query()->create([
                ...$numbers,
                ...collect($header)->only([
                    'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
                    'branch_hall_id', 'warehouse_location_id', 'destination_branch_store_id',
                    'destination_warehouse_location_id', 'document_type', 'document_date',
                    'purpose', 'movement_reason', 'source_stock_status', 'destination_stock_status',
                    'source_document_type', 'source_document_id', 'source_doc_num', 'customer_id',
                    'production_order_id', 'production_run_id', 'production_run_batch_id', 'notes',
                ])->all(),
                'status' => InventoryDocument::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($document, $header, $lines, $sourceStore, $destinationStore);

            return $document->refresh()->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraft(InventoryDocument $document, array $header, array $lines): InventoryDocument
    {
        return DB::transaction(function () use ($document, $header, $lines): InventoryDocument {
            $document = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            if (! $document->isUntouchedDraft()) {
                throw new DomainException(__('inventory.movements.messages.only_drafts_editable'));
            }

            if ((int) $document->company_id !== (int) $header['company_id']
                || (int) $document->financial_period_id !== (int) $header['financial_period_id']
                || (int) $document->branch_id !== (int) $header['branch_id']) {
                throw new DomainException(__('inventory.movements.messages.context_mismatch'));
            }

            [$sourceStore, $destinationStore] = $this->validatedStores($header);
            $document->fill(collect($header)->only([
                'branch_store_id', 'branch_hall_id', 'warehouse_location_id',
                'destination_branch_store_id', 'destination_warehouse_location_id',
                'document_type', 'document_date', 'purpose', 'movement_reason',
                'source_stock_status', 'destination_stock_status', 'notes',
            ])->all())->save();

            $document->lines()->forceDelete();
            $this->replaceLines($document, $header, $lines, $sourceStore, $destinationStore);

            return $document->refresh()->load('lines');
        });
    }

    /** @param array<string, mixed> $header
     * @return array{0: BranchStore, 1: ?BranchStore}
     */
    private function validatedStores(array $header): array
    {
        $sourceStore = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($header['branch_store_id']);
        $destinationStore = isset($header['destination_branch_store_id'])
            ? BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($header['destination_branch_store_id'])
            : null;

        if ((int) $sourceStore->branch_id !== (int) $header['branch_id']) {
            throw new DomainException(__('The source inventory store must belong to the operating branch.'));
        }

        if ($destinationStore
            && ((int) $destinationStore->branch?->company_id !== (int) $header['company_id']
                || $destinationStore->branch?->status !== 'active')) {
            throw new DomainException(__('The destination inventory store must belong to an active branch in the operating company.'));
        }
        if ($header['document_type'] === InventoryDocument::TypeTransfer) {
            $this->assertTransferBranchAccess((int) $header['company_id'], [(int) $sourceStore->branch_id, (int) ($destinationStore?->branch_id ?? $sourceStore->branch_id)]);
        }

        $this->assertLocationBelongsToStore($header['warehouse_location_id'] ?? null, (int) $sourceStore->getKey());
        $this->assertLocationBelongsToStore(
            $header['destination_warehouse_location_id'] ?? null,
            (int) ($destinationStore?->getKey() ?? $sourceStore->getKey()),
        );

        return [$sourceStore, $destinationStore];
    }

    /** @param list<int> $branchIds */
    public function assertTransferBranchAccess(int $companyId, array $branchIds): void
    {
        $company = Company::query()->findOrFail($companyId);
        $user = auth()->user();
        $branchIds = array_values(array_unique($branchIds));
        if ($user === null || app(OperatingScopeAccessService::class)->allowedBranchQuery($user, [$company->doc_num])
            ->whereIn('branches.id', $branchIds)->count() !== count($branchIds)) {
            throw new AuthorizationException(__('inventory.movements.messages.receipt_completion_branch_access'));
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(
        InventoryDocument $document,
        array $header,
        array $lines,
        BranchStore $sourceStore,
        ?BranchStore $destinationStore,
    ): void {
        if ($lines === []) {
            throw new DomainException(__('An inventory movement requires at least one line.'));
        }

        $lines = $this->expandSerialLines($lines);
        foreach (array_values($lines) as $index => $input) {
            $product = Product::query()->lockForUpdate()->findOrFail($input['product_id']);
            $quantity = (string) $input['quantity'];
            $serialIdentityId = null;
            $selectedLayerId = filled($input['selected_receipt_layer_id'] ?? null) ? (int) $input['selected_receipt_layer_id'] : null;
            if ($selectedLayerId !== null) {
                $layer = InventoryReceiptLayer::query()->whereKey($selectedLayerId)
                    ->where('company_id', $document->company_id)->where('branch_store_id', $sourceStore->id)
                    ->where('product_id', $product->id)->where('stock_status', $document->source_stock_status ?? InventoryTransaction::StatusAvailable)
                    ->where('remaining_quantity', '>', 0)->withAuthoritativeCost()->lockForUpdate()->first();
                if ($layer === null || (filled($input['batch_lot'] ?? null) && $input['batch_lot'] !== $layer->batch_lot)) {
                    throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                }
                $input = [...$input, 'warehouse_location_id' => $layer->warehouse_location_id, 'batch_lot' => $layer->batch_lot,
                    'manufacture_date' => $layer->manufacture_date, 'expiry_date' => $layer->expiry_date];
                $serialIdentityId = $layer->inventory_serial_identity_id;
            }
            if (filled($input['serial_number'] ?? null)) {
                if ($selectedLayerId !== null) {
                    throw new DomainException(__('inventory_serial.select_existing'));
                }
                $serialIdentityId = app(InventorySerialService::class)->resolve($product, (string) $input['serial_number'])->id;
            }
            if (($product->tracks_serials || $serialIdentityId !== null) && bccomp($quantity, '1', 8) !== 0) {
                throw new DomainException(__('inventory_serial.exact_unit_required'));
            }
            $sourceLocationId = $input['warehouse_location_id'] ?? $header['warehouse_location_id'] ?? null;
            $destinationLocationId = $input['destination_warehouse_location_id'] ?? $header['destination_warehouse_location_id'] ?? null;

            if ((int) $product->company_id !== (int) $header['company_id'] || bccomp($quantity, '0', 8) <= 0) {
                throw new DomainException(__('Inventory movement lines require a company product and a positive base quantity.'));
            }

            if ($document->document_type === InventoryDocument::TypeReceipt
                && $product->item_classification === Product::ClassificationFinishedProduct) {
                throw new DomainException(__('inventory.movements.messages.finished_goods_require_production_receipt'));
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
                'selected_receipt_layer_id' => $selectedLayerId,
                'inventory_serial_identity_id' => $serialIdentityId,
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
                'production_run_id' => $input['production_run_id'] ?? $header['production_run_id'] ?? null,
                'inventory_reservation_id' => $input['inventory_reservation_id'] ?? null,
                'unit_cost' => $input['unit_cost'] ?? null,
                'product_snapshot' => $input['product_snapshot'] ?? ['doc_num' => $product->doc_num, 'name' => $product->name],
                'notes' => $input['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function expandSerialLines(array $lines): array
    {
        $expanded = [];
        foreach ($lines as $input) {
            $serials = $input['serial_numbers'] ?? [];
            if (is_string($serials)) {
                $serials = preg_split('/\r\n|\r|\n/', $serials);
            }
            if (! is_array($serials)) {
                throw new DomainException(__('inventory_serial.invalid_serial'));
            }
            $serials = array_values(array_filter(array_map(fn ($value): string => is_string($value) ? trim($value) : '', $serials), fn (string $value): bool => $value !== ''));
            if ($serials === []) {
                $expanded[] = $input;

                continue;
            }
            if (count($serials) > 10000 || bccomp((string) count($serials), (string) $input['quantity'], 8) !== 0
                || filled($input['selected_receipt_layer_id'] ?? null) || filled($input['serial_number'] ?? null)) {
                throw new DomainException(__('inventory_serial.count_mismatch'));
            }
            foreach ($serials as $serial) {
                $expanded[] = [...$input, 'quantity' => '1', 'base_quantity' => '1',
                    'transaction_quantity' => bcdiv('1', (string) ($input['conversion_factor'] ?? '1'), 8), 'serial_number' => $serial];
            }
        }

        return $expanded;
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
