<?php

namespace Modules\Production\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Production\DataTables\ProductionIdentifiersDataTable;
use Modules\Production\Http\Requests\BulkDeleteProductionIdentifiersRequest;
use Modules\Production\Http\Requests\StoreProductionIdentifierRequest;
use Modules\Production\Http\Requests\UpdateProductionIdentifierDocumentNumberSettingsRequest;
use Modules\Production\Http\Requests\UpdateProductionIdentifierRequest;
use Modules\Production\Models\ProductionIdentifier;
use Modules\Production\Services\ProductionIdentifierDocumentNumberSettingsService;
use Modules\Production\Services\ProductionIdentifierSelect2Service;
use Modules\Production\Services\ProductionIdentifierService;
use Modules\Production\Services\ProductionIdentifierTreeReport;

class ProductionIdentifierController extends Controller
{
    public function __construct(
        private readonly ProductionIdentifierService $identifiers,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(ProductionIdentifierDocumentNumberSettingsService $settings): View
    {
        return view('modules.production.identifiers.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.production.identifiers.index'),
            'documentNumberSettings' => $settings->current(),
        ]);
    }

    public function data(Request $request, ProductionIdentifiersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function tree(Request $request, ProductionIdentifierTreeReport $report): JsonResponse
    {
        $rows = $report->rows($report->filtersFromRequest($request));

        return response()->json([
            'success' => true,
            'data' => $report->treeNodes($rows),
        ]);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(string $identifier): View
    {
        return $this->form('view', $this->resolveIdentifier($identifier, withTrashed: true));
    }

    public function edit(string $identifier): View
    {
        return $this->form('edit', $this->resolveIdentifier($identifier));
    }

    public function clone(string $identifier): View
    {
        $identifier = $this->resolveIdentifier($identifier);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $identifier->doc_num);

        return $this->form('clone', $identifier, $cloneSourceToken);
    }

    public function store(StoreProductionIdentifierRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $this->validateCloneSourceToken($request);

        try {
            $identifier = $this->identifiers->create($request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('production_identifiers.messages.created'),
            ...$this->saveActionResponse($request, $identifier, 'store'),
            'data' => [
                'doc_num' => $identifier->doc_num,
                'doc_number' => $identifier->doc_number,
                'urls' => $this->identifierUrls($identifier),
            ],
        ]);
    }

    public function update(UpdateProductionIdentifierRequest $request, string $identifier): JsonResponse
    {
        $submitAction = $this->submitAction($request);
        $this->authorizeSubmitAction($request, $submitAction);
        $identifier = $this->resolveIdentifier($identifier);
        $previousDocNum = $identifier->doc_num;
        $previousDocNumber = $identifier->doc_number;

        try {
            $result = $this->identifiers->update($identifier, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'submit_action' => $submitAction,
            ]);
        }

        /** @var ProductionIdentifier $record */
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('production_identifiers.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'previous_doc_num' => $previousDocNum,
                'previous_doc_number' => $previousDocNumber,
                'urls' => $this->identifierUrls($record),
            ],
        ]);
    }

    public function destroy(string $identifier): JsonResponse
    {
        try {
            $this->identifiers->delete($this->resolveIdentifier($identifier));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('production_identifiers.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteProductionIdentifiersRequest $request): JsonResponse
    {
        try {
            $deleted = $this->identifiers->bulkDelete($request->validated('doc_nums'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('production_identifiers.messages.bulk_deleted', ['count' => $deleted])]);
    }

    public function restore(string $identifier): JsonResponse
    {
        try {
            $this->identifiers->restore($this->resolveIdentifier($identifier, withTrashed: true));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('production_identifiers.messages.restored')]);
    }

    public function updateDocumentNumberSettings(
        UpdateProductionIdentifierDocumentNumberSettingsRequest $request,
        ProductionIdentifierDocumentNumberSettingsService $settings
    ): JsonResponse {
        $settings->update($request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json(['success' => true, 'message' => __('production_identifiers.document_number_settings.updated_successfully')]);
    }

    public function select2Identifiers(Request $request, ProductionIdentifierSelect2Service $select2): JsonResponse
    {
        return response()->json($select2->identifiers($request));
    }

    private function form(string $mode, ?ProductionIdentifier $identifier = null, ?string $cloneSourceToken = null): View
    {
        $identifier?->loadMissing('parent');

        return view('modules.production.identifiers.form', [
            'mode' => $mode,
            'identifier' => $identifier,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.production.identifiers.store') : route('admin.production.identifiers.update', $identifier?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('production.identifiers.document_number.control'),
            'metadata' => $this->metadata($identifier),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.production.identifiers.index', [['label' => __("production_identifiers.{$mode}"), 'active' => true]]),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function identifierUrls(ProductionIdentifier $identifier): array
    {
        return [
            'show' => route('admin.production.identifiers.show', $identifier->doc_num),
            'clone' => route('admin.production.identifiers.clone', $identifier->doc_num),
            'edit' => route('admin.production.identifiers.edit', $identifier->doc_num),
            'update' => route('admin.production.identifiers.update', $identifier->doc_num),
            'destroy' => route('admin.production.identifiers.destroy', $identifier->doc_num),
            'index' => route('admin.production.identifiers.index'),
        ];
    }

    private function saveActionResponse(Request $request, ProductionIdentifier $identifier, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.production.identifiers.show', $identifier->doc_num),
            'save_edit' => route('admin.production.identifiers.edit', $identifier->doc_num),
            'save_back' => route('admin.production.identifiers.index'),
            'save_new' => $operation === 'store' ? null : route('admin.production.identifiers.create'),
            'save_clone' => route('admin.production.identifiers.clone', $identifier->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $identifier) : null,
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
            'save_view' => 'production.identifiers.view',
            'save_edit' => 'production.identifiers.edit',
            'save_back' => 'production.identifiers.view',
            'save_new' => $cloning ? 'production.identifiers.clone' : 'production.identifiers.create',
            'save_clone' => 'production.identifiers.clone',
            default => $cloning ? 'production.identifiers.clone' : null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('production_identifiers.messages.action_forbidden'));
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

    private function redirectAfterStore(Request $request, ProductionIdentifier $identifier): string
    {
        if ($request->user()?->can('production.identifiers.edit')) {
            return route('admin.production.identifiers.edit', $identifier->doc_num);
        }

        if ($request->user()?->can('production.identifiers.view')) {
            return route('admin.production.identifiers.show', $identifier->doc_num);
        }

        return route('admin.production.identifiers.index');
    }

    private function validateCloneSourceToken(StoreProductionIdentifierRequest $request): void
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return;
        }

        abort_unless((bool) $request->user()?->can('production.identifiers.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '' || ! ProductionIdentifier::query()->forCompany($this->companies->requireCompanyId($request))->where('doc_num', $sourceDocNum)->exists()) {
            throw ValidationException::withMessages([
                'name' => __('production_identifiers.messages.clone_not_allowed'),
            ]);
        }
    }

    private function resolveIdentifier(string $docNum, bool $withTrashed = false): ProductionIdentifier
    {
        $query = $withTrashed ? ProductionIdentifier::withTrashed() : ProductionIdentifier::query();

        return $query
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'production_identifiers.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?ProductionIdentifier $identifier): array
    {
        if (! $identifier instanceof ProductionIdentifier) {
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
            ->whereIn('id', array_filter([$identifier->created_by, $identifier->updated_by, $identifier->deleted_by, $identifier->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($identifier->created_by)),
            'created_at' => $settings->formatDateTime($identifier->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($identifier->updated_by)),
            'updated_at' => $settings->formatDateTime($identifier->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($identifier->deleted_by)),
            'deleted_at' => $settings->formatDateTime($identifier->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($identifier->restored_by)),
            'restored_at' => $settings->formatDateTime($identifier->restored_at, ''),
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
