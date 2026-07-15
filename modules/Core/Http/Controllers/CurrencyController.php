<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\CurrenciesDataTable;
use Modules\Core\Http\Requests\Currencies\BulkDeleteCurrenciesRequest;
use Modules\Core\Http\Requests\Currencies\StoreCurrencyRequest;
use Modules\Core\Http\Requests\Currencies\UpdateCurrencyDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\Currencies\UpdateCurrencyRequest;
use Modules\Core\Models\Currency;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CurrencyDocumentNumberSettingsService;
use Modules\Core\Services\CurrencySelect2Service;
use Modules\Core\Services\CurrencyService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;

class CurrencyController extends Controller
{
    public function __construct(private readonly CurrencyService $currencies, private readonly BreadcrumbService $breadcrumbs) {}

    public function index(CurrencyDocumentNumberSettingsService $settings): View
    {
        return view('modules.core.currencies.index', ['breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.currencies.index'), 'documentNumberSettings' => $settings->current()]);
    }

    public function data(Request $request, CurrenciesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, Currency $currency): View
    {
        abort_if($currency->trashed() && ! $request->user()?->can('currencies.view_trashed'), 404);

        return $this->form('view', $currency);
    }

    public function edit(Currency $currency): View
    {
        return $this->form('edit', $currency);
    }

    public function clone(Currency $currency): View
    {
        $token = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($token), $currency->doc_num);

        return $this->form('clone', $currency, $token);
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $this->validateCloneSourceToken($request);
        $result = $this->currencies->create($request->validated());
        $record = $result['record'];

        return response()->json(['success' => true, 'message' => __('currencies.messages.created'), ...$this->saveResponse($request, $record, 'store'), 'data' => ['doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $result = $this->currencies->update($currency, $request->validated());
        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes'), 'submit_action' => $this->submitAction($request)]);
        }
        $record = $result['record'];

        return response()->json(['success' => true, 'message' => __('currencies.messages.updated'), ...$this->saveResponse($request, $record, 'update'), 'data' => ['old_doc_number' => $result['old_doc_number'], 'old_doc_num' => $result['old_doc_num'], 'doc_num' => $record->doc_num, 'doc_number' => $record->doc_number, 'urls' => $this->urls($record)]]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        try {
            $this->currencies->delete($currency);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('currencies.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteCurrenciesRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => __('currencies.messages.bulk_deleted', ['count' => $this->currencies->bulkDelete($request->validated('doc_nums'))])]);
    }

    public function restore(string $currency): JsonResponse
    {
        try {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $this->currencies->restore(Currency::withTrashed()->forCompany($companyId)->where('doc_num', $currency)->firstOrFail());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('currencies.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateCurrencyDocumentNumberSettingsRequest $request, CurrencyDocumentNumberSettingsService $settings): JsonResponse
    {
        $settings->update($request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('currencies.document_number_settings.updated_successfully'),
            'data' => $settings->current(),
        ]);
    }

    public function select2(Request $request, CurrencySelect2Service $select2): JsonResponse
    {
        return response()->json($select2->paginated($request));
    }

    private function form(string $mode, ?Currency $record = null, ?string $cloneSourceToken = null): View
    {
        return view('modules.core.currencies.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.currencies.store') : route('admin.currencies.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('currencies.document_number.control'),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?Currency $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.currencies.show', $record->doc_num) : null,
                ],
                ['label' => __('currencies.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.currencies.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.currencies.index', $extra);
    }

    private function urls(Currency $record): array
    {
        return ['show' => route('admin.currencies.show', $record->doc_num), 'edit' => route('admin.currencies.edit', $record->doc_num), 'clone' => route('admin.currencies.clone', $record->doc_num), 'update' => route('admin.currencies.update', $record->doc_num), 'destroy' => route('admin.currencies.destroy', $record->doc_num)];
    }

    private function saveResponse(Request $request, Currency $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.currencies.show', $record->doc_num),
            'save_edit' => route('admin.currencies.edit', $record->doc_num),
            'save_back' => route('admin.currencies.index'),
            'save_clone' => route('admin.currencies.clone', $record->doc_num),
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

    private function validateCloneSourceToken(StoreCurrencyRequest $request): void
    {
        $token = $request->string('clone_source_token')->trim()->toString();
        if ($token === '') {
            return;
        }
        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($token), '');
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        if ($sourceDocNum === '' || ! Currency::query()->forCompany($companyId)->where('doc_num', $sourceDocNum)->exists()) {
            throw ValidationException::withMessages(['code' => __('currencies.messages.clone_not_allowed')]);
        }
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'currencies.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?Currency $record): array
    {
        if (! $record) {
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
        if (! $user) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }
}
