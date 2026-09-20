<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\Role;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\RequestMemo;
use Spatie\Permission\Models\Permission;

class PermissionDelegationService
{
    public function __construct(
        private readonly PermissionRegistryService $permissionRegistry,
        private readonly OperatingScopeAccessService $operatingScopeAccess,
        private readonly RequestMemo $memo,
    ) {}

    /**
     * @param  list<int>  $additionalRoleIds
     */
    public function lockMutationState(User $actor, ?User $targetUser = null, array $additionalRoleIds = []): User
    {
        $userIds = collect([$actor->getKey(), $targetUser?->getKey()])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        User::query()->whereKey($userIds->all())->orderBy('id')->lockForUpdate()->get();

        $userRoleRows = DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds->all())
            ->orderBy('role_id')
            ->orderBy('model_id')
            ->lockForUpdate()
            ->get();

        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds->all())
            ->orderBy('permission_id')
            ->orderBy('model_id')
            ->lockForUpdate()
            ->get();

        $roleIds = $userRoleRows
            ->pluck('role_id')
            ->merge($additionalRoleIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($roleIds->isNotEmpty()) {
            Role::withTrashed()->whereKey($roleIds->all())->orderBy('id')->lockForUpdate()->get();

            foreach ([
                config('permission.table_names.role_has_permissions', 'role_has_permissions'),
                'role_company_access',
                'role_branch_access',
                'role_financial_period_access',
            ] as $table) {
                DB::table($table)
                    ->whereIn('role_id', $roleIds->all())
                    ->orderBy('role_id')
                    ->lockForUpdate()
                    ->get();
            }
        }

        $freshActor = User::query()->findOrFail($actor->getKey());
        $freshActor->unsetRelation('roles')->unsetRelation('permissions');
        $this->forgetOperatingScopeMemo($freshActor, $userRoleRows
            ->where('model_id', $freshActor->getKey())
            ->pluck('role_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        return $freshActor;
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function assertCanDelegatePermissions(User $actor, array $permissions): array
    {
        $canonicalPermissions = $this->canonicalPermissionNames($permissions);
        $registeredPermissions = array_flip($this->permissionRegistry->formAssignablePermissions());
        $existingCanonicalPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $canonicalPermissions)
            ->pluck('name')
            ->all();

        $invalidPermissions = array_values(array_filter(
            $canonicalPermissions,
            fn (string $permission): bool => ! isset($registeredPermissions[$permission])
                || ! in_array($permission, $existingCanonicalPermissions, true),
        ));

        if ($invalidPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => [__('roles.validation.permissions_invalid')],
            ]);
        }

        $actorPermissions = array_flip($this->effectivePermissionNames($actor));
        $forbiddenPermissions = array_values(array_filter(
            $canonicalPermissions,
            fn (string $permission): bool => ! isset($actorPermissions[$permission]),
        ));

        if ($forbiddenPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => [__('roles.validation.permissions_not_delegable')],
            ]);
        }

        return $canonicalPermissions;
    }

    /**
     * @param  list<string>  $roleDocNums
     * @param  list<string>  $currentlyAssignedRoleDocNums
     *
     * @throws ValidationException
     */
    public function assertCanAssignRoles(
        User $actor,
        array $roleDocNums,
        array $currentlyAssignedRoleDocNums = [],
    ): void {
        $changedRoleDocNums = array_values(array_unique([
            ...array_diff($roleDocNums, $currentlyAssignedRoleDocNums),
            ...array_diff($currentlyAssignedRoleDocNums, $roleDocNums),
        ]));

        if ($changedRoleDocNums === []) {
            return;
        }

        $roles = Role::query()
            ->whereIn('doc_num', $changedRoleDocNums)
            ->with([
                'permissions:id,name',
                'companyAccessCompanies:id,doc_num',
                'branchAccessBranches:id,doc_num',
                'financialPeriodAccessPeriods:id,doc_num',
            ])
            ->get();

        if ($roles->count() !== count($changedRoleDocNums)) {
            throw ValidationException::withMessages([
                'roles' => [__('users.validation.roles_invalid')],
            ]);
        }

        $actorPermissions = array_flip($this->effectivePermissionNames($actor));

        foreach ($roles as $role) {
            $rolePermissions = $this->canonicalPermissionNames($role->permissions->pluck('name')->all());

            if (array_filter($rolePermissions, fn (string $permission): bool => ! isset($actorPermissions[$permission])) !== []) {
                throw ValidationException::withMessages([
                    'roles' => [__('users.validation.roles_not_delegable')],
                ]);
            }

            $this->assertCanDelegateRoleScope($actor, $role);
        }
    }

    /**
     * @param  list<string>  $companyDocNums
     * @param  list<string>  $branchDocNums
     * @param  list<string>  $periodDocNums
     */
    public function assertCanDelegateOperatingScope(
        User $actor,
        bool $companyRestricted,
        array $companyDocNums,
        bool $branchRestricted,
        array $branchDocNums,
        bool $periodRestricted,
        array $periodDocNums,
    ): void {
        if (! $companyRestricted) {
            $this->throwIfRestrictedActor(
                ! $this->operatingScopeAccess->hasUnrestrictedCompanyAccess($actor),
                'accessible_company_doc_nums',
                __('roles.operating_scope.company_unavailable'),
            );
        } elseif ($this->operatingScopeAccess->allowedCompanyQuery($actor)
            ->whereIn('companies.doc_num', $companyDocNums)
            ->count() !== count($companyDocNums)) {
            $this->scopeValidationError('accessible_company_doc_nums', __('roles.operating_scope.company_unavailable'));
        }

        if (! $branchRestricted) {
            $this->throwIfRestrictedActor(
                ! $this->operatingScopeAccess->hasUnrestrictedBranchAccess($actor),
                'accessible_branch_doc_nums',
                __('roles.operating_scope.branch_company_or_unavailable'),
            );
        } elseif ($this->operatingScopeAccess->allowedBranchQuery($actor, $companyDocNums)
            ->whereIn('branches.doc_num', $branchDocNums)
            ->count() !== count($branchDocNums)) {
            $this->scopeValidationError('accessible_branch_doc_nums', __('roles.operating_scope.branch_company_or_unavailable'));
        }

        if (! $periodRestricted) {
            $this->throwIfRestrictedActor(
                ! $this->operatingScopeAccess->hasUnrestrictedFinancialPeriodAccess($actor),
                'accessible_financial_period_doc_nums',
                __('roles.operating_scope.financial_period_company_or_unavailable'),
            );
        } elseif ($this->operatingScopeAccess->allowedFinancialPeriodQuery($actor, $companyDocNums)
            ->whereIn('financial_periods.doc_num', $periodDocNums)
            ->count() !== count($periodDocNums)) {
            $this->scopeValidationError('accessible_financial_period_doc_nums', __('roles.operating_scope.financial_period_company_or_unavailable'));
        }
    }

    /**
     * @return list<string>
     */
    public function effectivePermissionNames(User $actor): array
    {
        return $this->canonicalPermissionNames($actor->getAllPermissions()->pluck('name')->all());
    }

    /**
     * @return list<string>
     */
    public function permissionsToPreserve(Role $role, User $actor): array
    {
        $actorPermissions = array_flip($this->effectivePermissionNames($actor));
        $formPermissions = array_flip($this->permissionRegistry->formAssignablePermissions());

        return collect($role->permissions)
            ->pluck('name')
            ->map(fn (string $permission): string => $this->permissionRegistry->canonicalPermission($permission))
            ->filter(fn (string $permission): bool => ! isset($formPermissions[$permission]) || ! isset($actorPermissions[$permission]))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Role>  $query
     */
    public function constrainToDelegableRoles(Builder $query, User $actor): void
    {
        $effectivePermissions = array_flip($this->effectivePermissionNames($actor));
        $delegableStoredPermissionNames = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->filter(fn (string $permission): bool => isset($effectivePermissions[$this->permissionRegistry->canonicalPermission($permission)]))
            ->values()
            ->all();

        $query->whereDoesntHave('permissions', function (Builder $permissionQuery) use ($delegableStoredPermissionNames): void {
            $permissionQuery->whereNotIn('permissions.name', $delegableStoredPermissionNames);
        });

        if (! $this->operatingScopeAccess->hasUnrestrictedCompanyAccess($actor)) {
            $allowedCompanyIds = $this->operatingScopeAccess->allowedCompanyQuery($actor)->pluck('companies.id')->all();
            $query->where('company_access_restricted', true)
                ->where('name', '!=', 'admin')
                ->whereDoesntHave('companyAccessCompanies', fn (Builder $companyQuery): Builder => $companyQuery
                    ->whereNotIn('companies.id', $allowedCompanyIds));
        }

        if (! $this->operatingScopeAccess->hasUnrestrictedBranchAccess($actor)) {
            $allowedBranchIds = $this->operatingScopeAccess->allowedBranchQuery($actor)->pluck('branches.id')->all();
            $query->where('branch_access_restricted', true)
                ->whereDoesntHave('branchAccessBranches', fn (Builder $branchQuery): Builder => $branchQuery
                    ->whereNotIn('branches.id', $allowedBranchIds));
        }

        if (! $this->operatingScopeAccess->hasUnrestrictedFinancialPeriodAccess($actor)) {
            $allowedPeriodIds = $this->operatingScopeAccess->allowedFinancialPeriodQuery($actor)->pluck('financial_periods.id')->all();
            $query->where('financial_period_access_restricted', true)
                ->whereDoesntHave('financialPeriodAccessPeriods', fn (Builder $periodQuery): Builder => $periodQuery
                    ->whereNotIn('financial_periods.id', $allowedPeriodIds));
        }
    }

    /**
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    private function canonicalPermissionNames(array $permissions): array
    {
        return collect($permissions)
            ->filter(fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(fn (string $permission): string => $this->permissionRegistry->canonicalPermission($permission))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function assertCanDelegateRoleScope(User $actor, Role $role): void
    {
        $isAdmin = $role->name === 'admin' && $role->guard_name === 'web';

        $this->assertCanDelegateOperatingScope(
            $actor,
            ! $isAdmin && (bool) $role->company_access_restricted,
            $role->companyAccessCompanies->pluck('doc_num')->filter()->values()->all(),
            ! $isAdmin && (bool) $role->branch_access_restricted,
            $role->branchAccessBranches->pluck('doc_num')->filter()->values()->all(),
            ! $isAdmin && (bool) $role->financial_period_access_restricted,
            $role->financialPeriodAccessPeriods->pluck('doc_num')->filter()->values()->all(),
        );
    }

    private function forgetOperatingScopeMemo(User $actor, array $roleIds): void
    {
        $roleIds = collect($roleIds)->sort()->implode(',');
        $this->memo->forget("operating_scope_access.role_scope.{$actor->getKey()}");

        foreach (['companies', 'branches', 'financial_periods'] as $dimension) {
            $this->memo->forget("operating_scope_access.restricted_ids.{$dimension}.{$roleIds}");
        }
    }

    private function throwIfRestrictedActor(bool $condition, string $field, string $message): void
    {
        if ($condition) {
            $this->scopeValidationError($field, $message);
        }
    }

    private function scopeValidationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
