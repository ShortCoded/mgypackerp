<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Http\Requests\StoreInventoryOperationRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;

class InventoryDocumentController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.inventory.documents.index', [
            'records' => InventoryDocument::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->with(['branchStore', 'destinationBranchStore', 'productionRun'])
                ->latest('document_date')
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.inventory.documents.create', [
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
            'locations' => WarehouseLocation::query()->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))->orderBy('code')->get(),
            'products' => Product::query()->forCompany($context['company_id'])->active()->nonService()->orderBy('name')->limit(500)->get(),
        ]);
    }

    public function store(StoreInventoryOperationRequest $request, InventoryMovementService $service): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $document = $this->guard(fn (): InventoryDocument => $service->createAndPost([
            ...$context,
            ...$request->safe()->except('lines'),
            'purpose' => $request->validated('movement_reason'),
        ], $request->validated('lines')));

        $url = route('admin.inventory.documents.show', $document);

        return $this->respond($request, ['doc_num' => $document->doc_num, 'url' => $url], $url, 201);
    }

    public function show(InventoryDocument $inventoryDocument): View
    {
        return view('modules.inventory.documents.show', [
            'record' => $inventoryDocument->load([
                'lines.product', 'lines.unit', 'transactions', 'branchStore',
                'destinationBranchStore', 'productionOrder', 'productionRun',
            ]),
        ]);
    }

    public function print(InventoryDocument $inventoryDocument): View
    {
        return view('modules.inventory.documents.print', [
            'record' => $inventoryDocument->load(['lines.product', 'lines.unit', 'branchStore', 'destinationBranchStore']),
        ]);
    }

    public function reverse(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|RedirectResponse
    {
        $record = $this->guard(fn (): InventoryDocument => $posting->reverse($inventoryDocument));

        return $this->respond($request, ['doc_num' => $record->doc_num, 'status' => $record->status], route('admin.inventory.documents.show', $record));
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
        ];
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    private function respond(Request $request, array $data, string $redirectUrl, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], $status);
        }

        return redirect()->to($redirectUrl)->with('success', __('Inventory operation completed.'));
    }
}
