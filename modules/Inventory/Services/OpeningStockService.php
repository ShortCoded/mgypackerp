<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;

class OpeningStockService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly ProductImageResolver $productImages,
        private readonly NumericFormatService $numbers,
        private readonly InventoryOpeningStockPostingService $posting,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->currentContext();
            $record = OpeningStock::query()->create([
                ...$this->values($data, $context),
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines'] ?? [], $context);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['lines.product.unit', 'branch', 'branchHall', 'branchStore'])];
        });
    }

    public function update(OpeningStock $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $record = OpeningStock::query()->lockForUpdate()->findOrFail($record->getKey());
            $context = $this->currentContext();
            $this->assertInCurrentContext($record, $context);
            $this->assertEditable($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, $context);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $context)];
            }

            $this->audit->saveUpdate($record, $values);
            $this->syncLines($record->refresh(), $data['lines'] ?? [], $context);

            return [
                'record' => $record->refresh()->load(['lines.product.unit', 'branch', 'branchHall', 'branchStore']),
                'changed' => true,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(OpeningStock $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertInCurrentContext($record, $this->currentContext());

            $this->assertDeletable($record);

            $this->audit->softDelete($record);

            $record->refresh()->lines()->get()->each(function (OpeningStockLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
        });
    }

    public function restore(OpeningStock $record): OpeningStock
    {
        return DB::transaction(function () use ($record): OpeningStock {
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

    public function approve(OpeningStock $record): OpeningStock
    {
        return DB::transaction(function () use ($record): OpeningStock {
            $this->assertInCurrentContext($record, $this->currentContext());

            /** @var OpeningStock $locked */
            $locked = OpeningStock::query()
                ->with(['lines.product', 'branch'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            if ($locked->trashed()) {
                throw new DomainException(__('inventory.opening_stocks.messages.deleted_not_approvable'));
            }

            if ($locked->approved || $locked->status === OpeningStock::StatusApproved) {
                throw new DomainException(__('inventory.opening_stocks.messages.already_approved'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('inventory.opening_stocks.messages.no_lines_approve'));
            }

            if ($locked->branch?->type === Branch::TypeFactory && ! $locked->branch_store_id) {
                throw new DomainException(__('inventory.opening_stocks.messages.store_required_for_factory'));
            }

            foreach ($locked->lines as $line) {
                if (! $line->product instanceof Product || (float) $line->quantity <= 0) {
                    throw new DomainException(__('inventory.opening_stocks.messages.no_lines_approve'));
                }
            }

            $locked->forceFill([
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'is_closed' => true,
                'status' => OpeningStock::StatusApproved,
                'updated_by' => auth()->id(),
            ])->save();

            $this->posting->post($locked);

            return $locked->refresh()->load(['lines.product.unit', 'branch', 'branchHall', 'branchStore']);
        });
    }

    public function branchHallId(array $context, ?string $uuid): ?int
    {
        $uuid = trim((string) $uuid);

        if ($uuid === '') {
            return null;
        }

        return BranchHall::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->value('id');
    }

    public function branchStoreId(array $context, ?string $uuid): ?int
    {
        $uuid = trim((string) $uuid);

        if ($uuid === '') {
            return null;
        }

        return BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->value('id');
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context): array
    {
        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'branch_hall_id' => $this->branchHallId($context, $data['branch_hall_uuid'] ?? null),
            'branch_store_id' => $this->branchStoreId($context, $data['branch_store_uuid'] ?? null),
            'document_date' => $data['document_date'],
            'notes' => $data['notes'] ?? null,
            'is_closed' => true,
            'status' => OpeningStock::StatusClosed,
            'approved' => false,
            'approved_at' => null,
            'approved_by' => null,
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, array $context): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('inventory_opening_stocks', (int) $data['doc_number'])]
            : $this->nextScopedDocument($context['company_id'], $context['financial_period_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function syncLines(OpeningStock $record, array $lines, array $context): void
    {
        $existingLines = $record->lines()->lockForUpdate()->get();
        $existingByPublicId = $existingLines->keyBy('public_id');
        $keptLineIds = [];
        $reassignedLineIds = [];
        $plannedLines = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $this->productByDocNum($context['company_id'], $line['product_doc_num'] ?? null);

            if (! $product instanceof Product) {
                continue;
            }

            $publicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $publicId !== ''
                ? $existingByPublicId->get($publicId)
                : $existingLines->first(fn (OpeningStockLine $candidate): bool => (int) $candidate->product_id === (int) $product->getKey() && ! in_array($candidate->getKey(), $keptLineIds, true));

            if (($publicId !== '' && ! ($existingLine instanceof OpeningStockLine))
                || ($existingLine instanceof OpeningStockLine && in_array($existingLine->getKey(), $keptLineIds, true))) {
                throw new DomainException(__('inventory.opening_stocks.messages.line_invalid'));
            }

            $snapshot = $this->lineProductSnapshot($existingLine, $product);
            $lineValues = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'line_no' => $index + 1,
                'product_id' => $product->getKey(),
                'product_snapshot' => $snapshot,
                'quantity' => $this->numbers->normalizeToScale($line['quantity'] ?? 0, 4) ?? '0.0000',
                'stock_status' => $line['stock_status'] ?? InventoryTransaction::StatusAvailable,
                'batch_lot' => $this->nullableText($line['batch_lot'] ?? null),
                'manufacture_date' => $line['manufacture_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'notes' => $line['notes'] ?? null,
            ];

            if ($existingLine instanceof OpeningStockLine) {
                $keptLineIds[] = $existingLine->getKey();
                if ((int) $existingLine->product_id !== (int) $product->getKey()) {
                    $reassignedLineIds[] = $existingLine->getKey();
                }
            }

            $plannedLines[] = ['existing' => $existingLine, 'values' => $lineValues];
        }

        foreach ($existingLines as $existingLine) {
            if (in_array($existingLine->getKey(), $keptLineIds, true)
                && ! in_array($existingLine->getKey(), $reassignedLineIds, true)) {
                continue;
            }

            $existingLine->forceFill(['deleted_by' => auth()->id()])->save();
            $existingLine->delete();
        }

        foreach ($plannedLines as $plannedLine) {
            $existingLine = $plannedLine['existing'];
            $lineValues = $plannedLine['values'];

            if ($existingLine instanceof OpeningStockLine) {
                $existingLine->forceFill([...$lineValues, 'updated_by' => auth()->id(), 'deleted_by' => null]);
                if ($existingLine->trashed()) {
                    $existingLine->restore();
                } else {
                    $existingLine->save();
                }

                continue;
            }

            $record->lines()->create([...$lineValues, 'created_by' => auth()->id()]);
        }
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
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
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
     * @return array<string, mixed>
     */
    private function lineProductSnapshot(?OpeningStockLine $existingLine, Product $product): array
    {
        if (
            $existingLine instanceof OpeningStockLine
            && (int) $existingLine->product_id === (int) $product->getKey()
            && is_array($existingLine->product_snapshot)
            && $existingLine->product_snapshot !== []
        ) {
            return $existingLine->product_snapshot;
        }

        return $this->productSnapshot($product);
    }

    /**
     * @return array<string, string|null>
     */
    private function productSnapshot(Product $product): array
    {
        return [
            'doc_num' => (string) $product->doc_num,
            'name' => (string) $product->name,
            'barcode' => $this->nullableText($product->barcode),
            'unit_label' => $this->unitLabel($product),
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

    private function unitLabel(Product $product): ?string
    {
        $value = trim(implode(' / ', array_filter([$product->unit_doc_num, $product->unit_name])));

        return $value === '' ? null : $value;
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

    private function assertEditable(OpeningStock $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('inventory.opening_stocks.messages.approved_edit_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('inventory.opening_stocks.messages.closed_edit_forbidden'));
        }
    }

    private function assertDeletable(OpeningStock $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('inventory.opening_stocks.messages.approved_delete_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('inventory.opening_stocks.messages.closed_delete_forbidden'));
        }
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function assertInCurrentContext(OpeningStock $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id'] || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
    }

    /**
     * @return array{company_id: int, financial_period_id: int, branch_id: int}
     */
    private function currentContext(): array
    {
        $context = $this->operatingContext->snapshot(request());

        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }

    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('inventory_opening_stocks')));
        }

        $nextNumber = ((int) OpeningStock::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format('inventory_opening_stocks', $nextNumber),
        ];
    }
}
