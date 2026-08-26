<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\BranchesDataTable;
use Modules\Core\Http\Requests\BulkDeleteBranchesRequest;
use Modules\Core\Http\Requests\StoreBranchRequest;
use Modules\Core\Http\Requests\UpdateBranchDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateBranchRequest;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BranchDocumentNumberSettingsService;
use Modules\Core\Services\BranchService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanyAccessService;
use Modules\Core\Services\CompanySelect2Service;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\SettingService;
use Throwable;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $records,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request, BranchDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.core.branches.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.branches.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, BranchesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, Branch $branch): View
    {
        $this->abortIfTrashedRecordIsNotViewable($request, $branch);

        $this->logActivity($request, 'branches.view', $this->recordPublicProperties($branch));

        return $this->formView('view', $branch);
    }

    public function edit(Branch $branch): View
    {
        return $this->formView('edit', $branch);
    }

    public function clone(Branch $branch): View
    {
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $branch->doc_num);

        return $this->formView('clone', $branch, $cloneSourceToken);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $result = $this->records->create($request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $record = $result['record'];

        if ($cloneSource instanceof Branch) {
            $this->logActivity($request, 'branches.clone', ActivityLogProperties::crudCloned(
                'branches',
                ActivityLogProperties::record('branches', $cloneSource->name, $cloneSource->doc_num),
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'branches.create', ActivityLogProperties::crudCreated(
                'branches',
                $record->name,
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof Branch ? __('branches.messages.cloned') : __('branches.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->records->update($branch, $request->validated());
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, 'branches.update', ActivityLogProperties::crudUpdated(
            'branches',
            $record->name,
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'branches.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'branches',
                $record->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('branches.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $record->doc_number,
                'doc_num' => $record->doc_num,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        try {
            $this->records->delete($branch);
        } catch (DomainException $exception) {
            $this->logActivity($request, 'branches.delete_blocked', $this->recordPublicProperties($branch), 'blocked');

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'branches.delete', ActivityLogProperties::crudDeleted(
            'branches',
            $branch->name,
            $branch->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('branches.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteBranchesRequest $request): JsonResponse
    {
        try {
            $docNums = $request->validated()['doc_nums'];
            $deleted = $this->records->bulkDelete($docNums);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $this->logActivity($request, 'branches.bulk_delete', ActivityLogProperties::bulkDeleted(
            'branches',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('branches.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $branch): JsonResponse
    {
        $record = $this->restoreRecordByDocNum($branch);

        try {
            $record = $this->records->restore($record);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $this->logActivity($request, 'branches.restore', ActivityLogProperties::crudRestored(
            'branches',
            $record->name,
            $record->doc_num,
            $this->recordRestoreProperties($request, $record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('branches.messages.restored'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateBranchDocumentNumberSettingsRequest $request,
        BranchDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'branches.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'branches',
            [
                'prefix' => [
                    'old' => $result['old']['prefix'],
                    'new' => $result['new']['prefix'],
                ],
                'padding' => [
                    'old' => $result['old']['padding'],
                    'new' => $result['new']['padding'],
                ],
            ],
        ));

        return response()->json([
            'success' => true,
            'message' => __('branches.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?Branch $record = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(BranchDocumentNumberSettingsService::class)->current();
        $this->abortIfBranchCompanyIsNotAccessible($mode, $record);

        $record?->loadMissing([
            'company:id,doc_num,name',
            'halls:id,public_uuid,branch_id,name,position',
            'stores:id,public_uuid,branch_id,name,classification,position',
        ]);

        return view('modules.core.branches.form', [
            'mode' => $mode,
            'branch' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.branches.store') : route('admin.branches.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => (bool) auth()->user()?->can('branches.document_number.control'),
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'selectedCompanyOption' => $record?->company instanceof Company
                ? app(CompanySelect2Service::class)->item($record->company)
                : null,
        ]);
    }

    private function abortIfBranchCompanyIsNotAccessible(string $mode, ?Branch $record): void
    {
        if (! $record instanceof Branch || ! in_array($mode, ['edit', 'clone'], true)) {
            return;
        }

        $user = request()->user();
        $record->loadMissing('company');

        abort_if(
            ! $user instanceof User
            || ! $record->company instanceof Company
            || ! app(CompanyAccessService::class)->canAccessCompany($user, $record->company),
            403,
        );
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(Branch $record): array
    {
        return [
            'show' => route('admin.branches.show', $record->doc_num),
            'clone' => route('admin.branches.clone', $record->doc_num),
            'edit' => route('admin.branches.edit', $record->doc_num),
            'update' => route('admin.branches.update', $record->doc_num),
            'destroy' => route('admin.branches.destroy', $record->doc_num),
            'restore' => route('admin.branches.restore', $record->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, Branch $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.branches.show', $record->doc_num),
            'save_edit' => route('admin.branches.edit', $record->doc_num),
            'save_back' => route('admin.branches.index'),
            'save_new' => $operation === 'store' ? null : route('admin.branches.create'),
            'save_clone' => route('admin.branches.clone', $record->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('branches.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumber('branches', Branch::class);
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'branches.view',
            'save_edit' => 'branches.edit',
            'save_back' => 'branches.view',
            'save_new' => $cloning ? 'branches.clone' : 'branches.create',
            'save_clone' => 'branches.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('branches.messages.action_forbidden'));
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return $action;
    }

    /**
     * @return array{submit_action?: string}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return [];
        }

        return ['submit_action' => $this->submitAction($request, creating: $creating)];
    }

    private function redirectAfterStore(Request $request, Branch $record): string
    {
        if ($request->user()?->can('branches.edit')) {
            return route('admin.branches.edit', $record->doc_num);
        }

        if ($request->user()?->can('branches.view')) {
            return route('admin.branches.show', $record->doc_num);
        }

        return route('admin.branches.index');
    }

    private function cloneSourceFromRequest(StoreBranchRequest $request): ?Branch
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('branches.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('branches.messages.clone_not_allowed'),
            ]);
        }

        $sourceRecord = Branch::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceRecord instanceof Branch) {
            throw ValidationException::withMessages([
                'name' => __('branches.messages.clone_not_allowed'),
            ]);
        }

        return $sourceRecord;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'branches.clone_sources.'.$token;
    }

    private function restoreRecordByDocNum(string $docNum): Branch
    {
        return Branch::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? Branch::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, Branch $record): void
    {
        abort_if(
            $record->trashed() && ! $request->user()?->can('branches.view_trashed'),
            404,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function recordRestoreProperties(Request $request, Branch $record): array
    {
        $user = $request->user();

        return [
            ...$this->recordPublicProperties($record),
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $record->restored_at?->toJSON() ?? now()->toJSON(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?Branch $record): array
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

        $record->loadMissing(['createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($record->restoredBy),
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

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?Branch $record): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.branches.show', $record->doc_num) : null,
                ],
                ['label' => __('branches.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $record?->doc_num,
                    'url' => $record ? route('admin.branches.show', $record->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $record?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.branches.index', $extra);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();
        $map = [
            'doc_number' => ['branches_doc_number_unique_active', 'branches_doc_num_unique_active'],
            'name' => ['branches_company_name_unique_active'],
        ];

        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    throw ValidationException::withMessages([
                        $field => __("branches.validation.{$field}_unique"),
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPublicProperties(Branch $record): array
    {
        return [
            'doc_num' => $record->doc_num,
            'company_doc_num' => $record->company?->doc_num,
            'company_name' => $record->company?->name,
            'name' => $record->name,
            'type' => $record->type,
            'address' => $record->address,
            'camera_url' => $record->camera_url,
            'station_halls' => $record->type === Branch::TypeFactory ? $record->halls()->pluck('name')->all() : [],
            'branch_stores' => $record->stores()->get(['name', 'classification'])->map(fn (BranchStore $store): array => [
                'name' => $store->name,
                'classification' => $store->classification,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
