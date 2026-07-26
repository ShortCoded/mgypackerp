<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
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
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->currentContext();
            $record = OpeningStockPricing::query()->create([
                ...$this->values($data, $context),
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $totalAmount = $this->syncLines($record, $data['lines'] ?? [], $context);
            $record->forceFill(['total_amount' => number_format($totalAmount, 4, '.', '')])->save();
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['branch', 'branchHall', 'openingStock', 'currency', 'lines.openingStockLine.product'])];
        });
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
                'total_amount' => number_format($totalAmount, 4, '.', ''),
                'is_closed' => true,
                'status' => OpeningStockPricing::StatusClosed,
            ]);

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

    public function isFullyPriced(OpeningStock $openingStock, ?OpeningStockPricing $current = null): bool
    {
        $lineIds = $openingStock->lines()
            ->whereNull('deleted_at')
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
            ->where('inventory_opening_stock_pricing_lines.unit_price', '>', 0)
            ->when($exceptPricingId, fn ($query) => $query->where('inventory_opening_stock_pricings.id', '!=', $exceptPricingId))
            ->distinct()
            ->pluck('inventory_opening_stock_pricing_lines.opening_stock_line_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context): array
    {
        $branch = $this->branchByDocNum($context['company_id'], $data['branch_doc_num'] ?? null);
        $openingStock = $this->openingStockByDocNum($context, $branch, $data['opening_stock_doc_num'] ?? null);
        $currency = $this->currencyByDocNum($context['company_id'], $data['currency_doc_num'] ?? null);
        $exchangeRate = $currency?->is_main
            ? '1.000000'
            : ($this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000');

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $branch?->getKey(),
            'branch_hall_id' => $this->branchHallId($branch, $data['branch_hall_uuid'] ?? null),
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
    private function syncLines(OpeningStockPricing $record, array $lines, array $context): float
    {
        $openingStock = $record->openingStock()->first();
        if (! $openingStock instanceof OpeningStock) {
            return 0.0;
        }

        $openingLines = $openingStock->lines()
            ->whereNull('deleted_at')
            ->with(['product.unit'])
            ->get()
            ->keyBy('public_id');
        $existingByPublicId = $record->lines()->get()->keyBy('public_id');
        $existingByOpeningLineId = $record->lines()->get()->keyBy('opening_stock_line_id');
        $keptLineIds = [];
        $totalAmount = 0.0;

        foreach (array_values($lines) as $line) {
            $publicLineId = trim((string) ($line['opening_stock_line_public_id'] ?? ''));
            $openingLine = $openingLines->get($publicLineId);

            if (! $openingLine instanceof OpeningStockLine) {
                continue;
            }

            $unitPriceValue = $this->numbers->normalizeToScale($line['unit_price'] ?? 0, 4) ?? '0.0000';
            $quantityValue = $this->numbers->normalizeToScale($openingLine->quantity, 4) ?? '0.0000';
            $unitPrice = (float) $unitPriceValue;
            $quantity = (float) $quantityValue;
            $lineTotal = round($quantity * $unitPrice, 4);
            $totalAmount += $lineTotal;
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
                'line_total' => number_format($lineTotal, 4, '.', ''),
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

    private function branchByDocNum(int $companyId, ?string $docNum): ?Branch
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->where('status', 'active')
            ->whereIn('type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->first();
    }

    private function branchHallId(?Branch $branch, ?string $uuid): ?int
    {
        $uuid = trim((string) $uuid);

        if (! $branch instanceof Branch || $uuid === '') {
            return null;
        }

        return BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->value('id');
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function openingStockByDocNum(array $context, ?Branch $branch, ?string $docNum): ?OpeningStock
    {
        $docNum = trim((string) $docNum);

        if (! $branch instanceof Branch || $docNum === '') {
            return null;
        }

        return OpeningStock::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $branch->getKey())
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
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
