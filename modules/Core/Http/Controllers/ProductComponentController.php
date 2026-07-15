<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Http\Requests\StoreProductComponentRequest;
use Modules\Core\Http\Requests\UpdateProductComponentRequest;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductComponentService;
use Throwable;

class ProductComponentController extends Controller
{
    public function __construct(
        private readonly ProductComponentService $components,
        private readonly ActivityLogger $activityLogger,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function index(Request $request, string $product): JsonResponse
    {
        $product = $this->productByDocNum($request, $product, withTrashed: true);
        $components = ProductComponent::query()
            ->forCompany((int) $product->company_id)
            ->where('product_id', $product->getKey())
            ->with(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProductComponent $component): array => $this->componentPayload($component))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $components,
        ]);
    }

    public function store(StoreProductComponentRequest $request, string $product): JsonResponse
    {
        $product = $this->productByDocNum($request, $product);
        $component = $this->components->create($product, $request->validated());

        $this->logComponentActivity($request, 'product_components.create', $product, $component, actionType: 'create');

        return response()->json([
            'success' => true,
            'message' => __('products.components.created'),
            'data' => $this->componentPayload($component),
        ]);
    }

    public function update(UpdateProductComponentRequest $request, string $product, string $component): JsonResponse
    {
        $product = $this->productByDocNum($request, $product);
        $component = $this->componentByPublicId($request, $product, $component);
        $result = $this->components->update($product, $component, $request->validated());
        $component = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logComponentActivity($request, 'product_components.update', $product, $component, $result['changes'], 'update');

        return response()->json([
            'success' => true,
            'message' => __('products.components.updated'),
            'data' => $this->componentPayload($component),
        ]);
    }

    public function destroy(Request $request, string $product, string $component): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('products.edit'), 403);

        $product = $this->productByDocNum($request, $product);
        $component = $this->componentByPublicId($request, $product, $component);

        $this->components->delete($product, $component);
        $this->logComponentActivity($request, 'product_components.delete', $product, $component, actionType: 'delete');

        return response()->json([
            'success' => true,
            'message' => __('products.components.deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(ProductComponent $component): array
    {
        $component->loadMissing(['componentProduct.unit', 'componentProduct.equivalentUnit', 'unit']);
        $componentProduct = $component->componentProduct;
        $unit = $component->unit ?: $componentProduct?->unit;

        return [
            'public_id' => $component->public_id,
            'component_product_doc_num' => $componentProduct?->doc_num,
            'raw_material' => $this->productLabel($componentProduct),
            'unit' => $this->unitLabel($unit),
            'unit_doc_num' => $unit?->doc_num,
            'unit_options' => $componentProduct instanceof Product ? app(ProductComponentUnitOptionsService::class)->options($componentProduct) : [],
            'quantity' => $this->formattedQuantity($component->quantity),
            'quantity_raw' => (string) $component->quantity,
            'notes' => $component->notes,
        ];
    }

    private function productByDocNum(Request $request, string $docNum, bool $withTrashed = false): Product
    {
        $query = Product::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->withoutRawMaterials()
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function componentByPublicId(Request $request, Product $product, string $publicId): ProductComponent
    {
        return ProductComponent::query()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->where('product_id', $product->getKey())
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function productLabel(?Product $product): ?string
    {
        if (! $product instanceof Product) {
            return null;
        }

        return trim(implode(' / ', array_filter([$product->doc_num, $product->name])));
    }

    private function unitLabel(mixed $unit): ?string
    {
        if (! $unit) {
            return null;
        }

        return trim(implode(' / ', array_filter([$unit->doc_num, $unit->name])));
    }

    private function formattedQuantity(mixed $value): string
    {
        $formatted = number_format((float) $value, 8, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function logComponentActivity(
        Request $request,
        string $event,
        Product $product,
        ProductComponent $component,
        array $changes = [],
        string $actionType = 'create',
    ): void {
        try {
            $component->loadMissing(['componentProduct', 'unit']);
            $componentProduct = $component->componentProduct;

            $this->activityLogger->log($request, 'core', $event, 'success', [
                'properties_only' => true,
                'properties' => [
                    'record' => ActivityLogProperties::record('product_components', $this->productLabel($componentProduct), $component->public_id),
                    'action' => [
                        'type' => $actionType,
                        'label_key' => "product_components.actions.{$actionType}",
                    ],
                    'changes' => ActivityLogProperties::changes($changes),
                    'related' => [
                        'product' => ActivityLogProperties::record('products', $product->name, $product->doc_num),
                        'raw_material' => ActivityLogProperties::record('products', $componentProduct?->name, $componentProduct?->doc_num),
                    ],
                    'meta' => [
                        'quantity' => $this->formattedQuantity($component->quantity),
                        'unit' => $this->unitLabel($component->unit),
                    ],
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
