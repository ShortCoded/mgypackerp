<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;

class OpeningStockPricingService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly ProductImageResolver $productImages,
        private readonly NumericFormatService $numbers,
        private readonly InventoryOpeningStockPostingService $openingStockPosting,
    ) {}

    public function create(array $data, Request $request): array
    {
        try {
            return DB::transaction(function () use ($data, $request): array {
                $context = $this->currentContext();
                $period = FinancialPeriod::query()
                    ->whereKey($context['financial_period_id'])
                    ->where('company_id', $context['company_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $period || $period->is_closed || ! $period->allows_opening_entries) {
                    throw new DomainException(__('operating_context.validation.financial_period_invalid'));
                }
                $source = $this->openingStockByDocNum($context, $data['opening_stock_doc_num'] ?? null, $request, true);
                if (! $source instanceof OpeningStock || ! $source->approved || $source->status !== OpeningStock::StatusApproved) {
                    throw new DomainException(__('inventory.opening_stock_pricings.messages.opening_stock_unavailable'));
                }

                $publicIds = array_column($data['lines'] ?? [], 'opening_stock_line_public_id');
                $sourceLines = $source->lines()->whereIn('public_id', $publicIds)->where('quantity', '>', 0)->lockForUpdate()->get();
                if ($publicIds === [] || count($publicIds) !== count(array_unique($publicIds)) || $sourceLines->count() !== count($publicIds)
                    || $sourceLines->pluck('product_id')->unique()->count() !== $sourceLines->count()
                    || $this->pricedOpeningStockLineIds($sourceLines->modelKeys())->isNotEmpty()) {
                    throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_line_changed'));
                }

                $record = OpeningStockPricing::query()->create([
                    ...$this->values($data, $context, $source),
                    ...$this->document($data, $context),
                    'created_by' => auth()->id(),
                ]);
                $totalAmount = $this->syncLines($record, $data['lines'], $context);
                $record->forceFill(['total_amount' => $totalAmount])->save();
                $this->openingStockPosting->applyPricing($record);
                $this->audit->clearCreationUpdateAudit($record);

                return ['record' => $record->refresh()->load(['branch', 'branchHall', 'openingStock', 'currency', 'lines.openingStockLine.product'])];
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'inventory_opening_stock_pricing_lines_source_unique_active')) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_line_changed'), previous: $exception);
            }

            throw $exception;
        }
    }

    public function update(OpeningStockPricing $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
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
            $totalAmount = $this->syncLines($record->refresh(), $data['lines'] ?? [], $context);
            $this->audit->saveUpdate($record, [
                'total_amount' => $totalAmount,
                'is_closed' => true,
                'status' => OpeningStockPricing::StatusClosed,
            ]);
            $this->openingStockPosting->applyPricing($record);

            return [
                'record' => $record->refresh()->load(['branch', 'branchHall', 'openingStock', 'currency', 'lines.openingStockLine.product']),
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(OpeningStockPricing $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertInCurrentContext($record, $this->currentContext());
            $this->assertDeletable($record);
            $this->openingStockPosting->clearPricing($record);
            $this->audit->softDelete($record);

            $record->refresh()->lines()->get()->each(function (OpeningStockPricingLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
        });
    }

    public function restore(OpeningStockPricing $record): OpeningStockPricing
    {
        return DB::transaction(function () use ($record): OpeningStockPricing {
            $this->assertInCurrentContext($record, $this->currentContext());
            $deletedAt = $record->deleted_at;
            $restoringLines = $record->lines()
                ->withTrashed()
                ->when($deletedAt, fn ($query) => $query->where('deleted_at', '>=', $deletedAt))
                ->get();
            $sourceLineIds = $restoringLines->pluck('opening_stock_line_id')->all();

            $activeSourceLines = OpeningStockLine::query()->whereIn('id', $sourceLineIds)->orderBy('id')->lockForUpdate()->get();
            if ($activeSourceLines->count() !== count(array_unique($sourceLineIds)) || ! $record->openingStock()->exists()) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_line_changed'));
            }
            if ($this->pricedOpeningStockLineIds($sourceLineIds, $record->getKey())->isNotEmpty()) {
                throw new DomainException(__('inventory.opening_stock_pricings.messages.queue_line_changed'));
            }

            $this->audit->restore($record, auth()->id());
            $restoringLines->each->restore();
            $this->openingStockPosting->applyPricing($record);

            return $record->refresh();
        });
    }

    public function isFullyPriced(OpeningStock $openingStock, ?OpeningStockPricing $current = null): bool
    {
        $lineIds = $openingStock->lines()
            ->where('quantity', '>', 0)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($lineIds === []) {
            return false;
        }

        return $this->pricedOpeningStockLineIds($lineIds, $current?->getKey())->count() >= count($lineIds);
    }

    /**
     * @return Collection<int, int>
     */
    public function pricedOpeningStockLineIds(array $openingStockLineIds, ?int $exceptPricingId = null): Collection
    {
        if ($openingStockLineIds === []) {
            return collect();
        }

        return OpeningStockPricingLine::query()
            ->join('inventory_opening_stock_pricings', 'inventory_opening_stock_pricings.id', '=', 'inventory_opening_stock_pricing_lines.pricing_id')
            ->whereIn('inventory_opening_stock_pricing_lines.opening_stock_line_id', $openingStockLineIds)
            ->whereNull('inventory_opening_stock_pricing_lines.deleted_at')
            ->whereNull('inventory_opening_stock_pricings.deleted_at')
            ->when($exceptPricingId, fn ($query) => $query->where('inventory_opening_stock_pricings.id', '!=', $exceptPricingId))
            ->distinct()
            ->pluck('inventory_opening_stock_pricing_lines.opening_stock_line_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context, ?OpeningStock $source = null): array
    {
        $openingStock = $source ?? $this->openingStockByDocNum($context, $data['opening_stock_doc_num'] ?? null);
        $currency = $this->currencyByDocNum($context['company_id'], $data['currency_doc_num'] ?? null);
        $exchangeRate = $currency?->is_main
            ? '1.000000'
            : ($this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000');

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $openingStock?->branch_id,
            'branch_hall_id' => $openingStock?->branch_hall_id,
            'opening_stock_id' => $openingStock?->getKey(),
            'currency_id' => $currency?->getKey(),
            'exchange_rate' => $exchangeRate,
            'document_date' => $data['document_date'],
            'notes' => $data['notes'] ?? null,
            'is_closed' => true,
            'status' => OpeningStockPricing::StatusClosed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function syncLines(OpeningStockPricing $record, array $lines, array $context): string
    {
        $openingStock = $record->openingStock()->first();
        if (! $openingStock instanceof OpeningStock) {
            return '0.0000';
        }

        $openingLines = $openingStock->lines()
            ->whereNull('deleted_at')
            ->with(['product.unit'])
            ->get()
            ->keyBy('public_id');
        $existingByPublicId = $record->lines()->get()->keyBy('public_id');
        $existingByOpeningLineId = $record->lines()->get()->keyBy('opening_stock_line_id');
        $keptLineIds = [];
        $totalAmount = '0.0000';

        foreach (array_values($lines) as $line) {
            $publicLineId = trim((string) ($line['opening_stock_line_public_id'] ?? ''));
            $openingLine = $openingLines->get($publicLineId);

            if (! $openingLine instanceof OpeningStockLine) {
                continue;
            }

            $unitPriceValue = $this->numbers->normalizeToScale($line['unit_price'] ?? 0, 4) ?? '0.0000';
            $quantityValue = $this->numbers->normalizeToScale($openingLine->quantity, 4) ?? '0.0000';
            $lineTotal = bcround(bcmul($quantityValue, $unitPriceValue, 8), 4);
            $totalAmount = bcadd($totalAmount, $lineTotal, 4);
            $pricingPublicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $pricingPublicId !== ''
                ? $existingByPublicId->get($pricingPublicId)
                : $existingByOpeningLineId->get($openingLine->getKey());
            $snapshot = $this->pricingSnapshot($existingLine, $openingLine);
            $lineValues = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $record->branch_id,
                'opening_stock_line_id' => $openingLine->getKey(),
                'product_id' => $openingLine->product_id,
                'product_snapshot' => $snapshot,
                'quantity' => $quantityValue,
                'unit_price' => $unitPriceValue,
                'line_total' => $lineTotal,
                'notes' => $line['notes'] ?? null,
            ];

            if ($existingLine instanceof OpeningStockPricingLine && (int) $existingLine->pricing_id === (int) $record->getKey()) {
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
            ->each(function (OpeningStockPricingLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });

        return $totalAmount;
    }

    private function pricingSnapshot(?OpeningStockPricingLine $existingLine, OpeningStockLine $openingLine): array
    {
        if (
            $existingLine instanceof OpeningStockPricingLine
            && (int) $existingLine->opening_stock_line_id === (int) $openingLine->getKey()
            && is_array($existingLine->product_snapshot)
            && $existingLine->product_snapshot !== []
        ) {
            return $existingLine->product_snapshot;
        }

        if (is_array($openingLine->product_snapshot) && $openingLine->product_snapshot !== []) {
            return $this->safeSnapshot($openingLine->product_snapshot);
        }

        $product = $this->productForSnapshot((int) $openingLine->product_id);

        return $product instanceof Product ? $this->productSnapshot($product) : [];
    }

    private function safeSnapshot(array $snapshot): array
    {
        return collect($snapshot)
            ->only(['doc_num', 'name', 'barcode', 'unit_label', 'item_classification', 'category', 'group', 'size', 'color', 'model', 'decal', 'origin_country', 'image_url'])
            ->map(fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : null)
            ->all();
    }

    private function productForSnapshot(int $productId): ?Product
    {
        return Product::query()
            ->with('mainImageUsage.file')
            ->whereKey($productId)
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
                'products.doc_num',
                'products.name',
                'products.image_path',
                'products.barcode',
                'products.item_classification',
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

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function openingStockByDocNum(array $context, ?string $docNum, ?Request $request = null, bool $lock = false): ?OpeningStock
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        $allowedBranches = $this->operatingContext->allowedBranchQueryForCurrentCompany($request ?? request())
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->reorder()
            ->select('branches.id');

        return OpeningStock::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->whereIn('branch_id', $allowedBranches)
            ->where('doc_num', $docNum)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    private function currencyByDocNum(int $companyId, ?string $docNum): ?Currency
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Currency::query()
            ->active()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->first();
    }

    private function document(array $data, array $context): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('inventory_opening_stock_pricings', (int) $data['doc_number'])]
            : $this->nextScopedDocument($context['company_id'], $context['financial_period_id']);
    }

    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('inventory_opening_stock_pricings')));
        }

        $nextNumber = ((int) OpeningStockPricing::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format('inventory_opening_stock_pricings', $nextNumber),
        ];
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

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function assertInCurrentContext(OpeningStockPricing $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
    }

    private function assertEditable(OpeningStockPricing $record): void
    {
        if ($record->isClosed()) {
            throw new DomainException(__('inventory.opening_stock_pricings.messages.closed_edit_forbidden'));
        }
    }

    private function assertDeletable(OpeningStockPricing $record): void
    {
        if ($record->isClosed()) {
            throw new DomainException(__('inventory.opening_stock_pricings.messages.closed_delete_forbidden'));
        }
    }
}
