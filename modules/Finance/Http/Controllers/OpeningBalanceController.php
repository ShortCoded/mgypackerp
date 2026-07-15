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
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\DataTables\OpeningBalancesDataTable;
use Modules\Finance\Http\Requests\OpeningBalances\BulkApproveOpeningBalancesRequest;
use Modules\Finance\Http\Requests\OpeningBalances\BulkDeleteOpeningBalancesRequest;
use Modules\Finance\Http\Requests\OpeningBalances\StoreOpeningBalanceRequest;
use Modules\Finance\Http\Requests\OpeningBalances\UpdateOpeningBalanceDocumentNumberSettingsRequest;
use Modules\Finance\Http\Requests\OpeningBalances\UpdateOpeningBalanceRequest;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Finance\Services\OpeningBalanceApprovalService;
use Modules\Finance\Services\OpeningBalanceService;

class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $service,
        private readonly OpeningBalanceApprovalService $approval,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.finance.opening-balances.index', ['breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.finance.opening-balances.index'), 'documentNumberSettings' => $settings->current('opening_balances')]);
    }

    public function data(Request $request, OpeningBalancesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $openingBalance): View
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance, true);
        abort_if($openingBalance->trashed() && ! $request->user()?->can('opening_balances.view_trashed'), 404);

        return $this->form('view', $openingBalance);
    }

    public function edit(Request $request, string $openingBalance): View
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance);
        abort_if($openingBalance->isLockedForEditing(), 403, $this->editBlockedMessage($openingBalance));

        return $this->form('edit', $openingBalance);
    }

    public function clone(Request $request, string $openingBalance): View
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance);

        return $this->form('clone', $openingBalance, (string) Str::uuid());
    }

    public function store(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): OpeningBalance => $this->service->create($request->validated())['record']);

        $message = $this->submitAction($request, true) === 'save'
            ? __('opening_balances.messages.saved_and_new')
            : __('opening_balances.messages.created');

        return response()->json(['success' => true, 'message' => $message, ...$this->saveResponse($request, $record, 'store'), 'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)]]);
    }

    public function update(UpdateOpeningBalanceRequest $request, string $openingBalance): JsonResponse
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance);
        $result = $this->guardDomain(fn (): array => $this->service->update($openingBalance, $request->validated()));
        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }
        $record = $result['record'];

        return response()->json(['success' => true, 'message' => __('opening_balances.messages.updated'), ...$this->saveResponse($request, $record, 'update'), 'data' => ['old_doc_number' => $result['old_doc_number'], 'old_doc_num' => $result['old_doc_num'], 'doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function destroy(Request $request, string $openingBalance): JsonResponse
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance);
        $this->guardDomain(function () use ($openingBalance): null {
            $this->service->delete($openingBalance);

            return null;
        });

        return response()->json(['success' => true, 'message' => __('opening_balances.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteOpeningBalancesRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => __('opening_balances.messages.bulk_deleted', ['count' => $this->service->bulkDelete($request->validated('doc_nums'))])]);
    }

    public function bulkApprove(BulkApproveOpeningBalancesRequest $request): JsonResponse
    {
        $result = $this->approval->bulkApprove($request->validated('doc_nums'));

        return response()->json([
            'success' => true,
            'message' => trans_choice('opening_balances.messages.bulk_approved', $result['approved'], [
                'count' => $result['approved'],
                'skipped' => $result['skipped'],
            ]),
            'data' => $result,
        ]);
    }

    public function restore(Request $request, string $openingBalance): JsonResponse
    {
        $this->service->restore($this->findInCurrentContext($request, $openingBalance, true));

        return response()->json(['success' => true, 'message' => __('opening_balances.messages.restored')]);
    }

    public function approve(Request $request, string $openingBalance): JsonResponse
    {
        $openingBalance = $this->findInCurrentContext($request, $openingBalance);
        $record = $this->guardDomain(fn (): OpeningBalance => $this->approval->approve($openingBalance));

        return response()->json([
            'success' => true,
            'message' => __('opening_balances.messages.approved'),
            'data' => [
                'doc_num' => $record->doc_num,
                'journal_entry_doc_num' => $record->journalEntry?->doc_num,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function cancel(Request $request, string $openingBalance): JsonResponse
    {
        $this->findInCurrentContext($request, $openingBalance);

        throw ValidationException::withMessages([
            'document' => __('opening_balances.messages.cancel_requires_reversal'),
        ]);
    }

    public function updateDocumentNumberSettings(UpdateOpeningBalanceDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('opening_balances', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('opening_balances.document_number_settings.updated_successfully'), 'data' => $result['new']]);
    }

    private function form(string $mode, ?OpeningBalance $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['financialPeriod', 'currency', 'lines.account', 'journalEntry']);
        $context = app(OperatingContextService::class)->current(request());
        $locked = $record?->isLockedForEditing() ?? false;

        return view('modules.finance.opening-balances.form', ['mode' => $mode, 'record' => $record, 'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.finance.opening-balances.store') : route('admin.finance.opening-balances.update', $record?->doc_num), 'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT', 'canControlDocumentNumber' => (bool) auth()->user()?->can('opening_balances.document_number.control'), 'breadcrumbs' => $this->breadcrumbs($mode, $record), 'cloneSourceToken' => $cloneSourceToken, 'operatingContext' => $context, 'isLocked' => $locked, 'defaultCurrencyOption' => $this->defaultCurrencyOption($record), 'mainCurrencyDocNum' => $this->mainCurrencyDocNum(), 'metadata' => $this->metadata($record)]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?OpeningBalance $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.opening-balances.show', $record->doc_num) : null,
                ],
                ['label' => __('opening_balances.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.finance.opening-balances.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.finance.opening-balances.index', $extra);
    }

    private function urls(OpeningBalance $record): array
    {
        return ['show' => route('admin.finance.opening-balances.show', $record->doc_num), 'edit' => route('admin.finance.opening-balances.edit', $record->doc_num), 'clone' => route('admin.finance.opening-balances.clone', $record->doc_num), 'update' => route('admin.finance.opening-balances.update', $record->doc_num), 'destroy' => route('admin.finance.opening-balances.destroy', $record->doc_num), 'approve' => route('admin.finance.opening-balances.approve', $record->doc_num), 'cancel' => route('admin.finance.opening-balances.cancel', $record->doc_num)];
    }

    private function editBlockedMessage(OpeningBalance $record): string
    {
        if ($record->isApproved()) {
            return __('opening_balances.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('opening_balances.messages.closed_edit_forbidden');
        }

        return __('opening_balances.messages.document_locked');
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function defaultCurrencyOption(?OpeningBalance $record): ?array
    {
        $currency = $record?->currency;

        if (! $currency instanceof Currency) {
            $companyId = app(OperatingContextService::class)->snapshot(request())['company_id'];
            $currency = Currency::query()
                ->active()
                ->when($companyId, fn ($query) => $query->where('company_id', $companyId), fn ($query) => $query->whereRaw('1 = 0'))
                ->where('is_main', true)
                ->orderBy('doc_number')
                ->first();
        }

        if (! $currency instanceof Currency) {
            return null;
        }

        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
        ];
    }

    private function findInCurrentContext(Request $request, string $docNum, bool $withTrashed = false): OpeningBalance
    {
        $context = app(OperatingContextService::class)->snapshot($request);

        abort_unless($context['company_id'] && $context['financial_period_id'], 404);

        $query = $withTrashed ? OpeningBalance::withTrashed() : OpeningBalance::query();

        return $query
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->firstOrFail();
    }

    private function mainCurrencyDocNum(): ?string
    {
        $companyId = app(OperatingContextService::class)->snapshot(request())['company_id'];

        if (! $companyId) {
            return null;
        }

        return Currency::query()
            ->active()
            ->where('company_id', $companyId)
            ->where('is_main', true)
            ->value('doc_num');
    }

    private function saveResponse(Request $request, OpeningBalance $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.finance.opening-balances.show', $record->doc_num), 'save_edit' => route('admin.finance.opening-balances.edit', $record->doc_num), 'save_back' => route('admin.finance.opening-balances.index'), 'save_clone' => route('admin.finance.opening-balances.clone', $record->doc_num), default => $operation === 'store' ? route('admin.finance.opening-balances.create') : null,
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
    private function metadata(?OpeningBalance $record): array
    {
        if (! $record instanceof OpeningBalance) {
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
