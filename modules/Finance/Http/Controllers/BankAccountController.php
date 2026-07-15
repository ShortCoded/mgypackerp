<?php

namespace Modules\Finance\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\DataTables\BankAccountsDataTable;
use Modules\Finance\Http\Requests\BankAccounts\BulkDeleteBankAccountsRequest;
use Modules\Finance\Http\Requests\BankAccounts\StoreBankAccountRequest;
use Modules\Finance\Http\Requests\BankAccounts\StoreBankGroupRequest;
use Modules\Finance\Http\Requests\BankAccounts\UpdateBankAccountDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\BankAccounts\UpdateBankAccountRequest;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\BankAccountChartAccountService;
use Modules\Finance\Services\BankAccountService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;

class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountService $service, private readonly BreadcrumbService $breadcrumbs) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.bank-accounts.index', ['breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.finance.bank-accounts.index'), 'documentNumberSettings' => $settings->current('bank_accounts')]);
    }

    public function data(Request $request, BankAccountsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, BankAccount $bankAccount): View
    {
        abort_if($bankAccount->trashed() && ! $request->user()?->can('bank_accounts.view_trashed'), 404);

        return $this->form('view', $bankAccount);
    }

    public function edit(BankAccount $bankAccount): View
    {
        return $this->form('edit', $bankAccount);
    }

    public function clone(BankAccount $bankAccount): View
    {
        $token = (string) Str::uuid();

        return $this->form('clone', $bankAccount, $token);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $record = $this->service->create($request->validated())['record'];

        return response()->json(['success' => true, 'message' => __('bank_accounts.messages.created'), ...$this->saveResponse($request, $record, 'store'), 'data' => ['doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $result = $this->service->update($bankAccount, $request->validated());
        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }
        $record = $result['record'];

        return response()->json(['success' => true, 'message' => __('bank_accounts.messages.updated'), ...$this->saveResponse($request, $record, 'update'), 'data' => ['old_doc_number' => $result['old_doc_number'], 'old_doc_num' => $result['old_doc_num'], 'doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function storeBankGroup(StoreBankGroupRequest $request, BankAccountChartAccountService $chartAccounts): JsonResponse
    {
        $data = $request->validated();

        try {
            $account = $chartAccounts->createBankAccount(
                $data['name'],
                ($data['notes'] ?? null) ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('bank_accounts.messages.bank_created'),
            'data' => ['option' => $this->accountOption($account)],
        ]);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $this->service->delete($bankAccount);

        return response()->json(['success' => true, 'message' => __('bank_accounts.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteBankAccountsRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => __('bank_accounts.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))])]);
    }

    public function restore(string $bankAccount): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->service->restore(BankAccount::withTrashed()->forCompany($companyId)->where('doc_num', $bankAccount)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('bank_accounts.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateBankAccountDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('bank_accounts', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('bank_accounts.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?BankAccount $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['account.parent', 'bank', 'currency']);

        return view('modules.finance.bank-accounts.form', ['mode' => $mode, 'record' => $record, 'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.finance.bank-accounts.store') : route('admin.finance.bank-accounts.update', $record?->doc_num), 'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT', 'canControlDocumentNumber' => (bool) auth()->user()?->can('bank_accounts.document_number.control'), 'canCreateAccounts' => (bool) auth()->user()?->can('accounts.create'), 'breadcrumbs' => $this->breadcrumbs($mode, $record), 'cloneSourceToken' => $cloneSourceToken]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?BankAccount $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.bank-accounts.show', $record->doc_num) : null,
                ],
                ['label' => __('bank_accounts.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.bank-accounts.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.finance.bank-accounts.index', $extra);
    }

    private function urls(BankAccount $record): array
    {
        return ['show' => route('admin.finance.bank-accounts.show', $record->doc_num), 'edit' => route('admin.finance.bank-accounts.edit', $record->doc_num), 'clone' => route('admin.finance.bank-accounts.clone', $record->doc_num), 'update' => route('admin.finance.bank-accounts.update', $record->doc_num), 'destroy' => route('admin.finance.bank-accounts.destroy', $record->doc_num)];
    }

    private function saveResponse(Request $request, BankAccount $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.finance.bank-accounts.show', $record->doc_num),
            'save_edit' => route('admin.finance.bank-accounts.edit', $record->doc_num),
            'save_back' => route('admin.finance.bank-accounts.index'),
            'save_clone' => route('admin.finance.bank-accounts.clone', $record->doc_num),
            default => null,
        };
        $response = [
            'submit_action' => $action,
        ];

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
            'text' => trim($account->account_code.' — '.$account->name),
        ];
    }
}
