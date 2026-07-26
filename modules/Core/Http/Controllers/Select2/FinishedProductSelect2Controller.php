<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Select2ResponseService;

class FinishedProductSelect2Controller extends Controller
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly Select2ResponseService $select2,
        private readonly ProductImageResolver $productImages,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canManagePackagingMaterials($request), 403);

        $companyId = $this->companyContext->requireCompanyId($request);
        $query = Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->forCompany($companyId)
            ->finishedProducts()
            ->select([
                'products.id',
                'products.company_id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.barcode',
                'products.image_path',
            ])
            ->orderBy('products.name')
            ->orderBy('products.doc_number');

        $selectedDocNums = $this->selectedDocNums($request);

        if ($selectedDocNums !== []) {
            return response()->json([
                'results' => $query
                    ->whereIn('products.doc_num', $selectedDocNums)
                    ->get()
                    ->map(fn (Product $product): array => $this->item($product, $this->canViewProductImages($request)))
                    ->values()
                    ->all(),
                'pagination' => ['more' => false],
            ]);
        }

        $search = $request->input('q', $request->input('term'));
        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                ],
            ]);
        }

        $canViewProductImages = $this->canViewProductImages($request);

        return response()->json(
            $this->select2->paginated(
                $query,
                $request,
                fn (Product $product): array => $this->item($product, $canViewProductImages),
            ),
        );
    }

    /**
     * @return array{id: string, text: string, imageUrl: string|null}
     */
    private function item(Product $product, bool $canViewProductImages): array
    {
        return [
            'id' => (string) $product->doc_num,
            'text' => trim(implode(' — ', array_filter([$product->doc_num, $product->name]))),
            'imageUrl' => $canViewProductImages ? $this->productImages->url($product) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function selectedDocNums(Request $request): array
    {
        $value = $request->input('selected_doc_nums', $request->input('selected_doc_num', []));
        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }

    private function canManagePackagingMaterials(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('packaging_materials.create')
            || (bool) $user?->can('packaging_materials.edit')
            || (bool) $user?->can('packaging_materials.clone');
    }

    private function canViewProductImages(Request $request): bool
    {
        return (bool) $request->user()?->can('products.view');
    }
}
