<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\StoreProductionOrderRequest;
use Modules\Production\Http\Requests\UpdateProductionOrderDocumentNumberSettingsRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionStage;
use Modules\Production\Models\ProductProductionStage;
use Modules\Production\Services\ProductionCycleService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\SalesCycleReadService;
use Modules\Sales\Services\SalesUnitConversionService;
use Throwable;

class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
        private readonly ProductionCycleService $cycle,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request, DocumentNumberSettingsService $settings): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return view('modules.production.work-orders.index', [
            'documentNumberSettings' => $settings->current('production_orders'),
            'canManageProduction' => $this->isFactoryContext($context),
        ]);
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->orders($request);
    }

    public function create(Request $request): View
    {
        $context = $this->requiredFactoryContext($request);
        $clone = filled($request->query('clone'))
            ? ProductionOrder::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->with(['lines.product', 'lines.salesOrderLine', 'lines.customerInvoiceLine', 'salesOrder'])
                ->where('doc_num', $request->query('clone'))
                ->first()
            : null;

        return $this->form($clone, $clone !== null);
    }

    public function store(StoreProductionOrderRequest $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredFactoryContext($request);
        [$header, $lines] = $this->payload($request, $context);
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->createMakeToStockOrder($header, $lines));
        $url = $this->submitRedirectUrl($request, $record);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'doc_num' => $record->doc_num, 'redirect' => $url], 201)
            : redirect($url)->with('success', __('production_execution.messages.order_created'));
    }

    public function edit(Request $request, ProductionOrder $productionOrder): View
    {
        $this->requiredFactoryContext($request);
        $this->assertInCurrentContext($request, $productionOrder);
        abort_unless($productionOrder->status === ProductionOrder::StatusDraft && ! $productionOrder->runs()->exists(), 409, __('production_execution.messages.order_draft_only'));

        return $this->form($productionOrder->load(['lines.product', 'lines.salesOrderLine', 'lines.customerInvoiceLine', 'salesOrder']), false);
    }

    public function update(StoreProductionOrderRequest $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
        $context = $this->requiredFactoryContext($request);
        $this->assertInCurrentContext($request, $productionOrder);
        [$header, $lines] = $this->payload($request, $context);
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->updateDraftOrder($productionOrder, $header, $lines));
        $url = $this->submitRedirectUrl($request, $record);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'doc_num' => $record->doc_num, 'redirect' => $url])
            : redirect($url)->with('success', __('production_execution.messages.order_updated'));
    }

    public function destroy(Request $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
        $this->requiredFactoryContext($request);
        $this->assertInCurrentContext($request, $productionOrder);
        $this->guard(fn () => $this->cycle->deleteDraftOrder($productionOrder));

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : to_route('admin.production.work-orders.index')->with('success', __('production_execution.messages.order_deleted'));
    }

    public function restore(Request $request, string $productionOrder): JsonResponse|RedirectResponse
    {
        $context = $this->requiredFactoryContext($request);
        $record = ProductionOrder::onlyTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('doc_num', $productionOrder)
            ->firstOrFail();
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->restoreDraftOrder($record));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'doc_num' => $record->doc_num])
            : to_route('admin.production.work-orders.show', $record)->with('success', __('production_execution.messages.order_restored'));
    }

    public function clone(Request $request, ProductionOrder $productionOrder): RedirectResponse
    {
        $this->requiredFactoryContext($request);
        $this->assertInCurrentContext($request, $productionOrder);

        return to_route('admin.production.work-orders.create', ['clone' => $productionOrder->doc_num]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $context = $this->requiredFactoryContext($request);
        $validated = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1', 'max:100'],
            'doc_nums.*' => ['required', 'string', 'distinct', Rule::exists('production_orders', 'doc_num')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
        ]);

        DB::transaction(function () use ($validated, $context): void {
            $records = ProductionOrder::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('doc_num', $validated['doc_nums'])
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $this->cycle->deleteDraftOrder($record);
            }
        });

        return response()->json(['success' => true, 'message' => __('production_execution.messages.bulk_delete_orders_done')]);
    }

    public function updateDocumentNumberSettings(
        UpdateProductionOrderDocumentNumberSettingsRequest $request,
        DocumentNumberSettingsService $settings,
    ): JsonResponse {
        $this->requiredFactoryContext($request);
        $result = $settings->update('production_orders', $request->validated('prefix'), (int) $request->validated('padding'));

        $this->logActivity($request, 'production.orders.document_number_settings.update', ActivityLogProperties::settingsUpdated('production_orders', [
            'prefix' => ['old' => $result['old']['prefix'], 'new' => $result['new']['prefix']],
            'padding' => ['old' => $result['old']['padding'], 'new' => $result['new']['padding']],
        ]));

        return response()->json([
            'success' => true,
            'message' => __('production_execution.messages.document_number_settings_updated'),
            'data' => $result['new'],
        ]);
    }

    public function products(
        Request $request,
        DataTableSearchService $search,
        Select2ResponseService $select2,
        SalesCycleReadService $salesCycle,
        NumericFormatService $numbers,
    ): JsonResponse {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $input = $request->validate([
            'source_type' => ['nullable', Rule::in(['make_to_stock', 'sales_order', 'customer_invoice'])],
            'source_doc_num' => ['nullable', 'string', 'max:100'],
        ]);
        $sourceType = $input['source_type'] ?? 'make_to_stock';
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($sourceType === 'make_to_stock') {
            $query = Product::query()->forCompany($context['company_id'])->active()->nonService()
                ->where('item_classification', Product::ClassificationFinishedProduct)->orderBy('name');

            if ($terms !== []) {
                $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'barcode']]);
            }

            return response()->json($select2->paginated($query, $request, fn (Product $product): array => [
                'id' => 'product:'.$product->doc_num,
                'text' => trim($product->doc_num.' — '.$product->name),
            ]));
        }

        abort_if(blank($input['source_doc_num'] ?? null), 422, __('production_execution.messages.select_source_first'));
        $rows = $sourceType === 'sales_order'
            ? $this->salesOrderSourceLines($context['company_id'], (string) $input['source_doc_num'], $numbers)
            : $this->invoiceSourceLines($context['company_id'], (string) $input['source_doc_num'], $salesCycle, $numbers);

        if ($terms !== []) {
            $rows = $rows->filter(function (array $row) use ($terms): bool {
                $haystack = mb_strtolower($row['text']);

                return collect($terms)->every(fn (string $term): bool => str_contains($haystack, mb_strtolower($term)));
            })->values();
        }

        if ($request->boolean('all')) {
            return response()->json(['results' => $rows->values()->all(), 'pagination' => ['more' => false]]);
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $select2->perPage();

        return response()->json([
            'results' => $rows->forPage($page, $perPage)->values()->all(),
            'pagination' => ['more' => $rows->count() > $page * $perPage],
        ]);
    }

    public function sources(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $type = $request->validate(['source_type' => ['required', Rule::in(['sales_order', 'customer_invoice'])]])['source_type'];
        $query = $type === 'sales_order'
            ? SalesOrder::query()->where('company_id', $context['company_id'])->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled])
            : CustomerInvoice::query()->where('company_id', $context['company_id'])->where('document_type', CustomerInvoice::TypeInvoice)->where('status', CustomerInvoice::StatusPosted);
        $query->orderByDesc($type === 'sales_order' ? 'order_date' : 'invoice_date');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num']]);
        }

        return response()->json($select2->paginated($query, $request, fn ($source): array => [
            'id' => $source->doc_num,
            'text' => $source->doc_num,
        ]));
    }

    public function stages(Request $request, Select2ResponseService $select2, DataTableSearchService $search): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredFactoryContext($request);
        $validated = $request->validate([
            'source_type' => ['required', Rule::in(['make_to_stock', 'sales_order', 'customer_invoice'])],
            'source_doc_num' => ['nullable', 'string', 'max:100'],
            'source_line_reference' => ['required', 'string', 'max:180'],
        ]);
        [$referenceType, $publicReference] = array_pad(explode(':', $validated['source_line_reference'], 2), 2, null);
        $productId = match ($referenceType) {
            'product' => Product::query()->forCompany($context['company_id'])->where('doc_num', $publicReference)->value('id'),
            'sales_order_line' => SalesOrderLine::query()
                ->where('public_id', $publicReference)
                ->whereHas('order', fn ($orders) => $orders->where('company_id', $context['company_id'])->where('doc_num', $validated['source_doc_num']))
                ->value('product_id'),
            'customer_invoice_line' => CustomerInvoiceLine::query()
                ->where('public_id', $publicReference)
                ->whereHas('invoice', fn ($invoices) => $invoices->where('company_id', $context['company_id'])->where('doc_num', $validated['source_doc_num']))
                ->value('product_id'),
            default => null,
        };
        abort_if($productId === null, 422, __('production_execution.messages.invalid_source_line'));

        $query = ProductProductionStage::query()
            ->forCompany($context['company_id'])
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->whereHas('stage', fn ($stages) => $stages->visibleInBranch($context['branch_id']))
            ->with('stage')
            ->orderBy('sequence');
        $terms = $search->terms($request->input('q', $request->input('term')));

        foreach ($terms as $term) {
            $query->where(fn ($stages) => $stages
                ->where('sequence', 'like', '%'.$term.'%')
                ->orWhereHas('stage', fn ($stage) => $stage
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')));
        }

        return response()->json($select2->paginated($query, $request, fn (ProductProductionStage $routeStage): array => [
            'id' => $routeStage->public_id,
            'text' => __('production_execution.orders.stage_option', [
                'sequence' => $routeStage->sequence,
                'stage' => $routeStage->stage?->name,
            ]),
        ]));
    }

    public function orderStages(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredFactoryContext($request);
        $query = ProductionStage::query()
            ->forCompany($context['company_id'])
            ->visibleInBranch($context['branch_id'])
            ->where('status', ProductionStage::StatusActive)
            ->orderBy('display_order')
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name', 'description']]);
        }

        return response()->json($select2->paginated($query, $request, fn ($stage): array => [
            'id' => $stage->public_id,
            'text' => trim($stage->code.' — '.$stage->name),
        ]));
    }

    public function lineDetails(
        Request $request,
        SalesUnitConversionService $units,
        NumericFormatService $numbers,
    ): JsonResponse {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $validated = $request->validate([
            'source_type' => ['required', Rule::in(['make_to_stock', 'sales_order', 'customer_invoice'])],
            'source_doc_num' => ['nullable', 'string', 'max:100'],
            'source_line_reference' => ['required', 'string', 'max:180'],
        ]);
        [$referenceType, $publicReference] = array_pad(explode(':', $validated['source_line_reference'], 2), 2, null);
        $expectedReferenceType = match ($validated['source_type']) {
            'make_to_stock' => 'product',
            'sales_order' => 'sales_order_line',
            'customer_invoice' => 'customer_invoice_line',
        };
        abort_unless($referenceType === $expectedReferenceType, 422, __('production_execution.messages.invalid_source_line'));
        $sourceLine = match ($referenceType) {
            'product' => Product::query()->forCompany($context['company_id'])
                ->where('doc_num', $publicReference)
                ->active()
                ->where('item_classification', Product::ClassificationFinishedProduct)
                ->with(['unit', 'equivalentUnit'])
                ->firstOrFail(),
            'sales_order_line' => SalesOrderLine::query()
                ->where('public_id', $publicReference)
                ->whereHas('order', fn ($orders) => $orders
                    ->where('company_id', $context['company_id'])
                    ->where('doc_num', $validated['source_doc_num'])
                    ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled]))
                ->with(['product.unit', 'product.equivalentUnit', 'unit'])
                ->firstOrFail(),
            'customer_invoice_line' => CustomerInvoiceLine::query()
                ->where('public_id', $publicReference)
                ->whereHas('invoice', fn ($invoices) => $invoices
                    ->where('company_id', $context['company_id'])
                    ->where('doc_num', $validated['source_doc_num'])
                    ->where('document_type', CustomerInvoice::TypeInvoice)
                    ->where('status', CustomerInvoice::StatusPosted))
                ->with(['product.unit', 'product.equivalentUnit', 'unit'])
                ->firstOrFail(),
            default => abort(422, __('production_execution.messages.invalid_source_line')),
        };
        $product = $sourceLine instanceof Product ? $sourceLine : $sourceLine->product;
        abort_unless($product instanceof Product && (int) $product->company_id === (int) $context['company_id'], 404);
        abort_unless($product->item_classification === Product::ClassificationFinishedProduct, 422, __('production_execution.messages.production_line_product_invalid'));
        $unit = $sourceLine instanceof Product ? $product->unit : $sourceLine->unit;
        abort_unless($unit !== null, 422, __('production_execution.messages.product_unit_required'));
        $conversion = $units->snapshot($product, $unit->getKey(), '1');
        $equivalentFactor = filled($product->equivalent_value) && bccomp((string) $product->equivalent_value, '0', 8) > 0
            ? (string) $product->equivalent_value
            : '1.00000000';
        $outputFactor = bcmul($conversion['conversion_factor'], $equivalentFactor, 8);
        $equivalentUnit = $product->equivalentUnit ?: $product->unit;
        $components = ProductComponent::query()
            ->forCompany((int) $product->company_id)
            ->where('product_id', $product->getKey())
            ->with(['componentProduct.unit', 'unit'])
            ->orderBy('id')
            ->get()
            ->map(function (ProductComponent $component) use ($outputFactor, $numbers, $units): array {
                $componentProduct = $component->componentProduct;
                abort_unless($componentProduct instanceof Product && $component->unit !== null, 422);
                $componentQuantity = $units->snapshot(
                    $componentProduct,
                    $component->unit_id,
                    (string) $component->quantity,
                );
                $total = bcmul($componentQuantity['base_quantity'], $outputFactor, 8);

                return [
                    'product' => trim(($component->componentProduct?->doc_num ?? '').' — '.($component->componentProduct?->name ?? '')),
                    'unit' => $componentProduct->unit?->name ?? '',
                    'quantity_per_output' => $numbers->format($componentQuantity['base_quantity']),
                    'required_quantity' => $numbers->format($total),
                    'calculation_method' => $component->calculation_method,
                    'percentage' => filled($component->percentage) ? $numbers->format($component->percentage).'%' : null,
                ];
            })
            ->values();

        return response()->json([
            'unit' => $unit->name,
            'base_unit' => $product->unit?->name ?? '',
            'equivalent_value' => $numbers->format($product->equivalent_value ?: 1),
            'equivalent_unit' => $equivalentUnit?->name ?? $product->unit?->name ?? '',
            'conversion_factor' => $conversion['conversion_factor'],
            'output_factor' => $outputFactor,
            'components' => $components,
        ]);
    }

    public function show(Request $request, ProductionOrder $productionOrder): View
    {
        $this->assertInCurrentContext($request, $productionOrder);
        $record = $productionOrder->load(['branch', 'salesOrder.branch', 'salesOrder.branchStore', 'orderStageSnapshots.stage', 'orderStageSnapshots.events.changedBy', 'orderStageSnapshots.events.run', 'lines.product', 'lines.unit', 'lines.stageSnapshots.events.changedBy', 'lines.stageSnapshots.events.run', 'runs.product', 'runs.stageSnapshot', 'runs.requirements.product', 'runs.inventoryDocuments']);
        $sourceInvoice = $record->source_type === 'customer_invoice'
            ? CustomerInvoice::query()->whereKey($record->source_id)->first()
            : null;

        return view('modules.production.work-orders.show', [
            'record' => $record,
            'canManageProduction' => $this->isFactoryContext($this->requiredContext($request)),
            'sourceDocumentNumber' => $record->salesOrder?->doc_num ?: $sourceInvoice?->doc_num,
            'relatedDocuments' => collect([
                ['label' => __('production_execution.fields.sales_order'), 'number' => $record->salesOrder?->doc_num, 'url' => $record->salesOrder ? route('admin.sales.sales-orders.show', $record->salesOrder) : null, 'permission' => 'sales_orders.view'],
                ['label' => __('production_execution.source_types.customer_invoice'), 'number' => $sourceInvoice?->doc_num, 'url' => $sourceInvoice ? route('admin.sales.customer-invoices.show', $sourceInvoice) : null, 'permission' => 'customer_invoices.view'],
                ...$record->runs->map(fn ($run) => ['label' => __('production_execution.fields.run'), 'number' => $run->run_number, 'url' => route('admin.production.runs.show', $run), 'permission' => 'production.runs.view', 'meta' => $run->status])->all(),
                ...$record->runs->flatMap->inventoryDocuments->map(fn ($document) => ['label' => __(str($document->document_type)->replace('_', ' ')->title()->toString()), 'number' => $document->doc_num, 'url' => route('admin.inventory.documents.show', $document), 'permission' => 'inventory.documents.view', 'meta' => $document->status])->all(),
            ]),
        ]);
    }

    public function print(Request $request, ProductionOrder $productionOrder): Response
    {
        return $this->printDocument($request, $productionOrder, __('production_execution.fields.production_order'), 'production-order');
    }

    public function printRequirement(Request $request, ProductionOrder $productionOrder): Response
    {
        return $this->printDocument($request, $productionOrder, __('production_execution.fields.production_requirement'), 'production-requirement');
    }

    private function printDocument(Request $request, ProductionOrder $productionOrder, string $documentTitle, string $filenamePrefix): Response
    {
        $this->assertInCurrentContext($request, $productionOrder);
        $record = $productionOrder->load(['company', 'salesOrder.branch', 'salesOrder.salesEmployee', 'orderStageSnapshots.stage', 'lines.product', 'lines.unit', 'lines.stageSnapshots']);

        return $this->pdf->stream('reports.production.order', [
            'title' => $documentTitle.' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ], str($filenamePrefix.'-'.$record->doc_num)->slug().'.pdf');
    }

    private function assertInCurrentContext(Request $request, ProductionOrder $productionOrder): void
    {
        $context = $this->context->snapshot($request);

        abort_unless(
            $context['company_id']
            && $context['financial_period_id']
            && $context['branch_id']
            && (int) $productionOrder->company_id === (int) $context['company_id']
            && (int) $productionOrder->financial_period_id === (int) $context['financial_period_id']
            && ($this->isAdministrativeContext($context)
                || (int) $productionOrder->branch_id === (int) $context['branch_id']),
            404,
        );
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function payload(StoreProductionOrderRequest $request, array $context): array
    {
        $data = $request->safe()->except(['submit_action']);
        $source = match ($data['source_type']) {
            'sales_order' => SalesOrder::query()->where('company_id', $context['company_id'])->where('doc_num', $data['source_doc_num'])->firstOrFail(),
            'customer_invoice' => CustomerInvoice::query()->where('company_id', $context['company_id'])->where('doc_num', $data['source_doc_num'])->firstOrFail(),
            default => null,
        };
        $lines = collect($data['lines'])->map(function (array $line) use ($context, $source): array {
            [$referenceType, $publicReference] = explode(':', $line['source_line_reference'], 2);
            $sourceLine = match ($referenceType) {
                'sales_order_line' => SalesOrderLine::query()
                    ->where('sales_order_id', $source?->getKey())
                    ->where('public_id', $publicReference)
                    ->with('product')
                    ->firstOrFail(),
                'customer_invoice_line' => CustomerInvoiceLine::query()
                    ->where('customer_invoice_id', $source?->getKey())
                    ->where('public_id', $publicReference)
                    ->with(['product', 'orderLine'])
                    ->firstOrFail(),
                default => Product::query()
                    ->forCompany($context['company_id'])
                    ->where('doc_num', $publicReference)
                    ->firstOrFail(),
            };
            $product = $sourceLine instanceof Product ? $sourceLine : $sourceLine->product;
            $salesLine = $sourceLine instanceof SalesOrderLine ? $sourceLine : $sourceLine->orderLine;
            $invoiceLine = $sourceLine instanceof CustomerInvoiceLine ? $sourceLine : null;

            return [
                'product_id' => $product->getKey(),
                'unit_id' => $sourceLine instanceof Product ? $product->item_unit_id : $sourceLine->unit_id,
                'sales_order_line_id' => $salesLine?->getKey(),
                'customer_invoice_line_id' => $invoiceLine?->getKey(),
                'quantity' => $line['quantity'],
                'description' => $line['description'] ?: ($sourceLine->description ?? $product->name),
                'specifications' => $salesLine?->specifications,
                'production_notes' => $line['production_notes'] ?? null,
                'stage_public_ids' => $line['stage_public_ids'] ?? [],
            ];
        })->values()->all();

        return [[
            ...$data,
            ...$context,
            'source_id' => $source?->getKey(),
            'sales_order_id' => $source instanceof SalesOrder ? $source->getKey() : $source?->sales_order_id,
            'customer_id' => $source?->customer_id,
        ], $lines];
    }

    private function salesOrderSourceLines(int $companyId, string $documentNumber, NumericFormatService $numbers): Collection
    {
        $order = SalesOrder::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $documentNumber)
            ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled])
            ->firstOrFail();

        return $order->lines()
            ->with(['product', 'unit'])
            ->where('product_classification_snapshot', Product::ClassificationFinishedProduct)
            ->whereHas('product', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('item_classification', Product::ClassificationFinishedProduct)
                ->where('status', 'active'))
            ->orderBy('line_number')
            ->get()
            ->filter(fn (SalesOrderLine $line): bool => bccomp($line->remainingProductionDemandQuantity(), '0', 8) > 0)
            ->map(function (SalesOrderLine $line) use ($numbers): array {
                $remaining = $line->remainingProductionDemandQuantity();

                return [
                    'id' => 'sales_order_line:'.$line->public_id,
                    'product_text' => trim(($line->product?->doc_num ?? '').' — '.($line->product?->name ?? $line->description)),
                    'required_quantity' => $remaining,
                    'description' => $line->description ?? $line->product?->name,
                    'text' => __('production_execution.orders.source_line_option', [
                        'line' => $line->line_number,
                        'product' => trim(($line->product?->doc_num ?? '').' — '.($line->product?->name ?? $line->description)),
                        'required' => $numbers->format($line->quantity),
                        'planned' => $numbers->format($line->production_requested_quantity),
                        'remaining' => $numbers->format($remaining),
                    ]),
                ];
            })->values();
    }

    private function invoiceSourceLines(int $companyId, string $documentNumber, SalesCycleReadService $salesCycle, NumericFormatService $numbers): Collection
    {
        $invoice = CustomerInvoice::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $documentNumber)
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('status', CustomerInvoice::StatusPosted)
            ->with('order')
            ->firstOrFail();
        $salesRows = $invoice->order
            ? $salesCycle->backorders($companyId, (int) $invoice->order->branch_id, ['order_id' => $invoice->order->getKey()])->keyBy(fn (array $row): int => (int) $row['line']->getKey())
            : collect();

        return $invoice->lines()->with(['product', 'orderLine'])->where('is_service', false)->get()
            ->map(function (CustomerInvoiceLine $line) use ($salesRows, $numbers): ?array {
                $alreadyPlanned = (string) ProductionOrderLine::query()
                    ->where('customer_invoice_line_id', $line->getKey())
                    ->whereHas('order')
                    ->sum('base_quantity');
                $remainingBase = bcsub((string) $line->base_quantity, $alreadyPlanned, 8);
                $remainingBase = bccomp($remainingBase, '0', 8) < 0 ? '0.00000000' : $remainingBase;
                $salesRow = $line->sales_order_line_id ? $salesRows->get((int) $line->sales_order_line_id) : null;

                if ($salesRow !== null && bccomp((string) $salesRow['unplanned_base'], $remainingBase, 8) < 0) {
                    $remainingBase = (string) $salesRow['unplanned_base'];
                }
                if (bccomp($remainingBase, '0', 8) <= 0 || ! $line->product) {
                    return null;
                }

                $remaining = bcdiv($remainingBase, (string) $line->conversion_factor, 8);

                return [
                    'id' => 'customer_invoice_line:'.$line->public_id,
                    'product_text' => trim($line->product->doc_num.' — '.$line->product->name),
                    'required_quantity' => $remaining,
                    'description' => $line->description ?? $line->product->name,
                    'text' => __('production_execution.orders.invoice_source_line_option', [
                        'line' => $line->line_number,
                        'product' => trim($line->product->doc_num.' — '.$line->product->name),
                        'required' => $numbers->format($line->quantity),
                        'planned' => $numbers->format(bcdiv($alreadyPlanned, (string) $line->conversion_factor, 8)),
                        'remaining' => $numbers->format($remaining),
                    ]),
                ];
            })->filter()->values();
    }

    private function form(?ProductionOrder $record, bool $isClone): View
    {
        $record?->loadMissing(['orderStageSnapshots.stage', 'lines.product', 'lines.salesOrderLine', 'lines.customerInvoiceLine', 'lines.stageSnapshots.productStage', 'salesOrder.lines']);
        $sourceDocumentNumber = match ($record?->source_type) {
            'sales_order' => $record->salesOrder?->doc_num,
            'customer_invoice' => CustomerInvoice::query()->whereKey($record->source_id)->value('doc_num'),
            default => null,
        };
        $invoiceLines = $record?->source_type === 'customer_invoice'
            ? CustomerInvoiceLine::query()->where('customer_invoice_id', $record->source_id)->get()->groupBy('product_id')
            : collect();
        $sourceLineReferences = $record?->lines?->mapWithKeys(function (ProductionOrderLine $line) use ($record, $invoiceLines): array {
            $reference = match ($record->source_type) {
                'sales_order' => $line->salesOrderLine?->public_id,
                'customer_invoice' => $line->customerInvoiceLine?->public_id
                    ?? ($invoiceLines->get($line->product_id)?->count() === 1 ? $invoiceLines->get($line->product_id)?->first()?->public_id : null),
                default => $line->product?->doc_num,
            };
            $prefix = match ($record->source_type) {
                'sales_order' => 'sales_order_line:',
                'customer_invoice' => 'customer_invoice_line:',
                default => 'product:',
            };

            return [$line->getKey() => $reference === null ? null : $prefix.$reference];
        }) ?? collect();

        return view('modules.production.work-orders.form', compact('record', 'isClone', 'sourceDocumentNumber', 'sourceLineReferences'));
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return $context;
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredFactoryContext(Request $request): array
    {
        $context = $this->requiredContext($request);
        abort_unless($this->isFactoryContext($context), 403, __('production_execution.messages.factory_context_required'));

        return $context;
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function isFactoryContext(array $context): bool
    {
        return Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeFactory)
            ->exists();
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function isAdministrativeContext(array $context): bool
    {
        return Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
    }

    private function authorizeLookup(Request $request): void
    {
        abort_unless($request->user()?->canAny([
            'production.orders.view',
            'production.orders.create',
            'production.orders.edit',
            'production.orders.clone',
        ]), 403);
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['production_order' => $exception->getMessage()]);
        }
    }

    private function submitRedirectUrl(Request $request, ProductionOrder $record): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        return match ($action) {
            'save_view' => route('admin.production.work-orders.show', $record),
            'save_back' => route('admin.production.work-orders.index'),
            'save_clone' => route('admin.production.work-orders.create', ['clone' => $record->doc_num]),
            'save', 'save_edit' => $request->user()?->can('production.orders.edit')
                ? route('admin.production.work-orders.edit', $record)
                : route('admin.production.work-orders.show', $record),
            default => route('admin.production.work-orders.show', $record),
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = []): void
    {
        try {
            $this->activityLogger->log($request, 'production', $action, 'success', [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
