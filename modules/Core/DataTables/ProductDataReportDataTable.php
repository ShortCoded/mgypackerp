<?php

namespace Modules\Core\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Reports\ProductDataReport;
use Yajra\DataTables\Facades\DataTables;

class ProductDataReportDataTable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $rowCache = [];

    public function __construct(
        private readonly ProductDataReport $report,
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $this->rowCache = [];
        $filters = $this->report->filtersFromRequest($request);
        $query = $this->report->listingQuery($filters);
        $searchColumns = $this->report->searchableColumns($query);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $terms = $this->searchService->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->searchService->applyMultiTermSearch($query, $terms, ['text' => $searchColumns]);
                }
            })
            ->editColumn('doc_num', fn (Product $product): string => $this->text($this->row($product)['doc_num']))
            ->editColumn('name', fn (Product $product): string => $this->text($this->row($product)['name']))
            ->editColumn('item_classification', fn (Product $product): string => $this->classificationBadge($product->item_classification, $this->row($product)['item_classification']))
            ->editColumn('barcode', fn (Product $product): string => $this->text($this->row($product)['barcode']))
            ->addColumn('unit', fn (Product $product): string => $this->text($this->row($product)['unit']))
            ->addColumn('category', fn (Product $product): string => $this->text($this->row($product)['category']))
            ->addColumn('group', fn (Product $product): string => $this->text($this->row($product)['group']))
            ->addColumn('model', fn (Product $product): string => $this->text($this->row($product)['model']))
            ->addColumn('size', fn (Product $product): string => $this->text($this->row($product)['size']))
            ->addColumn('color', fn (Product $product): string => $this->text($this->row($product)['color']))
            ->addColumn('decal', fn (Product $product): string => $this->text($this->row($product)['decal']))
            ->addColumn('origin_country', fn (Product $product): string => $this->text($this->row($product)['origin_country']))
            ->editColumn('reorder_point', fn (Product $product): string => $this->text($this->row($product)['reorder_point']))
            ->addColumn('equivalent', fn (Product $product): string => $this->text($this->row($product)['equivalent']))
            ->editColumn('status', fn (Product $product): string => $this->statusBadge($product, $this->row($product)['status']))
            ->editColumn('components_count', fn (Product $product): string => $this->text($this->row($product)['components_count']))
            ->editColumn('component_doc_num', fn (Product $product): string => $this->text($this->row($product)['component_doc_num']))
            ->editColumn('component_name', fn (Product $product): string => $this->text($this->row($product)['component_name']))
            ->editColumn('component_classification', fn (Product $product): string => $this->classificationBadge($product->component_classification, $this->row($product)['component_classification']))
            ->editColumn('component_quantity', fn (Product $product): string => $this->text($this->row($product)['component_quantity']))
            ->editColumn('component_unit', fn (Product $product): string => $this->text($this->row($product)['component_unit']))
            ->editColumn('component_equivalent', fn (Product $product): string => $this->text($this->row($product)['component_equivalent']))
            ->editColumn('component_notes', fn (Product $product): string => $this->text($this->row($product)['component_notes']))
            ->editColumn('created_at', fn (Product $product): string => $this->text($this->row($product)['created_at']))
            ->orderColumn('doc_num', 'products.doc_number $1')
            ->orderColumn('name', 'products.name $1')
            ->orderColumn('item_classification', 'products.item_classification $1')
            ->orderColumn('barcode', 'products.barcode $1')
            ->orderColumn('unit', 'product_units.name $1')
            ->orderColumn('category', 'product_categories.name $1')
            ->orderColumn('group', 'product_groups.name $1')
            ->orderColumn('model', 'product_models.name $1')
            ->orderColumn('size', 'product_sizes.name $1')
            ->orderColumn('color', 'product_colors.name $1')
            ->orderColumn('decal', 'product_decals.name $1')
            ->orderColumn('origin_country', 'product_origin_countries.name $1')
            ->orderColumn('reorder_point', 'products.reorder_point $1')
            ->orderColumn('status', 'products.deleted_at $1, products.status $1')
            ->orderColumn('components_count', 'components_count $1')
            ->orderColumn('component_doc_num', 'component_products.doc_num $1')
            ->orderColumn('component_name', 'component_products.name $1')
            ->orderColumn('component_classification', 'component_products.item_classification $1')
            ->orderColumn('component_quantity', 'report_components.quantity $1')
            ->orderColumn('component_unit', 'component_unit_name $1')
            ->orderColumn('created_at', 'products.created_at $1')
            ->removeColumn(
                'id',
                'company_id',
                'doc_number',
                'deleted_at',
                'unit_doc_num',
                'unit_name',
                'equivalent_unit_doc_num',
                'equivalent_unit_name',
                'category_doc_num',
                'category_name',
                'group_doc_num',
                'group_name',
                'model_doc_num',
                'model_name',
                'size_doc_num',
                'size_name',
                'color_doc_num',
                'color_name',
                'decal_doc_num',
                'decal_name',
                'origin_country_doc_num',
                'origin_country_name',
                'component_line_id',
                'component_public_id',
                'component_barcode',
                'component_created_at',
                'component_equivalent_value',
                'component_equivalent_unit_doc_num',
                'component_equivalent_unit_name',
                'component_unit_doc_num',
                'component_unit_name',
            )
            ->rawColumns(['item_classification', 'status', 'component_classification'])
            ->toJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        $key = implode(':', [
            (string) ($product->getKey() ?? ''),
            (string) ($product->component_line_id ?? ''),
            (string) ($product->component_doc_num ?? ''),
        ]);

        return $this->rowCache[$key] ??= $this->report->row($product);
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function classificationBadge(mixed $classification, mixed $label): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            return '';
        }

        $class = match ((string) $classification) {
            Product::ClassificationFinishedProduct => 'success',
            Product::ClassificationSemiFinished => 'info',
            Product::ClassificationPackaging => 'primary',
            Product::ClassificationRawMaterial => 'warning',
            Product::ClassificationService => 'secondary',
            default => 'dark',
        };

        return $this->badge($label, $class);
    }

    private function statusBadge(Product $product, mixed $label): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            return '';
        }

        return $this->badge($label, $product->trashed() ? 'danger' : ($product->status === 'active' ? 'success' : 'secondary'));
    }

    private function badge(string $label, string $class): string
    {
        return '<span class="badge badge-subtle-'.$class.'">'.e($label).'</span>';
    }
}
