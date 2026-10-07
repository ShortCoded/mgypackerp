<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Http\Requests\ProductionHandoverRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionHandoverService;

class ProductionHandoverController extends Controller
{
    public function __construct(private readonly ProductionHandoverService $service) {}

    public function index(Request $request): View
    {
        return $this->listing($request, false);
    }

    public function warehouseIndex(Request $request): View
    {
        return $this->listing($request, true);
    }

    public function create(Request $request, ProductionRun $productionRun): View
    {
        $runs = $this->read(fn () => $this->service->runs($productionRun));

        return view('modules.production.handovers.create', ['record' => $productionRun, 'runs' => $runs,
            'quantities' => $runs->mapWithKeys(fn (ProductionRun $run): array => [$run->id => $this->service->quantities($run)]),
            'stores' => BranchStore::query()->where('branch_id', $productionRun->branch_id)->orderBy('position')->get()]);
    }

    public function store(ProductionHandoverRequest $request, ProductionRun $productionRun): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $lines = array_map(fn (array $line): array => [...$line, 'serial_numbers' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $line['serial_numbers'] ?? ''))))], $data['lines']);
        $document = $this->write(fn () => $this->service->createHandover($productionRun, (int) $data['branch_store_id'], $data['document_date'], $lines, $data['notes'] ?? null));

        return $this->respond($request, $document, route('admin.production.handovers.show', $document));
    }

    public function show(Request $request, InventoryDocument $inventoryDocument): View
    {
        abort_unless($request->user()->canAny(['production.handovers.view', 'inventory.production_receipts.view']), 403);
        $document = $this->read(fn () => $this->service->handover($inventoryDocument));

        return view('modules.production.handovers.show', ['record' => $document, 'lines' => $this->service->remainingLines($document),
            'receipts' => $this->service->warehouseReceipts($document)->with('lines.product')->get()]);
    }

    public function approve(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $document = $this->write(fn () => $this->service->approveHandover($inventoryDocument));

        return $this->respond($request, $document, route('admin.production.handovers.show', $document));
    }

    public function cancel(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $document = $this->write(fn () => $this->service->cancelHandover($inventoryDocument, $data['reason']));

        return $this->respond($request, $document, route('admin.production.handovers.show', $document));
    }

    public function createReceipt(Request $request, InventoryDocument $inventoryDocument): View
    {
        $document = $this->read(fn () => $this->service->handover($inventoryDocument));
        abort_unless($document->status === InventoryDocument::StatusApproved, 404);

        return view('modules.production.handovers.receipt-create', ['record' => $document, 'lines' => $this->service->remainingLines($document)]);
    }

    public function storeReceipt(ProductionHandoverRequest $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $receipt = $this->write(fn () => $this->service->createWarehouseReceipt($inventoryDocument, $data['document_date'], $data['lines'], $data['notes'] ?? null));

        return $this->respond($request, $receipt, route('admin.inventory.production-receipts.show', $receipt));
    }

    public function showReceipt(Request $request, InventoryDocument $inventoryDocument): View
    {
        $receipt = $this->read(fn () => $this->service->warehouseReceipt($inventoryDocument));
        $handover = $this->read(fn () => $this->service->handover(InventoryDocument::query()->findOrFail($receipt->source_document_id)));

        return view('modules.production.handovers.receipt-show', ['record' => $receipt, 'handover' => $handover]);
    }

    public function approveReceipt(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $receipt = $this->write(fn () => $this->service->approveWarehouseReceipt($inventoryDocument));

        return $this->respond($request, $receipt, route('admin.inventory.production-receipts.show', $receipt));
    }

    public function cancelReceipt(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $receipt = $this->write(fn () => $this->service->cancelDraftWarehouseReceipt($inventoryDocument, $data['reason']));

        return $this->respond($request, $receipt, route('admin.inventory.production-receipts.show', $receipt));
    }

    private function listing(Request $request, bool $warehouse): View
    {
        Gate::authorize($warehouse ? 'inventory.production_receipts.view' : 'production.handovers.view');
        $context = app(OperatingContextService::class)->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'] && $context['financial_period_id'], 422);
        $company = Company::query()->findOrFail($context['company_id']);
        $allowedPeriods = app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery($request->user(), [$company->doc_num])->select('financial_periods.id');
        $query = InventoryDocument::query()->where('company_id', $company->id)->where('branch_id', $context['branch_id'])
            ->whereIn('financial_period_id', $allowedPeriods);
        $documents = (clone $query)->where('document_type', $warehouse ? InventoryDocument::TypeProductionReceipt : InventoryDocument::TypeProductionHandover)
            ->when($warehouse, fn ($query) => $query->where('source_document_type', InventoryDocument::class))
            ->orderByDesc('id')->paginate(25);
        $handovers = $warehouse ? (clone $query)->where('document_type', InventoryDocument::TypeProductionHandover)
            ->where('status', InventoryDocument::StatusApproved)->orderByDesc('id')->paginate(10, pageName: 'handover_page') : null;

        return view('modules.production.handovers.index', compact('documents', 'handovers', 'warehouse'));
    }

    private function respond(Request $request, InventoryDocument $document, string $url): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['success' => true, 'doc_num' => $document->doc_num, 'status' => $document->status, 'redirect_url' => $url])
            : redirect($url)->with('success', __('Saved successfully.'));
    }

    private function write(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    private function read(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException) {
            abort(404);
        }
    }
}
