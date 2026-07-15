<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\DataTables\RolesDataTable;
use Modules\Auth\Exceptions\RoleDeleteBlockedException;
use Modules\Auth\Exceptions\RoleRestoreBlockedException;
use Modules\Auth\Http\Requests\BulkDeleteRolesRequest;
use Modules\Auth\Http\Requests\StoreRoleRequest;
use Modules\Auth\Http\Requests\UpdateRoleDocumentNumberSettingsRequest;
use Modules\Auth\Http\Requests\UpdateRoleRequest;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Auth\Services\RoleDocumentNumberSettingsService;
use Modules\Auth\Services\RoleService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BranchSelect2Service;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\CompanySelect2Service;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodSelect2Service;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\SettingService;
use Spatie\Permission\Models\Permission;
use Throwable;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request, RoleDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.auth.roles.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.roles.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, RolesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, Role $role): View
    {
        $this->abortIfTrashedRoleIsNotViewable($request, $role);

        $this->logActivity($request, 'roles.view', $this->rolePublicProperties($role));

        return $this->formView('view', $role);
    }

    public function edit(Role $role): View
    {
        return $this->formView('edit', $role);
    }

    public function clone(Role $role): View|RedirectResponse
    {
        try {
            $this->roles->ensureRoleCanBeCloned($role);
        } catch (DomainException $exception) {
            return redirect()
                ->route('admin.roles.index')
                ->with('error', $exception->getMessage());
        }

        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $role->doc_num);

        return $this->formView('clone', $role, $cloneSourceToken);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $role = $this->roles->create($request->validated(), $cloneSource);
        } catch (QueryException $exception) {
            $this->throwRoleValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        if ($cloneSource instanceof Role) {
            $this->logClone($request, $cloneSource, $role);
        } else {
            $this->logActivity($request, 'roles.create', ActivityLogProperties::crudCreated(
                'roles',
                $role->name,
                $role->doc_num,
                [
                    'permissions_count' => $this->permissionsCount($role),
                    ...$this->submitActionProperties($request, creating: true),
                ],
            ));
        }

        $selectedCompanyDocNums = $this->selectedCompanyDocNums($role);
        $selectedBranchDocNums = $this->selectedBranchDocNums($role);
        $selectedPeriodDocNums = $this->selectedFinancialPeriodDocNums($role);

        if ($selectedCompanyDocNums !== [] || $selectedBranchDocNums !== [] || $selectedPeriodDocNums !== []) {
            $this->logOperatingScopeSync(
                $request,
                $role,
                $selectedCompanyDocNums,
                [],
                $selectedBranchDocNums,
                [],
                $selectedPeriodDocNums,
                [],
                $selectedCompanyDocNums,
                $selectedBranchDocNums,
                $selectedPeriodDocNums,
            );
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof Role ? __('roles.messages.cloned_successfully') : __('auth.roles.messages.created'),
            ...$this->saveActionResponse($request, $role, 'store'),
            'data' => [
                'doc_num' => $role->doc_num,
                'doc_number' => $role->doc_number,
                'urls' => $this->roleUrls($role),
            ],
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->roles->update($role, $request->validated());
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (QueryException $exception) {
            $this->throwRoleValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $role = $result['role'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        if ($result['changed_fields'] !== []) {
            $this->logActivity($request, 'roles.update', ActivityLogProperties::crudUpdated(
                'roles',
                $role->name,
                $role->doc_num,
                $result['changes'],
                $this->submitActionProperties($request),
            ));
        }

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'roles.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'roles',
                $role->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $role->doc_number,
                $role->doc_num,
            ));
        }

        if ($result['permissions_added'] !== [] || $result['permissions_removed'] !== []) {
            $this->logActivity($request, 'roles.permissions.sync', [
                ...$this->rolePublicProperties($role),
                'added_permissions' => $result['permissions_added'],
                'removed_permissions' => $result['permissions_removed'],
                'permissions_count' => $this->permissionsCount($role),
            ]);
        }

        if ($result['operating_scope_changed']) {
            $this->logOperatingScopeSync(
                $request,
                $role,
                $result['company_access_added'],
                $result['company_access_removed'],
                $result['branch_access_added'],
                $result['branch_access_removed'],
                $result['period_access_added'],
                $result['period_access_removed'],
                $result['selected_company_doc_nums'],
                $result['selected_branch_doc_nums'],
                $result['selected_period_doc_nums'],
            );
        }

        return response()->json([
            'success' => true,
            'message' => __('auth.roles.messages.updated'),
            ...$this->saveActionResponse($request, $role, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $role->doc_number,
                'doc_num' => $role->doc_num,
                'urls' => $this->roleUrls($role),
            ],
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        try {
            $this->roles->delete($role);
        } catch (DomainException $exception) {
            if ($exception instanceof RoleDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'roles.delete', ActivityLogProperties::crudDeleted(
            'roles',
            $role->name,
            $role->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('auth.roles.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteRolesRequest $request): JsonResponse
    {
        try {
            $docNums = $request->validated()['doc_nums'];
            $deleted = $this->roles->bulkDelete($docNums);
        } catch (DomainException $exception) {
            if ($exception instanceof RoleDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'roles.bulk_delete', ActivityLogProperties::bulkDeleted(
            'roles',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('auth.roles.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $role): JsonResponse
    {
        $role = $this->restoreRoleByDocNum($role);

        try {
            $role = $this->roles->restore($role);
        } catch (RoleRestoreBlockedException $exception) {
            if ($exception->isConflict()) {
                $this->logRestoreBlocked($request, $role, $exception);
            }

            return $this->restoreError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $restoredAt = $role->restored_at?->toJSON() ?? now()->toJSON();

        $this->logActivity($request, 'roles.restore', ActivityLogProperties::crudRestored(
            'roles',
            $role->name,
            $role->doc_num,
            $this->roleRestoreProperties($request, $role, $restoredAt),
        ));

        return response()->json([
            'success' => true,
            'message' => __('roles.messages.restored_successfully'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateRoleDocumentNumberSettingsRequest $request,
        RoleDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'roles.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'roles',
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
            'message' => __('roles.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?Role $role = null, ?string $cloneSourceToken = null): View
    {
        $role?->load([
            'permissions:id,name',
            'accessibleCompanies:id,doc_num,name',
            'accessibleBranches:id,doc_num,name,company_id',
            'accessibleBranches.company:id,name,doc_num',
            'accessibleFinancialPeriods:id,doc_num,name,company_id',
            'accessibleFinancialPeriods.company:id,name,doc_num',
        ]);
        $documentNumberSettings = app(RoleDocumentNumberSettingsService::class)->current();
        $canControlDocumentNumber = (bool) auth()->user()?->can('roles.document_number.control');
        $canManageOperatingScope = (bool) auth()->user()?->can('roles.operating_scope.manage')
            || (bool) auth()->user()?->can('roles.company_access.manage');
        $permissionRegistry = app(PermissionRegistryService::class);
        $companySelect2 = app(CompanySelect2Service::class);
        $branchSelect2 = app(BranchSelect2Service::class);
        $periodSelect2 = app(FinancialPeriodSelect2Service::class);
        $assignedCompanies = $role?->accessibleCompanies ?? collect();
        $assignedBranches = $role?->accessibleBranches ?? collect();
        $assignedPeriods = $role?->accessibleFinancialPeriods ?? collect();
        $editor = auth()->user();
        $isProtectedRole = $role instanceof Role && $this->roles->isProtectedRole($role);
        $isProtectedReadonly = $mode === 'edit' && $isProtectedRole;

        if ($isProtectedRole) {
            $assignedCompanies = collect();
            $assignedBranches = collect();
            $assignedPeriods = collect();
        } elseif ($canManageOperatingScope && ! in_array($mode, ['view'], true) && $editor instanceof User) {
            $scopeAccess = app(OperatingScopeAccessService::class);
            $assignedCompanies = $this->assignableCompaniesForForm($assignedCompanies, $editor, $scopeAccess);
            $selectedCompanyDocNums = $this->scopeDocNums($assignedCompanies);
            $assignedBranches = $this->assignableBranchesForForm($assignedBranches, $selectedCompanyDocNums, $editor, $scopeAccess);
            $assignedPeriods = $this->assignableFinancialPeriodsForForm($assignedPeriods, $selectedCompanyDocNums, $editor, $scopeAccess);
        }

        $permissionNames = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return view('modules.auth.roles.form', [
            'mode' => $mode,
            'role' => $role,
            'permissionGroups' => $permissionRegistry->groupedForForm($permissionNames),
            'assignedPermissions' => collect($role?->permissions->pluck('name')->all() ?? [])
                ->map(fn (string $permission): string => $permissionRegistry->canonicalPermission($permission))
                ->unique()
                ->values()
                ->all(),
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.roles.store') : route('admin.roles.update', $role?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => $canControlDocumentNumber,
            'canManageCompanyAccess' => $canManageOperatingScope,
            'canManageOperatingScope' => $canManageOperatingScope,
            'companyAccessRestricted' => ! $isProtectedRole && (bool) ($role?->company_access_restricted ?? false),
            'branchAccessRestricted' => ! $isProtectedRole && (bool) ($role?->branch_access_restricted ?? false),
            'financialPeriodAccessRestricted' => ! $isProtectedRole && (bool) ($role?->financial_period_access_restricted ?? false),
            'assignedCompanyDocNums' => $this->scopeDocNums($assignedCompanies),
            'assignedCompanyOptions' => $assignedCompanies
                ->sortBy('name')
                ->values()
                ->map(fn (Company $company): array => $companySelect2->item($company))
                ->all(),
            'assignedBranchDocNums' => $this->scopeDocNums($assignedBranches),
            'assignedBranchOptions' => $assignedBranches
                ->sortBy('name')
                ->values()
                ->map(fn (Branch $branch): array => $branchSelect2->item($branch))
                ->all(),
            'assignedFinancialPeriodDocNums' => $this->scopeDocNums($assignedPeriods),
            'assignedFinancialPeriodOptions' => $assignedPeriods
                ->sortBy('name')
                ->values()
                ->map(fn (FinancialPeriod $period): array => $periodSelect2->item($period))
                ->all(),
            'metadata' => $this->roleMetadata($role),
            'breadcrumbs' => $this->roleBreadcrumbs($mode, $role),
            'cloneSourceToken' => $cloneSourceToken,
            'isProtectedRole' => $isProtectedRole,
            'isProtectedReadonly' => $isProtectedReadonly,
        ]);
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, Company>
     */
    private function assignableCompaniesForForm(Collection $companies, User $editor, OperatingScopeAccessService $scopeAccess): Collection
    {
        $companyDocNums = $this->scopeDocNums($companies);

        if ($companyDocNums === []) {
            return collect();
        }

        $assignableDocNums = $scopeAccess->allowedCompanyQuery($editor)
            ->whereIn('companies.doc_num', $companyDocNums)
            ->pluck('companies.doc_num')
            ->all();

        return $companies
            ->filter(fn (Company $company): bool => in_array((string) $company->doc_num, $assignableDocNums, true))
            ->values();
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @param  list<string>  $companyDocNums
     * @return Collection<int, Branch>
     */
    private function assignableBranchesForForm(Collection $branches, array $companyDocNums, User $editor, OperatingScopeAccessService $scopeAccess): Collection
    {
        $branchDocNums = $this->scopeDocNums($branches);

        if ($companyDocNums === [] || $branchDocNums === []) {
            return collect();
        }

        $assignableDocNums = $scopeAccess->allowedBranchQuery($editor, $companyDocNums)
            ->whereIn('branches.doc_num', $branchDocNums)
            ->pluck('branches.doc_num')
            ->all();

        return $branches
            ->filter(fn (Branch $branch): bool => in_array((string) $branch->doc_num, $assignableDocNums, true)
                && in_array((string) $branch->company?->doc_num, $companyDocNums, true))
            ->values();
    }

    /**
     * @param  Collection<int, FinancialPeriod>  $periods
     * @param  list<string>  $companyDocNums
     * @return Collection<int, FinancialPeriod>
     */
    private function assignableFinancialPeriodsForForm(Collection $periods, array $companyDocNums, User $editor, OperatingScopeAccessService $scopeAccess): Collection
    {
        $periodDocNums = $this->scopeDocNums($periods);

        if ($companyDocNums === [] || $periodDocNums === []) {
            return collect();
        }

        $assignableDocNums = $scopeAccess->allowedFinancialPeriodQuery($editor, $companyDocNums)
            ->whereIn('financial_periods.doc_num', $periodDocNums)
            ->pluck('financial_periods.doc_num')
            ->all();

        return $periods
            ->filter(fn (FinancialPeriod $period): bool => in_array((string) $period->doc_num, $assignableDocNums, true)
                && in_array((string) $period->company?->doc_num, $companyDocNums, true))
            ->values();
    }

    /**
     * @param  Collection<int, Company|Branch|FinancialPeriod>  $records
     * @return list<string>
     */
    private function scopeDocNums(Collection $records): array
    {
        return $records
            ->pluck('doc_num')
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function logClone(Request $request, Role $sourceRole, Role $newRole): void
    {
        $this->logActivity($request, 'roles.clone', ActivityLogProperties::crudCloned(
            'roles',
            ActivityLogProperties::record('roles', $sourceRole->name, $sourceRole->doc_num),
            $newRole->name,
            $newRole->doc_num,
            [
                'permissions_count' => $this->permissionsCount($newRole),
                ...$this->submitActionProperties($request, creating: true),
            ],
        ));
    }

    /**
     * @return array<string, string>
     */
    private function roleUrls(Role $role): array
    {
        return [
            'show' => route('admin.roles.show', $role->doc_num),
            'clone' => route('admin.roles.clone', $role->doc_num),
            'edit' => route('admin.roles.edit', $role->doc_num),
            'update' => route('admin.roles.update', $role->doc_num),
            'destroy' => route('admin.roles.destroy', $role->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, Role $role, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');

        $response = [
            'submit_action' => $action,
        ];

        $redirect = match ($action) {
            'save_view' => route('admin.roles.show', $role->doc_num),
            'save_edit' => route('admin.roles.edit', $role->doc_num),
            'save_back' => route('admin.roles.index'),
            'save_new' => $operation === 'store' ? null : route('admin.roles.create'),
            'save_clone' => route('admin.roles.clone', $role->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $role) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('roles.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumber('roles', Role::class);
            }
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'roles.view',
            'save_edit' => 'roles.edit',
            'save_back' => 'roles.view',
            'save_new' => $cloning ? 'roles.clone' : 'roles.create',
            'save_clone' => 'roles.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('roles.messages.action_forbidden'));
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

    private function redirectAfterStore(Request $request, Role $role): string
    {
        if ($request->user()?->can('roles.edit')) {
            return route('admin.roles.edit', $role->doc_num);
        }

        if ($request->user()?->can('roles.view')) {
            return route('admin.roles.show', $role->doc_num);
        }

        return route('admin.roles.index');
    }

    private function cloneSourceFromRequest(StoreRoleRequest $request): ?Role
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('roles.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('roles.messages.clone_not_allowed'),
            ]);
        }

        $sourceRole = Role::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceRole instanceof Role) {
            throw ValidationException::withMessages([
                'name' => __('roles.messages.clone_not_allowed'),
            ]);
        }

        try {
            $this->roles->ensureRoleCanBeCloned($sourceRole);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }

        return $sourceRole;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'roles.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function roleMetadata(?Role $role): array
    {
        if (! $role) {
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
            ->whereIn('id', array_filter([$role->created_by, $role->updated_by, $role->deleted_by, $role->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($role->created_by)),
            'created_at' => $settings->formatDateTime($role->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($role->updated_by)),
            'updated_at' => $settings->formatDateTime($role->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($role->deleted_by)),
            'deleted_at' => $settings->formatDateTime($role->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($role->restored_by)),
            'restored_at' => $settings->formatDateTime($role->restored_at, ''),
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
    private function roleBreadcrumbs(string $mode, ?Role $role): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $role?->doc_num,
                    'url' => $role ? route('admin.roles.show', $role->doc_num) : null,
                ],
                ['label' => __('roles.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $role?->doc_num,
                    'url' => $role ? route('admin.roles.show', $role->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $role?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.roles.index', $extra);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof RoleDeleteBlockedException && $exception->blockedRecords !== []) {
            $payload['data'] = [
                'blocked_records' => $exception->blockedRecords,
            ];
        }

        return response()->json($payload, 422);
    }

    private function restoreError(RoleRestoreBlockedException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'restore' => [$exception->getMessage()],
            ],
            'data' => [
                'conflict_type' => $exception->conflictType,
                'conflict_fields' => $exception->conflictFields,
            ],
        ], 422);
    }

    private function throwRoleValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        $isNameGuardConflict = str_contains($message, 'roles_name_guard_unique_active')
            || str_contains($message, 'roles_name_guard_name_unique')
            || (str_contains($message, 'roles.name') && str_contains($message, 'roles.guard_name'));

        if (! $isNameGuardConflict) {
            $isDocumentNumberConflict = str_contains($message, 'roles_doc_number_unique_active')
                || str_contains($message, 'roles_doc_num_unique_active')
                || str_contains($message, 'roles_doc_number_unique')
                || str_contains($message, 'roles_doc_num_unique')
                || str_contains($message, 'roles.doc_number')
                || str_contains($message, 'roles.doc_num');

            if (! $isDocumentNumberConflict) {
                return;
            }

            throw ValidationException::withMessages([
                'doc_number' => __('roles.validation.doc_number_unique'),
            ]);
        }

        throw ValidationException::withMessages([
            'name' => __('roles.validation.name_unique'),
        ]);
    }

    private function logDeleteBlocked(Request $request, RoleDeleteBlockedException $exception): void
    {
        foreach ($exception->blockedRecords as $record) {
            $this->logActivity($request, 'roles.delete_blocked', [
                'doc_num' => $record['doc_num'] ?? null,
                'role_name' => $record['role_name'] ?? null,
                'reason' => $record['reason'] ?? null,
                'related_users_count' => $record['related_users_count'] ?? null,
            ], 'blocked');
        }
    }

    private function logRestoreBlocked(Request $request, Role $role, RoleRestoreBlockedException $exception): void
    {
        $this->logActivity($request, 'roles.restore_blocked', [
            ...$this->rolePublicProperties($role),
            'conflict_type' => $exception->conflictType,
            'conflict_fields' => $exception->conflictFields,
        ], 'blocked');
    }

    /**
     * @return array{doc_num: string|null, role_name: string}
     */
    private function rolePublicProperties(Role $role): array
    {
        return [
            'doc_num' => $role->doc_num,
            'role_name' => $role->name,
        ];
    }

    private function restoreRoleByDocNum(string $docNum): Role
    {
        return Role::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? Role::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedRoleIsNotViewable(Request $request, Role $role): void
    {
        abort_if(
            $role->trashed() && ! $request->user()?->can('roles.view_trashed'),
            404,
            __('roles.trash.view_forbidden'),
        );
    }

    /**
     * @return array{doc_num: string|null, role_name: string, guard_name: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function roleRestoreProperties(Request $request, Role $role, string $restoredAt): array
    {
        $user = $request->user();

        return [
            ...$this->rolePublicProperties($role),
            'guard_name' => $role->guard_name,
            'restored_by_user_doc_num' => $user instanceof User ? $user->doc_num : null,
            'restored_at' => $restoredAt,
        ];
    }

    private function permissionsCount(Role $role): int
    {
        return $role->permissions()->count();
    }

    /**
     * @return list<string>
     */
    private function selectedCompanyDocNums(Role $role): array
    {
        return $role->accessibleCompanies()
            ->pluck('companies.doc_num')
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function selectedBranchDocNums(Role $role): array
    {
        return $role->accessibleBranches()
            ->pluck('branches.doc_num')
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function selectedFinancialPeriodDocNums(Role $role): array
    {
        return $role->accessibleFinancialPeriods()
            ->pluck('financial_periods.doc_num')
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $addedCompanyDocNums
     * @param  list<string>  $removedCompanyDocNums
     * @param  list<string>  $addedBranchDocNums
     * @param  list<string>  $removedBranchDocNums
     * @param  list<string>  $addedPeriodDocNums
     * @param  list<string>  $removedPeriodDocNums
     * @param  list<string>  $selectedCompanyDocNums
     * @param  list<string>  $selectedBranchDocNums
     * @param  list<string>  $selectedPeriodDocNums
     */
    private function logOperatingScopeSync(
        Request $request,
        Role $role,
        array $addedCompanyDocNums,
        array $removedCompanyDocNums,
        array $addedBranchDocNums,
        array $removedBranchDocNums,
        array $addedPeriodDocNums,
        array $removedPeriodDocNums,
        array $selectedCompanyDocNums,
        array $selectedBranchDocNums,
        array $selectedPeriodDocNums,
    ): void {
        $this->logActivity($request, 'roles.operating_scope.sync', [
            'role_doc_num' => $role->doc_num,
            'role_name' => $role->name,
            'added_company_doc_nums' => $addedCompanyDocNums,
            'removed_company_doc_nums' => $removedCompanyDocNums,
            'added_branch_doc_nums' => $addedBranchDocNums,
            'removed_branch_doc_nums' => $removedBranchDocNums,
            'added_period_doc_nums' => $addedPeriodDocNums,
            'removed_period_doc_nums' => $removedPeriodDocNums,
            'selected_company_doc_nums' => $selectedCompanyDocNums,
            'selected_branch_doc_nums' => $selectedBranchDocNums,
            'selected_period_doc_nums' => $selectedPeriodDocNums,
            'selected_company_count' => count($selectedCompanyDocNums),
            'selected_branch_count' => count($selectedBranchDocNums),
            'selected_period_count' => count($selectedPeriodDocNums),
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'auth', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
