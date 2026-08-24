<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Http\Requests\CompleteProductionOrderRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\SalesProductionDemandService;

class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 422, 'Operating context is required.');

        return view('modules.production.work-orders.index', [
            'records' => ProductionOrder::query()
                ->with(['salesOrder.customer'])
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
                ->latest('production_order_date')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function show(ProductionOrder $productionOrder): View
    {
        $record = $productionOrder->load(['salesOrder.branchStore', 'lines.product', 'lines.unit', 'runs.product', 'runs.requirements.product']);

        return view('modules.sales.cycle.show', [
            'kind' => 'production_request',
            'record' => $record,
            'showPrices' => false,
            'stores' => BranchStore::query()->where('branch_id', $record->branch_id)->orderBy('position')->get(),
        ]);
    }

    public function complete(CompleteProductionOrderRequest $request, ProductionOrder $productionOrder, SalesProductionDemandService $service): JsonResponse
    {
        $store = BranchStore::query()
            ->where('branch_id', $productionOrder->branch_id)
            ->where('public_uuid', $request->validated('branch_store_uuid'))
            ->firstOrFail();
        $lines = collect($request->validated('lines'))->map(fn (array $row): array => [
            'production_order_line_id' => $productionOrder->lines()->where('public_id', $row['production_order_line_public_id'])->firstOrFail()->getKey(),
            'quantity' => $row['quantity'],
        ])->all();
        $document = $service->receiveCompletion($productionOrder, $store->getKey(), $lines);

        return response()->json(['data' => [
            'doc_num' => $document->doc_num,
            'url' => route('admin.production.work-orders.show', $productionOrder),
        ]], 201);
    }

    public function print(ProductionOrder $productionOrder): View
    {
        $record = $productionOrder->load(['company', 'salesOrder', 'lines.product', 'lines.unit']);

        return view('modules.sales.cycle.print', [
            'kind' => 'production_request', 'record' => $record, 'showPrices' => false,
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ]);
    }
}
