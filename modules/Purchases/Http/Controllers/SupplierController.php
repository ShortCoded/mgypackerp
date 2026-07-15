<?php

namespace Modules\Purchases\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Purchases\DataTables\SuppliersDataTable;
use Modules\Purchases\Http\Requests\BulkDeleteSuppliersRequest;
use Modules\Purchases\Http\Requests\StoreSupplierGroupRequest;
use Modules\Purchases\Http\Requests\StoreSupplierRequest;
use Modules\Purchases\Http\Requests\UpdateSupplierDocumentNumberSettingsRequest;
use Modules\Purchases\Http\Requests\UpdateSupplierRequest;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Services\SupplierService;

class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.purchases.suppliers.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.purchases.suppliers.index'),
            'documentNumberSettings' => $settings->current('suppliers'),
        ]);
    }

    public function data(Request $request, SuppliersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, Supplier $supplier): View
    {
        abort_if($supplier->trashed() && ! $request->user()?->can('suppliers.view_trashed'), 404);

        return $this->form('view', $supplier);
    }

    public function edit(Supplier $supplier): View
    {
        return $this->form('edit', $supplier);
    }

    public function clone(Supplier $supplier): View
    {
        return $this->form('clone', $supplier, (string) Str::uuid());
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suppliers.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        try {
            $result = $this->service->update($supplier, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }

        /** @var Supplier $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('suppliers.messages.updated'),
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

    public function storeAccountGroup(StoreSupplierGroupRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $account = $this->accounts->createGroup(
                BusinessPartnerAccountService::Supplier,
                $data['name'],
                ($data['notes'] ?? null) ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('suppliers.messages.account_group_created'),
            'data' => ['option' => $this->accountOption($account)],
        ]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        try {
            $this->service->delete($supplier);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('suppliers.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteSuppliersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('suppliers.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))]),
        ]);
    }

    public function restore(string $supplier): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->service->restore(Supplier::withTrashed()->forCompany($companyId)->where('doc_num', $supplier)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('suppliers.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateSupplierDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('suppliers', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('suppliers.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?Supplier $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['account', 'accountGroup', 'country', 'governorate', 'cityLookup', 'area', 'creditLimits.currency']);

        return view('modules.purchases.suppliers.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.purchases.suppliers.store') : route('admin.purchases.suppliers.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('suppliers.document_number.control'),
            'canCreateAccounts' => (bool) auth()->user()?->can('accounts.create'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?Supplier $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.purchases.suppliers.show', $record->doc_num) : null,
                ],
                ['label' => __('suppliers.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.purchases.suppliers.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.purchases.suppliers.index', $extra);
    }

    private function urls(Supplier $record): array
    {
        return [
            'show' => route('admin.purchases.suppliers.show', $record->doc_num),
            'edit' => route('admin.purchases.suppliers.edit', $record->doc_num),
            'clone' => route('admin.purchases.suppliers.clone', $record->doc_num),
            'update' => route('admin.purchases.suppliers.update', $record->doc_num),
            'destroy' => route('admin.purchases.suppliers.destroy', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, Supplier $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.purchases.suppliers.show', $record->doc_num),
            'save_edit' => route('admin.purchases.suppliers.edit', $record->doc_num),
            'save_back' => route('admin.purchases.suppliers.index'),
            'save_clone' => route('admin.purchases.suppliers.clone', $record->doc_num),
            default => null,
        };
        $response = ['submit_action' => $action];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = true;
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
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
    private function metadata(?Supplier $record): array
    {
        if (! $record instanceof Supplier) {
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

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }
}
