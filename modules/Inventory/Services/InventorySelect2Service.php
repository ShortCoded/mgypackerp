<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Purchases\Models\Supplier;

class InventorySelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
        private readonly Select2ResponseService $select2,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly ProductImageResolver $productImages,
        private readonly ScreenDataVisibilityService $visibility,
    ) {}

    public function branchHalls(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $branchId = $context['branch_id'];

        $query = BranchHall::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderBy('name');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return $this->select2->paginated($query, $request, fn (BranchHall $hall): array => [
            'id' => (string) $hall->public_uuid,
            'text' => (string) $hall->name,
        ]);
    }

    public function branchStores(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $branchId = $context['branch_id'];
        $branch = $branchId
            ? Branch::query()
                ->whereKey($branchId)
                ->when($context['company_id'], fn ($query) => $query->where('company_id', $context['company_id']), fn ($query) => $query->whereRaw('1 = 0'))
                ->where('type', Branch::TypeFactory)
                ->first()
            : null;

        $query = BranchStore::query()
            ->when($branch instanceof Branch, fn ($query) => $query->where('branch_id', $branch->getKey()), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderBy('name');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return $this->select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->public_uuid,
            'text' => (string) $store->name,
        ]);
    }

    public function products(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $companyId = $context['company_id'];
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $query = $this->productQuery($companyId, $request->user());

        if ($selectedDocNum !== '') {
            $selected = (clone $query)->where('products.doc_num', $selectedDocNum)->first();

            return [
                'results' => $selected instanceof Product ? [$this->productItem($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                    'products.item_classification',
                    'item_units.name',
                    'item_units.doc_num',
                    'item_categories.name',
                    'item_groups.name',
                    'item_models.name',
                    'item_colors.name',
                    'item_sizes.name',
                    'item_decals.name',
                    'item_origin_countries.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Product $product): array => $this->productItem($product));
    }

    public function productDetails(Request $request, string $productDocNum): ?array
    {
        $product = $this->productQuery($this->operatingContext->snapshot($request)['company_id'], $request->user())
            ->where('products.doc_num', $productDocNum)
            ->first();

        return $product instanceof Product ? $this->productData($product) : null;
    }

    public function pricingBranches(Request $request): array
    {
        $query = $this->operatingContext
            ->allowedBranchQueryForCurrentCompany($request)
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->select(['branches.doc_num', 'branches.doc_number', 'branches.name', 'branches.type'])
            ->orderBy('branches.doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['branches.doc_num', 'branches.name', 'branches.type']]);
        }

        return $this->select2->paginated($query, $request, fn (Branch $branch): array => [
            'id' => (string) $branch->doc_num,
            'text' => trim(implode(' / ', array_filter([$branch->doc_num, $branch->name]))),
            'type' => (string) $branch->type,
        ]);
    }

    public function receiptBranches(Request $request): array
    {
        return $this->pricingBranches($request);
    }

    public function receiptBranchHalls(Request $request): array
    {
        return $this->pricingBranchHalls($request);
    }

    public function receiptBranchStores(Request $request): array
    {
        $branch = $this->pricingBranch($request);

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            return $this->empty();
        }

        $query = BranchStore::query()
            ->where('branch_id', $branch->getKey())
            ->whereNull('deleted_at')
            ->select(['public_uuid', 'name', 'position'])
            ->orderBy('position')
            ->orderBy('name');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return $this->select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->public_uuid,
            'text' => (string) $store->name,
        ]);
    }

    public function receiptSuppliers(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if (! $companyId) {
            return $this->empty();
        }

        $query = Supplier::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'name', 'phone', 'mobile', 'email'])
            ->orderBy('name')
            ->orderBy('doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'phone', 'mobile', 'email']]);
        }

        return $this->select2->paginated($query, $request, fn (Supplier $supplier): array => [
            'id' => (string) $supplier->doc_num,
            'text' => trim(implode(' / ', array_filter([$supplier->doc_num, $supplier->name, $supplier->phone ?: $supplier->mobile]))),
        ]);
    }

    public function pricingBranchHalls(Request $request): array
    {
        $branch = $this->pricingBranch($request);

        if (! $branch instanceof Branch || $branch->type !== Branch::TypeFactory) {
            return $this->empty();
        }

        $query = BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->whereNull('deleted_at')
            ->select(['public_uuid', 'name', 'position'])
            ->orderBy('position')
            ->orderBy('name');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return $this->select2->paginated($query, $request, fn (BranchHall $hall): array => [
            'id' => (string) $hall->public_uuid,
            'text' => (string) $hall->name,
        ]);
    }

    public function pricingCurrencies(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if (! $companyId) {
            return $this->empty();
        }

        $query = Currency::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'code', 'name', 'is_main'])
            ->orderByDesc('is_main')
            ->orderBy('code');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'code', 'name']]);
        }

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ]);
    }

    public function pricingOpeningStocks(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $branch = $this->pricingBranch($request);
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $currentPricing = $this->currentPricing($request);

        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $branch instanceof Branch) {
            return $this->empty();
        }

        $query = $this->pricingOpeningStockQuery((int) $context['company_id'], (int) $context['financial_period_id'], $branch, $request, $currentPricing);

        if ($selectedDocNum !== '') {
            $selected = (clone $query)->where('inventory_opening_stocks.doc_num', $selectedDocNum)->first();

            return [
                'results' => $selected instanceof OpeningStock ? [$this->pricingOpeningStockItem($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['inventory_opening_stocks.doc_num', 'inventory_opening_stocks.notes', 'branches.name', 'branch_halls.name']]);
        }

        return $this->select2->paginated($query, $request, fn (OpeningStock $openingStock): array => $this->pricingOpeningStockItem($openingStock));
    }

    public function pricingLines(Request $request): array
    {
        $query = $this->pricingLineQuery($request);
        $selectedPublicId = $request->string('selected_line_public_id')->trim()->toString();

        if (! $query instanceof Builder) {
            return $this->empty();
        }

        if ($selectedPublicId !== '') {
            $selected = (clone $query)->where('inventory_opening_stock_lines.public_id', $selectedPublicId)->first();

            return [
                'results' => $selected instanceof OpeningStockLine ? [$this->pricingLineItem($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (OpeningStockLine $line): array => $this->pricingLineItem($line));
    }

    public function remainingPricingLines(Request $request): array
    {
        $query = $this->pricingLineQuery($request);

        if (! $query instanceof Builder) {
            return ['lines' => []];
        }

        return [
            'lines' => $query
                ->get()
                ->map(fn (OpeningStockLine $line): array => $this->pricingLineItem($line))
                ->values()
                ->all(),
        ];
    }

    private function productQuery(?int $companyId, ?User $user): Builder
    {
        $query = Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->nonService()
            ->when($companyId, fn ($query) => $query->forCompany($companyId), fn ($query) => $query->whereRaw('1 = 0'));

        if ($user instanceof User) {
            $query = $this->visibility->applyAnyScreenToEloquent($query, $user, [Product::ContextProducts, Product::ContextRawMaterials, Product::ContextPackagingMaterials]);
        }

        return $query->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
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
                'products.reorder_point',
                'products.status',
                'products.notes',
                'products.item_unit_id',
                'products.equivalent_unit_id',
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
            ->orderBy('products.name')
            ->orderBy('products.doc_number');
    }

    private function pricingBranch(Request $request): ?Branch
    {
        $branchDocNum = $request->string('branch_doc_num')->trim()->toString();

        if ($branchDocNum === '') {
            return null;
        }

        return $this->operatingContext
            ->allowedBranchQueryForCurrentCompany($request)
            ->where('branches.doc_num', $branchDocNum)
            ->where('branches.status', 'active')
            ->whereIn('branches.type', [Branch::TypeWarehouse, Branch::TypeFactory])
            ->first();
    }

    private function currentPricing(Request $request): ?OpeningStockPricing
    {
        $context = $this->operatingContext->snapshot($request);
        $docNum = $request->string('current_pricing_doc_num')->trim()->toString();

        if ($docNum === '' || ! $context['company_id'] || ! $context['financial_period_id']) {
            return null;
        }

        return OpeningStockPricing::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('doc_num', $docNum)
            ->first();
    }

    private function pricingOpeningStockQuery(int $companyId, int $financialPeriodId, Branch $branch, Request $request, ?OpeningStockPricing $currentPricing): Builder
    {
        $hallUuid = $request->string('branch_hall_uuid')->trim()->toString();
        $currentPricingId = $currentPricing?->getKey();

        return OpeningStock::query()
            ->leftJoin('branches', 'branches.id', '=', 'inventory_opening_stocks.branch_id')
            ->leftJoin('branch_halls', 'branch_halls.id', '=', 'inventory_opening_stocks.branch_hall_id')
            ->where('inventory_opening_stocks.company_id', $companyId)
            ->where('inventory_opening_stocks.financial_period_id', $financialPeriodId)
            ->where('inventory_opening_stocks.branch_id', $branch->getKey())
            ->when($hallUuid !== '', function ($query) use ($hallUuid): void {
                $query->whereExists(function ($subQuery) use ($hallUuid): void {
                    $subQuery->selectRaw('1')
                        ->from('branch_halls as selected_halls')
                        ->whereColumn('selected_halls.id', 'inventory_opening_stocks.branch_hall_id')
                        ->where('selected_halls.public_uuid', $hallUuid)
                        ->whereNull('selected_halls.deleted_at');
                });
            })
            ->whereNull('inventory_opening_stocks.deleted_at')
            ->whereExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('inventory_opening_stock_lines')
                    ->whereColumn('inventory_opening_stock_lines.opening_stock_id', 'inventory_opening_stocks.id')
                    ->whereNull('inventory_opening_stock_lines.deleted_at');
            })
            ->whereNotExists(function ($subQuery) use ($currentPricingId): void {
                $subQuery->selectRaw('1')
                    ->from('inventory_opening_stock_pricings')
                    ->whereColumn('inventory_opening_stock_pricings.opening_stock_id', 'inventory_opening_stocks.id')
                    ->whereNull('inventory_opening_stock_pricings.deleted_at')
                    ->when($currentPricingId, fn ($query) => $query->where('inventory_opening_stock_pricings.id', '!=', $currentPricingId));
            })
            ->select([
                'inventory_opening_stocks.*',
                'branches.name as branch_name',
                'branch_halls.name as hall_name',
            ])
            ->orderByDesc('inventory_opening_stocks.document_date')
            ->orderByDesc('inventory_opening_stocks.doc_number');
    }

    private function pricingLineQuery(Request $request): ?Builder
    {
        $context = $this->operatingContext->snapshot($request);
        $branch = $this->pricingBranch($request);
        $openingStockDocNum = $request->string('opening_stock_doc_num')->trim()->toString();
        $currentPricing = $this->currentPricing($request);

        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $branch instanceof Branch || $openingStockDocNum === '') {
            return null;
        }

        $openingStock = OpeningStock::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $branch->getKey())
            ->where('doc_num', $openingStockDocNum)
            ->whereNull('deleted_at')
            ->first();

        if (! $openingStock instanceof OpeningStock) {
            return null;
        }

        return OpeningStockLine::query()
            ->leftJoin('products', 'products.id', '=', 'inventory_opening_stock_lines.product_id')
            ->with('product.mainImageUsage.file')
            ->where('inventory_opening_stock_lines.opening_stock_id', $openingStock->getKey())
            ->whereNull('inventory_opening_stock_lines.deleted_at')
            ->whereNotExists(function ($subQuery) use ($currentPricing): void {
                $subQuery->selectRaw('1')
                    ->from('inventory_opening_stock_pricing_lines')
                    ->join('inventory_opening_stock_pricings', 'inventory_opening_stock_pricings.id', '=', 'inventory_opening_stock_pricing_lines.pricing_id')
                    ->whereColumn('inventory_opening_stock_pricing_lines.opening_stock_line_id', 'inventory_opening_stock_lines.id')
                    ->whereNull('inventory_opening_stock_pricing_lines.deleted_at')
                    ->whereNull('inventory_opening_stock_pricings.deleted_at')
                    ->when($currentPricing instanceof OpeningStockPricing, fn ($query) => $query->where('inventory_opening_stock_pricings.id', '!=', $currentPricing->getKey()));
            })
            ->select([
                'inventory_opening_stock_lines.*',
                'products.doc_num as product_doc_num',
                'products.name as product_name',
                'products.barcode as product_barcode',
                'products.image_path as product_image_path',
            ])
            ->orderBy('inventory_opening_stock_lines.line_no');
    }

    private function pricingOpeningStockItem(OpeningStock $openingStock): array
    {
        $date = $openingStock->document_date
            ? app(DateFormatService::class)->formatDate($openingStock->document_date, '')
            : null;

        return [
            'id' => (string) $openingStock->doc_num,
            'text' => trim(implode(' / ', array_filter([
                $openingStock->doc_num,
                $date,
                $openingStock->branch_name,
                $openingStock->hall_name,
            ]))),
        ];
    }

    private function pricingLineItem(OpeningStockLine $line): array
    {
        $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
        $productDocNum = $snapshot['doc_num'] ?? $line->product_doc_num;
        $productName = $snapshot['name'] ?? $line->product_name;
        $barcode = $snapshot['barcode'] ?? $line->product_barcode;
        $unitLabel = $snapshot['unit_label'] ?? null;
        $imageUrl = $snapshot['image_url'] ?? ($line->product instanceof Product ? $this->imageUrl($line->product) : null);
        $quantity = $this->formatNumber($line->quantity) ?? '0';
        $productData = [
            'imageUrl' => $imageUrl,
            'doc_num' => $productDocNum,
            'name' => $productName,
            'barcode' => $barcode,
            'unit' => $unitLabel,
            'quantity' => $quantity,
        ];

        return [
            'id' => (string) $line->public_id,
            'text' => trim(implode(' / ', array_filter([$productDocNum, $productName, $barcode, $unitLabel, $quantity]))),
            'imageUrl' => $imageUrl,
            'unitLabel' => $unitLabel,
            'unit_text' => $unitLabel,
            'quantity' => $quantity,
            'productData' => $productData,
        ];
    }

    /**
     * @return array{id: string, text: string, imageUrl: string|null, unitLabel: string, unit_text: string, productData: array<string, mixed>}
     */
    private function productItem(Product $product): array
    {
        $unitLabel = $this->unitLabel($product);
        $barcode = trim((string) $product->barcode);

        return [
            'id' => (string) $product->doc_num,
            'text' => trim(implode(' / ', array_filter([$product->doc_num, $product->name, $barcode === '' ? null : $barcode, $unitLabel]))),
            'imageUrl' => $this->imageUrl($product),
            'unitLabel' => $unitLabel,
            'unit_text' => $unitLabel,
            'unit_options' => $this->unitOptions->options($product),
            'productData' => $this->productData($product),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Product $product): array
    {
        return [
            'imageUrl' => $this->imageUrl($product),
            'doc_num' => (string) $product->doc_num,
            'name' => (string) $product->name,
            'barcode' => $this->nullableText($product->barcode),
            'item_classification' => __("products.classifications.{$product->item_classification}"),
            'unit' => $this->unitLabel($product),
            'unit_options' => $this->unitOptions->options($product),
            'category' => $this->nullableText($product->category_name),
            'group' => $this->nullableText($product->group_name),
            'size' => $this->nullableText($product->size_name),
            'color' => $this->nullableText($product->color_name),
            'decal' => $this->nullableText($product->decal_name),
            'model' => $this->nullableText($product->model_name),
            'origin_country' => $this->nullableText($product->origin_country_name),
            'reorder_point' => $this->formatNumber($product->reorder_point),
            'status' => __("products.statuses.{$product->status}"),
            'notes' => $this->nullableText($product->notes),
        ];
    }

    private function unitLabel(Product $product): string
    {
        return trim(implode(' / ', array_filter([$product->unit_doc_num, $product->unit_name])));
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

    private function formatNumber(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->numbers->format($value);
    }

    private function empty(): array
    {
        return [
            'results' => [],
            'pagination' => ['more' => false],
        ];
    }
}
