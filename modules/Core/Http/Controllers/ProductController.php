<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\ProductsDataTable;
use Modules\Core\Http\Requests\BulkDeleteProductsRequest;
use Modules\Core\Http\Requests\StoreProductRequest;
use Modules\Core\Http\Requests\UpdateProductDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateProductRequest;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ItemLookupSelect2Service;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductDocumentNumberSettingsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\ProductService;
use Modules\Core\Services\SettingService;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly ProductImageResolver $productImages,
        private readonly NumericFormatService $numbers,
        private readonly ProductComponentUnitConversionService $unitConversions,
    ) {}

    public function index(Request $request, ProductDocumentNumberSettingsService $documentNumberSettings): View
    {
        $context = $this->productContext($request);

        return view('modules.core.products.index', [
            'productContext' => $context,
            'isRawMaterialsContext' => $this->isRawMaterialsContext($context),
            'isMaterialContext' => $this->isMaterialContext($context),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($this->routeName($context, 'index')),
            'documentNumberSettings' => $documentNumberSettings->current($this->documentNumberKey($context)),
            'routes' => $this->resourceRoutes($context),
        ]);
    }

    public function data(Request $request, ProductsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request, $this->productContext($request));
    }

    public function create(Request $request): View
    {
        $createDefaults = $request->boolean('purchase_asset') ? [
            'item_classification' => Product::ClassificationOther,
            'cost_as_inventory' => false,
            'is_displayable' => false,
            'tracks_expiry' => false,
        ] : [];

        return $this->formView('create', context: $this->productContext($request), createDefaults: $createDefaults);
    }

    public function show(Request $request, string $product): View
    {
        $product = $this->recordByDocNum($request, $product, withTrashed: true);
        $this->abortIfTrashedRecordIsNotViewable($request, $product);

        return $this->formView('view', $product, context: $this->productContext($request));
    }

    public function edit(Request $request, string $product): View
    {
        $product = $this->recordByDocNum($request, $product);

        return $this->formView('edit', $product, context: $this->productContext($request));
    }

    public function clone(Request $request, string $product): View
    {
        $product = $this->recordByDocNum($request, $product);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $product->doc_num);

        return $this->formView('clone', $product, $cloneSourceToken, $this->productContext($request));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $context = $this->productContext($request);
        $this->authorizeSubmitAction($request, $submitAction, $context, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $result = $this->products->create($request->validated(), $cloneSource);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);
            throw $exception;
        }

        if ($cloneSource instanceof Product) {
            $request->session()->forget(
                $this->cloneSourceSessionKey($request->string('clone_source_token')->trim()->toString()),
            );
        }

        $record = $result['record'];

        $this->logActivity($request, $cloneSource instanceof Product ? 'products.clone' : 'products.create', ActivityLogProperties::crudCreated(
            'products',
            $record->name,
            $record->doc_num,
            $this->submitActionProperties($request, creating: true),
        ));

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof Product ? $this->resourceText($context, 'messages.cloned') : $this->resourceText($context, 'messages.created'),
            ...$this->saveActionResponse($request, $record, 'store', $context),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'image_url' => $this->imageUrl($record),
                'components' => $this->componentRows('edit', $record, $context),
                'urls' => $this->recordUrls($record, $context),
            ],
        ]);
    }

    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        $product = $this->recordByDocNum($request, $product);
        $context = $this->productContext($request);
        $this->authorizeSubmitAction($request, $this->submitAction($request), $context);

        try {
            $result = $this->products->update($product, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);
            throw $exception;
        }

        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, 'products.update', ActivityLogProperties::crudUpdated(
            'products',
            $record->name,
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        return response()->json([
            'success' => true,
            'message' => $this->resourceText($context, 'messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update', $context),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'image_url' => $this->imageUrl($record),
                'components' => $this->componentRows('edit', $record, $context),
                'urls' => $this->recordUrls($record, $context),
            ],
        ]);
    }

    public function destroy(Request $request, string $product): JsonResponse
    {
        $product = $this->recordByDocNum($request, $product);
        $this->products->delete($product);
        $this->logActivity($request, 'products.delete', ActivityLogProperties::crudDeleted('products', $product->name, $product->doc_num));

        return response()->json(['success' => true, 'message' => $this->resourceText($this->productContext($request), 'messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteProductsRequest $request): JsonResponse
    {
        $docNums = $request->validated()['doc_nums'];
        $context = $this->productContext($request);
        $deleted = $this->products->bulkDelete($docNums, $context);

        $this->logActivity($request, 'products.bulk_delete', ActivityLogProperties::bulkDeleted('products', $deleted, $docNums));

        return response()->json([
            'success' => true,
            'message' => $this->resourceText($context, 'messages.bulk_deleted', ['count' => $deleted]),
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function restore(Request $request, string $product): JsonResponse
    {
        $record = $this->restoreRecordByDocNum($request, $product);
        $context = $this->productContext($request);

        try {
            $record = $this->products->restore($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'products.restore', ActivityLogProperties::crudRestored('products', $record->name, $record->doc_num));

        if ($request->hasSession()) {
            $request->session()->forget(['_old_input', 'errors']);
        }

        return response()->json(['success' => true, 'message' => $this->resourceText($context, 'messages.restored')]);
    }

    public function updateDocumentNumberSettings(
        UpdateProductDocumentNumberSettingsRequest $request,
        ProductDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $context = $this->productContext($request);
        $resource = $this->activityResource($context);
        $result = $documentNumberSettings->update($this->documentNumberKey($context), $request->validated('prefix'), (int) $request->validated('padding'));

        $this->logActivity($request, "{$resource}.document_number_settings.update", ActivityLogProperties::settingsUpdated($resource, [
            'prefix' => ['old' => $result['old']['prefix'], 'new' => $result['new']['prefix']],
            'padding' => ['old' => $result['old']['padding'], 'new' => $result['new']['padding']],
        ]));

        return response()->json([
            'success' => true,
            'message' => $this->resourceText($context, 'document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function image(Request $request, string $product)
    {
        $record = $this->recordByDocNum($request, $product, enforceContext: false);
        abort_unless(
            (bool) $request->user()?->can($this->permission(Product::contextForClassification($record->item_classification), 'view')),
            403,
        );
        $response = $this->productImages->response($record);

        abort_unless($response !== null, 404);

        return $response;
    }

    private function formView(
        string $mode,
        ?Product $record = null,
        ?string $cloneSourceToken = null,
        string $context = Product::ContextProducts,
        array $createDefaults = [],
    ): View {
        $settings = app(ProductDocumentNumberSettingsService::class)->current($this->documentNumberKey($context));
        $relationships = ['unit', 'equivalentUnit', 'size', 'color', 'decal', 'itemModel', 'originCountry', 'category', 'group', 'mainImageUsage.file'];

        if (! $this->isMaterialContext($context)) {
            $relationships[] = 'components.componentProduct.unit';
            $relationships[] = 'components.componentProduct.equivalentUnit';
            $relationships[] = 'components.componentProduct.mainImageUsage.file';
            $relationships[] = 'components.unit';
            $relationships[] = 'components.referenceComponent';
        }

        $record?->loadMissing($relationships);

        if ($context === Product::ContextPackagingMaterials && $record instanceof Product) {
            $record->setRelation(
                'relatedFinishedProducts',
                $record->relatedFinishedProducts()
                    ->withTrashed()
                    ->forCompany($this->companyContext->requireCompanyId())
                    ->with('mainImageUsage.file')
                    ->orderBy('products.doc_number')
                    ->orderBy('products.id')
                    ->get(),
            );
        }

        return view('modules.core.products.form', [
            'mode' => $mode,
            'productContext' => $context,
            'isRawMaterialsContext' => $this->isRawMaterialsContext($context),
            'isMaterialContext' => $this->isMaterialContext($context),
            'product' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route($this->routeName($context, 'store')) : route($this->routeName($context, 'update'), $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberSettings' => $settings,
            'canControlDocumentNumber' => (bool) auth()->user()?->can($this->permission($context, 'document_number.control')),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record, $context),
            'cloneSourceToken' => $cloneSourceToken,
            'lookupOptions' => $this->lookupOptions($record),
            'relatedFinishedProductOptions' => $this->relatedFinishedProductOptions($record, $context),
            'componentRows' => $this->componentRows($mode, $record, $context),
            'componentUnitConversionEdges' => $this->unitConversions->globalEdgesForCompany(
                $this->companyContext->requireCompanyId(),
            ),
            'recordNavigation' => $this->recordNavigation($mode, $record, $context),
            'routes' => $this->resourceRoutes($context),
            'createDefaults' => $createDefaults,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function componentRows(string $mode, ?Product $record, string $context): array
    {
        if (! $record instanceof Product || $this->isMaterialContext($context)) {
            return [];
        }

        $components = $record->components->values();
        $clientKeys = $components->mapWithKeys(fn (ProductComponent $component): array => [
            (string) $component->public_id => $mode === 'clone'
                ? (string) Str::uuid()
                : (string) $component->public_id,
        ]);

        return $components
            ->map(fn (ProductComponent $component): array => $this->componentPayload(
                $component,
                clientKey: $clientKeys[(string) $component->public_id],
                referenceKey: $component->referenceComponent instanceof ProductComponent
                    ? $clientKeys[(string) $component->referenceComponent->public_id]
                    : null,
                forClone: $mode === 'clone',
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(
        ProductComponent $component,
        string $clientKey,
        ?string $referenceKey,
        bool $forClone = false,
    ): array {
        $componentProduct = $component->componentProduct;
        $unit = $component->unit ?: $componentProduct?->unit;

        return [
            'public_id' => $forClone ? '' : $component->public_id,
            'client_key' => $clientKey,
            'component_product_doc_num' => $componentProduct?->doc_num,
            'raw_material' => $this->componentProductLabel($componentProduct),
            'imageUrl' => $componentProduct instanceof Product ? $this->imageUrl($componentProduct) : null,
            'unit' => $this->componentUnitLabel($unit),
            'unit_doc_num' => $unit?->doc_num,
            'unit_options' => $componentProduct instanceof Product ? app(ProductComponentUnitOptionsService::class)->options($componentProduct) : [],
            'unit_conversion_edges' => $componentProduct instanceof Product
                ? $this->unitConversions->productEdges($componentProduct)
                : [],
            'calculation_method' => $component->calculation_method,
            'quantity' => $this->formattedQuantity($component->quantity),
            'quantity_raw' => (string) $component->quantity,
            'percentage' => $component->percentage === null ? null : $this->formattedQuantity($component->percentage),
            'percentage_raw' => $component->percentage === null ? null : (string) $component->percentage,
            'reference_component_key' => $referenceKey,
            'input_source' => $component->calculation_method === ProductComponent::CalculationPercentage
                ? ProductComponent::InputPercentage
                : ProductComponent::InputWeight,
            'notes' => $component->notes,
        ];
    }

    /**
     * @return array{previous: string|null, next: string|null}
     */
    private function recordNavigation(string $mode, ?Product $record, string $context): array
    {
        if ($mode !== 'edit'
            || ! $record instanceof Product
            || ! auth()->user()?->can($this->permission($context, 'edit'))
            || $record->doc_number === null
        ) {
            return ['previous' => null, 'next' => null];
        }

        $previous = $this->adjacentProduct($record, previous: true, context: $context);
        $next = $this->adjacentProduct($record, previous: false, context: $context);

        return [
            'previous' => $previous instanceof Product ? route($this->routeName($context, 'edit'), $previous->doc_num) : null,
            'next' => $next instanceof Product ? route($this->routeName($context, 'edit'), $next->doc_num) : null,
        ];
    }

    private function adjacentProduct(Product $record, bool $previous, string $context): ?Product
    {
        $query = Product::query()
            ->forCompany((int) $record->company_id)
            ->forProductContext($context)
            ->select(['id', 'doc_number', 'doc_num'])
            ->where(function ($query) use ($record, $previous): void {
                if ($previous) {
                    $query
                        ->where('doc_number', '>', $record->doc_number)
                        ->orWhere(function ($query) use ($record): void {
                            $query
                                ->where('doc_number', $record->doc_number)
                                ->where($record->getTable().'.'.$record->getKeyName(), '>', $record->getKey());
                        });

                    return;
                }

                $query
                    ->where('doc_number', '<', $record->doc_number)
                    ->orWhere(function ($query) use ($record): void {
                        $query
                            ->where('doc_number', $record->doc_number)
                            ->where($record->getTable().'.'.$record->getKeyName(), '<', $record->getKey());
                    });
            });

        if ($previous) {
            $query->orderBy('doc_number')->orderBy('id');
        } else {
            $query->orderByDesc('doc_number')->orderByDesc('id');
        }

        return $query->first();
    }

    private function componentProductLabel(?Product $product): ?string
    {
        return $product ? trim(implode(' / ', array_filter([$product->doc_num, $product->name]))) : null;
    }

    private function componentUnitLabel(mixed $unit): ?string
    {
        if (! $unit) {
            return null;
        }

        return trim(implode(' / ', array_filter([$unit->doc_num, $unit->name])));
    }

    private function formattedQuantity(mixed $value): string
    {
        return $this->numbers->format($value);
    }

    /**
     * @return array<string, array{id: string, text: string}|null>
     */
    private function lookupOptions(?Product $record): array
    {
        $select2 = app(ItemLookupSelect2Service::class);

        return [
            'unit' => $record?->unit ? $select2->item($record->unit) : null,
            'equivalent_unit' => $record?->equivalentUnit ? $select2->item($record->equivalentUnit) : null,
            'size' => $record?->size ? $select2->item($record->size) : null,
            'color' => $record?->color ? $select2->item($record->color) : null,
            'decal' => $record?->decal ? $select2->item($record->decal) : null,
            'model' => $record?->itemModel ? $select2->item($record->itemModel) : null,
            'origin_country' => $record?->originCountry ? $select2->item($record->originCountry) : null,
            'category' => $record?->category ? $select2->item($record->category) : null,
            'group' => $record?->group ? $select2->item($record->group) : null,
        ];
    }

    /**
     * @return list<array{id: string, text: string, image_url: string|null, is_stale: bool}>
     */
    private function relatedFinishedProductOptions(?Product $record, string $context): array
    {
        if ($context !== Product::ContextPackagingMaterials) {
            return [];
        }

        $oldInput = session()->get('_old_input', []);
        $hasOldInput = is_array($oldInput) && array_key_exists('related_finished_product_doc_nums', $oldInput);
        $docNums = $hasOldInput
            ? $this->relatedFinishedProductDocNumsFromInput($oldInput['related_finished_product_doc_nums'])
            : ($record instanceof Product
                ? $record->relatedFinishedProducts
                    ->pluck('doc_num')
                    ->filter()
                    ->map(fn (mixed $docNum): string => (string) $docNum)
                    ->values()
                    ->all()
                : []);

        if ($docNums === []) {
            return [];
        }

        $companyId = $this->companyContext->requireCompanyId();
        $records = Product::withTrashed()
            ->forCompany($companyId)
            ->whereIn('doc_num', $docNums)
            ->with('mainImageUsage.file')
            ->get()
            ->keyBy('doc_num');

        return collect($docNums)
            ->map(function (string $docNum) use ($records): array {
                /** @var Product|null $product */
                $product = $records->get($docNum);

                if (! $product instanceof Product) {
                    return [
                        'id' => $docNum,
                        'text' => __('products.packaging_materials.related_finished_products.unavailable_selection', ['doc_num' => $docNum]),
                        'image_url' => null,
                        'is_stale' => true,
                    ];
                }

                return [
                    'id' => (string) $product->doc_num,
                    'text' => trim(implode(' — ', array_filter([$product->doc_num, $product->name]))),
                    'image_url' => $this->canViewFinishedProductImages() ? $this->productImages->url($product) : null,
                    'is_stale' => $product->trashed()
                        || $product->status !== 'active'
                        || $product->item_classification !== Product::ClassificationFinishedProduct,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function relatedFinishedProductDocNumsFromInput(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return collect($input)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }

    private function canViewFinishedProductImages(): bool
    {
        return (bool) auth()->user()?->can('products.view');
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(Product $record, string $context): array
    {
        return [
            'show' => route($this->routeName($context, 'show'), $record->doc_num),
            'clone' => route($this->routeName($context, 'clone'), $record->doc_num),
            'edit' => route($this->routeName($context, 'edit'), $record->doc_num),
            'update' => route($this->routeName($context, 'update'), $record->doc_num),
            'destroy' => route($this->routeName($context, 'destroy'), $record->doc_num),
            'restore' => route($this->routeName($context, 'restore'), $record->doc_num),
        ];
    }

    private function imageUrl(Product $record): ?string
    {
        return $this->productImages->url($record);
    }

    private function saveActionResponse(Request $request, Product $record, string $operation, string $context): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];
        $redirect = match ($action) {
            'save_view' => route($this->routeName($context, 'show'), $record->doc_num),
            'save_edit' => route($this->routeName($context, 'edit'), $record->doc_num),
            'save_back' => route($this->routeName($context, 'index')),
            'save_new' => $operation === 'store' ? null : route($this->routeName($context, 'create')),
            'save_clone' => route($this->routeName($context, 'clone'), $record->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record, $context) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, string $context, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => $this->permission($context, 'view'),
            'save_edit' => $this->permission($context, 'edit'),
            'save_back' => $this->permission($context, 'view'),
            'save_new' => $cloning ? $this->permission($context, 'clone') : $this->permission($context, 'create'),
            'save_clone' => $this->permission($context, 'clone'),
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('products.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return $action;
    }

    /**
     * @return array{submit_action?: string, company_doc_num?: string|null, company_name?: string|null}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $companyContext = $this->companyContext->companyPublicContext($request);
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return $companyContext;
        }

        return [
            'submit_action' => $this->submitAction($request, creating: $creating),
            ...$companyContext,
        ];
    }

    private function redirectAfterStore(Request $request, Product $record, string $context): string
    {
        if ($request->user()?->can($this->permission($context, 'edit'))) {
            return route($this->routeName($context, 'edit'), $record->doc_num);
        }

        if ($request->user()?->can($this->permission($context, 'view'))) {
            return route($this->routeName($context, 'show'), $record->doc_num);
        }

        return route($this->routeName($context, 'index'));
    }

    private function cloneSourceFromRequest(StoreProductRequest $request): ?Product
    {
        $token = $request->string('clone_source_token')->trim()->toString();
        $context = $this->productContext($request);

        if ($token === '') {
            return null;
        }

        $sourceDocNum = (string) $request->session()->get($this->cloneSourceSessionKey($token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages(['name' => $this->resourceText($context, 'messages.clone_not_allowed')]);
        }

        $source = Product::query()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->forProductContext($context)
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $source instanceof Product) {
            throw ValidationException::withMessages(['name' => $this->resourceText($context, 'messages.clone_not_allowed')]);
        }

        return $source;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'products.clone_sources.'.$token;
    }

    private function restoreRecordByDocNum(Request $request, string $docNum): Product
    {
        $companyId = $this->companyContext->requireCompanyId($request);
        $context = $this->productContext($request);

        return Product::onlyTrashed()
            ->forCompany($companyId)
            ->forProductContext($context)
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? Product::query()
                ->forCompany($companyId)
                ->forProductContext($context)
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, Product $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can($this->permission($this->productContext($request), 'view_trashed')),
            404,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?Product $record): array
    {
        if (! $record) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $record->loadMissing(['createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($record->restoredBy),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?Product $record, string $context): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [['label' => (string) $record?->doc_num, 'url' => $record ? route($this->routeName($context, 'show'), $record->doc_num) : null], ['label' => $this->resourceText($context, 'titles.clone')]],
            'edit' => [['label' => (string) $record?->doc_num, 'url' => $record ? route($this->routeName($context, 'show'), $record->doc_num) : null], ['label' => __('breadcrumb.edit')]],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute($this->routeName($context, 'index'), $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        foreach (['products_doc_number_unique_active', 'products_doc_num_unique_active', 'products_company_doc_number_unique_active', 'products_company_classification_doc_number_unique_active', 'products_company_doc_num_unique_active'] as $needle) {
            if (str_contains($message, $needle)) {
                throw ValidationException::withMessages(['doc_number' => __('products.validation.doc_number_unique')]);
            }
        }

        if (str_contains($message, 'products_company_barcode_unique_active')) {
            throw ValidationException::withMessages(['barcode' => __('products.validation.barcode_unique')]);
        }
    }

    private function recordByDocNum(Request $request, string $docNum, bool $withTrashed = false, bool $enforceContext = true): Product
    {
        $query = Product::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        $query->forCompany($this->companyContext->requireCompanyId($request));

        if ($enforceContext) {
            $query->forProductContext($this->productContext($request));
        }

        return $query
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function productContext(Request $request): string
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return match (true) {
            str_starts_with($routeName, 'admin.raw-materials.') => Product::ContextRawMaterials,
            str_starts_with($routeName, 'admin.packaging-materials.') => Product::ContextPackagingMaterials,
            default => Product::ContextProducts,
        };
    }

    private function isRawMaterialsContext(string $context): bool
    {
        return $context === Product::ContextRawMaterials;
    }

    private function isMaterialContext(string $context): bool
    {
        return in_array($context, [Product::ContextRawMaterials, Product::ContextPackagingMaterials], true);
    }

    private function routeName(string $context, string $action): string
    {
        return match ($context) {
            Product::ContextRawMaterials => 'admin.raw-materials.'.$action,
            Product::ContextPackagingMaterials => 'admin.packaging-materials.'.$action,
            default => 'admin.products.'.$action,
        };
    }

    private function documentNumberKey(string $context): string
    {
        return match ($context) {
            Product::ContextRawMaterials => ProductDocumentNumberSettingsService::RawMaterialsKey,
            Product::ContextPackagingMaterials => ProductDocumentNumberSettingsService::PackagingMaterialsKey,
            default => ProductDocumentNumberSettingsService::ProductsKey,
        };
    }

    private function activityResource(string $context): string
    {
        return match ($context) {
            Product::ContextRawMaterials => 'raw_materials',
            Product::ContextPackagingMaterials => 'packaging_materials',
            default => 'products',
        };
    }

    private function permissionPrefix(string $context): string
    {
        return match ($context) {
            Product::ContextRawMaterials => 'raw_materials',
            Product::ContextPackagingMaterials => 'packaging_materials',
            default => 'products',
        };
    }

    private function permission(string $context, string $action): string
    {
        return $this->permissionPrefix($context).'.'.$action;
    }

    /**
     * @return array<string, string>
     */
    private function resourceRoutes(string $context): array
    {
        return [
            'index' => route($this->routeName($context, 'index')),
            'create' => route($this->routeName($context, 'create')),
            'data' => route($this->routeName($context, 'data')),
            'bulk_delete' => route($this->routeName($context, 'bulk-delete')),
            'document_number_settings' => route($this->routeName($context, 'document-number-settings.update')),
        ];
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function resourceText(string $context, string $key, array $replace = []): string
    {
        return match ($context) {
            Product::ContextRawMaterials => __("products.raw_materials.{$key}", $replace),
            Product::ContextPackagingMaterials => __("products.packaging_materials.{$key}", $replace),
            default => __("products.{$key}", $replace),
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = []): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, 'success', [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
