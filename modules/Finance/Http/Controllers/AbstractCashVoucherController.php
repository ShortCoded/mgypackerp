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
use Modules\Finance\DataTables\CashVouchersDataTable;
use Modules\Finance\Http\Requests\CashVouchers\BulkDeleteCashVouchersRequest;
use Modules\Finance\Http\Requests\CashVouchers\CancelCashVoucherRequest;
use Modules\Finance\Http\Requests\CashVouchers\StoreCashVoucherRequest;
use Modules\Finance\Http\Requests\CashVouchers\UpdateCashVoucherDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\CashVouchers\UpdateCashVoucherRequest;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;

abstract class AbstractCashVoucherController extends Controller
{
    public function __construct(
        private readonly CashVoucherService $service,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.cash-vouchers.index', [
            'title' => __($this->translationKey().'.title'),
            'resource' => $this->permissionPrefix(),
            'routePrefix' => $this->routePrefix(),
            'tableId' => Str::kebab($this->permissionPrefix()).'-table',
            'tableName' => 'cash_vouchers',
            'columns' => $this->columns(),
            'documentNumberSettings' => $settings->current($this->documentNumberKey()),
            'translationKey' => $this->translationKey(),
        ]);
    }

    public function data(Request $request, CashVouchersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request, $this->voucherType(), $this->permissionPrefix(), $this->routePrefix(), $this->translationKey());
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $cashVoucher): View
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher, true);
        abort_if($cashVoucher->trashed() && ! $request->user()?->can($this->permissionPrefix().'.view_trashed'), 404);

        return $this->form('view', $cashVoucher);
    }

    public function edit(Request $request, string $cashVoucher): View
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);
        abort_if($cashVoucher->isLockedForEditing(), 403, __($this->translationKey().'.messages.document_locked'));

        return $this->form('edit', $cashVoucher);
    }

    public function clone(Request $request, string $cashVoucher): View
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);

        return $this->form('clone', $cashVoucher, (string) Str::uuid());
    }

    public function store(StoreCashVoucherRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): CashVoucher => $this->service->create($this->voucherType(), $request->validated())['record']);
        $action = $this->submitAction($request, true);
        $message = $action === 'save_new'
            ? __($this->translationKey().'.messages.saved_and_new')
            : __($this->translationKey().'.messages.created');

        return response()->json([
            'success' => true,
            'message' => $message,
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateCashVoucherRequest $request, string $cashVoucher): JsonResponse
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);
        $result = $this->guardDomain(fn (): array => $this->service->update($this->voucherType(), $cashVoucher, $request->validated()));

        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }

        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __($this->translationKey().'.messages.updated'),
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

    public function destroy(Request $request, string $cashVoucher): JsonResponse
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);
        $this->guardDomain(function () use ($cashVoucher): null {
            $this->service->delete($this->voucherType(), $cashVoucher);

            return null;
        });

        return response()->json(['success' => true, 'message' => __($this->translationKey().'.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteCashVouchersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => __($this->translationKey().'.messages.bulk_deleted', [
                'count' => $this->service->bulkDelete($this->voucherType(), $request->validated('doc_nums')),
            ]),
        ]);
    }

    public function restore(Request $request, string $cashVoucher): JsonResponse
    {
        $record = $this->findInCurrentCompany($request, $cashVoucher, true);
        $this->guardDomain(fn (): CashVoucher => $this->service->restore($this->voucherType(), $record));

        return response()->json(['success' => true, 'message' => __($this->translationKey().'.messages.restored')]);
    }

    public function approve(Request $request, string $cashVoucher): JsonResponse
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);
        $record = $this->guardDomain(fn (): CashVoucher => $this->service->approve($this->voucherType(), $cashVoucher));

        return response()->json([
            'success' => true,
            'message' => __($this->translationKey().'.messages.approved'),
            'data' => [
                'doc_num' => $record->doc_num,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function cancel(CancelCashVoucherRequest $request, string $cashVoucher): JsonResponse
    {
        $cashVoucher = $this->findInCurrentCompany($request, $cashVoucher);
        $record = $this->guardDomain(fn (): CashVoucher => $this->service->cancel($this->voucherType(), $cashVoucher, (string) $request->validated('cancel_reason')));

        return response()->json([
            'success' => true,
            'message' => __($this->translationKey().'.messages.cancelled'),
            'data' => [
                'doc_num' => $record->doc_num,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function updateDocumentNumberSettings(UpdateCashVoucherDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update($this->documentNumberKey(), $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __($this->translationKey().'.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    abstract protected function voucherType(): string;

    abstract protected function permissionPrefix(): string;

    abstract protected function routePrefix(): string;

    abstract protected function translationKey(): string;

    private function documentNumberKey(): string
    {
        return CashVoucher::documentNumberKeyForType($this->voucherType());
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'doc_num',
            'voucher_date',
            'cashbox',
            'person_name',
            'currency',
            'exchange_rate',
            'amount',
            'distributed_amount',
            'remaining_amount',
            'status',
            'reason',
            'created_by',
            'updated_by',
            'approved_by',
            'approved_at',
        ];
    }

    private function form(string $mode, ?CashVoucher $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['cashbox.account', 'currency', 'lines.account']);

        return view('modules.finance.cash-vouchers.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route($this->routePrefix().'.store') : route($this->routePrefix().'.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'resource' => $this->permissionPrefix(),
            'routePrefix' => $this->routePrefix(),
            'translationKey' => $this->translationKey(),
            'voucherType' => $this->voucherType(),
            'canControlDocumentNumber' => (bool) auth()->user()?->can($this->permissionPrefix().'.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'isLocked' => $record?->isLockedForEditing() ?? false,
            'cashboxOption' => $this->cashboxOption($record),
            'currencyOption' => $this->currencyOption($record),
            'mainCurrencyDocNum' => $this->mainCurrencyDocNum(),
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?CashVoucher $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route($this->routePrefix().'.show', $record->doc_num) : null,
                ],
                ['label' => __($this->translationKey().'.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route($this->routePrefix().'.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute($this->routePrefix().'.index', $extra);
    }

    private function urls(CashVoucher $record): array
    {
        return [
            'show' => route($this->routePrefix().'.show', $record->doc_num),
            'edit' => route($this->routePrefix().'.edit', $record->doc_num),
            'clone' => route($this->routePrefix().'.clone', $record->doc_num),
            'update' => route($this->routePrefix().'.update', $record->doc_num),
            'destroy' => route($this->routePrefix().'.destroy', $record->doc_num),
            'restore' => route($this->routePrefix().'.restore', $record->doc_num),
            'approve' => route($this->routePrefix().'.approve', $record->doc_num),
            'cancel' => route($this->routePrefix().'.cancel', $record->doc_num),
        ];
    }

    private function findInCurrentCompany(Request $request, string $docNum, bool $withTrashed = false): CashVoucher
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($request);
        abort_unless($companyId, 404);

        $query = $withTrashed ? CashVoucher::withTrashed() : CashVoucher::query();

        return $query
            ->where('company_id', $companyId)
            ->where('voucher_type', $this->voucherType())
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    /**
     * @return array{id: string, text: string, account_doc_num: string|null, account_label: string|null}|null
     */
    private function cashboxOption(?CashVoucher $record): ?array
    {
        $cashbox = $record?->cashbox;

        if (! $cashbox instanceof Cashbox) {
            return null;
        }

        return [
            'id' => (string) $cashbox->doc_num,
            'text' => trim(implode(' — ', array_filter([$cashbox->doc_num, $cashbox->name]))),
            'account_doc_num' => $cashbox->account?->doc_num,
            'account_label' => $cashbox->account?->codeNameLabel(),
        ];
    }

    /**
     * @return array{id: string, text: string, is_main: bool}|null
     */
    private function currencyOption(?CashVoucher $record): ?array
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

    private function mainCurrencyDocNum(): ?string
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        if ($companyId === null) {
            return null;
        }

        return Currency::query()
            ->active()
            ->forCompany($companyId)
            ->where('is_main', true)
            ->value('doc_num');
    }

    private function saveResponse(Request $request, CashVoucher $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route($this->routePrefix().'.show', $record->doc_num),
            'save_edit' => route($this->routePrefix().'.edit', $record->doc_num),
            'save_back' => route($this->routePrefix().'.index'),
            'save_clone' => route($this->routePrefix().'.clone', $record->doc_num),
            default => null,
        };
        $response = [
            'submit_action' => $action,
        ];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($operation === 'store' && $action === 'save_new') {
            $response['reset_form'] = true;
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && in_array($action, ['save', 'save_new'], true)) {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
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

    /**
     * @return array<string, string|null>
     */
    private function metadata(?CashVoucher $record): array
    {
        if (! $record instanceof CashVoucher) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
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
