<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Http\Requests\StoreInventoryOperationRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;

class InventoryDocumentController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);

        return view('modules.inventory.documents.index', [
            'records' => InventoryDocument::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
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
        $allowedDocumentTypes = collect([
            InventoryDocument::TypeTransfer => 'inventory.documents.transfer',
            InventoryDocument::TypeAdjustmentIn => 'inventory.documents.adjust',
            InventoryDocument::TypeAdjustmentOut => 'inventory.documents.adjust',
            InventoryDocument::TypeDamage => 'inventory.documents.damage_scrap',
            InventoryDocument::TypeScrap => 'inventory.documents.damage_scrap',
        ])->filter(fn (string $permission): bool => (bool) $request->user()?->can($permission))->keys()->all();

        abort_if($allowedDocumentTypes === [], 403);

        return view('modules.inventory.documents.create', [
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('position')->get(),
            'locations' => WarehouseLocation::query()->whereHas('branchStore', fn ($query) => $query->where('branch_id', $context['branch_id']))->orderBy('code')->get(),
            'products' => Product::query()->forCompany($context['company_id'])->active()->nonService()->orderBy('name')->limit(500)->get(),
            'allowedDocumentTypes' => $allowedDocumentTypes,
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

    public function show(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);

        return view('modules.inventory.documents.show', [
            'record' => $inventoryDocument->load([
                'lines.product', 'lines.unit', 'transactions', 'branchStore',
                'lines.reservation.productionMaterialRequirement', 'destinationBranchStore',
                'productionOrder.salesOrder', 'productionRun.order.salesOrder', 'salesOrder',
                'journalEntry', 'reversalJournalEntry',
            ]),
            'canViewFinancial' => (bool) $request->user()?->can('inventory.reports.financial'),
        ]);
    }

    public function print(Request $request, InventoryDocument $inventoryDocument): Response
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $record = $inventoryDocument->load([
            'company', 'lines.product', 'lines.unit', 'lines.warehouseLocation', 'branchStore', 'destinationBranchStore',
            'productionOrder', 'productionRun', 'journalEntry',
        ]);

        return $this->pdf->stream('reports.inventory.document', [
            'title' => __(str($record->document_type)->replace('_', ' ')->title()->toString()).' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ], str('inventory-'.$record->document_type.'-'.$record->doc_num)->slug().'.pdf');
    }

    public function reverse(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
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

    private function respond(Request $request, array $data, string $redirectUrl, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $data], $status);
        }

        return redirect()->to($redirectUrl)->with('success', __('Inventory operation completed.'));
    }
}
