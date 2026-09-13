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
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\DataTables\InventoryDocumentsDataTable;
use Modules\Inventory\Http\Requests\StoreInventoryOperationRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
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
        $this->requiredContext($request);

        return view('modules.inventory.documents.index');
    }

    public function data(Request $request, InventoryDocumentsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $this->requiredContext($request);
        $allowedDocumentTypes = $this->allowedDocumentTypes($request);

        abort_if($allowedDocumentTypes === [], 403);

        return view('modules.inventory.documents.create', [
            'record' => null,
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
        ]);
    }

    public function edit(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        abort_unless($inventoryDocument->status === InventoryDocument::StatusDraft, 409, __('inventory.movements.messages.only_drafts_editable'));

        $allowedDocumentTypes = $this->allowedDocumentTypes($request);
        abort_unless(in_array($inventoryDocument->document_type, $allowedDocumentTypes, true), 403);

        return view('modules.inventory.documents.create', [
            'record' => $inventoryDocument->load(['branchStore', 'destinationBranchStore', 'lines.product']),
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
        ]);
    }

    public function stores(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $context = $this->requiredContext($request);
        $query = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->orderBy('position')
            ->orderBy('name');
        $terms = $search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['name']]);
        }

        return response()->json($select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->getKey(),
            'text' => (string) $store->name,
        ]));
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
            'id' => (string) $product->getKey(),
            'text' => trim($product->doc_num.' — '.$product->name),
        ]));
    }

    public function store(StoreInventoryOperationRequest $request, InventoryMovementService $service): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
        $header = [
            ...$context,
            ...$request->safe()->except('lines'),
            'purpose' => $request->validated('movement_reason'),
        ];
        $draftAction = in_array($request->string('submit_action')->toString(), ['save_draft', 'save_and_edit'], true);
        $document = $this->guard(fn (): InventoryDocument => $draftAction
            ? $service->createDraft($header, $request->validated('lines'))
            : $service->createAndPost($header, $request->validated('lines')));

        $url = $request->string('submit_action')->toString() === 'save_and_new'
            ? route('admin.inventory.documents.create')
            : ($draftAction
                ? route('admin.inventory.documents.edit', $document)
                : route('admin.inventory.documents.show', $document));

        return $this->respond(
            $request,
            ['doc_num' => $document->doc_num, 'status' => $document->status, 'url' => $url],
            $url,
            201,
            $draftAction ? 'inventory.movements.messages.draft_saved' : 'inventory.movements.messages.posted',
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
        $header = [
            ...$context,
            ...$request->safe()->except('lines'),
            'purpose' => $request->validated('movement_reason'),
        ];
        $shouldPost = $request->string('submit_action')->toString() === 'post_and_view';
        $document = $this->guard(function () use ($service, $posting, $inventoryDocument, $header, $request, $shouldPost): InventoryDocument {
            $draft = $service->updateDraft($inventoryDocument, $header, $request->validated('lines'));

            return $shouldPost ? $posting->post($draft) : $draft;
        });
        $url = $shouldPost
            ? route('admin.inventory.documents.show', $document)
            : route('admin.inventory.documents.edit', $document);

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
            'title' => __('inventory.movements.types.'.$record->document_type).' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ], str('inventory-'.$record->document_type.'-'.$record->doc_num)->slug().'.pdf');
    }

    public function reverse(Request $request, InventoryDocument $inventoryDocument, InventoryDocumentPostingService $posting): JsonResponse|RedirectResponse
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $record = $this->guard(fn (): InventoryDocument => $posting->reverse($inventoryDocument));

        return $this->respond(
            $request,
            ['doc_num' => $record->doc_num, 'status' => $record->status],
            route('admin.inventory.documents.show', $record),
            200,
            'inventory.movements.messages.reversed',
        );
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
