<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\Supplier;

class UnpricedInventoryReceiptService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly ProductImageResolver $productImages,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->currentContext();
            $branch = $this->branchByDocNum($data['branch_doc_num'] ?? null);
            $record = UnpricedInventoryReceipt::query()->create([
                ...$this->values($data, $context, $branch),
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines'] ?? [], $context, $branch);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['lines.product.unit', 'branch', 'branchHall', 'branchStore', 'supplier'])];
        });
    }

    public function update(UnpricedInventoryReceipt $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $context = $this->currentContext();
            $this->assertInCurrentContext($record, $context);
            $this->assertEditable($record);

            $branch = $this->branchByDocNum($data['branch_doc_num'] ?? null);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, $context, $branch);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $context)];
            }

            $this->audit->saveUpdate($record, $values);
            $this->syncLines($record->refresh(), $data['lines'] ?? [], $context, $branch);

            return [
                'record' => $record->refresh()->load(['lines.product.unit', 'branch', 'branchHall', 'branchStore', 'supplier']),
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(UnpricedInventoryReceipt $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertInCurrentContext($record, $this->currentContext());
            $this->assertDeletable($record);
            $this->audit->softDelete($record);

            $record->refresh()->lines()->get()->each(function (UnpricedInventoryReceiptLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
        });
    }

    public function restore(UnpricedInventoryReceipt $record): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($record): UnpricedInventoryReceipt {
            $this->assertInCurrentContext($record, $this->currentContext());
            $deletedAt = $record->deleted_at;

            $this->audit->restore($record, auth()->id());
            $record->lines()
                ->withTrashed()
                ->when($deletedAt, fn ($query) => $query->where('deleted_at', '>=', $deletedAt))
                ->get()
                ->each
                ->restore();

            return $record->refresh();
        });
    }

    public function approve(UnpricedInventoryReceipt $record): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($record): UnpricedInventoryReceipt {
            $this->assertInCurrentContext($record, $this->currentContext());

            /** @var UnpricedInventoryReceipt $locked */
            $locked = UnpricedInventoryReceipt::query()
                ->with(['lines.product', 'lines.unit'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            if ($locked->trashed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.deleted_not_approvable'));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.cancelled_not_approvable'));
            }

            if ($locked->isClosed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.closed_not_approvable'));
            }

            if ($locked->isApproved()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.already_approved'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.no_lines_approve'));
            }

            foreach ($locked->lines as $line) {
                if (! $line->product instanceof Product || ! $line->unit instanceof ItemUnit || (float) $line->quantity <= 0) {
                    throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.no_lines_approve'));
                }
            }

            $locked->forceFill([
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'is_closed' => false,
                'status' => UnpricedInventoryReceipt::StatusApproved,
                'pricing_status' => UnpricedInventoryReceipt::PricingStatusUnpriced,
                'updated_by' => auth()->id(),
            ])->save();

            return $locked->refresh()->load(['lines.product.unit', 'lines.unit', 'branch', 'branchHall', 'branchStore', 'supplier']);
        });
    }

    public function close(UnpricedInventoryReceipt $record): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($record): UnpricedInventoryReceipt {
            $this->assertInCurrentContext($record, $this->currentContext());

            /** @var UnpricedInventoryReceipt $locked */
            $locked = UnpricedInventoryReceipt::query()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->trashed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.deleted_not_closeable'));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.cancelled_not_closeable'));
            }

            if (! $locked->isApproved()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.not_approved_close_forbidden'));
            }

            if ($locked->isClosed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.already_closed'));
            }

            $locked->forceFill([
                'is_closed' => true,
                'status' => UnpricedInventoryReceipt::StatusClosed,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ])->save();

            return $locked->refresh()->load(['lines.product.unit', 'lines.unit', 'branch', 'branchHall', 'branchStore', 'supplier']);
        });
    }

    public function cancel(UnpricedInventoryReceipt $record): UnpricedInventoryReceipt
    {
        return DB::transaction(function () use ($record): UnpricedInventoryReceipt {
            $this->assertInCurrentContext($record, $this->currentContext());

            /** @var UnpricedInventoryReceipt $locked */
            $locked = UnpricedInventoryReceipt::query()->with('lines')->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->trashed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.deleted_not_cancelable'));
            }

            if ($locked->isClosed()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.closed_cancel_forbidden'));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.already_cancelled'));
            }

            $locked->forceFill([
                'approved' => false,
                'is_closed' => true,
                'status' => UnpricedInventoryReceipt::StatusCancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ])->save();

            return $locked->refresh()->load(['lines.product.unit', 'lines.unit', 'branch', 'branchHall', 'branchStore', 'supplier']);
        });
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context, Branch $branch): array
    {
        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $branch->getKey(),
            'branch_hall_id' => $this->branchHallId($branch, $data['branch_hall_uuid'] ?? null),
            'branch_store_id' => $this->branchStoreId($branch, $data['branch_store_uuid'] ?? null),
            'supplier_id' => $this->supplierId($context['company_id'], $data['supplier_doc_num'] ?? null),
            'document_date' => $data['document_date'],
            'reference_number' => $data['reference_number'] ?? null,
            'reference_date' => $data['reference_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'pricing_status' => UnpricedInventoryReceipt::PricingStatusUnpriced,
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, array $context): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('unpriced_inventory_receipts', (int) $data['doc_number'])]
            : $this->nextScopedDocument($context['company_id'], $context['financial_period_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function syncLines(UnpricedInventoryReceipt $record, array $lines, array $context, Branch $branch): void
    {
        $existingLines = $record->lines()->get()->keyBy('public_id');
        $keptLineIds = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $this->productByDocNum($context['company_id'], $line['product_doc_num'] ?? null);

            if (! $product instanceof Product) {
                continue;
            }

            $unit = $this->unitOptions->unitForProduct($product, $line['unit_doc_num'] ?? null, $context['company_id']);

            if (! $unit instanceof ItemUnit) {
                continue;
            }

            $publicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $publicId !== '' ? $existingLines->get($publicId) : null;
            $snapshot = $this->lineProductSnapshot($existingLine, $product, $unit);
            $lineValues = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $branch->getKey(),
                'line_no' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'product_snapshot' => $snapshot,
                'quantity' => $this->formatDecimal($line['quantity'] ?? 0),
                'notes' => $line['notes'] ?? null,
            ];

            if ($existingLine instanceof UnpricedInventoryReceiptLine && (int) $existingLine->receipt_id === (int) $record->getKey()) {
                $existingLine->forceFill([...$lineValues, 'updated_by' => auth()->id()])->save();
                $keptLineIds[] = $existingLine->getKey();

                continue;
            }

            $createdLine = $record->lines()->create([...$lineValues, 'created_by' => auth()->id()]);
            $keptLineIds[] = $createdLine->getKey();
        }

        $record->lines()
            ->when($keptLineIds !== [], fn ($query) => $query->whereNotIn('id', $keptLineIds))
            ->get()
            ->each(function (UnpricedInventoryReceiptLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
    }

    private function branchByDocNum(?string $docNum): Branch
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.branch_required'));
        }

        $branch = $this->operatingContext
            ->allowedBranchQueryForCurrentCompany(request())
            ->where('branches.doc_num', $docNum)
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->first();

        if (! $branch instanceof Branch) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.branch_unavailable'));
        }

        return $branch;
    }

    private function branchHallId(Branch $branch, ?string $uuid): ?int
    {
        $uuid = trim((string) $uuid);

        if ($uuid === '') {
            return null;
        }

        return BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->value('id');
    }

    private function branchStoreId(Branch $branch, ?string $uuid): ?int
    {
        $uuid = trim((string) $uuid);

        if ($uuid === '') {
            return null;
        }

        return BranchStore::query()
            ->where('branch_id', $branch->getKey())
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->value('id');
    }

    private function supplierId(int $companyId, ?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Supplier::query()
            ->active()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->value('id');
    }

    private function productByDocNum(int $companyId, ?string $docNum): ?Product
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->nonService()
            ->forCompany($companyId)
            ->where('products.doc_num', $docNum)
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_units as equivalent_units', 'equivalent_units.id', '=', 'products.equivalent_unit_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_models', 'item_models.id', '=', 'products.item_model_id')
            ->leftJoin('item_colors', 'item_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_sizes', 'item_sizes.id', '=', 'products.item_size_id')
            ->leftJoin('item_decals', 'item_decals.id', '=', 'products.item_decal_id')
            ->leftJoin('item_origin_countries', 'item_origin_countries.id', '=', 'products.item_origin_country_id')
            ->select([
                'products.id',
                'products.company_id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.image_path',
                'products.barcode',
                'products.item_classification',
                'products.status',
                'products.item_unit_id',
                'products.equivalent_unit_id',
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
                'equivalent_units.doc_num as equivalent_unit_doc_num',
                'equivalent_units.name as equivalent_unit_name',
                'item_categories.name as category_name',
                'item_groups.name as group_name',
                'item_models.name as model_name',
                'item_colors.name as color_name',
                'item_sizes.name as size_name',
                'item_decals.name as decal_name',
                'item_origin_countries.name as origin_country_name',
            ])
            ->first();
    }

    /**
     * @return array<string, string|null>
     */
    private function lineProductSnapshot(?UnpricedInventoryReceiptLine $existingLine, Product $product, ItemUnit $unit): array
    {
        if (
            $existingLine instanceof UnpricedInventoryReceiptLine
            && (int) $existingLine->product_id === (int) $product->getKey()
            && (int) $existingLine->unit_id === (int) $unit->getKey()
            && is_array($existingLine->product_snapshot)
            && $existingLine->product_snapshot !== []
        ) {
            return $existingLine->product_snapshot;
        }

        return $this->productSnapshot($product, $unit);
    }

    /**
     * @return array<string, string|null>
     */
    private function productSnapshot(Product $product, ItemUnit $unit): array
    {
        return [
            'doc_num' => (string) $product->doc_num,
            'name' => (string) $product->name,
            'barcode' => $this->nullableText($product->barcode),
            'unit_doc_num' => (string) $unit->doc_num,
            'unit_label' => $this->unitLabel($unit),
            'item_classification' => __("products.classifications.{$product->item_classification}"),
            'category' => $this->nullableText($product->category_name),
            'group' => $this->nullableText($product->group_name),
            'size' => $this->nullableText($product->size_name),
            'color' => $this->nullableText($product->color_name),
            'model' => $this->nullableText($product->model_name),
            'decal' => $this->nullableText($product->decal_name),
            'origin_country' => $this->nullableText($product->origin_country_name),
            'image_url' => $this->imageUrl($product),
        ];
    }

    private function unitLabel(ItemUnit $unit): string
    {
        return trim(implode(' / ', array_filter([$unit->doc_num, $unit->name])));
    }

    private function imageUrl(Product $product): ?string
    {
        return $this->productImages->url($product);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function formatDecimal(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function assertEditable(UnpricedInventoryReceipt $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.approved_edit_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.closed_edit_forbidden'));
        }

        if ($record->isCancelled()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.cancelled_edit_forbidden'));
        }
    }

    private function assertDeletable(UnpricedInventoryReceipt $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.approved_delete_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.closed_delete_forbidden'));
        }

        if ($record->isCancelled()) {
            throw new DomainException(__('inventory.unpriced_inventory_receipts.messages.cancelled_delete_forbidden'));
        }
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function assertInCurrentContext(UnpricedInventoryReceipt $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
    }

    /**
     * @return array{company_id: int, financial_period_id: int}
     */
    private function currentContext(): array
    {
        $context = $this->operatingContext->snapshot(request());

        if (! $context['company_id'] || ! $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
        ];
    }

    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('unpriced_inventory_receipts')));
        }

        $nextNumber = ((int) UnpricedInventoryReceipt::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format('unpriced_inventory_receipts', $nextNumber),
        ];
    }
}
