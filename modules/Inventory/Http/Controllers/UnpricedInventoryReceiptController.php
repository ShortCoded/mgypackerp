<?php

namespace Modules\Inventory\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\SettingService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Inventory\DataTables\UnpricedInventoryReceiptsDataTable;
use Modules\Inventory\Http\Requests\UnpricedInventoryReceipts\StoreUnpricedInventoryReceiptRequest;
use Modules\Inventory\Http\Requests\UnpricedInventoryReceipts\UpdateUnpricedInventoryReceiptDocumentNumberSettingsRequest;
use Modules\Inventory\Http\Requests\UnpricedInventoryReceipts\UpdateUnpricedInventoryReceiptRequest;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventorySelect2Service;
use Modules\Inventory\Services\UnpricedInventoryReceiptService;
use Modules\Purchases\Models\Supplier;

class UnpricedInventoryReceiptController extends Controller
{
    public function __construct(
        private readonly UnpricedInventoryReceiptService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.inventory.unpriced-inventory-receipts.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.inventory.unpriced-inventory-receipts.index'),
            'documentNumberSettings' => $settings->current('unpriced_inventory_receipts'),
        ]);
    }

    public function data(Request $request, UnpricedInventoryReceiptsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $this->assertRequiredContext($request);

        return $this->form($request, 'create');
    }

    public function show(Request $request, string $unpricedInventoryReceipt): View
    {
        $record = $this->findInCurrentContext($request, $unpricedInventoryReceipt, true);
        abort_if($record->trashed() && ! $request->user()?->can('inventory.unpriced_inventory_receipts.view_trashed'), 404);

        return $this->form($request, 'view', $record);
    }

    public function edit(Request $request, string $unpricedInventoryReceipt): View
    {
        $this->assertRequiredContext($request);
        $record = $this->findInCurrentContext($request, $unpricedInventoryReceipt);
        abort_if($record->isLockedForEditing(), 403, $this->editBlockedMessage($record));

        return $this->form($request, 'edit', $record);
    }

    public function store(StoreUnpricedInventoryReceiptRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): UnpricedInventoryReceipt => $this->service->create($request->validated())['record']);

        $message = $this->submitAction($request, true) === 'save'
            ? __('inventory.unpriced_inventory_receipts.messages.saved_and_new')
            : __('inventory.unpriced_inventory_receipts.messages.created');

        return response()->json([
            'success' => true,
            'message' => $message,
            ...$this->saveResponse($request, $record, 'store'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function update(UpdateUnpricedInventoryReceiptRequest $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $record = $this->findInCurrentContext($request, $unpricedInventoryReceipt);
        $result = $this->guardDomain(fn (): array => $this->service->update($record, $request->validated()));
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('inventory.unpriced_inventory_receipts.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $record = $this->findInCurrentContext($request, $unpricedInventoryReceipt);
        $this->guardDomain(function () use ($record): null {
            $this->service->delete($record);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('inventory.unpriced_inventory_receipts.messages.deleted')]);
    }

    public function restore(Request $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $this->guardDomain(fn (): UnpricedInventoryReceipt => $this->service->restore($this->findInCurrentContext($request, $unpricedInventoryReceipt, true)));

        return response()->json(['success' => true, 'message' => __('inventory.unpriced_inventory_receipts.messages.restored')]);
    }

    public function approve(Request $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $record = $this->guardDomain(fn (): UnpricedInventoryReceipt => $this->service->approve($this->findInCurrentContext($request, $unpricedInventoryReceipt, true)));

        return response()->json([
            'success' => true,
            'message' => __('inventory.unpriced_inventory_receipts.messages.approved'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function close(Request $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $record = $this->guardDomain(fn (): UnpricedInventoryReceipt => $this->service->close($this->findInCurrentContext($request, $unpricedInventoryReceipt, true)));

        return response()->json([
            'success' => true,
            'message' => __('inventory.unpriced_inventory_receipts.messages.closed'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function cancel(Request $request, string $unpricedInventoryReceipt): JsonResponse
    {
        $record = $this->guardDomain(fn (): UnpricedInventoryReceipt => $this->service->cancel($this->findInCurrentContext($request, $unpricedInventoryReceipt, true)));

        return response()->json([
            'success' => true,
            'message' => __('inventory.unpriced_inventory_receipts.messages.cancelled'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function updateDocumentNumberSettings(UpdateUnpricedInventoryReceiptDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('unpriced_inventory_receipts', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('inventory.unpriced_inventory_receipts.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function branches(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canReadSelect2($request), 403);

        return response()->json($select2->receiptBranches($request));
    }

    public function branchHalls(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canWriteSelect2($request), 403);

        return response()->json($select2->receiptBranchHalls($request));
    }

    public function branchStores(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canWriteSelect2($request), 403);

        return response()->json($select2->receiptBranchStores($request));
    }

    public function suppliers(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canReadSelect2($request), 403);

        return response()->json($select2->receiptSuppliers($request));
    }

    public function products(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canReadSelect2($request), 403);

        return response()->json($select2->products($request));
    }

    public function productDetails(Request $request, string $product): JsonResponse
    {
        abort_unless($this->canReadSelect2($request), 403);

        $data = app(InventorySelect2Service::class)->productDetails($request, $product);

        abort_unless(is_array($data), 404);

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function form(Request $request, string $mode, ?UnpricedInventoryReceipt $record = null): View
    {
        $record?->loadMissing(['branch', 'branchHall', 'branchStore', 'supplier', 'financialPeriod', 'lines.product.unit', 'lines.product.mainImageUsage.file', 'lines.unit']);
        $isCreateLike = $mode === 'create';

        return view('modules.inventory.unpriced-inventory-receipts.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $isCreateLike ? route('admin.inventory.unpriced-inventory-receipts.store') : route('admin.inventory.unpriced-inventory-receipts.update', $record?->doc_num),
            'method' => $isCreateLike ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('inventory.unpriced_inventory_receipts.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'isLocked' => $record?->isLockedForEditing() ?? false,
            'dateValue' => $this->defaultDate($request, $record, $isCreateLike, 'document_date'),
            'referenceDateValue' => $this->defaultDate($request, $record, $isCreateLike, 'reference_date'),
            'financialPeriodLabel' => $this->financialPeriodLabel($request, $record),
            'branchOption' => $this->branchOption($record),
            'hallOption' => $this->hallOption($record),
            'storeOption' => $this->storeOption($record),
            'supplierOption' => $this->supplierOption($record),
            'lines' => $this->lines($record, $mode),
            'metadata' => $this->metadata($record),
            'canCreateProducts' => (bool) auth()->user()?->can('products.create'),
            'productCreateUrl' => route('admin.products.create'),
        ]);
    }

    private function findInCurrentContext(Request $request, string $docNum, bool $withTrashed = false): UnpricedInventoryReceipt
    {
        $context = $this->operatingContext->snapshot($request);

        abort_unless($context['company_id'] && $context['financial_period_id'], 404);

        $query = $withTrashed ? UnpricedInventoryReceipt::withTrashed() : UnpricedInventoryReceipt::query();

        return $query
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->whereIn('branch_id', $this->operatingContext->allowedBranchQueryForCurrentCompany($request)->pluck('branches.id')->all())
            ->firstOrFail();
    }

    private function assertRequiredContext(Request $request): void
    {
        $context = $this->operatingContext->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 403, __('operating_context.messages.required'));
    }

    private function defaultDate(Request $request, ?UnpricedInventoryReceipt $record, bool $isCreateLike, string $field): string
    {
        $dates = app(DateFormatService::class);

        if ($record instanceof UnpricedInventoryReceipt && ! $isCreateLike) {
            return $record->{$field} ? $dates->formatDate($record->{$field}, '') : '';
        }

        if ($field === 'reference_date') {
            return '';
        }

        $periodId = $this->operatingContext->snapshot($request)['financial_period_id'];
        $period = $periodId ? FinancialPeriod::query()->find($periodId) : null;
        $today = now()->toDateString();
        $default = $period && $period->from_date && $period->to_date && ($today < $period->from_date->toDateString() || $today > $period->to_date->toDateString())
            ? $period->from_date
            : now();

        return $dates->formatDate($default, '');
    }

    private function financialPeriodLabel(Request $request, ?UnpricedInventoryReceipt $record): ?string
    {
        $period = $record?->financialPeriod;

        if (! $period instanceof FinancialPeriod) {
            $periodId = $this->operatingContext->snapshot($request)['financial_period_id'];
            $period = $periodId ? FinancialPeriod::query()->find($periodId) : null;
        }

        if (! $period instanceof FinancialPeriod) {
            return null;
        }

        return trim(implode(' / ', array_filter([$period->doc_num, $period->name])));
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function branchOption(?UnpricedInventoryReceipt $record): ?array
    {
        if (! $record?->branch instanceof Branch) {
            return null;
        }

        return [
            'id' => (string) $record->branch->doc_num,
            'text' => trim(implode(' / ', array_filter([$record->branch->doc_num, $record->branch->name]))),
        ];
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function hallOption(?UnpricedInventoryReceipt $record): ?array
    {
        if (! $record?->branchHall instanceof BranchHall) {
            return null;
        }

        return [
            'id' => (string) $record->branchHall->public_uuid,
            'text' => (string) $record->branchHall->name,
        ];
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function storeOption(?UnpricedInventoryReceipt $record): ?array
    {
        if (! $record?->branchStore instanceof BranchStore) {
            return null;
        }

        return [
            'id' => (string) $record->branchStore->public_uuid,
            'text' => (string) $record->branchStore->name,
        ];
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function supplierOption(?UnpricedInventoryReceipt $record): ?array
    {
        if (! $record?->supplier instanceof Supplier) {
            return null;
        }

        return [
            'id' => (string) $record->supplier->doc_num,
            'text' => trim(implode(' / ', array_filter([$record->supplier->doc_num, $record->supplier->name]))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(?UnpricedInventoryReceipt $record, string $mode): array
    {
        $lines = old('lines');

        if (! is_array($lines)) {
            $lines = $record?->lines?->map(function (UnpricedInventoryReceiptLine $line): array {
                $product = $line->product;
                $unit = $line->unit;
                $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                $productDocNum = $snapshot['doc_num'] ?? $product?->doc_num;
                $unitDocNum = $snapshot['unit_doc_num'] ?? $unit?->doc_num;
                $unitLabel = $snapshot['unit_label'] ?? ($unit ? trim(implode(' / ', array_filter([$unit->doc_num, $unit->name]))) : null);
                $productLabel = trim(implode(' / ', array_filter([
                    $productDocNum,
                    $snapshot['name'] ?? ($product instanceof Product ? $product->name : null),
                    $snapshot['barcode'] ?? null,
                    $unitLabel,
                ])));

                return [
                    'public_id' => $line->public_id,
                    'product_doc_num' => $productDocNum,
                    'product_label' => $productLabel !== '' ? $productLabel : null,
                    'unit_doc_num' => $unitDocNum,
                    'unit' => $unitLabel,
                    'unit_options' => $product instanceof Product ? app(ProductComponentUnitOptionsService::class)->options($product) : [],
                    'imageUrl' => $snapshot['image_url'] ?? ($product instanceof Product ? app(ProductImageResolver::class)->url($product) : null),
                    'quantity' => $this->numbers->format($line->quantity),
                    'notes' => $line->notes,
                ];
            })->values()->all() ?? [];
        }

        if ($lines === [] && $mode !== 'view') {
            return [['public_id' => null, 'product_doc_num' => null, 'product_label' => null, 'unit_doc_num' => null, 'unit' => null, 'unit_options' => [], 'imageUrl' => null, 'quantity' => null, 'notes' => null]];
        }

        return array_values($lines);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?UnpricedInventoryReceipt $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.unpriced-inventory-receipts.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.inventory.unpriced-inventory-receipts.index', $extra);
    }

    private function urls(UnpricedInventoryReceipt $record): array
    {
        return [
            'show' => route('admin.inventory.unpriced-inventory-receipts.show', $record->doc_num),
            'edit' => route('admin.inventory.unpriced-inventory-receipts.edit', $record->doc_num),
            'update' => route('admin.inventory.unpriced-inventory-receipts.update', $record->doc_num),
            'destroy' => route('admin.inventory.unpriced-inventory-receipts.destroy', $record->doc_num),
            'approve' => route('admin.inventory.unpriced-inventory-receipts.approve', $record->doc_num),
            'close' => route('admin.inventory.unpriced-inventory-receipts.close', $record->doc_num),
            'cancel' => route('admin.inventory.unpriced-inventory-receipts.cancel', $record->doc_num),
        ];
    }

    private function editBlockedMessage(UnpricedInventoryReceipt $record): string
    {
        if ($record->isCancelled()) {
            return __('inventory.unpriced_inventory_receipts.messages.cancelled_edit_forbidden');
        }

        if ($record->isApproved()) {
            return __('inventory.unpriced_inventory_receipts.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('inventory.unpriced_inventory_receipts.messages.closed_edit_forbidden');
        }

        return __('inventory.unpriced_inventory_receipts.messages.locked_not_editable');
    }

    private function saveResponse(Request $request, UnpricedInventoryReceipt $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.inventory.unpriced-inventory-receipts.show', $record->doc_num),
            'save_edit' => route('admin.inventory.unpriced-inventory-receipts.edit', $record->doc_num),
            'save_back' => route('admin.inventory.unpriced-inventory-receipts.index'),
            default => $operation === 'store' ? route('admin.inventory.unpriced-inventory-receipts.create') : null,
        };

        return array_filter(['submit_action' => $action, 'redirect' => $redirect]);
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back'], true) ? $action : 'save';
    }

    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?UnpricedInventoryReceipt $record): array
    {
        if (! $record instanceof UnpricedInventoryReceipt) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'closed_by' => null,
                'closed_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->approved_by, $record->closed_by, $record->cancelled_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'approved_by' => $this->auditUserLabel($users->get($record->approved_by)),
            'approved_at' => $settings->formatDateTime($record->approved_at, ''),
            'closed_by' => $this->auditUserLabel($users->get($record->closed_by)),
            'closed_at' => $settings->formatDateTime($record->closed_at, ''),
            'cancelled_by' => $this->auditUserLabel($users->get($record->cancelled_by)),
            'cancelled_at' => $settings->formatDateTime($record->cancelled_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($record->deleted_by)),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($record->restored_by)),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    private function canReadSelect2(Request $request): bool
    {
        return (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.view')
            || (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.create')
            || (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.edit');
    }

    private function canWriteSelect2(Request $request): bool
    {
        return (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.create')
            || (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.edit');
    }
}
