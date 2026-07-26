<?php

namespace Modules\Core\Services\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\Select2ResponseService;

class ProductDataReport
{
    public const ModeSummary = 'summary';

    public const ModeDetailed = 'detailed';

    public const ItemScopeAll = 'all';

    public const ItemScopeProducts = 'products';

    public const ItemScopeRawMaterials = 'raw_materials';

    public const ItemScopePackagingMaterials = 'packaging_materials';

    public function __construct(
        private readonly OperatingCompanyContextService $companyContext,
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly DateFormatService $dates,
        private readonly NumericFormatService $numbers,
        private readonly ScreenDataVisibilityService $visibility,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function listingQuery(array $filters = []): Builder
    {
        return $this->mode($filters) === self::ModeDetailed
            ? $this->detailedQuery($filters)
            : $this->summaryQuery($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function summaryQuery(array $filters = []): Builder
    {
        $query = $this->baseProductQuery($filters);

        $this->joinProductLookups($query);

        $query
            ->select($this->productColumns())
            ->withCount('components');

        $this->applyProductFilters($query, $filters, hasComponentJoin: false);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function detailedQuery(array $filters = []): Builder
    {
        $query = $this->baseProductQuery($filters);

        $this->joinProductLookups($query);

        $query
            ->leftJoin('product_components as report_components', function ($join): void {
                $join
                    ->on('report_components.product_id', '=', 'products.id')
                    ->whereNull('report_components.deleted_at');
            })
            ->leftJoin('products as component_products', 'component_products.id', '=', 'report_components.component_product_id')
            ->leftJoin('item_units as component_units', 'component_units.id', '=', 'report_components.unit_id')
            ->leftJoin('item_units as component_product_units', 'component_product_units.id', '=', 'component_products.item_unit_id')
            ->leftJoin('item_units as component_equivalent_units', 'component_equivalent_units.id', '=', 'component_products.equivalent_unit_id')
            ->select([
                ...$this->productColumns(),
                'report_components.id as component_line_id',
                'report_components.public_id as component_public_id',
                'report_components.quantity as component_quantity',
                'report_components.notes as component_notes',
                'report_components.created_at as component_created_at',
                'component_products.doc_num as component_doc_num',
                'component_products.name as component_name',
                'component_products.barcode as component_barcode',
                'component_products.item_classification as component_classification',
                'component_products.equivalent_value as component_equivalent_value',
                'component_equivalent_units.doc_num as component_equivalent_unit_doc_num',
                'component_equivalent_units.name as component_equivalent_unit_name',
                DB::raw('COALESCE(component_units.doc_num, component_product_units.doc_num) as component_unit_doc_num'),
                DB::raw('COALESCE(component_units.name, component_product_units.name) as component_unit_name'),
            ])
            ->withCasts([
                'component_quantity' => 'decimal:8',
                'component_equivalent_value' => 'decimal:6',
            ])
            ->withCount('components');

        $this->applyProductFilters($query, $filters, hasComponentJoin: true);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function orderedQuery(array $filters = []): Builder
    {
        $query = $this->listingQuery($filters);

        if ($this->mode($filters) === self::ModeDetailed) {
            return $query
                ->orderByDesc('products.doc_number')
                ->orderByDesc('products.id')
                ->orderBy('report_components.created_at')
                ->orderBy('report_components.id');
        }

        return $query
            ->orderByDesc('products.doc_number')
            ->orderByDesc('products.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Product>
     */
    public function rows(array $filters = []): Collection
    {
        return $this->orderedQuery($filters)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request): array
    {
        $filters = $request->only([
            'result_mode',
            'item_scope',
            'record_state',
            'status',
            'product_doc_num',
            'doc_num',
            'name',
            'barcode',
            'item_classification',
            'item_category_doc_num',
            'item_group_doc_num',
            'item_model_doc_num',
            'item_size_doc_num',
            'item_color_doc_num',
            'item_decal_doc_num',
            'item_unit_doc_num',
            'item_origin_country_doc_num',
            'components_state',
            'component_product_doc_num',
            'component_unit_doc_num',
            'created_from',
            'created_to',
        ]);

        $filters['result_mode'] = $this->mode($filters);
        $filters['item_scope'] = $this->itemScope($filters);
        $filters['record_state'] = $this->recordState($filters);
        $filters['components_state'] = $this->componentsState($filters);

        if (! $this->classificationAllowedForScope(
            $filters['item_classification'] ?? null,
            $filters['item_scope'],
        )) {
            unset($filters['item_classification']);
        }

        if (! in_array($filters['status'] ?? null, ['active', 'inactive'], true)) {
            unset($filters['status']);
        }

        return array_filter(
            $filters,
            fn (mixed $value): bool => is_array($value) || trim((string) $value) !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function headings(array $filters = []): array
    {
        $base = [
            __('product_data_report.fields.doc_num'),
            __('product_data_report.fields.name'),
            __('product_data_report.fields.item_classification'),
            __('product_data_report.fields.barcode'),
            __('product_data_report.fields.unit'),
            __('product_data_report.fields.category'),
            __('product_data_report.fields.group'),
            __('product_data_report.fields.model'),
            __('product_data_report.fields.size'),
            __('product_data_report.fields.color'),
            __('product_data_report.fields.decal'),
            __('product_data_report.fields.origin_country'),
            __('product_data_report.fields.reorder_point'),
            __('product_data_report.fields.equivalent'),
            __('product_data_report.fields.status'),
            __('product_data_report.fields.components_count'),
            __('product_data_report.fields.created_at'),
        ];

        if ($this->mode($filters) !== self::ModeDetailed) {
            return $base;
        }

        return [
            __('product_data_report.fields.parent_doc_num'),
            __('product_data_report.fields.parent_name'),
            __('product_data_report.fields.parent_classification'),
            __('product_data_report.fields.component_doc_num'),
            __('product_data_report.fields.component_name'),
            __('product_data_report.fields.component_classification'),
            __('product_data_report.fields.component_quantity'),
            __('product_data_report.fields.component_unit'),
            __('product_data_report.fields.component_equivalent'),
            __('product_data_report.fields.component_notes'),
        ];
    }

    /**
     * @return list<mixed>
     */
    public function map(Product $row, array $filters = []): array
    {
        $display = $this->row($row);

        if ($this->mode($filters) === self::ModeDetailed) {
            return [
                $display['doc_num'],
                $display['name'],
                $display['item_classification'],
                $display['component_doc_num'],
                $display['component_name'],
                $display['component_classification'],
                $display['component_quantity'],
                $display['component_unit'],
                $display['component_equivalent'],
                $display['component_notes'],
            ];
        }

        return [
            $display['doc_num'],
            $display['name'],
            $display['item_classification'],
            $display['barcode'],
            $display['unit'],
            $display['category'],
            $display['group'],
            $display['model'],
            $display['size'],
            $display['color'],
            $display['decal'],
            $display['origin_country'],
            $display['reorder_point'],
            $display['equivalent'],
            $display['status'],
            $display['components_count'],
            $display['created_at'],
        ];
    }

    /**
     * @return list<mixed>
     */
    public function exportMap(Product $row, array $filters = []): array
    {
        if ($this->mode($filters) === self::ModeDetailed) {
            return [
                $this->plainText($row->doc_num),
                $this->plainText($row->name),
                $this->classificationLabel($row->item_classification),
                $this->plainText($row->component_doc_num),
                $this->plainText($row->component_name),
                $this->classificationLabel($row->component_classification ?? null),
                $this->canonicalDecimal($row->component_quantity ?? null),
                $this->lookupLabel($row->component_unit_doc_num ?? null, $row->component_unit_name ?? null),
                $this->canonicalEquivalentLabel($row->component_equivalent_value ?? null, $row->component_equivalent_unit_doc_num ?? null, $row->component_equivalent_unit_name ?? null),
                $this->plainText($row->component_notes),
            ];
        }

        $display = $this->row($row);

        return [
            $display['doc_num'],
            $display['name'],
            $display['item_classification'],
            $display['barcode'],
            $display['unit'],
            $display['category'],
            $display['group'],
            $display['model'],
            $display['size'],
            $display['color'],
            $display['decal'],
            $display['origin_country'],
            $this->canonicalDecimal($row->reorder_point ?? null),
            $this->canonicalEquivalentLabel($row->equivalent_value ?? null, $row->equivalent_unit_doc_num ?? null, $row->equivalent_unit_name ?? null),
            $display['status'],
            (int) ($row->components_count ?? 0),
            $display['created_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Product $row): array
    {
        return [
            'doc_num' => $this->plainText($row->doc_num),
            'name' => $this->plainText($row->name),
            'item_classification' => $this->classificationLabel($row->item_classification),
            'barcode' => $this->plainText($row->barcode),
            'unit' => $this->lookupLabel($row->unit_doc_num ?? null, $row->unit_name ?? null),
            'category' => $this->lookupLabel($row->category_doc_num ?? null, $row->category_name ?? null),
            'group' => $this->lookupLabel($row->group_doc_num ?? null, $row->group_name ?? null),
            'model' => $this->lookupLabel($row->model_doc_num ?? null, $row->model_name ?? null),
            'size' => $this->lookupLabel($row->size_doc_num ?? null, $row->size_name ?? null),
            'color' => $this->lookupLabel($row->color_doc_num ?? null, $row->color_name ?? null),
            'decal' => $this->lookupLabel($row->decal_doc_num ?? null, $row->decal_name ?? null),
            'origin_country' => $this->lookupLabel($row->origin_country_doc_num ?? null, $row->origin_country_name ?? null),
            'reorder_point' => $this->quantityLabel($row->reorder_point ?? null),
            'equivalent' => $this->equivalentLabel($row->equivalent_value ?? null, $row->equivalent_unit_doc_num ?? null, $row->equivalent_unit_name ?? null),
            'status' => $this->statusLabel($row),
            'components_count' => $this->numbers->format((int) ($row->components_count ?? 0)),
            'component_doc_num' => $this->plainText($row->component_doc_num),
            'component_name' => $this->plainText($row->component_name),
            'component_classification' => $this->classificationLabel($row->component_classification ?? null),
            'component_quantity' => $this->quantityLabel($row->component_quantity ?? null),
            'component_unit' => $this->lookupLabel($row->component_unit_doc_num ?? null, $row->component_unit_name ?? null),
            'component_equivalent' => $this->equivalentLabel($row->component_equivalent_value ?? null, $row->component_equivalent_unit_doc_num ?? null, $row->component_equivalent_unit_name ?? null),
            'component_notes' => $this->plainText($row->component_notes),
            'created_at' => $this->dates->formatDateTime($row->created_at, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function filterSummary(array $filters): array
    {
        $labels = [];

        $map = [
            'result_mode' => __('product_data_report.filters.result_mode'),
            'item_scope' => __('product_data_report.filters.item_scope'),
            'record_state' => __('product_data_report.filters.record_state'),
            'status' => __('product_data_report.filters.status'),
            'product_doc_num' => __('product_data_report.filters.product'),
            'doc_num' => __('product_data_report.filters.doc_num'),
            'name' => __('product_data_report.filters.name'),
            'barcode' => __('product_data_report.filters.barcode'),
            'item_classification' => __('product_data_report.filters.item_classification'),
            'item_category_doc_num' => __('product_data_report.filters.category'),
            'item_group_doc_num' => __('product_data_report.filters.group'),
            'item_model_doc_num' => __('product_data_report.filters.model'),
            'item_size_doc_num' => __('product_data_report.filters.size'),
            'item_color_doc_num' => __('product_data_report.filters.color'),
            'item_decal_doc_num' => __('product_data_report.filters.decal'),
            'item_unit_doc_num' => __('product_data_report.filters.unit'),
            'item_origin_country_doc_num' => __('product_data_report.filters.origin_country'),
            'components_state' => __('product_data_report.filters.components_state'),
            'component_product_doc_num' => __('product_data_report.filters.component_product'),
            'component_unit_doc_num' => __('product_data_report.filters.component_unit'),
            'created_from' => __('reports.from_date'),
            'created_to' => __('reports.to_date'),
        ];

        foreach ($map as $key => $label) {
            $value = $this->summaryFilterValue($key, $filters[$key] ?? null);

            if ($value !== '') {
                $labels[] = "{$label}: {$value}";
            }
        }

        return $labels;
    }

    /**
     * @return array{results: list<array{id: string, text: string, item_classification: string, item_scope: string}>, pagination: array{more: bool}}
     */
    public function productOptions(Request $request): array
    {
        return $this->productSelectOptions($request, onlyComponents: false);
    }

    /**
     * @return array{results: list<array{id: string, text: string, item_classification: string, item_scope: string}>, pagination: array{more: bool}}
     */
    public function componentOptions(Request $request): array
    {
        return $this->productSelectOptions($request, onlyComponents: true);
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    public function classificationOptions(array $filters = []): array
    {
        $classifications = match ($this->itemScope($filters)) {
            self::ItemScopeProducts => Product::productItemClassifications(),
            self::ItemScopeRawMaterials => [Product::ClassificationRawMaterial],
            self::ItemScopePackagingMaterials => [Product::ClassificationPackaging],
            default => Product::itemClassifications(),
        };

        return array_map(
            fn (string $classification): array => [
                'id' => $classification,
                'text' => $this->classificationLabel($classification),
            ],
            $classifications,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function mode(array $filters): string
    {
        return ($filters['result_mode'] ?? null) === self::ModeDetailed
            ? self::ModeDetailed
            : self::ModeSummary;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function itemScope(array $filters): string
    {
        $scope = $this->stringFilter($filters['item_scope'] ?? null);

        return in_array($scope, [self::ItemScopeAll, self::ItemScopeProducts, self::ItemScopeRawMaterials, self::ItemScopePackagingMaterials], true)
            ? $scope
            : self::ItemScopeAll;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    private function baseProductQuery(array $filters): Builder
    {
        $query = match ($this->recordState($filters)) {
            'deleted' => Product::onlyTrashed(),
            'all' => Product::withTrashed(),
            default => Product::query(),
        };

        $query = $this->companyContext->applyCompanyScope($query, 'products');
        $scope = $this->itemScope($filters);
        $user = request()->user();

        if ($user instanceof User) {
            if ($scope === self::ItemScopeAll) {
                $query = $this->applyCombinedVisibility($query, $user);
            } else {
                $query = $this->visibility->applyToEloquent($query, $user, $scope);
            }
        }

        return $this->applyItemScope($query, $scope);
    }

    /** @param Builder<Product> $query @return Builder<Product> */
    private function applyCombinedVisibility(Builder $query, User $user): Builder
    {
        return $this->visibility->applyAnyScreenToEloquent($query, $user, [
            self::ItemScopeProducts,
            self::ItemScopeRawMaterials,
            self::ItemScopePackagingMaterials,
        ]);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function joinProductLookups(Builder $query): void
    {
        $query
            ->leftJoin('item_units as product_units', 'product_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_units as product_equivalent_units', 'product_equivalent_units.id', '=', 'products.equivalent_unit_id')
            ->leftJoin('item_categories as product_categories', 'product_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups as product_groups', 'product_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_models as product_models', 'product_models.id', '=', 'products.item_model_id')
            ->leftJoin('item_sizes as product_sizes', 'product_sizes.id', '=', 'products.item_size_id')
            ->leftJoin('item_colors as product_colors', 'product_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_decals as product_decals', 'product_decals.id', '=', 'products.item_decal_id')
            ->leftJoin('item_origin_countries as product_origin_countries', 'product_origin_countries.id', '=', 'products.item_origin_country_id');
    }

    /**
     * @return list<string>
     */
    private function productColumns(): array
    {
        return [
            'products.id',
            'products.company_id',
            'products.doc_number',
            'products.doc_num',
            'products.name',
            'products.barcode',
            'products.item_classification',
            'products.reorder_point',
            'products.equivalent_value',
            'products.status',
            'products.created_at',
            'products.deleted_at',
            'product_units.doc_num as unit_doc_num',
            'product_units.name as unit_name',
            'product_equivalent_units.doc_num as equivalent_unit_doc_num',
            'product_equivalent_units.name as equivalent_unit_name',
            'product_categories.doc_num as category_doc_num',
            'product_categories.name as category_name',
            'product_groups.doc_num as group_doc_num',
            'product_groups.name as group_name',
            'product_models.doc_num as model_doc_num',
            'product_models.name as model_name',
            'product_sizes.doc_num as size_doc_num',
            'product_sizes.name as size_name',
            'product_colors.doc_num as color_doc_num',
            'product_colors.name as color_name',
            'product_decals.doc_num as decal_doc_num',
            'product_decals.name as decal_name',
            'product_origin_countries.doc_num as origin_country_doc_num',
            'product_origin_countries.name as origin_country_name',
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyProductFilters(Builder $query, array $filters, bool $hasComponentJoin): void
    {
        $productDocNum = $this->stringFilter($filters['product_doc_num'] ?? null);

        if ($productDocNum !== null) {
            $query->where('products.doc_num', $productDocNum);
        }

        foreach ([
            'doc_num' => 'products.doc_num',
            'name' => 'products.name',
            'barcode' => 'products.barcode',
        ] as $filter => $column) {
            $value = $this->stringFilter($filters[$filter] ?? null);

            if ($value !== null) {
                $query->where($column, 'LIKE', "%{$value}%");
            }
        }

        $classification = $this->stringFilter($filters['item_classification'] ?? null);

        if ($classification !== null && $this->classificationAllowedForScope($classification, $this->itemScope($filters))) {
            $query->where('products.item_classification', $classification);
        }

        $status = $this->stringFilter($filters['status'] ?? null);

        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            $query->where('products.status', $status);
        }

        foreach ($this->lookupFilterColumns() as $filter => $column) {
            $value = $this->stringFilter($filters[$filter] ?? null);

            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        $this->applyDateRange($query, $filters);
        $this->applyComponentsState($query, $filters);
        $this->applyComponentFilters($query, $filters, $hasComponentJoin);
    }

    /**
     * @return array<string, string>
     */
    private function lookupFilterColumns(): array
    {
        return [
            'item_unit_doc_num' => 'product_units.doc_num',
            'item_category_doc_num' => 'product_categories.doc_num',
            'item_group_doc_num' => 'product_groups.doc_num',
            'item_model_doc_num' => 'product_models.doc_num',
            'item_size_doc_num' => 'product_sizes.doc_num',
            'item_color_doc_num' => 'product_colors.doc_num',
            'item_decal_doc_num' => 'product_decals.doc_num',
            'item_origin_country_doc_num' => 'product_origin_countries.doc_num',
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, array $filters): void
    {
        $from = $this->dates->parseDate($this->stringFilter($filters['created_from'] ?? null));
        $to = $this->dates->parseDate($this->stringFilter($filters['created_to'] ?? null));

        if ($from) {
            $query->where('products.created_at', '>=', $from);
        }

        if ($to) {
            $query->where('products.created_at', '<=', $to->copy()->endOfDay());
        }
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyComponentsState(Builder $query, array $filters): void
    {
        match ($this->componentsState($filters)) {
            'with' => $query->whereHas('components'),
            'without' => $query->whereDoesntHave('components'),
            default => null,
        };
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyComponentFilters(Builder $query, array $filters, bool $hasComponentJoin): void
    {
        $componentProductDocNum = $this->stringFilter($filters['component_product_doc_num'] ?? null);

        if ($componentProductDocNum !== null) {
            if ($hasComponentJoin) {
                $query->where('component_products.doc_num', $componentProductDocNum);
            } else {
                $query->whereHas('components.componentProduct', fn (Builder $query): Builder => $query->where('products.doc_num', $componentProductDocNum));
            }
        }

        $componentUnitDocNum = $this->stringFilter($filters['component_unit_doc_num'] ?? null);

        if ($componentUnitDocNum === null) {
            return;
        }

        if ($hasComponentJoin) {
            $query->where(function (Builder $query) use ($componentUnitDocNum): void {
                $query
                    ->where('component_units.doc_num', $componentUnitDocNum)
                    ->orWhere(function (Builder $query) use ($componentUnitDocNum): void {
                        $query
                            ->whereNull('report_components.unit_id')
                            ->where('component_product_units.doc_num', $componentUnitDocNum);
                    });
            });

            return;
        }

        $query->whereHas('components', function (Builder $query) use ($componentUnitDocNum): void {
            $query->where(function (Builder $query) use ($componentUnitDocNum): void {
                $query
                    ->whereHas('unit', fn (Builder $query): Builder => $query->where('item_units.doc_num', $componentUnitDocNum))
                    ->orWhere(function (Builder $query) use ($componentUnitDocNum): void {
                        $query
                            ->whereNull('unit_id')
                            ->whereHas('componentProduct.unit', fn (Builder $query): Builder => $query->where('item_units.doc_num', $componentUnitDocNum));
                    });
            });
        });
    }

    /**
     * @param  Builder<Product>  $query
     * @return list<string>
     */
    public function searchableColumns(Builder $query): array
    {
        $columns = [
            'products.doc_num',
            'products.name',
            'products.barcode',
            'products.item_classification',
            'product_units.doc_num',
            'product_units.name',
            'product_equivalent_units.doc_num',
            'product_equivalent_units.name',
            'product_categories.doc_num',
            'product_categories.name',
            'product_groups.doc_num',
            'product_groups.name',
            'product_models.doc_num',
            'product_models.name',
            'product_sizes.doc_num',
            'product_sizes.name',
            'product_colors.doc_num',
            'product_colors.name',
            'product_decals.doc_num',
            'product_decals.name',
            'product_origin_countries.doc_num',
            'product_origin_countries.name',
        ];

        return str_contains($query->toSql(), 'component_products')
            ? [
                ...$columns,
                'component_products.doc_num',
                'component_products.name',
                'component_products.barcode',
                'component_products.item_classification',
                'component_units.doc_num',
                'component_units.name',
                'component_product_units.doc_num',
                'component_product_units.name',
                'component_equivalent_units.doc_num',
                'component_equivalent_units.name',
            ]
            : $columns;
    }

    /**
     * @return array{results: list<array{id: string, text: string, item_classification: string, item_scope: string}>, pagination: array{more: bool}}
     */
    private function productSelectOptions(Request $request, bool $onlyComponents): array
    {
        $companyId = $this->companyContext->requireCompanyId($request);
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $search = $request->input('q', $request->input('term'));
        $scope = $onlyComponents
            ? self::ItemScopeAll
            : $this->itemScope(['item_scope' => $request->input('item_scope')]);
        $query = Product::query()
            ->active()
            ->forCompany($companyId)
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->select([
                'products.id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.barcode',
                'products.item_classification',
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
            ])
            ->orderBy('products.name')
            ->orderBy('products.doc_number');

        $user = $request->user();

        if ($user instanceof User) {
            $query = $scope === self::ItemScopeAll
                ? $this->applyCombinedVisibility($query, $user)
                : $this->visibility->applyToEloquent($query, $user, $scope);
        }

        if ($onlyComponents) {
            $query->whereHas('usedInComponents', fn (Builder $query): Builder => $query->where('product_components.company_id', $companyId));
        } else {
            $this->applyItemScope($query, $scope);
        }

        if ($selectedDocNum !== '') {
            $selected = (clone $query)
                ->where('products.doc_num', $selectedDocNum)
                ->first();

            return [
                'results' => $selected instanceof Product ? [$this->selectItem($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                    'products.item_classification',
                    'item_units.doc_num',
                    'item_units.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Product $product): array => $this->selectItem($product));
    }

    /**
     * @return array{id: string, text: string, item_classification: string, item_scope: string}
     */
    private function selectItem(Product $product): array
    {
        return [
            'id' => (string) $product->doc_num,
            'item_classification' => (string) $product->item_classification,
            'item_scope' => Product::contextForClassification($product->item_classification),
            'text' => trim(implode(' / ', array_filter([
                $product->doc_num,
                $product->name,
                $product->barcode,
                $this->classificationLabel($product->item_classification),
                $this->lookupLabel($product->unit_doc_num ?? null, $product->unit_name ?? null),
            ]))),
        ];
    }

    private function recordState(array $filters): string
    {
        $state = $this->stringFilter($filters['record_state'] ?? null);

        return in_array($state, ['active', 'deleted', 'all'], true) ? $state : 'active';
    }

    private function componentsState(array $filters): string
    {
        $state = $this->stringFilter($filters['components_state'] ?? null);

        return in_array($state, ['all', 'with', 'without'], true) ? $state : 'all';
    }

    private function summaryFilterValue(string $key, mixed $value): string
    {
        $value = $this->stringFilter($value);

        if ($value === null) {
            return '';
        }

        return match ($key) {
            'result_mode' => __('product_data_report.modes.'.$this->mode(['result_mode' => $value])),
            'item_scope' => __('product_data_report.item_scopes.'.$this->itemScope(['item_scope' => $value])),
            'record_state' => __('product_data_report.record_states.'.$this->recordState(['record_state' => $value])),
            'components_state' => __('product_data_report.components_states.'.$this->componentsState(['components_state' => $value])),
            'item_classification' => $this->classificationLabel($value),
            'status' => __('products.statuses.'.$value),
            default => $value,
        };
    }

    private function lookupLabel(mixed $docNum, mixed $name): string
    {
        return trim(implode(' / ', array_filter([$this->plainText($docNum), $this->plainText($name)])));
    }

    private function plainText(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return Str::squish(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function classificationLabel(mixed $classification): string
    {
        $classification = trim((string) $classification);

        if ($classification === '') {
            return '';
        }

        $key = "products.classifications.{$classification}";

        return trans()->has($key) ? __($key) : $classification;
    }

    private function statusLabel(Product $row): string
    {
        if ($row->trashed()) {
            return __('product_data_report.record_states.deleted');
        }

        $status = trim((string) $row->status);

        return $status === '' ? '' : __("products.statuses.{$status}");
    }

    private function equivalentLabel(mixed $value, mixed $unitDocNum, mixed $unitName): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        return trim(implode(' ', array_filter([
            $this->quantityLabel($value),
            $this->lookupLabel($unitDocNum, $unitName),
        ])));
    }

    private function quantityLabel(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        return $this->numbers->format($value);
    }

    private function canonicalDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->numbers->normalize($value);
    }

    private function canonicalEquivalentLabel(mixed $value, mixed $unitDocNum, mixed $unitName): string
    {
        $decimal = $this->canonicalDecimal($value);

        if ($decimal === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $decimal,
            $this->lookupLabel($unitDocNum, $unitName),
        ])));
    }

    private function stringFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function classificationAllowedForScope(mixed $classification, string $scope): bool
    {
        if (! is_string($classification) || ! in_array($classification, Product::itemClassifications(), true)) {
            return false;
        }

        return match ($scope) {
            self::ItemScopeProducts => in_array($classification, Product::productItemClassifications(), true),
            self::ItemScopeRawMaterials => $classification === Product::ClassificationRawMaterial,
            self::ItemScopePackagingMaterials => $classification === Product::ClassificationPackaging,
            default => true,
        };
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applyItemScope(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            self::ItemScopeProducts => $query->productItems(),
            self::ItemScopeRawMaterials => $query->rawMaterials(),
            self::ItemScopePackagingMaterials => $query->packagingMaterials(),
            default => $query,
        };
    }
}
