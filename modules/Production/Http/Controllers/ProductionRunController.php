<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrShift;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\ProductionShiftEvidenceRequest;
use Modules\Production\Http\Requests\RecordProductionLaborRequest;
use Modules\Production\Http\Requests\StoreProductionRunRequest;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;
use Modules\Production\Models\ProductionStageOutputCostOwner;
use Modules\Production\Models\ProductionStageTransfer;
use Modules\Production\Services\ProductionCorrectionContextService;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Modules\Production\Services\ProductionStageOutputCostService;
use Modules\Production\Services\ProductionStageTransferService;

class ProductionRunController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionCycleService $cycle,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        $this->requiredContext($request);

        return view('modules.production.runs.index');
    }

    public function create(Request $request): View
    {
        $this->requiredContext($request);

        return $this->form(null, false, $request);
    }

    public function orderLines(Request $request, DataTableSearchService $search, Select2ResponseService $select2, NumericFormatService $numbers): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = ProductionOrderLine::query()
            ->with(['order', 'product', 'unit'])
            ->whereHas('order', fn ($orders) => $orders
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('status', [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted]))
            ->orderByDesc('production_order_id')
            ->orderBy('line_number');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, [
                'text' => ['production_order_lines.description'],
                'exists' => [
                    ['table' => 'production_orders', 'first' => 'production_orders.id', 'second' => 'production_order_lines.production_order_id', 'columns' => ['production_orders.doc_num']],
                    ['table' => 'products', 'first' => 'products.id', 'second' => 'production_order_lines.product_id', 'columns' => ['products.doc_num', 'products.name', 'products.barcode']],
                ],
            ]);
        }

        return response()->json($select2->paginated($query, $request, fn (ProductionOrderLine $line): array => [
            'id' => $line->public_id,
            'text' => __('production_execution.runs.order_line_option', [
                'order' => $line->order->doc_num,
                'line' => $line->line_number,
                'product' => $line->product?->name ?? $line->description,
                'quantity' => $numbers->format($line->quantity),
                'unit' => $line->unit?->name ?? '',
            ]),
        ]));
    }

    public function ordersLookup(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = ProductionOrder::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('status', [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted])
            ->orderByDesc('doc_number');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'technical_notes', 'production_notes']]);
        }

        return response()->json($select2->paginated($query, $request, fn (ProductionOrder $order): array => [
            'id' => $order->doc_num,
            'text' => $order->doc_num.' — '.__('production_execution.statuses.'.$order->status),
        ]));
    }

    public function orderLinesForOrder(Request $request, string $docNum, NumericFormatService $numbers): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $order = ProductionOrder::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('status', [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted])
            ->with(['lines.product', 'lines.unit', 'lines.stageSnapshots', 'orderStageSnapshots'])
            ->firstOrFail();

        $hasOrderStages = $order->orderStageSnapshots->contains('is_required', true);

        return response()->json([
            'data' => [
                'order' => ['id' => $order->doc_num, 'number' => $order->doc_num],
                'lines' => $order->lines->map(fn (ProductionOrderLine $line): array => [
                    'id' => $line->public_id,
                    'text' => __('production_execution.runs.order_item_option', [
                        'line' => $line->line_number,
                        'product' => $line->product?->name ?? $line->description,
                        'quantity' => $numbers->format($line->quantity),
                        'unit' => $line->unit?->name ?? '',
                    ]),
                    'has_stages' => $hasOrderStages || $line->stageSnapshots->contains('is_required', true),
                ])->values(),
            ],
        ]);
    }

    public function stages(Request $request, Select2ResponseService $select2, NumericFormatService $numbers, DataTableSearchService $search): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $validated = $request->validate(['production_order_line_public_id' => ['required', 'uuid']]);
        $line = ProductionOrderLine::query()
            ->where('public_id', $validated['production_order_line_public_id'])
            ->whereHas('order', fn ($orders) => $orders
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id']))
            ->firstOrFail();
        $line->load('order');
        $stages = $this->cycle->stagesForLine($line->order, $line);
        $terms = $search->terms($request->input('q', $request->input('term')));
        $results = $stages->map(function (ProductionOrderStageSnapshot $stage) use ($line, $numbers): array {
            $committedBase = ProductionRun::query()
                ->where('production_order_line_id', $line->getKey())
                ->where('production_order_stage_snapshot_id', $stage->getKey())
                ->where('status', '<>', ProductionRun::StatusCancelled)
                ->get(['status', 'planned_base_quantity', 'good_base_quantity'])
                ->reduce(fn (string $total, ProductionRun $run): string => bcadd(
                    $total,
                    $run->status === ProductionRun::StatusCompleted
                        ? (string) $run->good_base_quantity
                        : (string) $run->planned_base_quantity,
                    8,
                ), '0.00000000');
            $remainingBase = bcsub((string) $line->base_quantity, $committedBase, 8);
            $remaining = bccomp($remainingBase, '0', 8) > 0
                ? bcdiv($remainingBase, (string) $line->conversion_factor, 8)
                : '0';

            return [
                'id' => $stage->public_id,
                'text' => __('production_execution.runs.stage_option', [
                    'sequence' => $stage->sequence,
                    'stage' => ($stage->production_order_line_id === null
                        ? __('production_execution.orders.order_route_short').' — '
                        : __('production_execution.orders.line_route_short').' — ').$stage->stage_name,
                    'remaining' => $numbers->format($remaining),
                ]),
            ];
        });
        if ($terms !== []) {
            $results = $results->filter(fn (array $stage): bool => collect($terms)->every(
                fn (string $term): bool => str_contains(mb_strtolower($stage['text']), mb_strtolower($term)),
            ))->values();
        }
        $page = max(1, $request->integer('page', 1));
        $perPage = $select2->perPage();

        return response()->json([
            'results' => $results->forPage($page, $perPage)->values()->all(),
            'pagination' => ['more' => $results->count() > $page * $perPage],
        ]);
    }

    public function assets(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = FixedAsset::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('status', FixedAsset::StatusActive)
            ->orderBy('asset_name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'asset_name', 'serial_number']]);
        }

        return response()->json($select2->paginated($query, $request, fn (FixedAsset $asset): array => [
            'id' => $asset->doc_num,
            'text' => trim($asset->doc_num.' — '.$asset->asset_name),
        ]));
    }

    public function machines(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = ProductionMachine::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('status', ProductionMachine::StatusAvailable)
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name']]);
        }

        return response()->json($select2->paginated($query, $request, fn (ProductionMachine $machine): array => [
            'id' => $machine->public_id,
            'text' => trim($machine->code.' — '.$machine->name),
        ]));
    }

    public function workersLookup(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = HrEmployee::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('person_type', ['regular_labor', 'casual_labor'])
            ->where('status', 'active')
            ->orderBy('full_name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'full_name', 'name', 'employee_code']]);
        }

        return response()->json($select2->paginated($query, $request, fn (HrEmployee $worker): array => [
            'id' => $request->string('identifier')->toString() === 'id' ? (string) $worker->id : $worker->doc_num,
            'text' => trim($worker->doc_num.' — '.($worker->full_name ?: $worker->name)),
        ]));
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->runs($request);
    }

    public function store(StoreProductionRunRequest $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        if (is_array($request->validated('lines'))) {
            $firstLine = ProductionOrderLine::query()
                ->with('order')
                ->findOrFail($request->validated('lines.0.production_order_line_id'));
            abort_unless(
                (int) $firstLine->order->company_id === (int) $context['company_id']
                && (int) $firstLine->order->financial_period_id === (int) $context['financial_period_id']
                && (int) $firstLine->order->branch_id === (int) $context['branch_id'],
                404,
            );
            $batch = $this->guard(fn (): ProductionRunBatch => $this->cycle->createRunBatch(
                $firstLine->order,
                $request->safe()->except('submit_action'),
            ));
            $url = route('admin.production.runs.batches.show', $batch);

            return $this->respond($request, ['batch_number' => $batch->batch_number, 'url' => $url], $url, 201);
        }

        $line = ProductionOrderLine::query()->with('order')->findOrFail($request->validated('production_order_line_id'));
        abort_unless(
            (int) $line->order->company_id === (int) $context['company_id']
            && (int) $line->order->financial_period_id === (int) $context['financial_period_id']
            && (int) $line->order->branch_id === (int) $context['branch_id'],
            404,
        );
        $run = $this->guard(fn (): ProductionRun => $this->cycle->createRun($line, $request->safe()->except(['production_order_line_id', 'submit_action'])));
        $url = $this->submitRedirectUrl($request, $run);

        return $this->respond($request, ['run_number' => $run->run_number, 'url' => $url], $url, 201);
    }

    public function edit(Request $request, ProductionRun $productionRun): View
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless($this->runCanBeChanged($productionRun), 409, __('production_execution.messages.run_plan_only'));

        return $this->form($productionRun, false, $request);
    }

    public function update(StoreProductionRunRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $record = $this->guard(fn (): ProductionRun => $this->cycle->updatePlannedRun(
            $productionRun,
            $request->safe()->except('submit_action'),
        ));
        $url = $this->submitRedirectUrl($request, $record);

        return $this->respond($request, ['run_number' => $record->run_number, 'url' => $url], $url);
    }

    public function clone(Request $request, ProductionRun $productionRun): View
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->form($productionRun, true, $request);
    }

    public function destroy(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $this->guard(fn () => $this->cycle->deletePlannedRun($productionRun));

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : to_route('admin.production.runs.index')->with('success', __('production_execution.messages.run_deleted'));
    }

    public function restore(Request $request, string $productionRun): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $record = ProductionRun::onlyTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('public_id', $productionRun)
            ->firstOrFail();
        $record = $this->guard(fn (): ProductionRun => $this->cycle->restorePlannedRun($record));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'run_number' => $record->run_number])
            : to_route('admin.production.runs.show', $record)->with('success', __('production_execution.messages.run_restored'));
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $context = $this->requiredContext($request);
        $validated = $request->validate([
            'doc_nums' => ['required', 'array', 'min:1', 'max:100'],
            'doc_nums.*' => ['required', 'uuid', 'distinct', Rule::exists('production_runs', 'public_id')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
        ]);

        DB::transaction(function () use ($validated, $context): void {
            $records = ProductionRun::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('public_id', $validated['doc_nums'])
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $this->cycle->deletePlannedRun($record);
            }
        });

        return response()->json(['success' => true, 'message' => __('production_execution.messages.bulk_delete_runs_done')]);
    }

    public function show(Request $request, ProductionRun $productionRun): View
    {
        $this->assertRunInCurrentContext($request, $productionRun, true);

        return view('modules.production.runs.show', [
            'record' => $productionRun->load([
                'order.salesOrder', 'orderLine.stageSnapshots', 'product', 'fixedAsset', 'stageSnapshot',
                'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
                'inventoryDocuments.journalEntry', 'materialRequests', 'expenseRequests',
            ]),
            'batchRuns' => app(ProductionHandoverService::class)->runs($productionRun),
            'linkedWarehouseDocuments' => InventoryDocument::query()->where('company_id', $productionRun->company_id)->where('branch_id', $productionRun->branch_id)
                ->whereHas('lines', fn ($query) => $query->where('production_run_id', $productionRun->id))
                ->whereIn('document_type', [InventoryDocument::TypeProductionHandover, InventoryDocument::TypeProductionReceipt])
                ->orderByDesc('id')->paginate(20, pageName: 'documents_page'),
            'stores' => BranchStore::query()->where('branch_id', $productionRun->branch_id)->orderBy('position')->get(),
            'shiftEntries' => Schema::hasTable('production_shift_entries') ? app(ProductionShiftEvidenceService::class)->report($productionRun) : collect(),
            'hrShifts' => HrShift::query()->where('status', 'active')->orderBy('name')->get(),
            'workers' => HrEmployee::withTrashed()->where('company_id', $productionRun->company_id)->where('branch_id', $productionRun->branch_id)
                ->whereIn('id', collect(old('labor_details', $productionRun->labor_details ?? []))->pluck('employee_id'))->get()->keyBy('id'),
        ]);
    }

    public function operation(Request $request, ProductionRun $productionRun, string $operation): View
    {
        $permissions = ['setup' => 'production.runs.setup', 'reserve' => 'production.runs.reserve', 'issue' => 'production.runs.issue',
            'return' => 'production.runs.issue', 'account' => 'production.runs.account_materials', 'labor' => 'production.runs.labor',
            'complete' => 'production.runs.complete', 'cancel' => 'production.runs.cancel', 'crew' => 'production.runs.setup',
            'checklist' => 'production.orders.release'];
        abort_unless(isset($permissions[$operation]), 404);
        Gate::authorize($permissions[$operation]);
        $this->assertRunInCurrentContext($request, $productionRun);
        $record = $productionRun->load(['order', 'orderLine', 'product', 'unit', 'requirements.product', 'requirements.unit', 'progressEntries', 'order.orderStageSnapshots']);
        $workers = HrEmployee::withTrashed()->where('company_id', $record->company_id)->where('branch_id', $record->branch_id)
            ->whereIn('id', collect(old('labor_details', $record->labor_details ?? []))->pluck('employee_id'))->get()->keyBy('id');
        $dailyEntries = $record->progressEntries()->whereNull('material_documents')->whereIn('production_shift_entry_id',
            app(ProductionShiftEvidenceService::class)->entries($record)->where('sheet_fields->entry_source', 'daily_sheet')->select('id'))->orderBy('recorded_at')->get();

        return view('modules.production.runs.operation', compact('record', 'operation', 'workers', 'dailyEntries') + [
            'stores' => BranchStore::query()->where('branch_id', $record->branch_id)->orderBy('position')->get(),
            'hrShifts' => HrShift::query()->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function showBatch(Request $request, ProductionRunBatch $productionRunBatch): View
    {
        $context = $this->requiredContext($request);
        abort_unless(
            (int) $productionRunBatch->company_id === (int) $context['company_id']
            && (int) $productionRunBatch->financial_period_id === (int) $context['financial_period_id']
            && (int) $productionRunBatch->branch_id === (int) $context['branch_id'],
            404,
        );
        $batch = $productionRunBatch->load([
            'order', 'runs.order', 'runs.product', 'runs.unit', 'runs.stageSnapshot',
            'runs.orderLine.product', 'runs.orderLine.unit', 'runs.requirements.product', 'runs.requirements.unit',
        ]);

        return view('modules.production.runs.batch-show', [
            'record' => $batch,
            'numbers' => app(NumericFormatService::class),
            'stores' => BranchStore::query()->where('branch_id', $productionRunBatch->branch_id)->orderBy('position')->get(),
            'materialDocuments' => InventoryDocument::query()
                ->where('production_run_batch_id', $productionRunBatch->getKey())
                ->where('status', InventoryDocument::StatusPosted)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function createBatchIssue(Request $request, ProductionRunBatch $productionRunBatch): View
    {
        Gate::authorize('production.runs.issue');
        $view = $this->showBatch($request, $productionRunBatch);

        return view('modules.production.runs.batch-issue', $view->getData());
    }

    public function issueBatch(Request $request, ProductionRunBatch $productionRunBatch): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        abort_unless(
            (int) $productionRunBatch->company_id === (int) $context['company_id']
            && (int) $productionRunBatch->financial_period_id === (int) $context['financial_period_id']
            && (int) $productionRunBatch->branch_id === (int) $context['branch_id'],
            404,
        );
        $this->normalizeMaterialLayerInput($request);
        $validated = $request->validate([
            'branch_store_id' => ['required', 'integer', Rule::exists(BranchStore::class, 'id')->where('branch_id', $context['branch_id'])],
            'warehouse_location_id' => ['prohibited'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.requirement_id' => ['required', 'integer', 'distinct'],
            ...$this->receiptLayerRules(),
        ]);
        $document = $this->guard(fn (): InventoryDocument => $this->cycle->issueRunBatchMaterials(
            $productionRunBatch,
            (int) $validated['branch_store_id'],
            null,
            $this->receiptLayersByRequirement($validated['lines'] ?? []),
        ));

        return $this->respond($request, ['doc_num' => $document->doc_num], route('admin.production.runs.batches.show', $productionRunBatch));
    }

    public function print(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, 'reports.production.run-sheet', __('production_execution.print.run_sheet'), 'production-run');
    }

    public function printMaterials(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, 'reports.production.run-materials', __('production_execution.print.material_requirement'), 'material-requirement');
    }

    public function printQuality(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, 'reports.production.run-quality', __('production_execution.print.in_process_quality'), 'production-quality');
    }

    public function printCompletion(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, 'reports.production.run-completion', __('production_execution.print.completion_summary'), 'production-completion');
    }

    private function printRunDocument(Request $request, ProductionRun $productionRun, string $view, string $documentTitle, string $filenamePrefix): Response
    {
        $this->assertRunInCurrentContext($request, $productionRun, true);
        $record = $productionRun->load([
            'order.company', 'order.salesOrder', 'orderLine.product.unit', 'orderLine.product.equivalentUnit', 'product', 'fixedAsset', 'stageSnapshot', 'shift',
            'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
        ]);

        return $this->pdf->stream($view, [
            'title' => $documentTitle,
            'record' => $record,
            'companyPrintIdentity' => $record->order->print_identity_snapshot ?: $this->printIdentity->forCompany($record->order->company),
        ], str($filenamePrefix.'-'.$record->run_number)->slug().'.pdf');
    }

    public function releaseOrder(Request $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
        $this->assertOrderInCurrentContext($request, $productionOrder);
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->releaseOrder($productionOrder));

        return $this->respond($request, ['doc_num' => $record->doc_num, 'status' => $record->status], route('admin.production.work-orders.show', $record));
    }

    public function reserve(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate(['branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'], 'warehouse_location_id' => ['prohibited']]);
        $record = $this->guard(fn (): ProductionRun => $this->cycle->reserveRun($productionRun, $validated['branch_store_id'], null));

        return $this->respond($request, ['run_number' => $record->run_number], route('admin.production.runs.show', $record));
    }

    public function issue(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $this->normalizeMaterialLayerInput($request);
        $validated = $request->validate([...$this->materialRules(), ...$this->receiptLayerRules()]);
        $document = $this->guard(fn () => $this->cycle->issueMaterials(
            $productionRun,
            $validated['branch_store_id'],
            $this->quantitiesByRequirement($validated['lines'] ?? []),
            $request->boolean('additional'),
            null,
            [],
            $this->receiptLayersByRequirement($validated['lines'] ?? []),
        ));

        return $this->respond($request, ['doc_num' => $document->doc_num], route('admin.production.runs.show', $productionRun));
    }

    public function returnMaterials(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate($this->materialRules());
        $document = $this->guard(fn () => $this->cycle->returnMaterials(
            $productionRun,
            $validated['branch_store_id'],
            $this->quantitiesByRequirement($validated['lines']),
            null,
            collect($validated['lines'])->mapWithKeys(fn (array $line): array => [$line['requirement_id'] => $line['serial_receipt_layer_ids'] ?? []])->all(),
        ));

        return $this->respond($request, ['doc_num' => $document->doc_num], route('admin.production.runs.show', $productionRun));
    }

    public function startSetup(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->startSetup($productionRun)));
    }

    public function completeSetup(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->completeSetup($productionRun)));
    }

    public function start(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->startRun($productionRun)));
    }

    public function resume(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->resumeRun($productionRun)));
    }

    public function cancel(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->cancelRun($productionRun, $validated['reason'])));
    }

    public function shiftDefaults(ProductionShiftEvidenceRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $shift = $this->guard(fn () => app(ProductionShiftEvidenceService::class)->saveDefaults($productionRun, $request->validated()));

        return $this->respond($request, ['shift_id' => $shift->id], route('admin.production.runs.show', $productionRun));
    }

    public function recordShift(ProductionShiftEvidenceRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $entry = $this->guard(fn () => app(ProductionShiftEvidenceService::class)->record($productionRun, $request->validated()));

        return $this->respond($request, ['entry_id' => $entry->id], route('admin.production.runs.show', $productionRun));
    }

    public function closeShift(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate(['entry_id' => ['required', 'integer', 'min:1'], 'ended_at' => ['required', 'date'],
            'downtime_minutes' => ['nullable', 'numeric', 'min:0', 'max:1440']]);
        $this->guard(fn () => app(ProductionShiftEvidenceService::class)->close($productionRun, (int) $data['entry_id'], $data['ended_at'], $data['downtime_minutes'] ?? null));

        return $this->respond($request, ['entry_id' => (int) $data['entry_id']], route('admin.production.runs.show', $productionRun));
    }

    public function printShift(Request $request, ProductionRun $productionRun): Response
    {
        $this->assertRunInCurrentContext($request, $productionRun, true);
        $productionRun->load(['order.company', 'order.branch', 'orderLine', 'product', 'fixedAsset', 'machine']);

        return $this->pdf->stream('reports.production.shift', ['title' => __('production_execution.shift_evidence.report_title'),
            'record' => $productionRun, 'shiftEntries' => app(ProductionShiftEvidenceService::class)->report($productionRun),
            'companyPrintIdentity' => $productionRun->order->print_identity_snapshot ?: $this->printIdentity->forCompany($productionRun->order->company)],
            str('production-shift-'.$productionRun->run_number)->slug().'.pdf', 'L');
    }

    public function stageTransfers(Request $request, ProductionRun $productionRun): View
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $service = app(ProductionStageTransferService::class);
        $stages = $service->stages($productionRun);
        $index = $stages->search(fn ($stage): bool => (int) $stage->id === (int) $productionRun->production_order_stage_snapshot_id);
        $next = $index === false ? null : $stages->get($index + 1);
        $targets = ProductionRun::query()->where('company_id', $productionRun->company_id)->where('branch_id', $productionRun->branch_id)
            ->where('financial_period_id', $productionRun->financial_period_id)->where('production_order_line_id', $productionRun->production_order_line_id)
            ->where('production_order_stage_snapshot_id', $next?->id ?? 0)->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])->orderBy('id')->get();
        $target = $targets->firstWhere('id', $request->integer('target')) ?? ($targets->count() === 1 ? $targets->first() : null);
        $preview = null;
        $blocker = null;
        $ready = Schema::hasTable('production_stage_transfers');
        if (! $ready) {
            $blocker = __('production_stage_transfer.migration_required');
        } elseif ($target !== null && $service->isManaged($productionRun) && $service->isManaged($target)) {
            try {
                $preview = $service->preview($productionRun, $target);
            } catch (DomainException $exception) {
                $blocker = $exception->getMessage();
            }
        }
        $transfers = $ready ? ProductionStageTransfer::query()->where('company_id', $productionRun->company_id)
            ->where(fn ($query) => $query->where('source_run_id', $productionRun->id)->orWhere('target_run_id', $productionRun->id))
            ->with(['sourceRun', 'targetRun'])->latest('id')->paginate(20) : null;

        return view('modules.production.runs.stage-transfers', ['record' => $productionRun, 'targets' => $targets,
            'target' => $target, 'preview' => $preview, 'blocker' => $blocker, 'transfers' => $transfers, 'schemaReady' => $ready]);
    }

    public function prepareStageTransfer(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate(['target_run_id' => ['required', 'integer', 'min:1'], 'base_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'], 'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'fingerprint' => ['required', 'string', 'size:64'], '_submission_token' => ['required', 'uuid']]);
        $target = ProductionRun::query()->where('company_id', $productionRun->company_id)->where('branch_id', $productionRun->branch_id)
            ->where('financial_period_id', $productionRun->financial_period_id)->findOrFail($data['target_run_id']);
        $owner = $this->guard(fn () => app(ProductionStageTransferService::class)->prepare($productionRun, $target,
            (string) $data['base_quantity'], $data['reason'], $data['evidence'], $data['fingerprint'], $data['_submission_token']));

        return $this->respond($request, ['transfer_id' => $owner->id], route('admin.production.runs.stage-transfers.index', $productionRun));
    }

    public function approveStageTransfer(Request $request, ProductionRun $productionRun, ProductionStageTransfer $transfer): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless(in_array((int) $productionRun->id, [(int) $transfer->source_run_id, (int) $transfer->target_run_id], true), 404);
        $owner = $this->guard(fn () => app(ProductionStageTransferService::class)->approve($transfer));

        return $this->respond($request, ['transfer_id' => $owner->id], route('admin.production.runs.stage-transfers.index', $productionRun));
    }

    public function rejectStageTransfer(Request $request, ProductionRun $productionRun, ProductionStageTransfer $transfer): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless(in_array((int) $productionRun->id, [(int) $transfer->source_run_id, (int) $transfer->target_run_id], true), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $owner = $this->guard(fn () => app(ProductionStageTransferService::class)->reject($transfer, $data['reason']));

        return $this->respond($request, ['transfer_id' => $owner->id], route('admin.production.runs.stage-transfers.index', $productionRun));
    }

    public function reverseStageTransfer(Request $request, ProductionRun $productionRun, ProductionStageTransfer $transfer): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless(in_array((int) $productionRun->id, [(int) $transfer->source_run_id, (int) $transfer->target_run_id], true), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $owner = $this->guard(fn () => app(ProductionStageTransferService::class)->reverse($transfer, $data['reason']));

        return $this->respond($request, ['transfer_id' => $owner->id], route('admin.production.runs.stage-transfers.index', $productionRun));
    }

    public function stageOutputCosts(Request $request, ProductionRun $productionRun): View
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $service = app(ProductionStageOutputCostService::class);
        $preview = null;
        $blocker = null;
        $ready = Schema::hasTable('production_stage_output_cost_owners');
        try {
            $preview = $service->preview($productionRun);
        } catch (DomainException $exception) {
            $blocker = $exception->getMessage();
        }
        $owners = $ready ? ProductionStageOutputCostOwner::query()->where('company_id', $productionRun->company_id)
            ->where('production_run_id', $productionRun->id)->latest('id')->paginate(20) : null;
        $active = $ready ? ProductionStageOutputCostOwner::query()->where('company_id', $productionRun->company_id)
            ->where('production_run_id', $productionRun->id)->where('kind', 'allocation')->where('status', 'posted')->first() : null;
        $recovery = $active === null ? null : $this->guard(fn () => $service->recoveryPreview($active));
        $quality = $productionRun->inspections()->whereNull('production_quality_output_batch_id')
            ->whereIn('status', ['approved', 'closed'])->whereNotNull('approved_at')->orderByDesc('id')->limit(100)->get();

        return view('modules.production.runs.stage-output-costs', ['record' => $productionRun, 'preview' => $preview, 'blocker' => $blocker,
            'owners' => $owners, 'activeOwner' => $active, 'recovery' => $recovery, 'quality' => $quality]);
    }

    public function prepareStageOutputCosts(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate(['scrap_treatment' => ['required', Rule::in(['none', 'normal', 'abnormal'])], 'completed_stage_units' => ['accepted'],
            'quality_ids' => ['nullable', 'array:rejected,rework,scrap'], 'quality_ids.*' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'], 'evidence' => ['required', 'string', 'min:5', 'max:2000'],
            'fingerprint' => ['required', 'string', 'size:64'], '_submission_token' => ['required', 'uuid']]);
        $qualityIds = [];
        foreach (['rejected', 'rework', 'scrap'] as $kind) {
            if (filled($data['quality_ids'][$kind] ?? null)) {
                $qualityIds[$kind] = (int) $data['quality_ids'][$kind];
            }
        }
        $owner = $this->guard(fn () => app(ProductionStageOutputCostService::class)->prepareAllocation($productionRun,
            $data['scrap_treatment'], $request->boolean('completed_stage_units'), $qualityIds, $data['reason'], $data['evidence'], $data['fingerprint'], $data['_submission_token']));

        return $this->respond($request, ['owner_id' => $owner->id], route('admin.production.runs.stage-output-costs.index', $productionRun));
    }

    public function prepareStageOutputRecovery(Request $request, ProductionRun $productionRun, ProductionStageOutputCostOwner $outputOwner): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless((int) $outputOwner->production_run_id === (int) $productionRun->id, 404);
        $data = $request->validate(['output_kind' => ['required', Rule::in(['rejected', 'rework'])], 'base_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'quality_inspection_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000'], 'fingerprint' => ['required', 'string', 'size:64'], '_submission_token' => ['required', 'uuid']]);
        $owner = $this->guard(fn () => app(ProductionStageOutputCostService::class)->prepareRecovery($outputOwner, $data['output_kind'], (string) $data['base_quantity'],
            (int) $data['quality_inspection_id'], $data['reason'], $data['evidence'], $data['fingerprint'], $data['_submission_token']));

        return $this->respond($request, ['owner_id' => $owner->id], route('admin.production.runs.stage-output-costs.index', $productionRun));
    }

    public function approveStageOutputCosts(Request $request, ProductionRun $productionRun, ProductionStageOutputCostOwner $outputOwner): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless((int) $outputOwner->production_run_id === (int) $productionRun->id, 404);
        $owner = $this->guard(fn () => app(ProductionStageOutputCostService::class)->approve($outputOwner));

        return $this->respond($request, ['owner_id' => $owner->id], route('admin.production.runs.stage-output-costs.index', $productionRun));
    }

    public function rejectStageOutputCosts(Request $request, ProductionRun $productionRun, ProductionStageOutputCostOwner $outputOwner): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless((int) $outputOwner->production_run_id === (int) $productionRun->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $owner = $this->guard(fn () => app(ProductionStageOutputCostService::class)->reject($outputOwner, $data['reason']));

        return $this->respond($request, ['owner_id' => $owner->id], route('admin.production.runs.stage-output-costs.index', $productionRun));
    }

    public function reverseStageOutputCosts(Request $request, ProductionRun $productionRun, ProductionStageOutputCostOwner $outputOwner): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        abort_unless((int) $outputOwner->production_run_id === (int) $productionRun->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $owner = $this->guard(fn () => app(ProductionStageOutputCostService::class)->reverse($outputOwner, $data['reason']));

        return $this->respond($request, ['owner_id' => $owner->id], route('admin.production.runs.stage-output-costs.index', $productionRun));
    }

    public function outputEvidence(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        if (! $request->has('requirements') && ! $productionRun->requirements()->exists()) {
            $request->merge(['requirements' => []]);
        }
        $data = $request->validate([
            'execution_structure' => ['required', Rule::in(['physical_route', 'factory_workflow'])],
            'requirements' => ['present', 'array'],
            'requirements.*.requirement_public_id' => ['required', 'uuid', 'distinct'],
            'requirements.*.basis' => ['required', Rule::in(['output_components', 'measured_material'])],
            'stage_roles' => ['nullable', 'array'],
            'stage_roles.*.stage_public_id' => ['required', 'uuid', 'distinct'],
            'stage_roles.*.role' => ['nullable', Rule::in(['checklist', 'manufacturing', 'quality_notification', 'quality', 'receipt'])],
            'stage_roles.*.confirmation_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $record = $this->guard(fn () => $this->cycle->enableOutputEvidence($productionRun, $data['requirements'], $data['execution_structure'], $data['stage_roles'] ?? []));

        return $this->respond($request, ['run_number' => $record->run_number], route('admin.production.runs.show', $record));
    }

    public function confirmChecklist(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate(['stage_public_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->guard(fn () => $this->cycle->confirmWorkflowChecklist($productionRun, $data['stage_public_id'], $data['reason']));

        return $this->respond($request, [], route('admin.production.runs.show', $productionRun));
    }

    public function progress(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate([
            'good_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'stage_input_base_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'rejected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'rework_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'scrap_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'good_weight_kg' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8'],
            'production_scrap_weight_kg' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'notes' => ['nullable', 'string'],
            'material_evidence' => ['nullable', 'array', 'max:1000'],
            'production_shift_entry_id' => ['nullable', 'integer', 'min:1'],
            'material_evidence.*.requirement_public_id' => ['required', 'uuid', 'distinct'],
            'material_evidence.*.measured_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'material_evidence.*.waste_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'material_evidence.*.waste_classification' => ['nullable', Rule::in(['process_scrap', 'packaging_loss', 'roll_trim', 'rejected_output'])],
            'material_evidence.*.notes' => ['nullable', 'string', 'max:2000'],
            'material_evidence.*.consumed_receipt_layer_ids' => ['nullable', 'array', 'max:10000'],
            'material_evidence.*.consumed_receipt_layer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'material_evidence.*.waste_receipt_layer_ids' => ['nullable', 'array', 'max:10000'],
            'material_evidence.*.waste_receipt_layer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $entry = $this->guard(fn () => $this->cycle->recordProgress($productionRun, $data));

        return $this->respond($request, ['entry' => $entry->public_id], route('admin.production.runs.show', $productionRun));
    }

    public function labor(RecordProductionLaborRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $record = $this->guard(fn (): ProductionRun => $this->cycle->recordLabor($productionRun, $request->validated()));

        return $this->respond($request, [
            'run_number' => $record->run_number,
            'actual_labor_count' => $record->actual_labor_count,
            'total_labor_hours' => $record->totalLaborHours(),
        ], route('admin.production.runs.show', $record));
    }

    public function account(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate([
            'branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'],
            'warehouse_location_id' => ['prohibited'],
            'daily_progress_public_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.requirement_id' => ['required', 'integer', 'distinct', 'exists:production_material_requirements,id'],
            'lines.*.consumed_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.waste_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.waste_classification' => ['nullable', Rule::in(['process_scrap', 'packaging_loss', 'roll_trim', 'rejected_output'])],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.consumed_receipt_layer_ids' => ['nullable', 'array', 'max:10000'],
            'lines.*.consumed_receipt_layer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.waste_receipt_layer_ids' => ['nullable', 'array', 'max:10000'],
            'lines.*.waste_receipt_layer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $accounting = collect($validated['lines'])->mapWithKeys(fn (array $line): array => [
            $line['requirement_id'] => [
                'consumed_quantity' => $line['consumed_quantity'],
                'waste_quantity' => $line['waste_quantity'],
                'waste_classification' => $line['waste_classification'] ?? null,
                'notes' => $line['notes'] ?? null,
                'consumed_receipt_layer_ids' => $line['consumed_receipt_layer_ids'] ?? [],
                'waste_receipt_layer_ids' => $line['waste_receipt_layer_ids'] ?? [],
            ],
        ])->all();
        $dailyProgress = filled($validated['daily_progress_public_id'] ?? null)
            ? $productionRun->progressEntries()->where('public_id', $validated['daily_progress_public_id'])->firstOrFail() : null;
        $documents = $this->guard(fn (): array => $this->cycle->accountMaterials(
            $productionRun,
            $validated['branch_store_id'],
            $accounting,
            null,
            $dailyProgress,
        ));

        return $this->respond($request, collect($documents)->map->doc_num->all(), route('admin.production.runs.show', $productionRun));
    }

    public function receive(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        throw ValidationException::withMessages(['document' => __('production_handover.old_direct_receipt_disabled')]);
    }

    public function complete(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->completeRun($productionRun)));
    }

    public function shortClose(Request $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
        $this->assertOrderInCurrentContext($request, $productionOrder);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->shortCloseOrder($productionOrder, $validated['reason']));

        return $this->respond($request, ['doc_num' => $record->doc_num, 'status' => $record->status], route('admin.production.work-orders.show', $record));
    }

    /** @return array<string, mixed> */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('production_execution.messages.operating_context_required'));

        return $context;
    }

    private function authorizeLookup(Request $request): void
    {
        abort_unless($request->user()?->canAny([
            'production.runs.view',
            'production.runs.plan',
            'production.runs.edit',
            'production.runs.clone',
            'production.runs.labor',
        ]), 403);
    }

    private function assertRunInCurrentContext(Request $request, ProductionRun $productionRun, bool $allowCorrectionRead = false): void
    {
        $context = $this->requiredContext($request);
        $periodId = (int) $productionRun->financial_period_id;
        if ($periodId !== (int) $context['financial_period_id']) {
            $periodId = app(ProductionCorrectionContextService::class)->executionPeriodId($productionRun);
            if ($periodId !== (int) $context['financial_period_id'] && $allowCorrectionRead
                && $request->user()?->can('production.runs.correct_later_period')
                && $request->user()?->canAny(['production.runs.correct', 'production.runs.correct_approve'])) {
                $company = app(OperatingCompanyContextService::class)->currentCompany();
                abort_unless($company !== null && app(OperatingScopeAccessService::class)
                    ->allowedFinancialPeriodQuery($request->user(), [$company->doc_num])->where('financial_periods.id', $productionRun->financial_period_id)->exists(), 404);
                $periodId = (int) $context['financial_period_id'];
            }
        }

        abort_unless(
            (int) $productionRun->company_id === (int) $context['company_id']
            && $periodId === (int) $context['financial_period_id']
            && (int) $productionRun->branch_id === (int) $context['branch_id'],
            404,
        );
    }

    private function form(?ProductionRun $record, bool $isClone, Request $request): View
    {
        $record?->loadMissing(['order', 'orderLine.product', 'orderLine.unit', 'stageSnapshot', 'fixedAsset']);

        $context = $record === null ? $this->requiredContext($request) : [
            'company_id' => $record->company_id,
            'branch_id' => $record->branch_id,
        ];
        $orderDocNum = $record?->order?->doc_num ?? old('production_order_doc_num');
        $orderLabel = $record?->order?->doc_num;
        if ($orderLabel === null && filled($orderDocNum)) {
            $orderLabel = ProductionOrder::query()
                ->where('doc_num', $orderDocNum)
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->value('doc_num');
        }
        $asset = $record?->fixedAsset;
        if (! $asset && filled(old('fixed_asset_doc_num'))) {
            $asset = FixedAsset::query()
                ->where('doc_num', old('fixed_asset_doc_num'))
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('status', FixedAsset::StatusActive)
                ->first();
        }
        $assetOption = $asset
            ? ['id' => $asset->doc_num, 'text' => $asset->doc_num.' — '.$asset->asset_name]
            : null;

        return view('modules.production.runs.form', compact('record', 'isClone', 'orderDocNum', 'orderLabel', 'assetOption'));
    }

    private function runCanBeChanged(ProductionRun $run): bool
    {
        if ($run->status !== ProductionRun::StatusPlanned) {
            return false;
        }

        return ! $run->reservations()->exists()
            && ! $run->progressEntries()->exists()
            && ! $run->inspections()->exists()
            && ! $run->inventoryDocuments()->exists()
            && ! $run->materialRequests()->exists()
            && ! $run->expenseRequests()->exists()
            && ! $run->requirements()->where(function ($query): void {
                $query->where('reserved_quantity', '>', 0)
                    ->orWhere('issued_quantity', '>', 0)
                    ->orWhere('additional_issued_quantity', '>', 0)
                    ->orWhere('returned_quantity', '>', 0)
                    ->orWhere('consumed_quantity', '>', 0)
                    ->orWhere('waste_quantity', '>', 0);
            })->exists();
    }

    private function assertOrderInCurrentContext(Request $request, ProductionOrder $productionOrder): void
    {
        $context = $this->requiredContext($request);

        abort_unless(
            (int) $productionOrder->company_id === (int) $context['company_id']
            && (int) $productionOrder->financial_period_id === (int) $context['financial_period_id']
            && (int) $productionOrder->branch_id === (int) $context['branch_id'],
            404,
        );
    }

    /** @return array<string, mixed> */
    private function materialRules(): array
    {
        return [
            'branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'],
            'warehouse_location_id' => ['prohibited'],
            'additional' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.requirement_id' => ['required', 'integer', 'distinct', 'exists:production_material_requirements,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.serial_receipt_layer_ids' => ['nullable', 'array', 'max:10000'],
            'lines.*.serial_receipt_layer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array<string, mixed> */
    private function receiptLayerRules(): array
    {
        return [
            'lines.*.receipt_layers' => ['nullable', 'array', 'max:100'],
            'lines.*.receipt_layers.*' => ['array'],
            'lines.*.receipt_layers.*.layer_id' => ['required_with:lines.*.receipt_layers.*.quantity', 'nullable', 'integer', 'min:1'],
            'lines.*.receipt_layers.*.quantity' => ['required_with:lines.*.receipt_layers.*.layer_id', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    private function normalizeMaterialLayerInput(Request $request): void
    {
        if (! is_array($request->input('lines'))) {
            return;
        }
        $numbers = app(NumericFormatService::class);
        $request->merge(['lines' => array_map(function (mixed $line) use ($numbers): mixed {
            if (! is_array($line)) {
                return $line;
            }
            if (array_key_exists('quantity', $line)) {
                $line['quantity'] = $numbers->normalizeForValidation($line['quantity']);
            }
            if (is_array($line['receipt_layers'] ?? null)) {
                $line['receipt_layers'] = array_map(function (mixed $selection) use ($numbers): mixed {
                    if (is_array($selection)) {
                        $selection['quantity'] = $numbers->normalizeForValidation($selection['quantity'] ?? null);
                    }

                    return $selection;
                }, $line['receipt_layers']);
            }

            return $line;
        }, $request->input('lines'))]);
    }

    /** @param list<array<string, mixed>> $lines @return array<int, list<array<string, mixed>>> */
    private function receiptLayersByRequirement(array $lines): array
    {
        return collect($lines)->mapWithKeys(fn (array $line): array => [(int) $line['requirement_id'] => collect($line['receipt_layers'] ?? [])
            ->filter(fn (array $selection): bool => filled($selection['layer_id'] ?? null))->values()->all()])->all();
    }

    /** @param list<array<string, mixed>> $lines @return array<int, mixed> */
    private function quantitiesByRequirement(array $lines): array
    {
        return collect($lines)
            ->filter(fn (array $line): bool => filled($line['quantity'] ?? null))
            ->mapWithKeys(fn (array $line): array => [$line['requirement_id'] => $line['quantity']])
            ->all();
    }

    private function runTransition(Request $request, ProductionRun $run): JsonResponse|RedirectResponse
    {
        return $this->respond($request, ['run_number' => $run->run_number, 'status' => $run->status], route('admin.production.runs.show', $run));
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['production' => $exception->getMessage()]);
        }
    }

    private function respond(Request $request, array $data, string $redirectUrl, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], $status);
        }

        return redirect()->to($redirectUrl)->with('success', __('production_execution.messages.operation_completed'));
    }

    private function submitRedirectUrl(Request $request, ProductionRun $record): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        return match ($action) {
            'save_view' => route('admin.production.runs.show', $record),
            'save_back' => route('admin.production.runs.index'),
            'save_clone' => route('admin.production.runs.clone', $record),
            'save', 'save_edit' => $request->user()?->can('production.runs.edit') && $this->runCanBeChanged($record)
                ? route('admin.production.runs.edit', $record)
                : route('admin.production.runs.show', $record),
            default => route('admin.production.runs.show', $record),
        };
    }

    private function workers(int $companyId, int $branchId): mixed
    {
        return HrEmployee::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('person_type', ['regular_labor', 'casual_labor'])
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'doc_num', 'full_name', 'name', 'job_title']);
    }
}
