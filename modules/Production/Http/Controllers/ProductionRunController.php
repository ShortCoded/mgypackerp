<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\RecordProductionLaborRequest;
use Modules\Production\Http\Requests\StoreProductionRunRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionShift;
use Modules\Production\Services\ProductionCycleService;

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

        return $this->form(null, false);
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

    public function stages(Request $request, Select2ResponseService $select2, NumericFormatService $numbers): JsonResponse
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
        $query = $line->stageSnapshots()->withSum([
            'runs as planned_base_quantity_sum' => fn ($runs) => $runs->where('status', '<>', ProductionRun::StatusCancelled),
        ], 'planned_base_quantity')->orderBy('sequence');

        return response()->json($select2->paginated($query, $request, function (ProductionOrderStageSnapshot $stage) use ($line, $numbers): array {
            $remainingBase = bcsub((string) $line->base_quantity, (string) ($stage->planned_base_quantity_sum ?? 0), 8);
            $remaining = bccomp($remainingBase, '0', 8) > 0
                ? bcdiv($remainingBase, (string) $line->conversion_factor, 8)
                : '0';

            return [
                'id' => $stage->public_id,
                'text' => __('production_execution.runs.stage_option', [
                    'sequence' => $stage->sequence,
                    'stage' => $stage->stage_name,
                    'remaining' => $numbers->format($remaining),
                ]),
            ];
        }));
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

    public function costCenters(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $this->authorizeLookup($request);
        $context = $this->requiredContext($request);
        $query = CostCenter::query()
            ->forCompany($context['company_id'])
            ->active()
            ->where('is_group', false)
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name']]);
        }

        return response()->json($select2->paginated($query, $request, fn (CostCenter $costCenter): array => [
            'id' => $costCenter->doc_num,
            'text' => trim($costCenter->doc_num.' — '.$costCenter->name),
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
            'id' => $worker->doc_num,
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

        return $this->form($productionRun, false);
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

        return $this->form($productionRun, true);
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
        $this->assertRunInCurrentContext($request, $productionRun);

        return view('modules.production.runs.show', [
            'record' => $productionRun->load([
                'order.salesOrder', 'orderLine.stageSnapshots', 'product', 'fixedAsset', 'stageSnapshot',
                'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
                'inventoryDocuments.journalEntry', 'materialRequests', 'expenseRequests',
            ]),
            'stores' => BranchStore::query()->where('branch_id', $productionRun->branch_id)->orderBy('position')->get(),
            'workers' => $this->workers((int) $productionRun->company_id, (int) $productionRun->branch_id),
        ]);
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
        $this->assertRunInCurrentContext($request, $productionRun);
        $record = $productionRun->load([
            'order.company', 'order.salesOrder', 'orderLine', 'product', 'fixedAsset', 'stageSnapshot', 'shift',
            'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
        ]);

        return $this->pdf->stream($view, [
            'title' => $documentTitle.' — '.$record->run_number,
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
        $validated = $request->validate(['branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'], 'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id']]);
        $record = $this->guard(fn (): ProductionRun => $this->cycle->reserveRun($productionRun, $validated['branch_store_id'], $validated['warehouse_location_id'] ?? null));

        return $this->respond($request, ['run_number' => $record->run_number], route('admin.production.runs.show', $record));
    }

    public function issue(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate($this->materialRules());
        $document = $this->guard(fn () => $this->cycle->issueMaterials(
            $productionRun,
            $validated['branch_store_id'],
            $this->quantitiesByRequirement($validated['lines'] ?? []),
            $request->boolean('additional'),
            $validated['warehouse_location_id'] ?? null,
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
            $validated['warehouse_location_id'] ?? null,
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

    public function progress(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $data = $request->validate([
            'good_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'rejected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'rework_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'scrap_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
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
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.requirement_id' => ['required', 'integer', 'exists:production_material_requirements,id'],
            'lines.*.consumed_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.waste_quantity' => ['required', 'numeric', 'min:0'],
        ]);
        $accounting = collect($validated['lines'])->mapWithKeys(fn (array $line): array => [
            $line['requirement_id'] => [
                'consumed_quantity' => $line['consumed_quantity'],
                'waste_quantity' => $line['waste_quantity'],
            ],
        ])->all();
        $documents = $this->guard(fn (): array => $this->cycle->accountMaterials(
            $productionRun,
            $validated['branch_store_id'],
            $accounting,
            $validated['warehouse_location_id'] ?? null,
        ));

        return $this->respond($request, collect($documents)->map->doc_num->all(), route('admin.production.runs.show', $productionRun));
    }

    public function receive(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $validated = $request->validate([
            'branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'base_quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $document = $this->guard(fn () => $this->cycle->receiveFinishedGoods(
            $productionRun,
            $validated['branch_store_id'],
            (string) $validated['base_quantity'],
            $validated['warehouse_location_id'] ?? null,
        ));

        return $this->respond($request, ['doc_num' => $document->doc_num], route('admin.production.runs.show', $productionRun));
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
        ]), 403);
    }

    private function assertRunInCurrentContext(Request $request, ProductionRun $productionRun): void
    {
        $context = $this->requiredContext($request);

        abort_unless(
            (int) $productionRun->company_id === (int) $context['company_id']
            && (int) $productionRun->financial_period_id === (int) $context['financial_period_id']
            && (int) $productionRun->branch_id === (int) $context['branch_id'],
            404,
        );
    }

    private function form(?ProductionRun $record, bool $isClone): View
    {
        $record?->loadMissing(['order', 'orderLine.product', 'orderLine.unit', 'stageSnapshot', 'fixedAsset', 'costCenter']);

        $context = $record === null
            ? null
            : ['company_id' => $record->company_id, 'branch_id' => $record->branch_id];
        $shifts = $context === null
            ? collect()
            : ProductionShift::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('is_active', true)
                ->orderBy('starts_at')
                ->get();

        if ($context === null) {
            $snapshot = $this->context->snapshot(request());
            $shifts = ProductionShift::query()
                ->where('company_id', $snapshot['company_id'])
                ->where('branch_id', $snapshot['branch_id'])
                ->where('is_active', true)
                ->orderBy('starts_at')
                ->get();
        }

        return view('modules.production.runs.form', compact('record', 'isClone', 'shifts'));
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
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'additional' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.requirement_id' => ['required', 'integer', 'exists:production_material_requirements,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
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
