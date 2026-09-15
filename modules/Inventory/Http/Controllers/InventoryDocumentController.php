<?php

namespace Modules\Inventory\Http\Controllers;

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
            'isClone' => false,
            'allowedDocumentTypes' => $allowedDocumentTypes,
            'stockStatuses' => $this->stockStatuses(),
        ]);
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
        abort_unless($inventoryDocument->status === InventoryDocument::StatusDraft, 409, __('inventory.movements.messages.only_drafts_editable'));

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

    public function store(StoreInventoryOperationRequest $request, InventoryMovementService $service): JsonResponse|RedirectResponse
    {
        $context = $this->requiredContext($request);
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
        $this->assertInCurrentContext($request, $inventoryDocument);
        abort_unless($inventoryDocument->status === InventoryDocument::StatusDraft && ! $inventoryDocument->transactions()->exists(), 409, __('Only an unposted draft inventory movement can be deleted.'));
        $inventoryDocument->update(['deleted_by' => $request->user()?->getKey()]);
        $inventoryDocument->delete();

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
                if ($record->status !== InventoryDocument::StatusDraft || $record->transactions()->exists()) {
                    throw ValidationException::withMessages(['doc_nums' => __('Only unposted draft inventory movements can be deleted.')]);
                }
                $record->update(['deleted_by' => $request->user()?->getKey()]);
                $record->delete();
            }
        });

        return response()->json(['success' => true]);
    }

    public function show(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertInCurrentContext($request, $inventoryDocument);

        return view('modules.inventory.documents.show', [
            'record' => $inventoryDocument->load([
                'lines.product', 'lines.unit', 'transactions', 'branchStore',
                'lines.reservation.productionMaterialRequirement', 'destinationBranchStore',
                'productionOrder.salesOrder', 'productionRun.order.salesOrder', 'salesOrder',
            ]),
        ]);
    }

    public function print(Request $request, InventoryDocument $inventoryDocument): Response
    {
        $this->assertInCurrentContext($request, $inventoryDocument);
        $record = $inventoryDocument->load([
            'company', 'lines.product', 'lines.unit', 'lines.warehouseLocation', 'branchStore', 'destinationBranchStore',
            'productionOrder', 'productionRun',
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
