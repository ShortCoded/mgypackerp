<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\DataTables\InventoryDocumentsDataTable;
use Modules\Inventory\Http\Requests\StoreInventoryOperationRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryDocumentLineageService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Services\ProductionCycleService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryDocumentController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        $this->requiredContext($request);

        return view('modules.inventory.documents.index');
    }

    public function data(Request $request, InventoryDocumentsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $context = $this->requiredContext($request);
        $allowedDocumentTypes = $this->allowedDocumentTypes($request);

        abort_if($allowedDocumentTypes === [], 403);

        return view('modules.inventory.documents.create', [
            'record' => null,
            'isClone' => false,
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
            'batchLayerSelectionData' => $this->productionBatchOldInput($request, $context),
        ]);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function productionBatchOldInput(Request $request, array $context): array
    {
        $storeUuid = $request->old('branch_store_uuid');
        $batchId = $request->old('production_run_batch_public_id');
        $store = is_string($storeUuid) ? BranchStore::query()->where('branch_id', $context['branch_id'])->where('public_uuid', $storeUuid)->first() : null;
        $batch = is_string($batchId) ? ProductionRunBatch::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
            ->where('financial_period_id', $context['financial_period_id'])->where('public_id', $batchId)->with('runs.requirements')->first() : null;
        $selections = [];
        $input = $request->old('batch_material_selections', []);
        if ($batch !== null && $store !== null && is_array($input)) {
            $requirements = $batch->runs->flatMap->requirements->keyBy('id');
            $layerIds = [];
            foreach (array_slice($input, 0, 100) as $entry) {
                foreach (is_array($entry) && is_array($entry['receipt_layers'] ?? null) ? array_slice($entry['receipt_layers'], 0, 100) : [] as $selection) {
                    $id = is_array($selection) && is_scalar($selection['layer_id'] ?? null) ? (string) $selection['layer_id'] : '';
                    if (ctype_digit($id)) {
                        $layerIds[] = $id;
                    }
                }
            }
            $layers = InventoryReceiptLayer::query()->with('receiptTransaction')->whereIn('id', array_unique($layerIds))
                ->where('company_id', $context['company_id'])->where('branch_store_id', $store->id)
                ->whereIn('product_id', $requirements->pluck('product_id'))->where('stock_status', InventoryTransaction::StatusAvailable)->get()->keyBy('id');
            foreach (array_slice($input, 0, 100) as $entry) {
                $requirement = is_array($entry) && is_scalar($entry['requirement_id'] ?? null) ? $requirements->get($entry['requirement_id']) : null;
                if ($requirement === null || ! is_array($entry['receipt_layers'] ?? null)) {
                    continue;
                }
                $slices = [];
                foreach (array_slice($entry['receipt_layers'], 0, 100) as $selection) {
                    $id = is_array($selection) && is_scalar($selection['layer_id'] ?? null) ? $selection['layer_id'] : null;
                    $layer = $id !== null && ctype_digit((string) $id) ? $layers->get($id) : null;
                    if ($layer !== null && (int) $layer->product_id === (int) $requirement->product_id) {
                        $slices[] = ['layer_id' => (string) $layer->id, 'text' => $layer->receiptTransaction?->source_doc_num.' — '.$layer->batch_lot,
                            'quantity' => is_scalar($selection['quantity'] ?? null) ? $selection['quantity'] : null];
                    }
                }
                $selections[] = ['requirement_id' => $requirement->id, 'receipt_layers' => $slices];
            }
        }

        return ['batch' => $batch ? ['id' => $batch->public_id, 'text' => $batch->batch_number] : null,
            'store' => $store ? ['id' => $store->public_uuid, 'text' => $store->name] : null, 'selections' => $selections];
    }

    public function productionMaterialIssue(Request $request): View
    {
        $this->authorizeProductionMaterialIssue($request);
        $context = $this->requiredContext($request);
        $selectedRequest = null;
        $unlinkedReservations = collect();

        if ($request->filled('material_request')) {
            $selectedRequest = $this->issuableProductionMaterialRequests($context)
                ->with(['run', 'store', 'lines.product', 'lines.unit', 'lines.reservations'])
                ->where('doc_num', $request->string('material_request')->trim()->toString())
                ->firstOrFail();
            $unlinkedReservations = InventoryReservation::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('production_run_id', $selectedRequest->production_run_id)
                ->whereIn('production_material_requirement_id', $selectedRequest->lines->pluck('production_material_requirement_id'))
                ->whereNull('production_material_request_line_id')
                ->where('status', InventoryReservation::StatusActive)
                ->get();
        }

        return view('modules.inventory.documents.production-material-issue', compact('selectedRequest', 'unlinkedReservations'));
    }

    public function productionMaterialIssueRequests(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeProductionMaterialIssue($request);
        $context = $this->requiredContext($request);
        $query = $this->issuableProductionMaterialRequests($context)->with(['run', 'store'])->orderByDesc('id');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, [
                'text' => ['production_material_requests.doc_num'],
                'exists' => [[
                    'table' => 'production_runs',
                    'first' => 'production_runs.id',
                    'second' => 'production_material_requests.production_run_id',
                    'columns' => ['production_runs.run_number'],
                ]],
            ]);
        }

        return response()->json($select2->paginated($query, $request, fn (ProductionMaterialRequest $materialRequest): array => [
            'id' => $materialRequest->doc_num,
            'text' => $materialRequest->doc_num.' — '.$materialRequest->run?->run_number.' — '.$materialRequest->store?->name,
        ]));
    }

    public function clone(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $allowedDocumentTypes = $this->allowedDocumentTypes($request);
        abort_unless(in_array($inventoryDocument->document_type, $allowedDocumentTypes, true), 403);

        return view('modules.inventory.documents.create', [
            'record' => $inventoryDocument->load(['branchStore', 'destinationBranchStore.branch', 'lines.product']),
            'isClone' => true,
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
        ]);
    }

    public function edit(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        abort_unless($inventoryDocument->isUntouchedDraft(), 409, __('inventory.movements.messages.only_drafts_editable'));

        $allowedDocumentTypes = $this->allowedDocumentTypes($request);
        abort_unless(in_array($inventoryDocument->document_type, $allowedDocumentTypes, true), 403);

        return view('modules.inventory.documents.create', [
            'record' => $inventoryDocument->load(['branchStore', 'destinationBranchStore.branch', 'lines.product']),
            'isClone' => false,
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
        ]);
    }

    public function stores(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $context = $this->requiredContext($request);
        $query = BranchStore::query()
            ->with('branch')
            ->when(
                $request->string('scope')->toString() === 'destination',
                fn ($query) => $query->whereHas('branch', fn ($branch) => $branch
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
                fn ($query) => $query->where('branch_id', $context['branch_id']),
            )
            ->orderBy('position')
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return response()->json($select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->public_uuid,
            'text' => $request->string('scope')->toString() === 'destination'
                ? trim(($store->branch?->name ?? '').' — '.$store->name, ' —')
                : (string) $store->name,
        ]));
    }

    public function productionRunBatches(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $documentType = $this->authorizeProductionRunBatchAction($request);
        $context = $this->requiredContext($request);
        $query = $documentType === InventoryDocument::TypeReceipt
            ? $this->receivableProductionBatches($context)
            : $this->issueableProductionBatches($context);
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['batch_number']]);
        }

        return response()->json($select2->paginated($query, $request, fn (ProductionRunBatch $batch): array => [
            'id' => (string) $batch->public_id,
            'text' => (string) $batch->batch_number,
        ]));
    }

    public function productionRunBatchDetails(Request $request, string $publicId, NumericFormatService $numbers): JsonResponse
    {
        $documentType = $this->authorizeProductionRunBatchAction($request);
        $context = $this->requiredContext($request);
        $batchQuery = $documentType === InventoryDocument::TypeReceipt
            ? $this->receivableProductionBatches($context)
            : $this->issueableProductionBatches($context);
        $batch = $batchQuery
            ->with([
                'order',
                'runs.orderLine.product.unit',
                'runs.orderLine.unit',
                'runs.stageSnapshot',
                'runs.requirements.product',
                'runs.requirements.unit',
            ])
            ->where('public_id', $publicId)
            ->firstOrFail();

        $lines = $documentType === InventoryDocument::TypeReceipt
            ? $batch->runs
                ->filter(fn (ProductionRun $run): bool => bccomp(
                    bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8),
                    '0',
                    8,
                ) > 0)
                ->map(fn (ProductionRun $run): array => [
                    'run_number' => $run->run_number,
                    'line_number' => $run->orderLine->line_number,
                    'finished_product' => $run->orderLine->product?->name,
                    'stage' => $run->stageSnapshot?->stage_name,
                    'product' => $run->orderLine->product?->name,
                    'quantity' => $numbers->format(bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8)),
                    'unit' => $run->orderLine->product?->unit?->name ?? $run->orderLine->unit?->name,
                ])
                ->values()
            : $batch->runs->flatMap(function (ProductionRun $run) use ($numbers): array {
                return $run->requirements
                    ->filter(fn ($requirement): bool => bccomp(
                        bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8),
                        '0',
                        8,
                    ) > 0)
                    ->map(fn ($requirement): array => [
                        'requirement_id' => $requirement->id,
                        'product_doc_num' => $requirement->product?->doc_num,
                        'run_number' => $run->run_number,
                        'line_number' => $run->orderLine->line_number,
                        'finished_product' => $run->orderLine->product?->name,
                        'stage' => $run->stageSnapshot?->stage_name,
                        'product' => $requirement->product?->name,
                        'quantity' => $numbers->format(bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8)),
                        'unit' => $requirement->unit?->name,
                    ])
                    ->values()
                    ->all();
            })->values();

        abort_if($lines->isEmpty(), 409, $documentType === InventoryDocument::TypeReceipt
            ? __('production_execution.messages.run_batch_output_not_receivable')
            : __('production_execution.messages.run_batch_materials_already_issued'));

        return response()->json(['data' => [
            'batch_number' => $batch->batch_number,
            'order_number' => $batch->order->doc_num,
            ($documentType === InventoryDocument::TypeReceipt ? 'outputs' : 'materials') => $lines,
        ]]);
    }

    public function products(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $context = $this->requiredContext($request);
        $query = Product::query()
            ->forCompany($context['company_id'])
            ->active()
            ->nonService()
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'barcode']]);
        }

        return response()->json($select2->paginated($query, $request, fn (Product $product): array => [
            'id' => (string) $product->doc_num,
            'text' => trim($product->doc_num.' — '.$product->name),
        ]));
    }

    public function receiptLayers(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $canCorrectIssue = $request->user()?->can('inventory.documents.correct_prepare') && $request->user()?->can('inventory.documents.issue');
        abort_unless($canCorrectIssue || $request->user()?->canAny(['inventory.documents.create', 'inventory.documents.edit', 'production.material_requests.issue', 'production.runs.issue', 'production.runs.account_materials', 'production.runs.return', 'purchases.purchase_returns.create', 'purchases.purchase_returns.edit']), 403);
        $request->validate(['branch_store_uuid' => ['nullable', 'uuid'], 'product_doc_num' => ['nullable', 'string', 'max:100'],
            'branch_store_id' => ['nullable', 'integer', 'min:1'],
            'document_date' => ['nullable', 'string', 'max:50'], 'stock_status' => ['nullable', 'string', 'max:50'],
            'production_run_public_id' => ['nullable', 'uuid'],
            'receipt_line_public_id' => ['nullable', 'uuid'],
            'material_request_line_id' => ['nullable', 'integer', 'min:1']]);
        $context = $this->requiredContext($request);
        $store = BranchStore::query()->where('branch_id', $context['branch_id'])
            ->when($request->filled('branch_store_uuid'), fn ($query) => $query->where('public_uuid', $request->input('branch_store_uuid')),
                fn ($query) => $query->whereKey($request->integer('branch_store_id')))->first();
        $product = Product::query()->forCompany($context['company_id'])->active()->nonService()->where('doc_num', $request->input('product_doc_num'))->first();
        $date = app(DateFormatService::class)->normalizeForStorage($request->input('document_date'));
        $status = $request->input('stock_status', InventoryTransaction::StatusAvailable);
        $costPolicyId = $store !== null && $date !== null
            ? app(InventoryCostPolicyService::class)->resolve((int) $context['company_id'], (int) $store->id, $date)['policy_id'] : null;
        $query = InventoryReceiptLayer::query()->with(['receiptTransaction', 'serialIdentity'])->withBookCostBasis($costPolicyId)
            ->where('company_id', $context['company_id'])->where('branch_store_id', $store?->id ?? 0)->where('product_id', $product?->id ?? 0)
            ->where('stock_status', $status)->where('remaining_quantity', '>', 0)->withAuthoritativeCost()
            ->when($status === InventoryTransaction::StatusProductionStaging, fn ($query) => $query->whereHas('receiptTransaction',
                fn ($receipt) => $receipt->whereHas('productionRun', fn ($run) => $run->where('public_id', $request->input('production_run_public_id'))
                    ->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('financial_period_id', $context['financial_period_id']))))
            ->whereDate('original_receipt_date', '<=', $date ?? '0001-01-01')
            ->when($product?->tracks_expiry, fn ($query) => $query->whereNotNull('expiry_date')->whereDate('expiry_date', '>=', $date ?? '0001-01-01'))
            ->orderBy('original_receipt_date')->orderBy('id');
        if ($request->filled('receipt_line_public_id')) {
            $receiptLine = UnpricedInventoryReceiptLine::query()
                ->where('public_id', $request->input('receipt_line_public_id'))->where('product_id', $product?->id ?? 0)
                ->whereHas('receipt', fn ($receipt) => $receipt->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])->where('branch_store_id', $store?->id ?? 0)->where('posting_status', 'posted'))->first();
            $source = $receiptLine === null ? null : InventoryTransaction::query()->where('company_id', $context['company_id'])
                ->where('posting_key', "purchase-receipt:{$receiptLine->id}")->first();
            $query->whereIn('receipt_transaction_id', $source === null ? [] : app(InventoryLayerService::class)->receiptLineageTransactionIds($source->id));
        } elseif (! $canCorrectIssue && ! $request->user()?->canAny(['inventory.documents.create', 'inventory.documents.edit', 'production.material_requests.issue', 'production.runs.issue', 'production.runs.account_materials', 'production.runs.return'])) {
            $query->whereRaw('1 = 0');
        }
        if ($request->filled('material_request_line_id')) {
            $query->whereExists(fn ($reservation) => $reservation->selectRaw('1')->from('inventory_reservations')
                ->where('production_material_request_line_id', $request->integer('material_request_line_id'))
                ->whereColumn('inventory_reservations.company_id', 'inventory_receipt_layers.company_id')
                ->whereColumn('inventory_reservations.branch_store_id', 'inventory_receipt_layers.branch_store_id')
                ->whereColumn('inventory_reservations.product_id', 'inventory_receipt_layers.product_id')
                ->where('inventory_reservations.status', InventoryReservation::StatusActive)
                ->whereRaw('(inventory_reservations.quantity - inventory_reservations.consumed_quantity - inventory_reservations.released_quantity) > 0')
                ->where(fn ($batch) => $batch->whereColumn('inventory_reservations.batch_lot', 'inventory_receipt_layers.batch_lot')
                    ->orWhere(fn ($null) => $null->whereNull('inventory_reservations.batch_lot')->whereNull('inventory_receipt_layers.batch_lot')))
                ->where(fn ($location) => $location->whereColumn('inventory_reservations.warehouse_location_id', 'inventory_receipt_layers.warehouse_location_id')
                    ->orWhere(fn ($null) => $null->whereNull('inventory_reservations.warehouse_location_id')->whereNull('inventory_receipt_layers.warehouse_location_id'))));
        }
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $query->where(function ($query) use ($search, $terms): void {
                $search->applyMultiTermSearch($query, $terms, ['text' => ['batch_lot']]);
                $query->orWhereHas('serialIdentity', fn ($identity) => $search->applyMultiTermSearch($identity, $terms, ['text' => ['serial_number']]));
            });
        }
        $numbers = app(NumericFormatService::class);

        return response()->json($select2->paginated($query, $request, fn (InventoryReceiptLayer $layer): array => [
            'id' => (string) $layer->id,
            'text' => ($layer->receiptTransaction?->source_doc_num ?? '').' — '.($layer->serialIdentity?->serial_number ?? $layer->batch_lot ?? __('inventory_cost_policy.no_batch')).' — '.$numbers->format($layer->remaining_quantity).' — '.$numbers->format($layer->bookUnitCostForPolicy($costPolicyId)),
            'serial_number' => $layer->serialIdentity?->serial_number,
            'batch_lot' => $layer->batch_lot, 'manufacture_date' => $layer->manufacture_date?->toDateString(), 'expiry_date' => $layer->expiry_date?->toDateString(),
            'remaining_quantity' => $layer->remaining_quantity, 'unit_cost' => $layer->bookUnitCostForPolicy($costPolicyId),
            'receipt_unit_cost' => $layer->unit_cost,
        ]));
    }

    public function store(
        StoreInventoryOperationRequest $request,
        InventoryMovementService $service,
        ProductionCycleService $productionCycle,
    ): JsonResponse|RedirectResponse {
        $context = $this->requiredContext($request);
        $data = $request->validated();

        if (filled($data['production_run_batch_public_id'] ?? null)) {
            $batch = ProductionRunBatch::query()
                ->where('public_id', $data['production_run_batch_public_id'])
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->firstOrFail();
            $store = BranchStore::query()
                ->where('branch_id', $context['branch_id'])
                ->where('public_uuid', $data['branch_store_uuid'])
                ->firstOrFail();
            if ($data['document_type'] === InventoryDocument::TypeReceipt) {
                throw ValidationException::withMessages(['document' => __('production_handover.old_direct_receipt_disabled')]);
            }

            $selectedLayers = collect($data['batch_material_selections'] ?? [])->mapWithKeys(fn (array $line): array => [(int) $line['requirement_id'] => collect($line['receipt_layers'] ?? [])
                ->filter(fn (array $selection): bool => filled($selection['layer_id'] ?? null))->values()->all()])->all();
            $document = $this->guard(fn (): InventoryDocument => $productionCycle->issueRunBatchMaterials($batch, (int) $store->getKey(), selectedLayersByRequirementId: $selectedLayers, documentDate: $data['document_date'] ?? null));
            $url = route('admin.inventory.documents.show', $document);

            return $this->respond(
                $request,
                ['doc_num' => $document->doc_num, 'status' => $document->status, 'url' => $url],
                $url,
                201,
                'inventory.movements.messages.posted',
            );
        }

        [$header, $lines] = $this->movementPayload($request, $context);
        $shouldPost = $request->string('submit_action')->toString() === 'post_and_view';
        $document = $this->guard(fn (): InventoryDocument => $shouldPost
            ? $service->createAndPost($header, $lines)
            : $service->createDraft($header, $lines));
        $url = $this->submitRedirectUrl($request, $document, $shouldPost);

        return $this->respond(
            $request,
            ['doc_num' => $document->doc_num, 'status' => $document->status, 'url' => $url],
            $url,
            201,
            $shouldPost ? 'inventory.movements.messages.posted' : 'inventory.movements.messages.draft_saved',
        );
    }

    public function update(
        StoreInventoryOperationRequest $request,
        InventoryDocument $inventoryDocument,
        InventoryMovementService $service,
        InventoryDocumentPostingService $posting,
    ): JsonResponse|RedirectResponse {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $context = $this->requiredContext($request);
        [$header, $lines] = $this->movementPayload($request, $context);
        $shouldPost = $request->string('submit_action')->toString() === 'post_and_view';
        $document = $this->guard(function () use ($service, $posting, $inventoryDocument, $header, $lines, $shouldPost): InventoryDocument {
            $draft = $service->updateDraft($inventoryDocument, $header, $lines);

            return $shouldPost ? $posting->post($draft) : $draft;
        });
        $url = $this->submitRedirectUrl($request, $document, $shouldPost);

        return $this->respond(
            $request,
            ['doc_num' => $document->doc_num, 'status' => $document->status, 'url' => $url],
            $url,
            200,
            $shouldPost ? 'inventory.movements.messages.posted' : 'inventory.movements.messages.draft_saved',
        );
    }

    public function post(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $typePermission = $this->manualDocumentTypePermissions()[$inventoryDocument->document_type] ?? null;
        abort_unless($typePermission && $request->user()?->can($typePermission), 403);
        $record = $this->guard(fn (): InventoryDocument => $posting->post($inventoryDocument));

        return $this->respond(
            $request,
            ['doc_num' => $record->doc_num, 'status' => $record->status],
            route('admin.inventory.documents.show', $record),
            200,
            'inventory.movements.messages.posted',
        );
    }

    public function destroy(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $inventoryDocument): void {
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($inventoryDocument->getKey());
            $this->assertInCurrentContext($request, $locked);
            abort_unless($locked->isUntouchedDraft(), 409, __('Only an unposted draft inventory movement can be deleted.'));
            $locked->update(['deleted_by' => $request->user()?->getKey()]);
            $locked->delete();
        });

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : to_route('admin.inventory.documents.index')->with('success', __('Inventory movement deleted successfully.'));
    }

    public function restore(Request $request, string $inventoryDocument): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $record = InventoryDocument::onlyTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('doc_num', $inventoryDocument)
            ->firstOrFail();
        $record->restore();
        $record->update(['restored_by' => $request->user()?->getKey(), 'restored_at' => now(), 'updated_by' => $request->user()?->getKey()]);

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : to_route('admin.inventory.documents.show', $record)->with('success', __('Inventory movement restored successfully.'));
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $context = $this->requiredContext($request);
        $validated = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1', 'max:100'],
            'doc_nums.*' => ['required', 'string', 'distinct', Rule::exists('inventory_documents', 'doc_num')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('status', InventoryDocument::StatusDraft)
                ->whereNull('deleted_at'))],
        ]);

        DB::transaction(function () use ($validated, $context, $request): void {
            $records = InventoryDocument::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('doc_num', $validated['doc_nums'])
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                if (! $record->isUntouchedDraft()) {
                    throw ValidationException::withMessages(['doc_nums' => __('Only unposted draft inventory movements can be deleted.')]);
                }
                $record->update(['deleted_by' => $request->user()?->getKey()]);
                $record->delete();
            }
        });

        return response()->json(['success' => true]);
    }

    public function show(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposalService $proposals): View
    {
        $this->assertReadableInCurrentContext($request, $inventoryDocument);

        $record = $inventoryDocument->load([
            'lines.product', 'lines.unit', 'transactions', 'branchStore',
            'destinationBranchStore',
            'productionOrder.salesOrder', 'productionRun.order.salesOrder', 'productionMaterialRequest', 'salesOrder',
            'costProposals.preparedBy', 'costProposals.approvedBy', 'costProposals.valueAdjustment.journalEntry',
        ]);
        foreach ($record->costProposals as $proposal) {
            if ($proposal->impact_snapshot !== null) {
                $proposals->assertImpactBranchAccess($request, $proposal->impact_snapshot);
            }
        }

        $lineageRows = app(InventoryDocumentLineageService::class)->forDocument($record);

        return view('modules.inventory.documents.show', compact('record', 'lineageRows'));
    }

    public function print(Request $request, InventoryDocument $inventoryDocument): Response
    {
        $this->assertReadableInCurrentContext($request, $inventoryDocument);
        $record = $inventoryDocument->load([
            'company', 'lines.product', 'lines.unit', 'branchStore', 'destinationBranchStore',
            'productionOrder', 'productionRun', 'productionMaterialRequest',
        ]);

        return $this->pdf->stream('reports.inventory.document', [
            'title' => $this->pdf->stockDocumentTitle($record).' — '.$record->doc_num,
            'record' => $record,
            'lineageRows' => app(InventoryDocumentLineageService::class)->forDocument($record),
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ], str('inventory-'.$record->document_type.'-'.$record->doc_num)->slug().'.pdf');
    }

    public function reversalPreview(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $plan = $this->guard(fn (): array => $posting->reversalPlan($inventoryDocument, $request->validate(['posting_date' => ['nullable', 'date_format:Y-m-d']])['posting_date'] ?? null));

        if ($request->expectsJson()) {
            return response()->json($plan);
        }

        return view('modules.inventory.documents.reversal-preview', [
            'record' => $inventoryDocument,
            'plan' => $plan,
        ]);
    }

    public function reverse(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'preview_token' => ['required', 'string', 'size:64'],
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $plan = $this->guard(fn (): array => $posting->reversalPlan($inventoryDocument, $data['posting_date'] ?? null));
        if (! $plan['can_reverse']) {
            throw ValidationException::withMessages(['document' => implode(' ', $plan['blockers'])]);
        }
        if (! hash_equals($plan['preview_token'], $data['preview_token'])) {
            throw ValidationException::withMessages(['preview_token' => __('inventory.movements.reversal.preview_changed')]);
        }
        $record = $this->guard(fn (): InventoryDocument => $posting->reverse($inventoryDocument, $data['reason'], $data['posting_date'] ?? null));

        return $this->respond(
            $request,
            ['doc_num' => $record->doc_num, 'status' => $record->status],
            route('admin.inventory.documents.show', $record),
            200,
            'inventory.movements.messages.reversed',
        );
    }

    public function priceReceipt(
        Request $request,
        InventoryDocument $inventoryDocument,
        InventoryReceiptCostProposalService $proposals,
        NumericFormatService $numbers,
    ): RedirectResponse {
        $this->assertInCurrentContext($request, $inventoryDocument);
        if ($request->hasFile('workbook')) {
            $request->request->remove('unit_costs');
        } else {
            try {
                $request->merge([
                    'unit_costs' => collect($request->input('unit_costs', []))
                        ->map(fn (mixed $cost): ?string => $numbers->normalizeToScale($cost, 8))
                        ->all(),
                ]);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['unit_costs' => __('inventory.movements.messages.receipt_pricing_precision')]);
            }
        }
        $data = $request->validate([
            'basis' => ['required', Rule::in([InventoryReceiptCostProposal::BasisDocumented, InventoryReceiptCostProposal::BasisEstimate])],
            'source_reference' => ['nullable', 'string', 'min:5', 'max:255'],
            'basis_note' => ['required_if:basis,estimate', 'nullable', 'string', 'min:5', 'max:2000'],
            'workbook' => ['nullable', 'file', 'mimes:xlsx', 'max:10240'],
            'unit_costs' => [$request->hasFile('workbook') ? 'nullable' : 'required', 'array', 'min:1'],
            'unit_costs.*' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ]);
        $this->guard(fn (): InventoryReceiptCostProposal => $proposals->prepare(
            $request, $inventoryDocument, $data, $data['unit_costs'] ?? [], $request->file('workbook'),
        ));

        return to_route('admin.inventory.documents.show', $inventoryDocument)
            ->with('success', __('inventory.movements.messages.receipt_pricing_prepared'));
    }

    public function receiptCostTemplate(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposalService $proposals): BinaryFileResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $path = $this->guard(fn (): string => $proposals->template($request, $inventoryDocument));

        return response()->download($path, $inventoryDocument->doc_num.'-receipt-costs.xlsx')->deleteFileAfterSend(true);
    }

    public function receiptCostSource(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal): StreamedResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        abort_unless((int) $proposal->inventory_document_id === (int) $inventoryDocument->getKey()
            && (int) $proposal->company_id === (int) $inventoryDocument->company_id
            && $proposal->source_file_path !== null
            && Storage::disk('local')->exists($proposal->source_file_path), 404);
        abort_unless(hash_file('sha256', Storage::disk('local')->path($proposal->source_file_path)) === $proposal->source_file_sha256, 409);

        return Storage::disk('local')->download($proposal->source_file_path, $proposal->source_file_name ?: 'receipt-cost-source.xlsx');
    }

    public function approveReceiptCost(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal, InventoryReceiptCostProposalService $proposals): RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $data = $request->validate([
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'approval_reference' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $this->guard(fn (): InventoryDocument => $proposals->approve(
            $request, $inventoryDocument, $proposal, $data['source_reference'], $data['approval_reference'],
        ));

        return to_route('admin.inventory.documents.show', $inventoryDocument)
            ->with('success', __('inventory.movements.messages.receipt_priced'));
    }

    public function rejectReceiptCost(Request $request, InventoryDocument $inventoryDocument, InventoryReceiptCostProposal $proposal, InventoryReceiptCostProposalService $proposals): RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->guard(fn (): InventoryReceiptCostProposal => $proposals->reject($request, $inventoryDocument, $proposal, $data['reason']));

        return to_route('admin.inventory.documents.show', $inventoryDocument)
            ->with('success', __('inventory.movements.messages.receipt_pricing_rejected'));
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
            422,
            __('An operating company, branch, and financial period are required.'),
        );

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
        ];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function issueableProductionBatches(array $context): Builder
    {
        $allowedRunStatuses = [ProductionRun::StatusPlanned, ProductionRun::StatusSetup, ProductionRun::StatusReady];

        return ProductionRunBatch::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereHas('runs')
            ->whereDoesntHave('runs', fn ($runs) => $runs->whereNotIn('status', $allowedRunStatuses))
            ->whereHas('runs.requirements', fn ($requirements) => $requirements
                ->whereColumn('planned_quantity', '>', 'issued_quantity'))
            ->orderByDesc('id');
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function issuableProductionMaterialRequests(array $context): Builder
    {
        return ProductionMaterialRequest::query()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->whereIn('status', [
                ProductionMaterialRequest::StatusApproved,
                ProductionMaterialRequest::StatusShortage,
                ProductionMaterialRequest::StatusPartiallyIssued,
            ])
            ->whereHas('lines', fn (Builder $lines): Builder => $lines
                ->whereColumn('reserved_quantity', '>', 'issued_quantity'));
    }

    private function authorizeProductionMaterialIssue(Request $request): void
    {
        abort_unless((bool) $request->user()?->canAny([
            'inventory.documents.issue',
            'production.material_requests.issue',
        ]), 403);
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function receivableProductionBatches(array $context): Builder
    {
        return ProductionRunBatch::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereHas('runs', fn ($runs) => $runs
                ->where('status', ProductionRun::StatusRunning)
                ->whereColumn('good_base_quantity', '>', 'received_base_quantity'))
            ->orderByDesc('id');
    }

    private function authorizeProductionRunBatchAction(Request $request): string
    {
        $documentType = $request->input('document_type', InventoryDocument::TypeIssue);
        $abilities = match ($documentType) {
            InventoryDocument::TypeIssue => ['inventory.documents.issue', 'production.runs.issue'],
            InventoryDocument::TypeReceipt => ['inventory.documents.receive', 'production.runs.receive'],
            default => abort(422, __('inventory.movements.messages.production_run_batch_type_invalid')),
        };

        abort_unless(collect($abilities)->every(fn (string $ability): bool => (bool) $request->user()?->can($ability)), 403);

        return $documentType;
    }

    private function assertReadableInCurrentContext(Request $request, InventoryDocument $inventoryDocument): void
    {
        $context = $this->requiredContext($request);
        abort_unless((int) $inventoryDocument->company_id === $context['company_id']
            && (int) $inventoryDocument->branch_id === $context['branch_id'], 404);
        $company = Company::query()->findOrFail($context['company_id']);
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($scope->allowedFinancialPeriodQuery($request->user(), [$company->doc_num])
            ->where('financial_periods.id', $inventoryDocument->financial_period_id)->exists()
            && $scope->allowedBranchQuery($request->user(), [$company->doc_num])->where('branches.id', $inventoryDocument->branch_id)->exists(), 404);
    }

    private function assertInCurrentContext(Request $request, InventoryDocument $inventoryDocument): void
    {
        $context = $this->requiredContext($request);

        abort_unless(
            (int) $inventoryDocument->company_id === $context['company_id']
            && (int) $inventoryDocument->financial_period_id === $context['financial_period_id']
            && (int) $inventoryDocument->branch_id === $context['branch_id'],
            404,
        );
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function movementPayload(StoreInventoryOperationRequest $request, array $context): array
    {
        $data = $request->validated();
        $sourceStore = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $data['branch_store_uuid'])
            ->firstOrFail();
        $destinationStore = filled($data['destination_branch_store_uuid'] ?? null)
            ? BranchStore::query()
                ->whereHas('branch', fn ($query) => $query->where('company_id', $context['company_id']))
                ->where('public_uuid', $data['destination_branch_store_uuid'])
                ->firstOrFail()
            : null;
        $products = Product::query()
            ->forCompany($context['company_id'])
            ->active()
            ->nonService()
            ->whereIn('doc_num', collect($data['lines'])->pluck('product_doc_num'))
            ->get()
            ->keyBy('doc_num');
        $lines = collect($data['lines'])->map(function (array $line) use ($products): array {
            $product = $products->get($line['product_doc_num']);

            return [
                ...collect($line)->except('product_doc_num')->all(),
                'product_id' => $product->getKey(),
            ];
        })->values()->all();

        return [[
            ...$context,
            ...collect($data)->except(['lines', 'submit_action', 'branch_store_uuid', 'destination_branch_store_uuid'])->all(),
            'branch_store_id' => $sourceStore->getKey(),
            'destination_branch_store_id' => $destinationStore?->getKey(),
            'purpose' => $data['movement_reason'],
        ], $lines];
    }

    private function submitRedirectUrl(Request $request, InventoryDocument $document, bool $posted): string
    {
        if ($posted) {
            return route('admin.inventory.documents.show', $document);
        }

        return match ($request->string('submit_action')->trim()->toString() ?: 'save') {
            'save_view' => route('admin.inventory.documents.show', $document),
            'save_back' => route('admin.inventory.documents.index'),
            'save_clone' => route('admin.inventory.documents.clone', $document),
            default => route('admin.inventory.documents.edit', $document),
        };
    }

    /** @return list<string> */
    private function allowedDocumentTypes(Request $request): array
    {
        return collect($this->manualDocumentTypePermissions())
            ->filter(fn (string $permission): bool => (bool) $request->user()?->can($permission))
            ->keys()
            ->all();
    }

    /** @return array<string, string> */
    private function manualDocumentTypePermissions(): array
    {
        return [
            InventoryDocument::TypeReceipt => 'inventory.documents.receive',
            InventoryDocument::TypeIssue => 'inventory.documents.issue',
            InventoryDocument::TypeReturn => 'inventory.documents.return',
            InventoryDocument::TypeTransfer => 'inventory.documents.transfer',
            InventoryDocument::TypeAdjustmentIn => 'inventory.documents.adjust',
            InventoryDocument::TypeAdjustmentOut => 'inventory.documents.adjust',
            InventoryDocument::TypeDamage => 'inventory.documents.damage_scrap',
            InventoryDocument::TypeScrap => 'inventory.documents.damage_scrap',
        ];
    }

    /** @return list<string> */
    private function stockStatuses(): array
    {
        return [
            InventoryTransaction::StatusAvailable,
            InventoryTransaction::StatusReserved,
            InventoryTransaction::StatusQcHold,
            InventoryTransaction::StatusQuarantine,
            InventoryTransaction::StatusRework,
            InventoryTransaction::StatusProductionStaging,
            InventoryTransaction::StatusWip,
            InventoryTransaction::StatusRejected,
            InventoryTransaction::StatusDamaged,
            InventoryTransaction::StatusScrap,
            InventoryTransaction::StatusInTransit,
        ];
    }

    private function respond(
        Request $request,
        array $data,
        string $redirectUrl,
        int $status = 200,
        string $message = 'inventory.movements.messages.posted',
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __($message),
                'redirect_url' => $redirectUrl,
                'data' => $data,
            ], $status);
        }

        return redirect()->to($redirectUrl)->with('success', __($message));
    }
}
