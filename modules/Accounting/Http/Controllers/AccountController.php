<?php

namespace Modules\Accounting\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\DataTables\AccountsDataTable;
use Modules\Accounting\Exports\AccountsExport;
use Modules\Accounting\Http\Requests\BulkDeleteAccountsRequest;
use Modules\Accounting\Http\Requests\StoreAccountRequest;
use Modules\Accounting\Http\Requests\UpdateAccountDocumentNumberSettingsRequest;
use Modules\Accounting\Http\Requests\UpdateAccountRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountDocumentNumberSettingsService;
use Modules\Accounting\Services\AccountSelect2Service;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\AccountTreeReport;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Core\Services\SettingService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(AccountDocumentNumberSettingsService $settings): View
    {
        return view('modules.accounting.accounts.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.accounts.index'),
            'documentNumberSettings' => $settings->current(),
        ]);
    }

    public function data(Request $request, AccountsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function tree(Request $request, AccountTreeReport $report): JsonResponse
    {
        $accounts = $report->rows($report->filtersFromRequest($request));

        return response()->json([
            'success' => true,
            'data' => $report->treeNodes($accounts),
        ]);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(string $account): View
    {
        return $this->form('view', $this->resolveAccount($account, withTrashed: true));
    }

    public function edit(string $account): View
    {
        return $this->form('edit', $this->resolveAccount($account));
    }

    public function clone(string $account): View
    {
        $account = $this->resolveAccount($account);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $account->doc_num);

        return $this->form('clone', $account, $cloneSourceToken);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $this->validateCloneSourceToken($request);

        $account = $this->accounts->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounts.messages.created'),
            ...$this->saveActionResponse($request, $account, 'store'),
            'data' => [
                'doc_num' => $account->doc_num,
                'doc_number' => $account->doc_number,
                'urls' => $this->accountUrls($account),
            ],
        ]);
    }

    public function update(UpdateAccountRequest $request, string $account): JsonResponse
    {
        $submitAction = $this->submitAction($request);
        $this->authorizeSubmitAction($request, $submitAction);
        $account = $this->resolveAccount($account);
        $previousDocNum = $account->doc_num;
        $previousDocNumber = $account->doc_number;
        $result = $this->accounts->update($account, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'submit_action' => $submitAction,
            ]);
        }

        /** @var Account $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('accounts.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'previous_doc_num' => $previousDocNum,
                'previous_doc_number' => $previousDocNumber,
                'urls' => $this->accountUrls($record),
            ],
        ]);
    }

    public function destroy(string $account): JsonResponse
    {
        try {
            $account = $this->resolveAccount($account);
            $this->accounts->delete($account);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('accounts.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteAccountsRequest $request): JsonResponse
    {
        try {
            $deleted = $this->accounts->bulkDelete($request->validated('doc_nums'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('accounts.messages.bulk_deleted', ['count' => $deleted])]);
    }

    public function restore(string $account): JsonResponse
    {
        $record = $this->resolveAccount($account, withTrashed: true);

        try {
            $this->accounts->restore($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('accounts.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateAccountDocumentNumberSettingsRequest $request, AccountDocumentNumberSettingsService $settings): JsonResponse
    {
        $settings->update($request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('accounts.document_number_settings.updated_successfully')]);
    }

    public function exportExcel(Request $request, AccountTreeReport $report): BinaryFileResponse
    {
        return Excel::download(new AccountsExport($report, $report->filtersFromRequest($request)), 'chart-of-accounts.xlsx');
    }

    public function exportCsv(Request $request, AccountTreeReport $report): BinaryFileResponse
    {
        return Excel::download(new AccountsExport($report, $report->filtersFromRequest($request)), 'chart-of-accounts.csv', ExcelFormat::CSV);
    }

    public function exportPdf(Request $request, ReportPdfService $pdf, AccountTreeReport $report): Response
    {
        $filters = $report->filtersFromRequest($request);
        $rows = $report->rows($filters);

        return $pdf->stream('reports.accounts', [
            'title' => __('accounts.title'),
            'headings' => $report->pdfHeadings(),
            'rows' => $report->pdfRows($rows),
            'filters' => $report->filterSummary($filters),
        ], 'chart-of-accounts.pdf');
    }

    public function select2Accounts(Request $request, AccountSelect2Service $select2): JsonResponse
    {
        return response()->json($select2->accounts($request));
    }

    public function select2Classifications(Request $request, AccountSelect2Service $select2): JsonResponse
    {
        return response()->json($select2->classifications($request));
    }

    private function form(string $mode, ?Account $account = null, ?string $cloneSourceToken = null): View
    {
        $account?->loadMissing(['parent', 'classification']);

        return view('modules.accounting.accounts.form', [
            'mode' => $mode,
            'account' => $account,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.accounting.accounts.store') : route('admin.accounting.accounts.update', $account?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('accounts.document_number.control'),
            'canControlAccountCode' => (bool) auth()->user()?->can('accounts.account_code.control'),
            'metadata' => $this->metadata($account),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.accounting.accounts.index', [['label' => __("accounts.{$mode}"), 'active' => true]]),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function accountUrls(Account $account): array
    {
        return [
            'show' => route('admin.accounting.accounts.show', $account->doc_num),
            'clone' => route('admin.accounting.accounts.clone', $account->doc_num),
            'edit' => route('admin.accounting.accounts.edit', $account->doc_num),
            'update' => route('admin.accounting.accounts.update', $account->doc_num),
            'destroy' => route('admin.accounting.accounts.destroy', $account->doc_num),
            'index' => route('admin.accounting.accounts.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, Account $account, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.accounting.accounts.show', $account->doc_num),
            'save_edit' => route('admin.accounting.accounts.edit', $account->doc_num),
            'save_back' => route('admin.accounting.accounts.index'),
            'save_new' => $operation === 'store' ? null : route('admin.accounting.accounts.create'),
            'save_clone' => route('admin.accounting.accounts.clone', $account->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $account) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'accounts.view',
            'save_edit' => 'accounts.edit',
            'save_back' => 'accounts.view',
            'save_new' => $cloning ? 'accounts.clone' : 'accounts.create',
            'save_clone' => 'accounts.clone',
            default => $cloning ? 'accounts.clone' : null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('accounts.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        $allowedActions = ['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'];

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        if (! in_array($action, $allowedActions, true)) {
            return $creating ? 'save_new' : 'save';
        }

        return $action;
    }

    private function redirectAfterStore(Request $request, Account $account): string
    {
        if ($request->user()?->can('accounts.edit')) {
            return route('admin.accounting.accounts.edit', $account->doc_num);
        }

        if ($request->user()?->can('accounts.view')) {
            return route('admin.accounting.accounts.show', $account->doc_num);
        }

        return route('admin.accounting.accounts.index');
    }

    private function validateCloneSourceToken(StoreAccountRequest $request): void
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return;
        }

        abort_unless((bool) $request->user()?->can('accounts.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '' || ! Account::query()->forCompany($this->companies->requireCompanyId($request))->where('doc_num', $sourceDocNum)->exists()) {
            throw ValidationException::withMessages([
                'account_code' => __('accounts.messages.clone_not_allowed'),
            ]);
        }
    }

    private function resolveAccount(string $docNum, bool $withTrashed = false): Account
    {
        $query = $withTrashed ? Account::withTrashed() : Account::query();

        return $query
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'accounts.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?Account $account): array
    {
        if (! $account instanceof Account) {
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
            ->whereIn('id', array_filter([$account->created_by, $account->updated_by, $account->deleted_by, $account->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($account->created_by)),
            'created_at' => $settings->formatDateTime($account->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($account->updated_by)),
            'updated_at' => $settings->formatDateTime($account->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($account->deleted_by)),
            'deleted_at' => $settings->formatDateTime($account->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($account->restored_by)),
            'restored_at' => $settings->formatDateTime($account->restored_at, ''),
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
