<?php

namespace Modules\Finance\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Currency;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Modules\Finance\DataTables\ChequesDataTable;
use Modules\Finance\Http\Requests\Cheques\BulkDeleteChequesRequest;
use Modules\Finance\Http\Requests\Cheques\CancelChequeRequest;
use Modules\Finance\Http\Requests\Cheques\StoreChequeRequest;
use Modules\Finance\Http\Requests\Cheques\UpdateChequeDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\Cheques\UpdateChequeRequest;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\ChequeService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class ChequeController extends Controller
{
    public function __construct(
        private readonly ChequeService $service,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.cheques.index', [
            'title' => __('cheques.title'),
            'resource' => 'cheques',
            'routePrefix' => 'admin.finance.cheques',
            'tableId' => 'cheques-table',
            'tableName' => 'cheques',
            'columns' => $this->columns(),
            'documentNumberSettings' => [
                'received_cheques' => $settings->current('received_cheques'),
                'issued_cheques' => $settings->current('issued_cheques'),
            ],
        ]);
    }

    public function data(Request $request, ChequesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        return $this->form('create', null, null, $request->string('type')->toString());
    }

    public function show(Request $request, string $cheque): View
    {
        $cheque = $this->findInCurrentCompany($request, $cheque, true);
        abort_if($cheque->trashed() && ! $request->user()?->can('cheques.view_trashed'), 404);

        return $this->form('view', $cheque);
    }

    public function edit(Request $request, string $cheque): View
    {
        $cheque = $this->findInCurrentCompany($request, $cheque);
        abort_if($cheque->isLockedForEditing(), 403, __('cheques.messages.document_locked'));

        return $this->form('edit', $cheque);
    }

    public function clone(Request $request, string $cheque): View
    {
        $cheque = $this->findInCurrentCompany($request, $cheque);

        return $this->form('clone', $cheque, (string) Str::uuid());
    }

    public function store(StoreChequeRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): Cheque => $this->service->create($request->validated())['record']);
        $action = $this->submitAction($request, true);

        return response()->json([
            'success' => true,
            'message' => $action === 'save_new' ? __('cheques.messages.saved_and_new') : __('cheques.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateChequeRequest $request, string $cheque): JsonResponse
    {
        $cheque = $this->findInCurrentCompany($request, $cheque);
        $result = $this->guardDomain(fn (): array => $this->service->update($cheque, $request->validated()));

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }

        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('cheques.messages.updated'),
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

    public function destroy(Request $request, string $cheque): JsonResponse
    {
        $cheque = $this->findInCurrentCompany($request, $cheque);
        $this->guardDomain(function () use ($cheque): null {
            $this->service->delete($cheque);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('cheques.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteChequesRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __('cheques.messages.bulk_deleted', [
                'count' => $this->service->bulkDelete($request->validated('doc_nums')),
            ]),
        ]);
    }

    public function restore(Request $request, string $cheque): JsonResponse
    {
        $record = $this->findInCurrentCompany($request, $cheque, true);
        $this->guardDomain(fn (): Cheque => $this->service->restore($record));

        return response()->json(['success' => true, 'message' => __('cheques.messages.restored')]);
    }

    public function markDeposited(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markDeposited', 'deposited');
    }

    public function markCollected(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markCollected', 'collected');
    }

    public function markReturned(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markReturned', 'returned');
    }

    public function markIssued(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markIssued', 'issued');
    }

    public function markDelivered(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markDelivered', 'delivered');
    }

    public function markCleared(Request $request, string $cheque): JsonResponse
    {
        return $this->statusResponse($request, $cheque, 'markCleared', 'cleared');
    }

    public function cancel(CancelChequeRequest $request, string $cheque): JsonResponse
    {
        $cheque = $this->findInCurrentCompany($request, $cheque);
        $record = $this->guardDomain(fn (): Cheque => $this->service->cancel($cheque, (string) $request->validated('cancel_reason')));

        return response()->json([
            'success' => true,
            'message' => __('cheques.messages.cancelled'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function print(Request $request, string $cheque, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentities): Response
    {
        $record = $this->findInCurrentCompany($request, $cheque, true);
        $record->loadMissing(['company', 'bankAccount.bank', 'bankAccount.account', 'currency', 'lines.account']);
        $identity = $printIdentities->forCompany($record->company);

        return $pdf->stream('modules.finance.cheques.print', [
            'title' => ($record->isIssued() ? __('Issued / Payment Cheque') : __('Received Cheque')).' — '.$record->doc_num,
            'companyName' => $identity['legal_name'] ?: $identity['name'],
            'companyLogoPath' => $identity['logo_source'],
            'companyPrintIdentity' => $identity,
            'record' => $record,
        ], str(($record->isIssued() ? 'outgoing-cheque-' : 'received-cheque-').$record->doc_num)->slug().'.pdf', 'P');
    }

    public function updateDocumentNumberSettings(UpdateChequeDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update(
            (string) $request->validated('document_key'),
            $request->validated('prefix'),
            (int) $request->validated('padding')
        );

        return response()->json([
            'success' => true,
            'message' => __('cheques.document_number_settings.updated_successfully'),
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
            'cheque_type',
            'cheque_number',
            'party',
            'bank_account',
            'external_bank_name',
            'currency',
            'exchange_rate',
            'amount',
            'distributed_amount',
            'remaining_amount',
            'due_date',
            'status',
            'created_by',
            'updated_by',
        ];
    }

    private function form(string $mode, ?Cheque $record = null, ?string $cloneSourceToken = null, ?string $defaultType = null): View
    {
        $record?->loadMissing(['bankAccount.currency', 'currency', 'lines.account']);
        $defaultType = in_array($defaultType, Cheque::types(), true) ? $defaultType : Cheque::TypeReceived;

        return view('modules.finance.cheques.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.finance.cheques.store') : route('admin.finance.cheques.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'resource' => 'cheques',
            'routePrefix' => 'admin.finance.cheques',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('cheques.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'isLocked' => $record?->isLockedForEditing() ?? false,
            'defaultType' => $record?->cheque_type ?? $defaultType,
            'bankAccountOption' => $this->bankAccountOption($record),
            'currencyOption' => $this->currencyOption($record),
            'partyOption' => $this->partyOption($record),
            'mainCurrencyDocNum' => $this->mainCurrencyDocNum(),
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?Cheque $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.finance.cheques.show', $record->doc_num) : null],
                ['label' => __('cheques.clone')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.finance.cheques.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.finance.cheques.index', $extra);
    }

    private function statusResponse(Request $request, string $docNum, string $method, string $messageKey): JsonResponse
    {
        $cheque = $this->findInCurrentCompany($request, $docNum);
        $record = $this->guardDomain(fn (): Cheque => $this->service->{$method}($cheque));

        return response()->json([
            'success' => true,
            'message' => __("cheques.messages.{$messageKey}"),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    private function urls(Cheque $record): array
    {
        return [
            'show' => route('admin.finance.cheques.show', $record->doc_num),
            'edit' => route('admin.finance.cheques.edit', $record->doc_num),
            'clone' => route('admin.finance.cheques.clone', $record->doc_num),
            'update' => route('admin.finance.cheques.update', $record->doc_num),
            'destroy' => route('admin.finance.cheques.destroy', $record->doc_num),
            'restore' => route('admin.finance.cheques.restore', $record->doc_num),
            'mark_deposited' => route('admin.finance.cheques.mark-deposited', $record->doc_num),
            'mark_collected' => route('admin.finance.cheques.mark-collected', $record->doc_num),
            'mark_returned' => route('admin.finance.cheques.mark-returned', $record->doc_num),
            'mark_issued' => route('admin.finance.cheques.mark-issued', $record->doc_num),
            'mark_delivered' => route('admin.finance.cheques.mark-delivered', $record->doc_num),
            'mark_cleared' => route('admin.finance.cheques.mark-cleared', $record->doc_num),
            'cancel' => route('admin.finance.cheques.cancel', $record->doc_num),
            'print' => route('admin.finance.cheques.print', $record->doc_num),
        ];
    }

    private function findInCurrentCompany(Request $request, string $docNum, bool $withTrashed = false): Cheque
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($request);
        abort_unless($companyId, 404);

        $query = $withTrashed ? Cheque::withTrashed() : Cheque::query();

        return $query
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function bankAccountOption(?Cheque $record): ?array
    {
        $bankAccount = $record?->bankAccount;

        if (! $bankAccount instanceof BankAccount) {
            return null;
        }

        return [
            'id' => (string) $bankAccount->doc_num,
            'text' => trim(implode(' — ', array_filter([$bankAccount->doc_num, $bankAccount->bank_name, $bankAccount->account_name]))),
            'currency_doc_num' => $bankAccount->currency?->doc_num,
            'currency_text' => trim(implode(' — ', array_filter([$bankAccount->currency?->code, $bankAccount->currency?->name]))),
            'currency_is_main' => (bool) $bankAccount->currency?->is_main,
        ];
    }

    private function currencyOption(?Cheque $record): ?array
    {
        $currency = $record?->currency;

        if (! $currency instanceof Currency) {
            return null;
        }

        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }

    private function partyOption(?Cheque $record): ?array
    {
        if (! $record instanceof Cheque || ! in_array($record->party_type, ['customer', 'supplier'], true) || ! $record->party_id) {
            return null;
        }

        $party = $record->party_type === 'customer'
            ? Customer::withTrashed()->where('company_id', $record->company_id)->find($record->party_id)
            : Supplier::withTrashed()->where('company_id', $record->company_id)->find($record->party_id);

        if (! ($party instanceof Customer) && ! ($party instanceof Supplier)) {
            return null;
        }

        return [
            'id' => (string) $party->doc_num,
            'text' => trim(implode(' / ', array_filter([$party->doc_num, $party->name, $party->phone ?: $party->mobile]))),
            'name' => (string) $party->name,
            'type' => (string) $record->party_type,
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

    private function saveResponse(Request $request, Cheque $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');

        if ($operation === 'store' && $action === 'save_new') {
            return ['submit_action' => $action, 'reset_form' => true];
        }

        return array_filter([
            'submit_action' => $action,
            'redirect' => $operation === 'store' ? route('admin.finance.cheques.show', $record->doc_num) : null,
        ], fn ($value): bool => $value !== null);
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        return $creating && $action === 'save_new' ? 'save_new' : 'save';
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     *
     * @throws ValidationException
     */
    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    private function metadata(?Cheque $record): array
    {
        if (! $record instanceof Cheque) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->cancelled_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
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
