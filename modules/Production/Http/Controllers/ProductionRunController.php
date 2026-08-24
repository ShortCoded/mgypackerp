<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Http\Requests\StoreProductionRunRequest;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionShift;
use Modules\Production\Services\ProductionCycleService;

class ProductionRunController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly ProductionCycleService $cycle,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.production.runs.index', [
            'records' => ProductionRun::query()
                ->where('company_id', $context['company_id'])
                ->with(['order', 'product', 'machine', 'mold'])
                ->latest('planned_start_at')
                ->paginate(30)
                ->withQueryString(),
            'orders' => ProductionOrder::query()
                ->where('company_id', $context['company_id'])
                ->whereIn('status', [ProductionOrder::StatusReleased, ProductionOrder::StatusInProgress, ProductionOrder::StatusPartiallyCompleted])
                ->with('lines.product')
                ->orderByDesc('production_order_date')
                ->get(),
            'machines' => ProductionMachine::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('status', ProductionMachine::StatusAvailable)->orderBy('code')->get(),
            'molds' => ProductionMold::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('status', ProductionMold::StatusAvailable)->orderBy('code')->get(),
            'shifts' => ProductionShift::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('is_active', true)->orderBy('starts_at')->get(),
            'finishedProducts' => Product::query()->forCompany($context['company_id'])->where('item_classification', Product::ClassificationFinishedProduct)->active()->with(['unit', 'equivalentUnit'])->orderBy('name')->get(),
            'finishedUnits' => ItemUnit::query()->forCompany($context['company_id'])->active()->orderBy('name')->get(),
        ]);
    }

    public function storeMakeToStockOrder(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'expected_start_date' => ['nullable', 'date'],
            'expected_finish_date' => ['nullable', 'date', 'after_or_equal:expected_start_date'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'overproduction_tolerance_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'production_notes' => ['nullable', 'string'],
        ]);
        $order = $this->guard(fn (): ProductionOrder => $this->cycle->createMakeToStockOrder([
            ...$context,
            ...collect($data)->except(['product_id', 'unit_id', 'quantity'])->all(),
        ], [[
            'product_id' => $data['product_id'],
            'unit_id' => $data['unit_id'] ?? null,
            'quantity' => $data['quantity'],
        ]]));

        $url = route('admin.production.work-orders.show', $order);

        return $this->respond($request, ['doc_num' => $order->doc_num, 'url' => $url], $url, 201);
    }

    public function store(StoreProductionRunRequest $request): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $line = ProductionOrderLine::query()->findOrFail($request->validated('production_order_line_id'));
        abort_unless((int) $line->order()->value('company_id') === $context['company_id'], 404);
        $run = $this->guard(fn (): ProductionRun => $this->cycle->createRun($line, $request->safe()->except('production_order_line_id')));

        $url = route('admin.production.runs.show', $run);

        return $this->respond($request, ['run_number' => $run->run_number, 'url' => $url], $url, 201);
    }

    public function show(ProductionRun $productionRun): View
    {
        return view('modules.production.runs.show', [
            'record' => $productionRun->load([
                'order', 'orderLine', 'product', 'machine', 'mold', 'shift',
                'requirements.product', 'requirements.unit', 'progressEntries', 'inspections.results',
            ]),
            'stores' => BranchStore::query()->where('branch_id', $productionRun->branch_id)->orderBy('position')->get(),
        ]);
    }

    public function print(ProductionRun $productionRun): View
    {
        return view('modules.production.runs.print', ['record' => $productionRun->load(['order', 'product', 'requirements.product', 'requirements.unit'])]);
    }

    public function releaseOrder(Request $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
        $record = $this->guard(fn (): ProductionOrder => $this->cycle->releaseOrder($productionOrder));

        return $this->respond($request, ['doc_num' => $record->doc_num, 'status' => $record->status], route('admin.production.work-orders.show', $record));
    }

    public function reserve(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'], 'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id']]);
        $record = $this->guard(fn (): ProductionRun => $this->cycle->reserveRun($productionRun, $validated['branch_store_id'], $validated['warehouse_location_id'] ?? null));

        return $this->respond($request, ['run_number' => $record->run_number], route('admin.production.runs.show', $record));
    }

    public function issue(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
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
        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->startSetup($productionRun)));
    }

    public function completeSetup(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->completeSetup($productionRun)));
    }

    public function start(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->startRun($productionRun)));
    }

    public function resume(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->resumeRun($productionRun)));
    }

    public function cancel(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->cancelRun($productionRun, $validated['reason'])));
    }

    public function progress(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recorded_at' => ['nullable', 'date'],
            'good_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'rejected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'rework_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'scrap_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $entry = $this->guard(fn () => $this->cycle->recordProgress($productionRun, $data));

        return $this->respond($request, ['entry' => $entry->public_id], route('admin.production.runs.show', $productionRun));
    }

    public function inspect(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'quality_inspection_type_id' => ['nullable', 'integer', 'exists:quality_inspection_types,id'],
            'sampled_at' => ['nullable', 'date'],
            'result' => ['required', 'in:passed,failed,conditional'],
            'defect_code' => ['nullable', 'string', 'max:100'],
            'affected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'corrective_action' => ['nullable', 'string'],
            'evidence_file' => ['nullable', 'image', 'max:5120'],
            'notes' => ['nullable', 'string'],
            'results' => ['nullable', 'array'],
            'results.*.quality_checkpoint_id' => ['required', 'integer', 'exists:quality_checkpoints,id'],
            'results.*.result' => ['required', 'string', 'max:30'],
            'results.*.measured_value' => ['nullable', 'string', 'max:255'],
            'results.*.notes' => ['nullable', 'string'],
        ]);
        if ($request->hasFile('evidence_file')) {
            $data['evidence'] = [[
                'disk' => 'public',
                'path' => $request->file('evidence_file')->store('production-quality', 'public'),
                'original_name' => $request->file('evidence_file')->getClientOriginalName(),
            ]];
        }
        unset($data['evidence_file']);
        $inspection = $this->guard(fn () => $this->cycle->recordInspection($productionRun, $data));

        return $this->respond($request, ['doc_num' => $inspection->doc_num], route('admin.production.runs.show', $productionRun));
    }

    public function account(Request $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
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
        return $this->runTransition($request, $this->guard(fn (): ProductionRun => $this->cycle->completeRun($productionRun)));
    }

    public function shortClose(Request $request, ProductionOrder $productionOrder): JsonResponse|RedirectResponse
    {
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
