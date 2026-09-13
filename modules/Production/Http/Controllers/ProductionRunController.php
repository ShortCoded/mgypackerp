<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Http\Requests\RecordProductionLaborRequest;
use Modules\Production\Http\Requests\StoreProductionRunRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
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
        $context = $this->requiredContext($request);

        return view('modules.production.runs.index', [
            'orders' => ProductionOrder::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereIn('status', [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted])
                ->with(['lines.product', 'lines.stageSnapshots'])
                ->orderByDesc('production_order_date')
                ->get(),
            'assets' => FixedAsset::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('status', FixedAsset::StatusActive)->orderBy('asset_name')->get(),
        ]);
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
        $run = $this->guard(fn (): ProductionRun => $this->cycle->createRun($line, $request->safe()->except('production_order_line_id')));

        $url = route('admin.production.runs.show', $run);

        return $this->respond($request, ['run_number' => $run->run_number, 'url' => $url], $url, 201);
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
        ]);
    }

    public function print(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, __('Production Run Sheet'), 'production-run');
    }

    public function printMaterials(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, __('Material Requirement'), 'material-requirement');
    }

    public function printQuality(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, __('In-Process Quality Inspection'), 'production-quality');
    }

    public function printCompletion(Request $request, ProductionRun $productionRun): Response
    {
        return $this->printRunDocument($request, $productionRun, __('Production Completion Summary'), 'production-completion');
    }

    private function printRunDocument(Request $request, ProductionRun $productionRun, string $documentTitle, string $filenamePrefix): Response
    {
        $this->assertRunInCurrentContext($request, $productionRun);
        $record = $productionRun->load([
            'order.company', 'order.salesOrder', 'orderLine', 'product', 'fixedAsset', 'stageSnapshot',
            'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
        ]);

        return $this->pdf->stream('reports.production.run-sheet', [
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
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return $context;
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

        return redirect()->to($redirectUrl)->with('success', __('Production operation completed.'));
    }
}
