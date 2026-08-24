<?php

namespace Modules\Inventory\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Inventory\DataTables\OpeningStocksDataTable;
use Modules\Inventory\Http\Requests\OpeningStocks\StoreOpeningStockRequest;
use Modules\Inventory\Http\Requests\OpeningStocks\UpdateOpeningStockDocumentNumberSettingsRequest;
use Modules\Inventory\Http\Requests\OpeningStocks\UpdateOpeningStockRequest;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Services\InventorySelect2Service;
use Modules\Inventory\Services\OpeningStockService;

class OpeningStockController extends Controller
{
    public function __construct(
        private readonly OpeningStockService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request, FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.inventory.opening-stocks.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.inventory.opening-stocks.index'),
            'documentNumberSettings' => $settings->current('inventory_opening_stocks'),
            'canUseCurrentBranch' => $this->canUseBranch($this->currentBranch($request)),
        ]);
    }

    public function data(Request $request, OpeningStocksDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $this->assertUsableBranch($request);

        return $this->form($request, 'create');
    }

    public function show(Request $request, string $openingStock): View
    {
        $record = $this->findInCurrentContext($request, $openingStock, true);
        abort_if($record->trashed() && ! $request->user()?->can('inventory.opening_stocks.view_trashed'), 404);

        return $this->form($request, 'view', $record);
    }

    public function print(Request $request, string $openingStock): Response
    {
        $record = $this->findInCurrentContext($request, $openingStock, true)->load([
            'company', 'branch', 'branchHall', 'branchStore', 'lines.product', 'lines.warehouseLocation', 'approvedBy',
        ]);

        return $this->pdf->stream('reports.inventory.opening-stock', [
            'title' => __('inventory.opening_stocks.title').' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $this->printIdentity->forCompany($record->company),
        ], str('opening-stock-'.$record->doc_num)->slug().'.pdf');
    }

    public function edit(Request $request, string $openingStock): View
    {
        $this->assertUsableBranch($request);
        $record = $this->findInCurrentContext($request, $openingStock);
        abort_if($record->isLockedForEditing(), 403, $this->editBlockedMessage($record));

        return $this->form($request, 'edit', $record);
    }

    public function clone(Request $request, string $openingStock): View
    {
        $this->assertUsableBranch($request);
        $record = $this->findInCurrentContext($request, $openingStock);

        return $this->form($request, 'clone', $record, (string) Str::uuid());
    }

    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): OpeningStock => $this->service->create($request->validated())['record']);

        $message = $this->submitAction($request, true) === 'save'
            ? __('inventory.opening_stocks.messages.saved_and_new')
            : __('inventory.opening_stocks.messages.created');

        return response()->json([
            'success' => true,
            'message' => $message,
            ...$this->saveResponse($request, $record, 'store'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function update(UpdateOpeningStockRequest $request, string $openingStock): JsonResponse
    {
        $record = $this->findInCurrentContext($request, $openingStock);
        $result = $this->guardDomain(fn (): array => $this->service->update($record, $request->validated()));
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('inventory.opening_stocks.messages.updated'),
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

    public function destroy(Request $request, string $openingStock): JsonResponse
    {
        $record = $this->findInCurrentContext($request, $openingStock);
        $this->guardDomain(function () use ($record): null {
            $this->service->delete($record);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('inventory.opening_stocks.messages.deleted')]);
    }

    public function restore(Request $request, string $openingStock): JsonResponse
    {
        $this->guardDomain(fn (): OpeningStock => $this->service->restore($this->findInCurrentContext($request, $openingStock, true)));

        return response()->json(['success' => true, 'message' => __('inventory.opening_stocks.messages.restored')]);
    }

    public function approve(Request $request, string $openingStock): JsonResponse
    {
        $record = $this->guardDomain(fn (): OpeningStock => $this->service->approve($this->findInCurrentContext($request, $openingStock, true)));

        return response()->json([
            'success' => true,
            'message' => __('inventory.opening_stocks.messages.approved'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function updateDocumentNumberSettings(UpdateOpeningStockDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('inventory_opening_stocks', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('inventory.opening_stocks.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function branchHalls(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('inventory.opening_stocks.create') || (bool) $request->user()?->can('inventory.opening_stocks.edit'), 403);

        return response()->json($select2->branchHalls($request));
    }

    public function branchStores(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('inventory.opening_stocks.create') || (bool) $request->user()?->can('inventory.opening_stocks.edit'), 403);

        return response()->json($select2->branchStores($request));
    }

    public function products(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('inventory.opening_stocks.view') || (bool) $request->user()?->can('inventory.opening_stocks.create') || (bool) $request->user()?->can('inventory.opening_stocks.edit'), 403);

        return response()->json($select2->products($request));
    }

    public function productDetails(Request $request, string $product): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('inventory.opening_stocks.view') || (bool) $request->user()?->can('inventory.opening_stocks.create') || (bool) $request->user()?->can('inventory.opening_stocks.edit'), 403);

        $data = app(InventorySelect2Service::class)->productDetails($request, $product);

        abort_unless(is_array($data), 404);

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function form(Request $request, string $mode, ?OpeningStock $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['branch', 'branchHall', 'branchStore', 'lines.product.unit', 'lines.product.mainImageUsage.file']);
        $branch = $this->currentBranch($request);
        $isCreateLike = in_array($mode, ['create', 'clone'], true);
        $showLocationSelectors = $branch instanceof Branch && $branch->type === Branch::TypeFactory;

        return view('modules.inventory.opening-stocks.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $isCreateLike ? route('admin.inventory.opening-stocks.store') : route('admin.inventory.opening-stocks.update', $record?->doc_num),
            'method' => $isCreateLike ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('inventory.opening_stocks.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'isLocked' => $record?->isLockedForEditing() ?? false,
            'showHallSelector' => $showLocationSelectors,
            'showStoreSelector' => $showLocationSelectors,
            'dateValue' => $this->defaultDate($request, $record, $isCreateLike),
            'hallOption' => $this->hallOption($record),
            'storeOption' => $this->storeOption($record),
            'lines' => $this->lines($record, $mode),
            'metadata' => $this->metadata($record),
            'canCreateProducts' => (bool) auth()->user()?->can('products.create'),
            'productCreateUrl' => route('admin.products.create'),
        ]);
    }

    private function findInCurrentContext(Request $request, string $docNum, bool $withTrashed = false): OpeningStock
    {
        $context = $this->operatingContext->snapshot($request);

        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 404);

        $query = $withTrashed ? OpeningStock::withTrashed() : OpeningStock::query();

        return $query
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->firstOrFail();
    }

    private function currentBranch(Request $request): ?Branch
    {
        $branchId = $this->operatingContext->snapshot($request)['branch_id'];

        return $branchId ? Branch::query()->find($branchId) : null;
    }

    private function canUseBranch(?Branch $branch): bool
    {
        return $branch instanceof Branch && in_array($branch->type, [Branch::TypeWarehouse, Branch::TypeFactory], true);
    }

    private function assertUsableBranch(Request $request): void
    {
        abort_unless($this->canUseBranch($this->currentBranch($request)), 403, __('inventory.opening_stocks.messages.branch_type_required'));
    }

    private function defaultDate(Request $request, ?OpeningStock $record, bool $isCreateLike): string
    {
        $dates = app(DateFormatService::class);

        if ($record instanceof OpeningStock && ! $isCreateLike) {
            return $record->document_date ? $dates->formatDate($record->document_date, '') : '';
        }

        $periodId = $this->operatingContext->snapshot($request)['financial_period_id'];
        $period = $periodId ? FinancialPeriod::query()->find($periodId) : null;
        $today = now()->toDateString();
        $default = $period && $period->from_date && $period->to_date && ($today < $period->from_date->toDateString() || $today > $period->to_date->toDateString())
            ? $period->from_date
            : now();

        return $dates->formatDate($default, '');
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function hallOption(?OpeningStock $record): ?array
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
    private function storeOption(?OpeningStock $record): ?array
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
     * @return list<array<string, mixed>>
     */
    private function lines(?OpeningStock $record, string $mode): array
    {
        $lines = old('lines');

        if (! is_array($lines)) {
            $lines = $record?->lines?->map(function (OpeningStockLine $line) use ($mode): array {
                $product = $line->product;
                $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                $productDocNum = $snapshot['doc_num'] ?? $product?->doc_num;
                $unitLabel = $snapshot['unit_label'] ?? ($product instanceof Product ? $product->unit?->name : null);
                $productLabel = trim(implode(' / ', array_filter([
                    $productDocNum,
                    $snapshot['name'] ?? ($product instanceof Product ? $product->name : null),
                    $snapshot['barcode'] ?? null,
                    $unitLabel,
                ])));

                return [
                    'public_id' => $mode === 'clone' ? null : $line->public_id,
                    'product_doc_num' => $productDocNum,
                    'product_label' => $productLabel !== '' ? $productLabel : null,
                    'imageUrl' => $snapshot['image_url'] ?? ($product instanceof Product ? app(ProductImageResolver::class)->url($product) : null),
                    'unit' => $unitLabel,
                    'quantity' => $this->numbers->format($line->quantity),
                    'stock_status' => $line->stock_status,
                    'batch_lot' => $line->batch_lot,
                    'notes' => $line->notes,
                ];
            })->values()->all() ?? [];
        }

        if ($lines === [] && $mode !== 'view') {
            return [['public_id' => null, 'product_doc_num' => null, 'product_label' => null, 'unit' => null, 'quantity' => null, 'stock_status' => 'available', 'batch_lot' => null, 'notes' => null]];
        }

        return array_values($lines);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?OpeningStock $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.opening-stocks.show', $record->doc_num) : null],
                ['label' => __('common.actions.clone_record')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.opening-stocks.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.inventory.opening-stocks.index', $extra);
    }

    private function urls(OpeningStock $record): array
    {
        return [
            'show' => route('admin.inventory.opening-stocks.show', $record->doc_num),
            'edit' => route('admin.inventory.opening-stocks.edit', $record->doc_num),
            'clone' => route('admin.inventory.opening-stocks.clone', $record->doc_num),
            'update' => route('admin.inventory.opening-stocks.update', $record->doc_num),
            'destroy' => route('admin.inventory.opening-stocks.destroy', $record->doc_num),
            'approve' => route('admin.inventory.opening-stocks.approve', $record->doc_num),
        ];
    }

    private function editBlockedMessage(OpeningStock $record): string
    {
        if ($record->isApproved()) {
            return __('inventory.opening_stocks.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('inventory.opening_stocks.messages.closed_edit_forbidden');
        }

        return __('inventory.opening_stocks.messages.approved_not_editable');
    }

    private function saveResponse(Request $request, OpeningStock $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.inventory.opening-stocks.show', $record->doc_num),
            'save_edit' => route('admin.inventory.opening-stocks.edit', $record->doc_num),
            'save_back' => route('admin.inventory.opening-stocks.index'),
            'save_clone' => route('admin.inventory.opening-stocks.clone', $record->doc_num),
            default => $operation === 'store' ? route('admin.inventory.opening-stocks.create') : null,
        };

        return array_filter(['submit_action' => $action, 'redirect' => $redirect]);
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
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
    private function metadata(?OpeningStock $record): array
    {
        if (! $record instanceof OpeningStock) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->approved_by, $record->deleted_by, $record->restored_by]))
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
}
