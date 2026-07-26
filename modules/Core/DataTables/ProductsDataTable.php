<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class ProductsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly ProductImageResolver $productImages,
        private readonly ScreenDataVisibilityService $visibility,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request, string $context = Product::ContextProducts): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();
        $permissionPrefix = $this->permissionPrefix($context);
        $trashFilter = $this->trashFilter($request, $permissionPrefix);
        $canView = (bool) $request->user()?->can("{$permissionPrefix}.view");
        $query = $this->baseQuery($trashFilter, $context);
        if ($request->user()) {
            $query = $this->visibility->applyToEloquent($query, $request->user(), $permissionPrefix);
        }

        $query->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_colors', 'item_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_decals', 'item_decals.id', '=', 'products.item_decal_id')
            ->leftJoin('item_origin_countries', 'item_origin_countries.id', '=', 'products.item_origin_country_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'products.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'products.updated_by')
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
                'products.cost_as_inventory',
                'products.is_displayable',
                'products.status',
                'products.created_at',
                'products.updated_at',
                'products.deleted_at',
                'item_units.name as unit_name',
                'item_units.doc_num as unit_doc_num',
                'item_categories.name as category_name',
                'item_categories.doc_num as category_doc_num',
                'item_groups.name as group_name',
                'item_groups.doc_num as group_doc_num',
                'item_colors.name as color_name',
                'item_colors.doc_num as color_doc_num',
                'item_decals.name as decal_name',
                'item_decals.doc_num as decal_doc_num',
                'item_origin_countries.name as origin_country_name',
                'item_origin_countries.doc_num as origin_country_doc_num',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $terms = $this->searchService->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
                }
            })
            ->addColumn('checkbox', fn (Product $product): string => view('modules.core.products.partials.checkbox', ['product' => $product, 'productContext' => $context])->render())
            ->editColumn('doc_num', fn (Product $product): string => $this->docNumColumn($product, $canView, $context))
            ->addColumn('image', fn (Product $product): string => $this->imageColumn($product))
            ->editColumn('name', fn (Product $product): string => $this->ellipsisText($product->name))
            ->editColumn('barcode', fn (Product $product): string => $this->barcodeColumn($product->barcode))
            ->editColumn('item_classification', fn (Product $product): string => $this->classificationBadge($product))
            ->editColumn('reorder_point', fn (Product $product): string => $this->plainText($this->formattedQuantity($product->reorder_point)))
            ->addColumn('unit', fn (Product $product): string => $this->lookupLabel($product->unit_doc_num, $product->unit_name))
            ->addColumn('category', fn (Product $product): string => $this->lookupLabel($product->category_doc_num, $product->category_name))
            ->addColumn('group', fn (Product $product): string => $this->lookupLabel($product->group_doc_num, $product->group_name))
            ->addColumn('color', fn (Product $product): string => $this->lookupLabel($product->color_doc_num, $product->color_name))
            ->addColumn('decal', fn (Product $product): string => $this->lookupLabel($product->decal_doc_num, $product->decal_name))
            ->addColumn('origin_country', fn (Product $product): string => $this->lookupLabel($product->origin_country_doc_num, $product->origin_country_name))
            ->editColumn('cost_as_inventory', fn (Product $product): string => $this->booleanBadge((bool) $product->cost_as_inventory))
            ->editColumn('is_displayable', fn (Product $product): string => $this->booleanBadge((bool) $product->is_displayable))
            ->editColumn('status', fn (Product $product): string => $this->badge(__("products.statuses.{$product->status}"), $product->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (Product $product): string => $this->ellipsisText($product->created_by_name))
            ->editColumn('created_at', fn (Product $product): string => $this->plainText($product->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Product $product): string => $this->ellipsisText($product->updated_by_name))
            ->editColumn('updated_at', fn (Product $product): string => $this->plainText($product->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Product $product): string => view('modules.core.products.partials.actions', ['product' => $product, 'productContext' => $context])->render())
            ->orderColumn('doc_num', 'products.doc_number $1')
            ->orderColumn('name', 'products.name $1')
            ->orderColumn('barcode', 'products.barcode $1')
            ->orderColumn('item_classification', 'products.item_classification $1')
            ->orderColumn('reorder_point', 'products.reorder_point $1')
            ->orderColumn('unit', 'item_units.name $1')
            ->orderColumn('category', 'item_categories.name $1')
            ->orderColumn('group', 'item_groups.name $1')
            ->orderColumn('color', 'item_colors.name $1')
            ->orderColumn('decal', 'item_decals.name $1')
            ->orderColumn('origin_country', 'item_origin_countries.name $1')
            ->orderColumn('status', 'products.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'products.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'products.updated_at $1')
            ->removeColumn(
                'id',
                'company_id',
                'doc_number',
                'image_path',
                'deleted_at',
                'unit_name',
                'unit_doc_num',
                'category_name',
                'category_doc_num',
                'group_name',
                'group_doc_num',
                'color_name',
                'color_doc_num',
                'decal_name',
                'decal_doc_num',
                'origin_country_name',
                'origin_country_doc_num',
                'created_by_name',
                'updated_by_name',
                'main_image_usage',
            )
            ->rawColumns(['checkbox', 'doc_num', 'image', 'name', 'barcode', 'item_classification', 'unit', 'category', 'group', 'color', 'decal', 'origin_country', 'cost_as_inventory', 'is_displayable', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return Builder<Product>
     */
    private function baseQuery(string $trashFilter, string $context): Builder
    {
        $query = match ($trashFilter) {
            'trashed' => Product::onlyTrashed(),
            'all' => Product::withTrashed(),
            default => Product::query(),
        };

        return $this->companyContext->applyCompanyScope($query, 'products')
            ->forProductContext($context)
            ->with('mainImageUsage.file');
    }

    private function trashFilter(Request $request, string $permissionPrefix): string
    {
        if (! $request->user()?->can("{$permissionPrefix}.view_trashed")) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function permissionPrefix(string $context): string
    {
        return match ($context) {
            Product::ContextRawMaterials => 'raw_materials',
            Product::ContextPackagingMaterials => 'packaging_materials',
            default => 'products',
        };
    }

    private function docNumColumn(Product $product, bool $canView, string $context): string
    {
        $docNum = (string) $product->doc_num;

        if ($docNum === '') {
            return '';
        }

        if (! $canView) {
            return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($docNum));
        }

        return sprintf(
            '<a class="fw-semibold dt-code-value" dir="ltr" href="%s">%s</a>',
            e(route($this->routeName($context, 'show'), $docNum)),
            e($docNum),
        );
    }

    private function routeName(string $context, string $action): string
    {
        return match ($context) {
            Product::ContextRawMaterials => 'admin.raw-materials.'.$action,
            Product::ContextPackagingMaterials => 'admin.packaging-materials.'.$action,
            default => 'admin.products.'.$action,
        };
    }

    private function lookupLabel(?string $docNum, ?string $name): string
    {
        return $this->ellipsisText(trim(implode(' / ', array_filter([$docNum, $name]))));
    }

    private function imageColumn(Product $product): string
    {
        $label = trim((string) $product->name) ?: __('products.attributes.image');
        $url = $this->productImages->url($product);

        if ($url === null) {
            return sprintf(
                '<div class="d-flex align-items-center justify-content-center"><span class="product-table-image product-table-image-placeholder bg-white border rounded-2 d-inline-flex align-items-center justify-content-center" title="%s" data-bs-title="%s"><span class="fas fa-image text-400 fs-9" aria-hidden="true"></span><span class="visually-hidden">%s</span></span></div>',
                e(__('products.image.no_file_selected')),
                e(__('products.image.no_file_selected')),
                e(__('products.image.no_file_selected')),
            );
        }

        return sprintf(
            '<div class="d-flex align-items-center justify-content-center"><button type="button" class="btn btn-link p-0 border-0 product-table-image-trigger js-product-image-preview" title="%s" data-bs-title="%s" aria-label="%s" data-product-image-url="%s" data-product-name="%s"><span class="product-table-image bg-white border rounded-2 overflow-hidden d-inline-flex align-items-center justify-content-center"><img class="h-100 w-100 object-fit-cover" src="%s" alt="%s"><span class="visually-hidden">%s</span></span></button></div>',
            e(__('products.image.preview')),
            e(__('products.image.preview')),
            e(__('products.image.preview')),
            e($url),
            e($label),
            e($url),
            e($label),
            e(__('products.image.preview')),
        );
    }

    private function barcodeColumn(mixed $barcode): string
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '') {
            return '';
        }

        return sprintf('<span class="dt-code-value" dir="ltr">%s</span>', e($barcode));
    }

    private function formattedQuantity(mixed $value): string
    {
        return $this->numbers->format($value);
    }

    private function booleanBadge(bool $value): string
    {
        return $this->badge($value ? __('common.actions.yes') : __('common.actions.no'), $value ? 'success' : 'secondary');
    }

    private function classificationBadge(Product $product): string
    {
        $classification = $product->item_classification ?: Product::ClassificationFinishedProduct;

        return $this->badge(
            __('products.classifications.'.$classification),
            $classification === Product::ClassificationRawMaterial ? 'warning' : 'info',
        );
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'products.doc_num',
                'products.name',
                'products.barcode',
                'products.item_classification',
                'products.status',
                'item_units.name',
                'item_units.doc_num',
                'item_categories.name',
                'item_categories.doc_num',
                'item_groups.name',
                'item_groups.doc_num',
                'item_colors.name',
                'item_colors.doc_num',
                'item_decals.name',
                'item_decals.doc_num',
                'item_origin_countries.name',
                'item_origin_countries.doc_num',
                'created_users.name',
                'updated_users.name',
            ],
            'dates' => [
                'products.created_at',
                'products.updated_at',
            ],
            'date_text' => [
                'products.created_at',
                'products.updated_at',
            ],
        ];
    }
}
