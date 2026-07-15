<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Company;

class CompanyAccessService
{
    public function userHasUnrestrictedCompanyAccess(User $user): bool
    {
        return $this->accessibleCompanyIdsFor($user) === null;
    }

    /**
     * Returns null when the user is unrestricted.
     *
     * @return list<int>|null
     */
    public function accessibleCompanyIdsFor(User $user): ?array
    {
        $roles = $user->roles()
            ->select(['roles.id', 'roles.name', 'roles.guard_name', 'roles.company_access_restricted'])
            ->get();

        if ($roles->isEmpty()) {
            return null;
        }

        if ($roles->contains(fn (Role $role): bool => $this->roleIsUnrestricted($role))) {
            return null;
        }

        $roleIds = $roles
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $companyIds = DB::table('role_company_access')
            ->join('companies', 'companies.id', '=', 'role_company_access.company_id')
            ->whereIn('role_company_access.role_id', $roleIds)
            ->whereNull('companies.deleted_at')
            ->where('companies.status', 'active')
            ->distinct()
            ->pluck('companies.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        sort($companyIds);

        return $companyIds;
    }

    public function canAccessCompany(User $user, Company $company): bool
    {
        if ($company->trashed() || $company->status !== 'active') {
            return false;
        }

        $companyIds = $this->accessibleCompanyIdsFor($user);

        return $companyIds === null || in_array((int) $company->getKey(), $companyIds, true);
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeCompaniesForUser(Builder $query, User $user): Builder
    {
        $table = $query->getModel()->getTable();
        $companyIds = $this->accessibleCompanyIdsFor($user);

        $query
            ->whereNull("{$table}.deleted_at")
            ->where("{$table}.status", 'active');

        if ($companyIds === null) {
            return $query;
        }

        if ($companyIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn("{$table}.id", $companyIds);
    }

    private function roleIsUnrestricted(Role $role): bool
    {
        if ($role->name === 'admin' && $role->guard_name === 'web') {
            return true;
        }

        return ! (bool) $role->company_access_restricted;
    }
}
