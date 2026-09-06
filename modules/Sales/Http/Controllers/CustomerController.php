<?php

namespace Modules\Sales\Http\Controllers;

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
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\DataTables\CustomersDataTable;
use Modules\Sales\Http\Requests\BulkDeleteCustomersRequest;
use Modules\Sales\Http\Requests\StoreCustomerGroupRequest;
use Modules\Sales\Http\Requests\StoreCustomerRequest;
use Modules\Sales\Http\Requests\UpdateCustomerDocumentNumberSettingsRequest;
use Modules\Sales\Http\Requests\UpdateCustomerRequest;
use Modules\Sales\Models\Customer;
use Modules\Sales\Services\CustomerSalesOverviewService;
use Modules\Sales\Services\CustomerService;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.sales.customers.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.customers.index'),
            'documentNumberSettings' => $settings->current('customers'),
        ]);
    }

    public function data(Request $request, CustomersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, Customer $customer): View
    {
        abort_if($customer->trashed() && ! $request->user()?->can('customers.view_trashed'), 404);

        return $this->form('view', $customer)->with('salesOverview', app(CustomerSalesOverviewService::class)->forCustomer($customer, (int) app(OperatingContextService::class)->snapshot($request)['branch_id']));
    }

    public function edit(Customer $customer): View
    {
        return $this->form('edit', $customer);
    }

    public function clone(Customer $customer): View
    {
        return $this->form('clone', $customer, (string) Str::uuid());
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('customers.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $result = $this->service->update($customer, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }

        /** @var Customer $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('customers.messages.updated'),
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

    public function storeAccountGroup(StoreCustomerGroupRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $account = $this->accounts->createGroup(
                BusinessPartnerAccountService::Customer,
                $data['name'],
                ($data['notes'] ?? null) ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('customers.messages.account_group_created'),
            'data' => ['option' => $this->accountOption($account)],
        ]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $this->service->delete($customer);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('customers.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteCustomersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('customers.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))]),
        ]);
    }

    public function restore(string $customer): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->service->restore(Customer::withTrashed()->forCompany($companyId)->where('doc_num', $customer)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('customers.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateCustomerDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('customers', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('customers.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?Customer $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['account', 'accountGroup', 'country', 'governorate', 'cityLookup', 'area', 'creditLimits.currency']);

        return view('modules.sales.customers.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.sales.customers.store') : route('admin.sales.customers.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('customers.document_number.control'),
            'canCreateAccounts' => (bool) auth()->user()?->can('accounts.create'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?Customer $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.sales.customers.show', $record->doc_num) : null,
                ],
                ['label' => __('customers.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.sales.customers.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.sales.customers.index', $extra);
    }

    private function urls(Customer $record): array
    {
        return [
            'show' => route('admin.sales.customers.show', $record->doc_num),
            'edit' => route('admin.sales.customers.edit', $record->doc_num),
            'clone' => route('admin.sales.customers.clone', $record->doc_num),
            'update' => route('admin.sales.customers.update', $record->doc_num),
            'destroy' => route('admin.sales.customers.destroy', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, Customer $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.sales.customers.show', $record->doc_num),
            'save_edit' => route('admin.sales.customers.edit', $record->doc_num),
            'save_back' => route('admin.sales.customers.index'),
            'save_clone' => route('admin.sales.customers.clone', $record->doc_num),
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
    private function metadata(?Customer $record): array
    {
        if (! $record instanceof Customer) {
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
