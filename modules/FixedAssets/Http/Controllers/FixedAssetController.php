<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\FixedAssets\DataTables\FixedAssetsDataTable;
use Modules\FixedAssets\Http\Requests\BulkDeleteFixedAssetsRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetCategoryRequest;
use Modules\FixedAssets\Http\Requests\StoreFixedAssetRequest;
use Modules\FixedAssets\Http\Requests\UpdateFixedAssetDocumentNumberSettingsRequest;
use Modules\FixedAssets\Http\Requests\UpdateFixedAssetRequest;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Services\FixedAssetAccessService;
use Modules\FixedAssets\Services\FixedAssetImageResolver;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Purchases\Models\PurchaseInvoiceLine;

class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly FixedAssetImageResolver $assetImages,
        private readonly FixedAssetPurchaseIntegrationService $purchaseIntegration,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.fixed-assets.assets.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.fixed-assets.assets.index'),
            'documentNumberSettings' => $settings->current('fixed_assets'),
        ]);
    }

    public function data(Request $request, FixedAssetsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $input = $request->validate(['purchase_invoice_line' => ['nullable', 'string']]);
        try {
            $purchaseSource = filled($input['purchase_invoice_line'] ?? null)
                ? $this->purchaseIntegration->sourceLine((string) $input['purchase_invoice_line'], true)
                : null;
        } catch (DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->form('create', purchaseSource: $purchaseSource);
    }

    public function show(Request $request, FixedAsset $fixedAsset): View
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);
        abort_if($fixedAsset->trashed() && ! $request->user()?->can('fixed_assets.view_trashed'), 404);

        return $fixedAsset->trashed() ? $this->form('view', $fixedAsset) : app(FixedAssetLifecycleController::class)->show($fixedAsset);
    }

    public function edit(FixedAsset $fixedAsset): View
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);

        abort_unless($fixedAsset->canEditMaster(), 409, __('fixed_assets.messages.master_locked'));

        return $this->form('edit', $fixedAsset);
    }

    public function clone(FixedAsset $fixedAsset): View
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);

        return $this->form('clone', $fixedAsset, (string) Str::uuid());
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('fixed_assets.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'image_url' => $this->assetImages->url($record),
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        try {
            $result = $this->service->update($fixedAsset, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }

        /** @var FixedAsset $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('fixed_assets.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'image_url' => $this->assetImages->url($record),
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function storeAssetCategory(StoreFixedAssetCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $account = $this->accounts->createGroup(
                BusinessPartnerAccountService::FixedAsset,
                $data['name'],
                ($data['notes'] ?? null) ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('fixed_assets.messages.asset_category_created'),
            'data' => ['option' => $this->accountOption($account)],
        ]);
    }

    public function destroy(FixedAsset $fixedAsset): JsonResponse
    {
        try {
            $this->service->delete($fixedAsset);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('fixed_assets.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteFixedAssetsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('fixed_assets.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))]),
        ]);
    }

    public function restore(string $fixedAsset): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->service->restore(FixedAsset::withTrashed()->forCompany($companyId)->where('doc_num', $fixedAsset)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('fixed_assets.messages.restored')]);
    }

    public function image(Request $request, FixedAsset $fixedAsset)
    {
        app(FixedAssetAccessService::class)->assertAsset($fixedAsset);
        abort_if($fixedAsset->trashed() && ! $request->user()?->can('fixed_assets.view_trashed'), 404);

        $response = $this->assetImages->response($fixedAsset);

        abort_unless($response !== null, 404);

        return $response;
    }

    public function updateDocumentNumberSettings(UpdateFixedAssetDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('fixed_assets', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('fixed_assets.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?FixedAsset $record = null, ?string $cloneSourceToken = null, ?PurchaseInvoiceLine $purchaseSource = null): View
    {
        $record?->loadMissing([
            'account', 'assetGroupAccount', 'creditAccount', 'costCenter', 'branch', 'branchHall', 'currency', 'mainImageUsage.file',
        ]);
        if ($record?->source_type === FixedAssetPurchaseIntegrationService::SourceType) {
            $record->loadMissing([
                'purchaseInvoiceLine.purchaseInvoice.supplier', 'purchaseInvoiceLine.purchaseOrderLine.purchaseOrder',
                'purchaseInvoiceLine.receiptLine.receipt',
            ]);
        }

        return view('modules.fixed-assets.assets.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.fixed-assets.assets.store') : route('admin.fixed-assets.assets.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('fixed_assets.document_number.control'),
            'canCreateAccounts' => (bool) auth()->user()?->can('accounts.create'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
            'defaults' => $this->defaults($record, $purchaseSource),
            'purchaseSource' => $purchaseSource,
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?FixedAsset $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.fixed-assets.assets.show', $record->doc_num) : null,
                ],
                ['label' => __('fixed_assets.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.fixed-assets.assets.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.fixed-assets.assets.index', $extra);
    }

    private function urls(FixedAsset $record): array
    {
        return [
            'show' => route('admin.fixed-assets.assets.show', $record->doc_num),
            'edit' => route('admin.fixed-assets.assets.edit', $record->doc_num),
            'clone' => route('admin.fixed-assets.assets.clone', $record->doc_num),
            'update' => route('admin.fixed-assets.assets.update', $record->doc_num),
            'destroy' => route('admin.fixed-assets.assets.destroy', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, FixedAsset $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_new' => route('admin.fixed-assets.assets.create'),
            'save_view' => route('admin.fixed-assets.assets.show', $record->doc_num),
            'save_edit' => route('admin.fixed-assets.assets.edit', $record->doc_num),
            'save_back' => route('admin.fixed-assets.assets.index'),
            'save_clone' => route('admin.fixed-assets.assets.clone', $record->doc_num),
            default => null,
        };
        $response = ['submit_action' => $action];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_view';
        }

        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
    }

    private function accountOption(Account $account): array
    {
        return [
            'id' => $account->doc_num,
            'text' => $account->codeNameLabel(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?FixedAsset $record): array
    {
        if (! $record instanceof FixedAsset) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($record->deleted_by)),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($record->restored_by)),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(?FixedAsset $record, ?PurchaseInvoiceLine $purchaseSource = null): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $context = app(OperatingContextService::class)->snapshot(request());
        $period = Arr::get($context, 'financial_period_id') ? FinancialPeriod::query()->find(Arr::get($context, 'financial_period_id')) : null;
        $today = Carbon::today();
        $date = $today;

        if ($period instanceof FinancialPeriod && ($today->lt($period->from_date) || $today->gt($period->to_date))) {
            $date = $period->from_date;
        }

        $mainCurrency = $companyId ? Currency::query()->forCompany($companyId)->active()->where('is_main', true)->first() : null;
        $settings = app(DateFormatService::class);

        $purchaseDefaults = $purchaseSource ? $this->purchaseIntegration->defaults($purchaseSource) : [];
        foreach (['asset_date', 'purchase_date', 'acquisition_date', 'operation_date'] as $dateField) {
            if (filled($purchaseDefaults[$dateField] ?? null)) {
                $purchaseDefaults[$dateField] = $settings->formatDate($purchaseDefaults[$dateField], '');
            }
        }

        return [
            'asset_date' => $settings->formatDate($record?->asset_date ?? $date, ''),
            'currency_option' => $record?->currency ? $this->currencyOption($record->currency) : ($mainCurrency ? $this->currencyOption($mainCurrency) : null),
            'main_currency_doc_num' => $mainCurrency?->doc_num,
            'exchange_rate' => $record?->exchange_rate ?? ($mainCurrency ? '1' : null),
            ...$purchaseDefaults,
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    private function currencyOption(Currency $currency): array
    {
        return [
            'id' => $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
        ];
    }
}
