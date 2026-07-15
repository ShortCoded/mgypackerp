<?php

namespace Modules\Finance\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Currency;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\DataTables\FundTransfersDataTable;
use Modules\Finance\Http\Requests\FundTransfers\BulkDeleteFundTransfersRequest;
use Modules\Finance\Http\Requests\FundTransfers\CancelFundTransferRequest;
use Modules\Finance\Http\Requests\FundTransfers\StoreFundTransferRequest;
use Modules\Finance\Http\Requests\FundTransfers\UpdateFundTransferDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\FundTransfers\UpdateFundTransferRequest;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Finance\Services\FundTransferService;

class FundTransferController extends Controller
{
    public function __construct(
        private readonly FundTransferService $service,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.fund-transfers.index', [
            'title' => __('fund_transfers.title'),
            'resource' => 'fund_transfers',
            'routePrefix' => 'admin.finance.fund-transfers',
            'tableId' => 'fund-transfers-table',
            'tableName' => 'fund_transfers',
            'columns' => $this->columns(),
            'documentNumberSettings' => $settings->current('fund_transfers'),
        ]);
    }

    public function data(Request $request, FundTransfersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $fundTransfer): View
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer, true);
        abort_if($fundTransfer->trashed() && ! $request->user()?->can('fund_transfers.view_trashed'), 404);

        return $this->form('view', $fundTransfer);
    }

    public function edit(Request $request, string $fundTransfer): View
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);
        abort_if($fundTransfer->isLockedForEditing(), 403, __('fund_transfers.messages.document_locked'));

        return $this->form('edit', $fundTransfer);
    }

    public function clone(Request $request, string $fundTransfer): View
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);

        return $this->form('clone', $fundTransfer, (string) Str::uuid());
    }

    public function store(StoreFundTransferRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): FundTransfer => $this->service->create($request->validated())['record']);
        $action = $this->submitAction($request, true);

        return response()->json([
            'success' => true,
            'message' => $action === 'save_new' ? __('fund_transfers.messages.saved_and_new') : __('fund_transfers.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateFundTransferRequest $request, string $fundTransfer): JsonResponse
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);
        $result = $this->guardDomain(fn (): array => $this->service->update($fundTransfer, $request->validated()));

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }

        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('fund_transfers.messages.updated'),
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

    public function destroy(Request $request, string $fundTransfer): JsonResponse
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);
        $this->guardDomain(function () use ($fundTransfer): null {
            $this->service->delete($fundTransfer);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('fund_transfers.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteFundTransfersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('fund_transfers.messages.bulk_deleted', [
                'count' => $this->service->bulkDelete($request->validated('doc_nums')),
            ]),
        ]);
    }

    public function restore(Request $request, string $fundTransfer): JsonResponse
    {
        $record = $this->findInCurrentCompany($request, $fundTransfer, true);
        $this->guardDomain(fn (): FundTransfer => $this->service->restore($record));

        return response()->json(['success' => true, 'message' => __('fund_transfers.messages.restored')]);
    }

    public function approve(Request $request, string $fundTransfer): JsonResponse
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);
        $record = $this->guardDomain(fn (): FundTransfer => $this->service->approve($fundTransfer));

        return response()->json([
            'success' => true,
            'message' => __('fund_transfers.messages.approved'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function cancel(CancelFundTransferRequest $request, string $fundTransfer): JsonResponse
    {
        $fundTransfer = $this->findInCurrentCompany($request, $fundTransfer);
        $record = $this->guardDomain(fn (): FundTransfer => $this->service->cancel($fundTransfer, (string) $request->validated('cancel_reason')));

        return response()->json([
            'success' => true,
            'message' => __('fund_transfers.messages.cancelled'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function print(Request $request, string $fundTransfer): JsonResponse
    {
        $this->findInCurrentCompany($request, $fundTransfer, true);

        return response()->json(['success' => false, 'message' => __('fund_transfers.messages.print_not_implemented')], 501);
    }

    public function updateDocumentNumberSettings(UpdateFundTransferDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('fund_transfers', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('fund_transfers.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'doc_num',
            'transfer_date',
            'source',
            'source_currency',
            'source_amount',
            'target',
            'target_currency',
            'target_amount',
            'exchange_rate',
            'status',
            'reason',
            'created_by',
            'updated_by',
        ];
    }

    private function form(string $mode, ?FundTransfer $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['sourceCashbox', 'sourceBankAccount.currency', 'targetCashbox', 'targetBankAccount.currency', 'sourceCurrency', 'targetCurrency']);

        return view('modules.finance.fund-transfers.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.finance.fund-transfers.store') : route('admin.finance.fund-transfers.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'resource' => 'fund_transfers',
            'routePrefix' => 'admin.finance.fund-transfers',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('fund_transfers.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'isLocked' => $record?->isLockedForEditing() ?? false,
            'holderOptions' => $this->holderOptions($record),
            'currencyOptions' => $this->currencyOptions($record),
            'mainCurrencyDocNum' => $this->mainCurrencyDocNum(),
            'metadata' => $this->metadata($record),
        ]);
    }

    private function breadcrumbs(string $mode, ?FundTransfer $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.finance.fund-transfers.show', $record->doc_num) : null],
                ['label' => __('fund_transfers.clone')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.finance.fund-transfers.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.finance.fund-transfers.index', $extra);
    }

    private function urls(FundTransfer $record): array
    {
        return [
            'show' => route('admin.finance.fund-transfers.show', $record->doc_num),
            'edit' => route('admin.finance.fund-transfers.edit', $record->doc_num),
            'clone' => route('admin.finance.fund-transfers.clone', $record->doc_num),
            'update' => route('admin.finance.fund-transfers.update', $record->doc_num),
            'destroy' => route('admin.finance.fund-transfers.destroy', $record->doc_num),
            'restore' => route('admin.finance.fund-transfers.restore', $record->doc_num),
            'approve' => route('admin.finance.fund-transfers.approve', $record->doc_num),
            'cancel' => route('admin.finance.fund-transfers.cancel', $record->doc_num),
            'print' => route('admin.finance.fund-transfers.print', $record->doc_num),
        ];
    }

    private function findInCurrentCompany(Request $request, string $docNum, bool $withTrashed = false): FundTransfer
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($request);
        abort_unless($companyId, 404);

        $query = $withTrashed ? FundTransfer::withTrashed() : FundTransfer::query();

        return $query
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function holderOptions(?FundTransfer $record): array
    {
        return [
            'source' => $this->holderOption($record, 'source'),
            'target' => $this->holderOption($record, 'target'),
        ];
    }

    private function holderOption(?FundTransfer $record, string $side): ?array
    {
        if (! $record instanceof FundTransfer) {
            return null;
        }

        if ($record->{"{$side}_type"} === FundTransfer::HolderBankAccount) {
            $bankAccount = $record->{"{$side}BankAccount"};

            if (! $bankAccount instanceof BankAccount) {
                return null;
            }

            return [
                'id' => (string) $bankAccount->doc_num,
                'text' => trim(implode(' — ', array_filter([$bankAccount->doc_num, $bankAccount->bank_name, $bankAccount->account_name]))),
            ];
        }

        $cashbox = $record->{"{$side}Cashbox"};

        if (! $cashbox instanceof Cashbox) {
            return null;
        }

        return [
            'id' => (string) $cashbox->doc_num,
            'text' => trim(implode(' — ', array_filter([$cashbox->doc_num, $cashbox->name]))),
        ];
    }

    private function currencyOptions(?FundTransfer $record): array
    {
        return [
            'source' => $this->currencyOption($record?->sourceCurrency),
            'target' => $this->currencyOption($record?->targetCurrency),
        ];
    }

    private function currencyOption(?Currency $currency): ?array
    {
        if (! $currency instanceof Currency) {
            return null;
        }

        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }

    private function mainCurrencyDocNum(): ?string
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        if ($companyId === null) {
            return null;
        }

        return Currency::query()->active()->forCompany($companyId)->where('is_main', true)->value('doc_num');
    }

    private function saveResponse(Request $request, FundTransfer $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');

        if ($operation === 'store' && $action === 'save_new') {
            return ['submit_action' => $action, 'reset_form' => true];
        }

        return array_filter([
            'submit_action' => $action,
            'redirect' => $operation === 'store' ? route('admin.finance.fund-transfers.show', $record->doc_num) : null,
        ], fn ($value): bool => $value !== null);
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        return $creating && $action === 'save_new' ? 'save_new' : 'save';
    }

    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    private function metadata(?FundTransfer $record): array
    {
        if (! $record instanceof FundTransfer) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->approved_by, $record->cancelled_by, $record->deleted_by, $record->restored_by]))
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
}
