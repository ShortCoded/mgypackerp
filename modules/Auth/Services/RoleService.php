<?php

namespace Modules\Auth\Services;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Exceptions\RoleDeleteBlockedException;
use Modules\Auth\Exceptions\RoleRestoreBlockedException;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    private ?int $firstProtectedRoleId = null;

    private bool $firstProtectedRoleIdResolved = false;

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly PermissionRegistryService $permissionRegistry,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array{name: string, doc_number?: int, notes?: string|null, permissions?: array<int, string>, accessible_company_doc_nums?: array<int, string>, accessible_branch_doc_nums?: array<int, string>, accessible_financial_period_doc_nums?: array<int, string>}  $data
     */
    public function create(array $data, ?Role $cloneSource = null): Role
    {
        return DB::transaction(function () use ($data, $cloneSource): Role {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('roles', Role::class);
            $userId = auth()->id();
            $hasCompanyAccessPayload = array_key_exists('accessible_company_doc_nums', $data);
            $hasBranchAccessPayload = array_key_exists('accessible_branch_doc_nums', $data);
            $hasPeriodAccessPayload = array_key_exists('accessible_financial_period_doc_nums', $data);
            $companyDocNums = $hasCompanyAccessPayload
                ? $this->normalizeDocNums($data['accessible_company_doc_nums'])
                : [];
            $branchDocNums = $hasBranchAccessPayload
                ? $this->normalizeDocNums($data['accessible_branch_doc_nums'])
                : [];
            $periodDocNums = $hasPeriodAccessPayload
                ? $this->normalizeDocNums($data['accessible_financial_period_doc_nums'])
                : [];

            $role = Role::query()->create([
                'name' => trim((string) $data['name']),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'guard_name' => 'web',
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'company_access_restricted' => $hasCompanyAccessPayload && $companyDocNums !== [],
                'branch_access_restricted' => $hasBranchAccessPayload && $branchDocNums !== [],
                'financial_period_access_restricted' => $hasPeriodAccessPayload && $periodDocNums !== [],
                'created_by' => $userId,
            ]);

            $this->syncPermissions($role, $this->normalizeFormPermissions($data['permissions'] ?? []));

            if ($hasCompanyAccessPayload) {
                $this->syncCompanyAccess($role, $companyDocNums);
            }

            if ($hasBranchAccessPayload) {
                $this->syncBranchAccess($role, $branchDocNums);
            }

            if ($hasPeriodAccessPayload) {
                $this->syncFinancialPeriodAccess($role, $periodDocNums);
            }

            if (! $hasCompanyAccessPayload && ! $hasBranchAccessPayload && ! $hasPeriodAccessPayload && $cloneSource instanceof Role) {
                $this->copyOperatingScope($cloneSource, $role);
            }

            $this->crudAudit->clearCreationUpdateAudit($role);

            return $role->refresh();
        });
    }

    /**
     * @param  array{name: string, doc_number?: int, notes?: string|null, permissions?: array<int, string>, accessible_company_doc_nums?: array<int, string>, accessible_branch_doc_nums?: array<int, string>, accessible_financial_period_doc_nums?: array<int, string>}  $data
     * @return array{role: Role, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, permissions_added: list<string>, permissions_removed: list<string>, operating_scope_changed: bool, company_access_added: list<string>, company_access_removed: list<string>, branch_access_added: list<string>, branch_access_removed: list<string>, period_access_added: list<string>, period_access_removed: list<string>, selected_company_doc_nums: list<string>, selected_branch_doc_nums: list<string>, selected_period_doc_nums: list<string>, old_doc_number: int|null, old_doc_num: string|null, old_role_name: string}
     */
    public function update(Role $role, array $data): array
    {
        $this->ensureRoleCanBeUpdated($role);

        return DB::transaction(function () use ($role, $data): array {
            $role->loadMissing('permissions:id,name');

            $oldDocNumber = $role->doc_number === null ? null : (int) $role->doc_number;
            $oldDocNum = $role->doc_num;
            $oldRoleName = $role->name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format('roles', $newDocNumber) : $oldDocNum;
            $newName = trim((string) $data['name']);
            $newNotes = $this->normalizeNotes($data['notes'] ?? null);
            $newPermissions = $this->mergePreservedNonFormPermissions(
                $role,
                $this->normalizeFormPermissions($data['permissions'] ?? []),
            );
            $currentPermissions = $this->normalizePermissions($role->permissions->pluck('name')->all());
            $hasCompanyAccessPayload = array_key_exists('accessible_company_doc_nums', $data);
            $hasBranchAccessPayload = array_key_exists('accessible_branch_doc_nums', $data);
            $hasPeriodAccessPayload = array_key_exists('accessible_financial_period_doc_nums', $data);
            $currentCompanyDocNums = $hasCompanyAccessPayload ? $this->currentActiveCompanyDocNums($role) : [];
            $newCompanyDocNums = $hasCompanyAccessPayload ? $this->normalizeDocNums($data['accessible_company_doc_nums']) : [];
            $currentBranchDocNums = $hasBranchAccessPayload ? $this->currentActiveBranchDocNums($role) : [];
            $newBranchDocNums = $hasBranchAccessPayload ? $this->normalizeDocNums($data['accessible_branch_doc_nums']) : [];
            $currentPeriodDocNums = $hasPeriodAccessPayload ? $this->currentActiveFinancialPeriodDocNums($role) : [];
            $newPeriodDocNums = $hasPeriodAccessPayload ? $this->normalizeDocNums($data['accessible_financial_period_doc_nums']) : [];
            $newCompanyAccessRestricted = $hasCompanyAccessPayload
                ? $newCompanyDocNums !== []
                : (bool) $role->company_access_restricted;
            $newBranchAccessRestricted = $hasBranchAccessPayload
                ? $newBranchDocNums !== []
                : (bool) $role->branch_access_restricted;
            $newPeriodAccessRestricted = $hasPeriodAccessPayload
                ? $newPeriodDocNums !== []
                : (bool) $role->financial_period_access_restricted;

            $changedFields = [];
            $changes = [];

            if ($role->name !== $newName) {
                $changedFields[] = 'name';
                $changes['name'] = [
                    'old' => $role->name,
                    'new' => $newName,
                ];
            }

            if ($canChangeDocumentNumber && ($oldDocNumber !== $newDocNumber || $oldDocNum !== $newDocNum)) {
                $changedFields[] = 'doc_number';
                $changes['doc_number'] = [
                    'old' => $oldDocNumber,
                    'new' => $newDocNumber,
                ];
                $changes['doc_num'] = [
                    'old' => $oldDocNum,
                    'new' => $newDocNum,
                ];
            }

            if (($role->notes ?? null) !== $newNotes) {
                $changedFields[] = 'notes';
                $changes['notes'] = [
                    'old' => $role->notes,
                    'new' => $newNotes,
                ];
            }

            $permissionsAdded = array_values(array_diff($newPermissions, $currentPermissions));
            $permissionsRemoved = array_values(array_diff($currentPermissions, $newPermissions));
            $permissionsChanged = $permissionsAdded !== [] || $permissionsRemoved !== [];
            $companyAccessAdded = $hasCompanyAccessPayload ? array_values(array_diff($newCompanyDocNums, $currentCompanyDocNums)) : [];
            $companyAccessRemoved = $hasCompanyAccessPayload ? array_values(array_diff($currentCompanyDocNums, $newCompanyDocNums)) : [];
            $branchAccessAdded = $hasBranchAccessPayload ? array_values(array_diff($newBranchDocNums, $currentBranchDocNums)) : [];
            $branchAccessRemoved = $hasBranchAccessPayload ? array_values(array_diff($currentBranchDocNums, $newBranchDocNums)) : [];
            $periodAccessAdded = $hasPeriodAccessPayload ? array_values(array_diff($newPeriodDocNums, $currentPeriodDocNums)) : [];
            $periodAccessRemoved = $hasPeriodAccessPayload ? array_values(array_diff($currentPeriodDocNums, $newPeriodDocNums)) : [];
            $companyAccessChanged = $hasCompanyAccessPayload
                && (
                    (bool) $role->company_access_restricted !== $newCompanyAccessRestricted
                    || $companyAccessAdded !== []
                    || $companyAccessRemoved !== []
                );
            $branchAccessChanged = $hasBranchAccessPayload
                && (
                    (bool) $role->branch_access_restricted !== $newBranchAccessRestricted
                    || $branchAccessAdded !== []
                    || $branchAccessRemoved !== []
                );
            $periodAccessChanged = $hasPeriodAccessPayload
                && (
                    (bool) $role->financial_period_access_restricted !== $newPeriodAccessRestricted
                    || $periodAccessAdded !== []
                    || $periodAccessRemoved !== []
                );
            $operatingScopeChanged = $companyAccessChanged || $branchAccessChanged || $periodAccessChanged;

            if ($permissionsChanged) {
                $changes['permissions'] = [
                    'old' => $currentPermissions,
                    'new' => $newPermissions,
                ];
            }

            if ($operatingScopeChanged) {
                $changedFields[] = 'operating_scope';
                $changes['operating_scope'] = [
                    'old' => [
                        'companies' => $currentCompanyDocNums,
                        'branches' => $currentBranchDocNums,
                        'financial_periods' => $currentPeriodDocNums,
                    ],
                    'new' => [
                        'companies' => $newCompanyDocNums,
                        'branches' => $newBranchDocNums,
                        'financial_periods' => $newPeriodDocNums,
                    ],
                ];
            }

            $roleBusinessChanged = array_values(array_diff($changedFields, ['operating_scope'])) !== [];

            if ($changedFields === [] && ! $permissionsChanged) {
                return [
                    'role' => $role->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'permissions_added' => [],
                    'permissions_removed' => [],
                    'operating_scope_changed' => false,
                    'company_access_added' => [],
                    'company_access_removed' => [],
                    'branch_access_added' => [],
                    'branch_access_removed' => [],
                    'period_access_added' => [],
                    'period_access_removed' => [],
                    'selected_company_doc_nums' => $currentCompanyDocNums,
                    'selected_branch_doc_nums' => $currentBranchDocNums,
                    'selected_period_doc_nums' => $currentPeriodDocNums,
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'old_role_name' => $oldRoleName,
                ];
            }

            if ($changedFields !== []) {
                $this->crudAudit->saveUpdate($role, [
                    'name' => $newName,
                    'notes' => $newNotes,
                    'guard_name' => 'web',
                    'doc_number' => $canChangeDocumentNumber ? $newDocNumber : $role->doc_number,
                    'doc_num' => $canChangeDocumentNumber ? $newDocNum : $role->doc_num,
                    'company_access_restricted' => $newCompanyAccessRestricted,
                    'branch_access_restricted' => $newBranchAccessRestricted,
                    'financial_period_access_restricted' => $newPeriodAccessRestricted,
                ]);
            }

            if ($permissionsChanged) {
                $this->syncPermissions($role, $newPermissions);
            }

            if ($companyAccessChanged) {
                $this->syncCompanyAccess($role, $newCompanyDocNums);
            }

            if ($branchAccessChanged) {
                $this->syncBranchAccess($role, $newBranchDocNums);
            }

            if ($periodAccessChanged) {
                $this->syncFinancialPeriodAccess($role, $newPeriodDocNums);
            }

            if ($operatingScopeChanged && ! $roleBusinessChanged) {
                $this->crudAudit->touchUpdateAudit($role);
            }

            if ($changedFields === [] && $permissionsChanged) {
                $this->crudAudit->touchUpdateAudit($role);
            }

            if ($changedFields !== [] && ! $permissionsChanged) {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            return [
                'role' => $role->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'permissions_added' => $permissionsAdded,
                'permissions_removed' => $permissionsRemoved,
                'operating_scope_changed' => $operatingScopeChanged,
                'company_access_added' => $companyAccessAdded,
                'company_access_removed' => $companyAccessRemoved,
                'branch_access_added' => $branchAccessAdded,
                'branch_access_removed' => $branchAccessRemoved,
                'period_access_added' => $periodAccessAdded,
                'period_access_removed' => $periodAccessRemoved,
                'selected_company_doc_nums' => $newCompanyDocNums,
                'selected_branch_doc_nums' => $newBranchDocNums,
                'selected_period_doc_nums' => $newPeriodDocNums,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'old_role_name' => $oldRoleName,
            ];
        });
    }

    public function delete(Role $role): void
    {
        $this->ensureRoleCanBeDeleted($role, single: true);

        DB::transaction(function () use ($role): void {
            $this->crudAudit->softDelete($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function restore(Role $role): Role
    {
        return DB::transaction(function () use ($role): Role {
            $this->ensureRoleCanBeRestored($role);

            $this->crudAudit->restore($role, auth()->id());
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->refresh();
        });
    }

    public function ensureRoleCanBeCloned(Role $role): void
    {
        if ($this->isProtectedRole($role)) {
            throw new DomainException(__('roles.messages.clone_not_allowed'));
        }
    }

    public function ensureRoleCanBeUpdated(Role $role): void
    {
        if ($this->isProtectedRole($role)) {
            throw new DomainException(__('roles.messages.protected_update_blocked'));
        }
    }

    public function isProtectedRole(Role $role): bool
    {
        if ((int) $role->getKey() === $this->firstProtectedRoleId()) {
            return true;
        }

        return $role->name === 'admin' && $role->guard_name === 'web';
    }

    public function firstProtectedRoleId(): ?int
    {
        if ($this->firstProtectedRoleIdResolved) {
            return $this->firstProtectedRoleId;
        }

        $id = Role::withTrashed()
            ->orderBy('id')
            ->value('id');

        $this->firstProtectedRoleId = $id === null ? null : (int) $id;
        $this->firstProtectedRoleIdResolved = true;

        return $this->firstProtectedRoleId;
    }

    /**
     * @param  array<int, string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $roles = Role::query()->whereIn('doc_num', $docNums)->get();
            $this->ensureRolesCanBeBulkDeleted($roles);

            $deleted = 0;

            foreach ($roles as $role) {
                $this->crudAudit->softDelete($role);
                $deleted++;
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $deleted;
        });
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions): void
    {
        $role->syncPermissions($this->normalizePermissions($permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private function normalizePermissions(array $permissions): array
    {
        return collect($permissions)
            ->filter(fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(fn (string $permission): string => trim($permission))
            ->map(fn (string $permission): string => $this->permissionRegistry->canonicalPermission($permission))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private function normalizeFormPermissions(array $permissions): array
    {
        $formPermissions = array_flip($this->permissionRegistry->formAssignablePermissions());

        return collect($this->normalizePermissions($permissions))
            ->filter(fn (string $permission): bool => isset($formPermissions[$permission]))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function mergePreservedNonFormPermissions(Role $role, array $permissions): array
    {
        $formPermissions = array_flip($this->permissionRegistry->formAssignablePermissions());
        $preserved = $role->permissions
            ->pluck('name')
            ->map(fn (string $permission): string => $this->permissionRegistry->canonicalPermission($permission))
            ->filter(fn (string $permission): bool => ! isset($formPermissions[$permission]))
            ->values()
            ->all();

        return $this->normalizePermissions([...$permissions, ...$preserved]);
    }

    /**
     * @return list<string>
     */
    private function normalizeDocNums(mixed $docNums): array
    {
        return collect(is_array($docNums) ? $docNums : [])
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function currentActiveCompanyDocNums(Role $role): array
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
    private function currentActiveBranchDocNums(Role $role): array
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
    private function currentActiveFinancialPeriodDocNums(Role $role): array
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
     * @param  list<string>  $docNums
     */
    private function syncCompanyAccess(Role $role, array $docNums): void
    {
        if ($docNums === []) {
            $role->companyAccessCompanies()->sync([]);

            return;
        }

        $companyIds = Company::query()
            ->active()
            ->whereIn('doc_num', $docNums)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $role->companyAccessCompanies()->sync([
            ...$companyIds,
            ...$this->currentUnavailableCompanyIds($role),
        ]);
    }

    /**
     * @param  list<string>  $docNums
     */
    private function syncBranchAccess(Role $role, array $docNums): void
    {
        if ($docNums === []) {
            $role->branchAccessBranches()->sync([]);

            return;
        }

        $branchIds = Branch::query()
            ->active()
            ->whereIn('doc_num', $docNums)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $role->branchAccessBranches()->sync([
            ...$branchIds,
            ...$this->currentUnavailableBranchIds($role),
        ]);
    }

    /**
     * @param  list<string>  $docNums
     */
    private function syncFinancialPeriodAccess(Role $role, array $docNums): void
    {
        if ($docNums === []) {
            $role->financialPeriodAccessPeriods()->sync([]);

            return;
        }

        $periodIds = FinancialPeriod::query()
            ->whereIn('doc_num', $docNums)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $role->financialPeriodAccessPeriods()->sync([
            ...$periodIds,
            ...$this->currentUnavailableFinancialPeriodIds($role),
        ]);
    }

    /**
     * @return list<int>
     */
    private function currentUnavailableCompanyIds(Role $role): array
    {
        return DB::table('role_company_access')
            ->join('companies', 'companies.id', '=', 'role_company_access.company_id')
            ->where('role_company_access.role_id', $role->getKey())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('companies.deleted_at')
                    ->orWhere('companies.status', '<>', 'active');
            })
            ->pluck('role_company_access.company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function currentUnavailableBranchIds(Role $role): array
    {
        return DB::table('role_branch_access')
            ->join('branches', 'branches.id', '=', 'role_branch_access.branch_id')
            ->where('role_branch_access.role_id', $role->getKey())
            ->where(function ($query): void {
                $query
                    ->whereNotNull('branches.deleted_at')
                    ->orWhere('branches.status', '<>', 'active');
            })
            ->pluck('role_branch_access.branch_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function currentUnavailableFinancialPeriodIds(Role $role): array
    {
        return DB::table('role_financial_period_access')
            ->join('financial_periods', 'financial_periods.id', '=', 'role_financial_period_access.financial_period_id')
            ->where('role_financial_period_access.role_id', $role->getKey())
            ->whereNotNull('financial_periods.deleted_at')
            ->pluck('role_financial_period_access.financial_period_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function copyOperatingScope(Role $sourceRole, Role $newRole): void
    {
        $companyIds = DB::table('role_company_access')
            ->where('role_id', $sourceRole->getKey())
            ->pluck('company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $branchIds = DB::table('role_branch_access')
            ->where('role_id', $sourceRole->getKey())
            ->pluck('branch_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $periodIds = DB::table('role_financial_period_access')
            ->where('role_id', $sourceRole->getKey())
            ->pluck('financial_period_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $newRole->forceFill([
            'company_access_restricted' => (bool) $sourceRole->company_access_restricted,
            'branch_access_restricted' => (bool) $sourceRole->branch_access_restricted,
            'financial_period_access_restricted' => (bool) $sourceRole->financial_period_access_restricted,
        ])->save();

        $newRole->companyAccessCompanies()->sync($companyIds);
        $newRole->branchAccessBranches()->sync($branchIds);
        $newRole->financialPeriodAccessPeriods()->sync($periodIds);
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : $notes;
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('roles', $docNumber),
        ];
    }

    private function ensureRoleCanBeDeleted(Role $role, bool $single = false): void
    {
        if ($this->isProtectedRole($role)) {
            throw new RoleDeleteBlockedException(
                $single ? __('auth.roles.messages.cannot_delete_admin') : __('auth.roles.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel([$this->blockedRecord($role, 'admin_role', 0)]),
                ]),
                [$this->blockedRecord($role, 'admin_role', 0)],
            );
        }

        $relatedUsersCount = $this->relatedUsersCount($role);

        if ($relatedUsersCount > 0) {
            throw new RoleDeleteBlockedException(
                __('auth.roles.messages.related_data_exists'),
                [$this->blockedRecord($role, 'users', $relatedUsersCount)],
            );
        }
    }

    private function ensureRoleCanBeRestored(Role $role): void
    {
        if (! $role->trashed()) {
            throw new RoleRestoreBlockedException(
                __('roles.messages.restore_not_allowed'),
                'already_active',
            );
        }

        $conflictFields = $this->restoreConflictFields($role);

        if ($conflictFields !== []) {
            throw new RoleRestoreBlockedException(
                __('roles.messages.restore_conflict'),
                $this->restoreConflictType($conflictFields),
                $conflictFields,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(Role $role): array
    {
        $conflictFields = [];

        if ($this->activeRoleExists(function (Builder $query) use ($role): void {
            $query
                ->where('name', $role->name)
                ->where('guard_name', $role->guard_name);
        })) {
            $conflictFields[] = 'name';
            $conflictFields[] = 'guard_name';
        }

        if ($role->doc_number !== null && $this->activeRoleExists(function (Builder $query) use ($role): void {
            $query->where('doc_number', $role->doc_number);
        })) {
            $conflictFields[] = 'doc_number';
        }

        if ($role->doc_num !== null && $this->activeRoleExists(function (Builder $query) use ($role): void {
            $query->where('doc_num', $role->doc_num);
        })) {
            $conflictFields[] = 'doc_num';
        }

        return array_values(array_unique($conflictFields));
    }

    private function activeRoleExists(callable $constraint): bool
    {
        $query = Role::query()->whereNull('deleted_at');
        $constraint($query);

        return $query->exists();
    }

    /**
     * @param  list<string>  $conflictFields
     */
    private function restoreConflictType(array $conflictFields): string
    {
        if (in_array('doc_num', $conflictFields, true)) {
            return 'document_code_conflict';
        }

        if (in_array('doc_number', $conflictFields, true)) {
            return 'document_number_conflict';
        }

        return 'role_name_conflict';
    }

    /**
     * @param  iterable<int, Role>  $roles
     */
    private function ensureRolesCanBeBulkDeleted(iterable $roles): void
    {
        $blockedRecords = [];

        foreach ($roles as $role) {
            if ($this->isProtectedRole($role)) {
                $blockedRecords[] = $this->blockedRecord($role, 'admin_role', 0);

                continue;
            }

            $relatedUsersCount = $this->relatedUsersCount($role);

            if ($relatedUsersCount > 0) {
                $blockedRecords[] = $this->blockedRecord($role, 'users', $relatedUsersCount);
            }
        }

        if ($blockedRecords !== []) {
            throw new RoleDeleteBlockedException(
                __('auth.roles.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel($blockedRecords),
                ]),
                $blockedRecords,
            );
        }
    }

    private function relatedUsersCount(Role $role): int
    {
        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        return DB::table($modelHasRolesTable)
            ->where($rolePivotKey, $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->distinct('model_id')
            ->count('model_id');
    }

    /**
     * @return array{doc_num: string|null, role_name: string, reason: string, related_users_count: int}
     */
    private function blockedRecord(Role $role, string $reason, int $relatedUsersCount): array
    {
        return [
            'doc_num' => $role->doc_num,
            'role_name' => $role->name,
            'reason' => $reason,
            'related_users_count' => $relatedUsersCount,
        ];
    }

    /**
     * @param  list<array{doc_num: string|null, role_name: string, reason: string, related_users_count: int}>  $blockedRecords
     */
    private function blockedRecordsLabel(array $blockedRecords): string
    {
        return collect($blockedRecords)
            ->map(fn (array $record): string => trim(($record['doc_num'] ? $record['doc_num'].' - ' : '').$record['role_name']))
            ->implode(', ');
    }
}
