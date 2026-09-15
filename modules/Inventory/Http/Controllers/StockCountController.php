<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
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
use Modules\Inventory\DataTables\StockCountsDataTable;
use Modules\Inventory\Exports\StockCountExport;
use Modules\Inventory\Http\Requests\StockCountBalanceRequest;
use Modules\Inventory\Http\Requests\StoreStockCountRequest;
use Modules\Inventory\Http\Requests\UpdateStockCountDocumentNumberSettingsRequest;
use Modules\Inventory\Http\Requests\UpdateStockCountRequest;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountLine;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventorySelect2Service;
use Modules\Inventory\Services\StockCountService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockCountController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly StockCountService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly NumericFormatService $numbers,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request, FinanceDocumentNumberSettingsService $settings): View
    {
        $this->requiredContext($request);

        return view('modules.inventory.stock-counts.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.inventory.stock-counts.index'),
            'documentNumberSettings' => $settings->current('inventory_stock_counts'),
        ]);
    }

    public function data(Request $request, StockCountsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        return $this->form($request, 'create');
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $record = $this->guard(fn (): StockCount => $this->service->create($request->validated()));

        return response()->json([
            'success' => true,
            'message' => __('inventory.stock_counts.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function show(Request $request, StockCount $stockCount): View
    {
        $this->assertInCurrentContext($request, $stockCount);
        abort_if($stockCount->trashed() && ! $request->user()?->can('inventory.stock_counts.view_trashed'), 404);

        return $this->form($request, 'view', $stockCount);
    }

    public function edit(Request $request, StockCount $stockCount): View
    {
        $this->assertInCurrentContext($request, $stockCount);
        abort_unless($stockCount->isEditable(), 403, __('inventory.stock_counts.messages.approved_edit_forbidden'));

        return $this->form($request, 'edit', $stockCount);
    }

    public function clone(Request $request, StockCount $stockCount): View
    {
        $this->assertInCurrentContext($request, $stockCount);

        return $this->form($request, 'clone', $stockCount, (string) Str::uuid());
    }

    public function update(UpdateStockCountRequest $request, StockCount $stockCount): JsonResponse
    {
        $this->assertInCurrentContext($request, $stockCount);
        $oldDocNumber = $stockCount->doc_number;
        $oldDocNum = $stockCount->doc_num;
        $record = $this->guard(fn (): StockCount => $this->service->update($stockCount, $request->validated()));

        return response()->json([
            'success' => true,
            'message' => __('inventory.stock_counts.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->assertInCurrentContext($request, $stockCount);
        $this->guard(function () use ($stockCount): null {
            $this->service->delete($stockCount);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('inventory.stock_counts.messages.deleted')]);
    }

    public function restore(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->assertInCurrentContext($request, $stockCount);
        $this->guard(fn (): StockCount => $this->service->restore($stockCount));

        return response()->json(['success' => true, 'message' => __('inventory.stock_counts.messages.restored')]);
    }

    public function approve(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->assertInCurrentContext($request, $stockCount);
        $documents = $this->guard(fn (): array => $this->service->approve($stockCount));
        $record = $stockCount->refresh();

        return response()->json([
            'success' => true,
            'message' => __('inventory.stock_counts.messages.approved'),
            'data' => [
                'documents' => collect($documents)->pluck('doc_num')->all(),
                'doc_num' => $record->doc_num,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function print(Request $request, StockCount $stockCount): Response
    {
        $this->assertInCurrentContext($request, $stockCount);
        $record = $stockCount->load($this->reportRelations());

        return $this->pdf->stream('reports.inventory.stock-count', [
            'title' => __('inventory.stock_counts.title').' — '.$record->doc_num,
            'record' => $record,
            'totals' => $this->totals($record),
            'companyPrintIdentity' => $this->printIdentity->forCompany($record->company),
        ], str('stock-count-'.$record->doc_num)->slug().'.pdf', 'L');
    }

    public function export(Request $request, StockCount $stockCount): BinaryFileResponse
    {
        $this->assertInCurrentContext($request, $stockCount);
        $record = $stockCount->load($this->reportRelations());

        return Excel::download(new StockCountExport($record, $this->totals($record)), 'stock-count-'.$record->doc_num.'.xlsx');
    }

    public function products(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless((bool) $request->user()?->canAny(['inventory.stock_counts.view', 'inventory.stock_counts.create', 'inventory.stock_counts.edit']), 403);

        return response()->json($select2->products($request));
    }

    public function productDetails(Request $request, string $product, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless((bool) $request->user()?->canAny(['inventory.stock_counts.view', 'inventory.stock_counts.create', 'inventory.stock_counts.edit']), 403);
        $data = $select2->productDetails($request, $product);
        abort_unless(is_array($data), 404);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function balance(StockCountBalanceRequest $request): JsonResponse
    {
        $context = $this->requiredContext($request);
        $data = $request->validated();
        $product = Product::query()
            ->forCompany($context['company_id'])
            ->active()
            ->nonService()
            ->where('doc_num', $data['product_doc_num'])
            ->firstOrFail(['id', 'item_unit_id']);
        $quantity = $this->service->currentQuantity(
            $context['company_id'],
            (int) $data['branch_store_id'],
            isset($data['warehouse_location_id']) ? (int) $data['warehouse_location_id'] : null,
            (int) $product->getKey(),
            $product->item_unit_id === null ? null : (int) $product->item_unit_id,
            $data['stock_status'],
            $data['batch_lot'] ?? null,
        );

        return response()->json(['success' => true, 'data' => ['system_quantity' => $quantity]]);
    }

    public function updateDocumentNumberSettings(
        UpdateStockCountDocumentNumberSettingsRequest $request,
        FinanceDocumentNumberSettingsService $settings,
    ): JsonResponse {
        $result = $settings->update('inventory_stock_counts', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('inventory.stock_counts.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function form(Request $request, string $mode, ?StockCount $record = null, ?string $cloneSourceToken = null): View
    {
        $context = $this->requiredContext($request);
        $record?->loadMissing($this->formRelations());
        $stores = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name']);
        $locations = WarehouseLocation::query()
            ->whereIn('branch_store_id', $stores->modelKeys() !== [] ? $stores->modelKeys() : [0])
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('code')
            ->get(['id', 'branch_store_id', 'code', 'name']);
        $isCreateLike = in_array($mode, ['create', 'clone'], true);

        return view('modules.inventory.stock-counts.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $isCreateLike ? route('admin.inventory.stock-counts.store') : route('admin.inventory.stock-counts.update', $record),
            'method' => $isCreateLike ? 'POST' : 'PUT',
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'canControlDocumentNumber' => (bool) $request->user()?->can('inventory.stock_counts.document_number.control'),
            'isLocked' => $record ? ! $record->isEditable() : false,
            'dateValue' => $this->defaultDate($context, $record, $isCreateLike),
            'stores' => $stores,
            'locations' => $locations,
            'lines' => $this->lines($record, $mode),
            'totals' => $record ? $this->totals($record) : $this->emptyTotals(),
            'metadata' => $this->metadata($record),
            'stockStatuses' => $this->stockStatuses(),
            'canCreateProducts' => (bool) $request->user()?->can('products.create'),
            'productCreateUrl' => route('admin.products.create'),
        ]);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('operating_context.messages.required'));

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }

    private function assertInCurrentContext(Request $request, StockCount $stockCount): void
    {
        $context = $this->requiredContext($request);
        abort_unless(
            (int) $stockCount->company_id === $context['company_id']
            && (int) $stockCount->financial_period_id === $context['financial_period_id']
            && (int) $stockCount->branch_id === $context['branch_id'],
            404,
        );
    }

    /** @return list<array<string, mixed>> */
    private function lines(?StockCount $record, string $mode): array
    {
        $lines = old('lines');

        if (! is_array($lines)) {
            $lines = $record?->lines->map(function (StockCountLine $line) use ($mode): array {
                $product = $line->product;
                $unit = $line->unit?->name ?? $product?->unit?->name;
                $productLabel = trim(implode(' / ', array_filter([$product?->doc_num, $product?->name, $product?->barcode, $unit])));

                return [
                    'line_id' => $mode === 'clone' ? null : $line->getKey(),
                    'product_doc_num' => $product?->doc_num,
                    'product_label' => $productLabel,
                    'imageUrl' => $product instanceof Product ? app(ProductImageResolver::class)->url($product) : null,
                    'unit' => $unit,
                    'stock_status' => $line->stock_status,
                    'batch_lot' => $line->batch_lot,
                    'system_quantity' => $this->numbers->format($line->system_quantity),
                    'physical_quantity' => $this->numbers->format($line->physical_quantity),
                    'variance_quantity' => $this->numbers->format($line->variance_quantity),
                    'variance_reason' => $line->variance_reason,
                    'notes' => $line->notes,
                ];
            })->values()->all() ?? [];
        }

        if ($lines === [] && $mode !== 'view') {
            return [$this->emptyLine()];
        }

        return array_values($lines);
    }

    /** @return array<string, mixed> */
    private function emptyLine(): array
    {
        return [
            'line_id' => null,
            'product_doc_num' => null,
            'product_label' => null,
            'imageUrl' => null,
            'unit' => null,
            'stock_status' => InventoryTransaction::StatusAvailable,
            'batch_lot' => null,
            'system_quantity' => '0',
            'physical_quantity' => null,
            'variance_quantity' => '0',
            'variance_reason' => null,
            'notes' => null,
        ];
    }

    /** @return array<string, string> */
    private function totals(StockCount $record): array
    {
        $system = '0.00000000';
        $physical = '0.00000000';
        $variance = '0.00000000';
        $shortage = '0.00000000';
        $surplus = '0.00000000';

        foreach ($record->lines as $line) {
            $system = bcadd($system, (string) $line->system_quantity, 8);
            $physical = bcadd($physical, (string) ($line->physical_quantity ?? 0), 8);
            $variance = bcadd($variance, (string) $line->variance_quantity, 8);
            if (bccomp((string) $line->variance_quantity, '0', 8) < 0) {
                $shortage = bcadd($shortage, bcsub('0', (string) $line->variance_quantity, 8), 8);
            } elseif (bccomp((string) $line->variance_quantity, '0', 8) > 0) {
                $surplus = bcadd($surplus, (string) $line->variance_quantity, 8);
            }
        }

        return compact('system', 'physical', 'variance', 'shortage', 'surplus');
    }

    /** @return array<string, string> */
    private function emptyTotals(): array
    {
        return ['system' => '0', 'physical' => '0', 'variance' => '0', 'shortage' => '0', 'surplus' => '0'];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function defaultDate(array $context, ?StockCount $record, bool $isCreateLike): string
    {
        $dates = app(DateFormatService::class);
        if ($record instanceof StockCount && ! $isCreateLike) {
            return $dates->formatDate($record->count_date, '');
        }

        $period = FinancialPeriod::query()->find($context['financial_period_id']);
        $today = now()->toDateString();
        $default = $period && $period->from_date && $period->to_date && ($today < $period->from_date->toDateString() || $today > $period->to_date->toDateString())
            ? $period->from_date
            : now();

        return $dates->formatDate($default, '');
    }

    /** @return array<int, array{label: string, url?: string|null, active?: bool}> */
    private function breadcrumbs(string $mode, ?StockCount $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.stock-counts.show', $record) : null],
                ['label' => __('common.actions.clone_record')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.stock-counts.show', $record) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.inventory.stock-counts.index', $extra);
    }

    /** @return array<string, string> */
    private function urls(StockCount $record): array
    {
        return [
            'show' => route('admin.inventory.stock-counts.show', $record),
            'edit' => route('admin.inventory.stock-counts.edit', $record),
            'clone' => route('admin.inventory.stock-counts.clone', $record),
            'update' => route('admin.inventory.stock-counts.update', $record),
            'destroy' => route('admin.inventory.stock-counts.destroy', $record),
            'approve' => route('admin.inventory.stock-counts.approve', $record),
        ];
    }

    /** @return array<string, mixed> */
    private function saveResponse(Request $request, StockCount $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.inventory.stock-counts.show', $record),
            'save_edit' => route('admin.inventory.stock-counts.edit', $record),
            'save_back' => route('admin.inventory.stock-counts.index'),
            'save_clone' => route('admin.inventory.stock-counts.clone', $record),
            default => $operation === 'store' ? route('admin.inventory.stock-counts.create') : null,
        };

        return array_filter(['submit_action' => $action, 'redirect' => $redirect]);
    }

    private function submitAction(Request $request, bool $creating): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    /** @return array<string, string|null> */
    private function metadata(?StockCount $record): array
    {
        if (! $record instanceof StockCount) {
            return array_fill_keys(['created_by', 'created_at', 'updated_by', 'updated_at', 'approved_by', 'approved_at', 'deleted_by', 'deleted_at', 'restored_by', 'restored_at'], null);
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
        return $user instanceof User ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    /** @return list<string> */
    private function stockStatuses(): array
    {
        return [
            InventoryTransaction::StatusAvailable,
            InventoryTransaction::StatusProductionStaging,
            InventoryTransaction::StatusQcHold,
            InventoryTransaction::StatusQuarantine,
            InventoryTransaction::StatusDamaged,
        ];
    }

    /** @return list<string> */
    private function formRelations(): array
    {
        return ['branchStore', 'warehouseLocation', 'lines.product.unit', 'lines.product.mainImageUsage.file', 'lines.unit', 'adjustmentDocument'];
    }

    /** @return list<string> */
    private function reportRelations(): array
    {
        return ['company', 'branch', 'branchStore', 'warehouseLocation', 'lines.product', 'lines.unit', 'adjustmentDocument', 'approvedBy'];
    }
}
