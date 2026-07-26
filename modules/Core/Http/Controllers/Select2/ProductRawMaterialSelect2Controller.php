<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Select2ResponseService;

class ProductRawMaterialSelect2Controller extends Controller
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly Select2ResponseService $select2,
        private readonly ProductImageResolver $productImages,
        private readonly ProductComponentUnitConversionService $unitConversions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseProducts($request), 403);

        $companyId = $this->companyContext->requireCompanyId($request);
        $currentProductDocNum = $request->string('current_product_doc_num')->trim()->toString();
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $search = $request->input('q', $request->input('term'));
        $query = Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->forCompany($companyId)
            ->materialItems()
            ->leftJoin('item_units', function (JoinClause $join) use ($companyId): void {
                $join->on('item_units.id', '=', 'products.item_unit_id')
                    ->where('item_units.company_id', $companyId)
                    ->whereNull('item_units.deleted_at');
            })
            ->leftJoin('item_units as equivalent_units', function (JoinClause $join) use ($companyId): void {
                $join->on('equivalent_units.id', '=', 'products.equivalent_unit_id')
                    ->where('equivalent_units.company_id', $companyId)
                    ->whereNull('equivalent_units.deleted_at');
            })
            ->select([
                'products.id',
                'products.company_id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.barcode',
                'products.image_path',
                'products.item_unit_id',
                'products.equivalent_value',
                'products.equivalent_unit_id',
                'item_units.name as unit_name',
                'item_units.doc_num as unit_doc_num',
                'equivalent_units.name as equivalent_unit_name',
                'equivalent_units.doc_num as equivalent_unit_doc_num',
            ])
            ->orderBy('products.name')
            ->orderBy('products.doc_number');

        if ($currentProductDocNum !== '') {
            $query->where('products.doc_num', '!=', $currentProductDocNum);
        }

        if ($selectedDocNum !== '') {
            $selected = (clone $query)
                ->where('products.doc_num', $selectedDocNum)
                ->first();

            return response()->json([
                'results' => $selected instanceof Product ? [$this->item($selected)] : [],
                'pagination' => ['more' => false],
            ]);
        }

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                    'item_units.name',
                    'item_units.doc_num',
                    'equivalent_units.name',
                    'equivalent_units.doc_num',
                ],
            ]);
        }

        return response()->json($this->select2->paginated($query, $request, fn (Product $product): array => $this->item($product)));
    }

    /**
     * @return array{id: string, text: string, unit_text: string, unit_doc_num: string|null, unit_options: list<array{id: string, text: string}>, unit_conversion_edges: list<array{from: string, to: string, factor: string}>, imageUrl: string|null}
     */
    private function item(Product $product): array
    {
        $unitText = trim(implode(' / ', array_filter([$product->unit_doc_num, $product->unit_name])));
        $barcode = trim((string) ($product->barcode ?? ''));

        return [
            'id' => (string) $product->doc_num,
            'text' => trim(implode(' / ', array_filter([$product->doc_num, $product->name, $barcode === '' ? null : $barcode, $unitText]))),
            'unit_text' => $unitText,
            'unit_doc_num' => $product->unit_doc_num ? (string) $product->unit_doc_num : null,
            'unit_options' => $this->unitOptions($product),
            'unit_conversion_edges' => $this->unitConversions->productEdges($product),
            'imageUrl' => $this->imageUrl($product),
        ];
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    private function unitOptions(Product $product): array
    {
        $options = [];

        foreach ([
            [(string) ($product->unit_doc_num ?? ''), (string) ($product->unit_name ?? '')],
            [(string) ($product->equivalent_unit_doc_num ?? ''), (string) ($product->equivalent_unit_name ?? '')],
        ] as [$docNum, $name]) {
            if ($docNum === '' || isset($options[$docNum])) {
                continue;
            }

            $options[$docNum] = [
                'id' => $docNum,
                'text' => trim(implode(' / ', array_filter([$docNum, $name]))),
            ];
        }

        return array_values($options);
    }

    private function imageUrl(Product $product): ?string
    {
        return $this->productImages->url($product);
    }

    private function canUseProducts(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('products.view')
            || (bool) $user?->can('products.create')
            || (bool) $user?->can('products.edit');
    }
}
