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
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\DataTables\CashboxesDataTable;
use Modules\Finance\Http\Requests\Cashboxes\BulkDeleteCashboxesRequest;
use Modules\Finance\Http\Requests\Cashboxes\StoreCashboxGroupRequest;
use Modules\Finance\Http\Requests\Cashboxes\StoreCashboxRequest;
use Modules\Finance\Http\Requests\Cashboxes\UpdateCashboxDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\Cashboxes\UpdateCashboxRequest;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Services\CashboxChartAccountService;
use Modules\Finance\Services\CashboxService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;

class CashboxController extends Controller
{
    public function __construct(
        private readonly CashboxService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.cashboxes.index', ['breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.finance.cashboxes.index'), 'documentNumberSettings' => $settings->current('cashboxes')]);
    }

    public function data(Request $request, CashboxesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, Cashbox $cashbox): View
    {
        abort_if($cashbox->trashed() && ! $request->user()?->can('cashboxes.view_trashed'), 404);

        return $this->form('view', $cashbox);
    }

    public function edit(Cashbox $cashbox): View
    {
        return $this->form('edit', $cashbox);
    }

    public function clone(Cashbox $cashbox): View
    {
        return $this->form('clone', $cashbox, (string) Str::uuid());
    }

    public function store(StoreCashboxRequest $request): JsonResponse
    {
        try {
            $record = $this->service->create($request->validated())['record'];
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cashboxes.messages.created'), ...$this->saveResponse($request, $record, 'store'), 'data' => ['doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function update(UpdateCashboxRequest $request, Cashbox $cashbox): JsonResponse
    {
        try {
            $result = $this->service->update($cashbox, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }
        $record = $result['record'];

        return response()->json(['success' => true, 'message' => __('cashboxes.messages.updated'), ...$this->saveResponse($request, $record, 'update'), 'data' => ['old_doc_number' => $result['old_doc_number'], 'old_doc_num' => $result['old_doc_num'], 'doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function storeAccountGroup(StoreCashboxGroupRequest $request, CashboxChartAccountService $chartAccounts): JsonResponse
    {
        $data = $request->validated();

        try {
            $account = $chartAccounts->createCashboxGroup(
                $data['name'],
                ($data['notes'] ?? null) ?: null,
            );
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('cashboxes.messages.account_group_created'),
            'data' => ['option' => $this->accountOption($account)],
        ]);
    }

    public function destroy(Cashbox $cashbox): JsonResponse
    {
        try {
            $this->service->delete($cashbox);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cashboxes.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteCashboxesRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => __('cashboxes.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))])]);
    }

    public function restore(string $cashbox): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->service->restore(Cashbox::withTrashed()->forCompany($companyId)->where('doc_num', $cashbox)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('cashboxes.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateCashboxDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('cashboxes', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('cashboxes.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?Cashbox $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['account', 'branch.company', 'currencies.currency']);
        $branch = $record?->branch;

        if (! $branch && in_array($mode, ['create', 'clone'], true)) {
            $branchDocNum = request()->session()->get(OperatingContextService::BranchDocNumKey);
            $branch = is_string($branchDocNum) && $branchDocNum !== ''
                ? $this->operatingContext->allowedBranchForCurrentCompany(request(), $branchDocNum)
                : null;
        }

        return view('modules.finance.cashboxes.form', ['mode' => $mode, 'record' => $record, 'defaultBranch' => $branch, 'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.finance.cashboxes.store') : route('admin.finance.cashboxes.update', $record?->doc_num), 'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT', 'canControlDocumentNumber' => (bool) auth()->user()?->can('cashboxes.document_number.control'), 'canCreateAccounts' => (bool) auth()->user()?->can('accounts.create'), 'breadcrumbs' => $this->breadcrumbs($mode, $record), 'cloneSourceToken' => $cloneSourceToken]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?Cashbox $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.cashboxes.show', $record->doc_num) : null,
                ],
                ['label' => __('cashboxes.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.cashboxes.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.finance.cashboxes.index', $extra);
    }

    private function urls(Cashbox $record): array
    {
        return ['show' => route('admin.finance.cashboxes.show', $record->doc_num), 'edit' => route('admin.finance.cashboxes.edit', $record->doc_num), 'clone' => route('admin.finance.cashboxes.clone', $record->doc_num), 'update' => route('admin.finance.cashboxes.update', $record->doc_num), 'destroy' => route('admin.finance.cashboxes.destroy', $record->doc_num)];
    }

    private function saveResponse(Request $request, Cashbox $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.finance.cashboxes.show', $record->doc_num),
            'save_edit' => route('admin.finance.cashboxes.edit', $record->doc_num),
            'save_back' => route('admin.finance.cashboxes.index'),
            'save_clone' => route('admin.finance.cashboxes.clone', $record->doc_num),
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
            'text' => $account->codeNameLabel(),
        ];
    }
}
