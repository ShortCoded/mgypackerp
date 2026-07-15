<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class OperatingScopeAccessService
{
    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    /**
     * @return Builder<Company>
     */
    public function allowedCompanyQuery(User $user): Builder
    {
        $query = Company::query()
            ->active()
            ->orderBy('companies.name')
            ->orderBy('companies.doc_number');

        $companyIds = $this->restrictedCompanyIds($this->roleScope($user));

        if ($companyIds !== null) {
            $query->whereIn('companies.id', $companyIds !== [] ? $companyIds : [0]);
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $companyDocNums
     * @return Builder<Branch>
     */
    public function allowedBranchQuery(User $user, ?array $companyDocNums = null): Builder
    {
        $query = Branch::query()
            ->with('company:id,doc_num,name,status')
            ->join('companies', 'companies.id', '=', 'branches.company_id')
            ->whereNull('companies.deleted_at')
            ->where('companies.status', 'active')
            ->active()
            ->select('branches.*')
            ->orderBy('branches.name')
            ->orderBy('branches.doc_number');

        $roleScope = $this->roleScope($user);
        $companyIds = $this->restrictedCompanyIds($roleScope);

        if ($companyIds !== null) {
            $query->whereIn('branches.company_id', $companyIds !== [] ? $companyIds : [0]);
        }

        $this->applyCompanyDocNumFilter($query, $companyDocNums);

        $branchIds = $this->restrictedBranchIds($roleScope);

        if ($branchIds !== null) {
            $query->whereIn('branches.id', $branchIds !== [] ? $branchIds : [0]);
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $companyDocNums
     * @return Builder<FinancialPeriod>
     */
    public function allowedFinancialPeriodQuery(User $user, ?array $companyDocNums = null, bool $openOnly = false): Builder
    {
        $query = FinancialPeriod::query()
            ->join('companies', 'companies.id', '=', 'financial_periods.company_id')
            ->whereNull('companies.deleted_at')
            ->where('companies.status', 'active')
            ->select('financial_periods.*')
            ->orderByDesc('financial_periods.from_date')
            ->orderByDesc('financial_periods.doc_number');

        if ($openOnly) {
            $query->open();
        }

        $roleScope = $this->roleScope($user);
        $companyIds = $this->restrictedCompanyIds($roleScope);

        if ($companyIds !== null) {
            $query->whereIn('financial_periods.company_id', $companyIds !== [] ? $companyIds : [0]);
        }

        $this->applyCompanyDocNumFilter($query, $companyDocNums);

        $periodIds = $this->restrictedFinancialPeriodIds($roleScope);

        if ($periodIds !== null) {
            $query->whereIn('financial_periods.id', $periodIds !== [] ? $periodIds : [0]);
        }

        return $query;
    }

    public function canAccessCompany(User $user, Company $company): bool
    {
        return ! $company->trashed()
            && $company->status === 'active'
            && $this->allowedCompanyQuery($user)
                ->where('companies.id', $company->getKey())
                ->exists();
    }

    public function canAccessBranch(User $user, Branch $branch, Company $company): bool
    {
        return ! $branch->trashed()
            && $branch->status === 'active'
            && (int) $branch->company_id === (int) $company->getKey()
            && $this->allowedBranchQuery($user, [(string) $company->doc_num])
                ->where('branches.id', $branch->getKey())
                ->exists();
    }

    public function canAccessFinancialPeriod(User $user, FinancialPeriod $period, Company $company): bool
    {
        return ! $period->trashed()
            && (int) $period->company_id === (int) $company->getKey()
            && $this->allowedFinancialPeriodQuery($user, [(string) $company->doc_num])
                ->where('financial_periods.id', $period->getKey())
                ->exists();
    }

    public function hasUnrestrictedCompanyAccess(User $user): bool
    {
        return $this->restrictedCompanyIds($this->roleScope($user)) === null;
    }

    public function hasUnrestrictedBranchAccess(User $user): bool
    {
        return $this->restrictedBranchIds($this->roleScope($user)) === null;
    }

    public function hasUnrestrictedFinancialPeriodAccess(User $user): bool
    {
        return $this->restrictedFinancialPeriodIds($this->roleScope($user)) === null;
    }

    /**
     * @param  Builder<Branch>|Builder<FinancialPeriod>  $query
     * @param  list<string>|null  $companyDocNums
     */
    private function applyCompanyDocNumFilter(Builder $query, ?array $companyDocNums): void
    {
        if ($companyDocNums === null) {
            return;
        }

        $companyDocNums = $this->normalizeDocNums($companyDocNums);

        if ($companyDocNums === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('companies.doc_num', $companyDocNums);
    }

    /**
     * @return Collection<int, Role>
     */
    private function roleScope(User $user): Collection
    {
        /** @var Collection<int, Role> $roles */
        return $this->memo->remember(
            "operating_scope_access.role_scope.{$user->getKey()}",
            fn (): Collection => $user->roles()
                ->select('roles.id', 'roles.name', 'roles.guard_name', 'roles.company_access_restricted', 'roles.branch_access_restricted', 'roles.financial_period_access_restricted')
                ->get()
        );
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<int>|null
     */
    private function restrictedCompanyIds(Collection $roles): ?array
    {
        if ($roles->isEmpty() || $roles->contains(fn (Role $role): bool => $this->roleHasUnrestrictedCompanyAccess($role))) {
            return null;
        }

        return DB::table('role_company_access')
            ->whereIn('role_id', $roles->pluck('id')->all())
            ->pluck('company_id')
            ->unique()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<int>|null
     */
    private function restrictedBranchIds(Collection $roles): ?array
    {
        if ($roles->isEmpty() || $roles->contains(fn (Role $role): bool => $this->roleHasUnrestrictedBranchAccess($role))) {
            return null;
        }

        return DB::table('role_branch_access')
            ->whereIn('role_id', $roles->pluck('id')->all())
            ->pluck('branch_id')
            ->unique()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<int>|null
     */
    private function restrictedFinancialPeriodIds(Collection $roles): ?array
    {
        if ($roles->isEmpty() || $roles->contains(fn (Role $role): bool => $this->roleHasUnrestrictedFinancialPeriodAccess($role))) {
            return null;
        }

        return DB::table('role_financial_period_access')
            ->whereIn('role_id', $roles->pluck('id')->all())
            ->pluck('financial_period_id')
            ->unique()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function roleHasUnrestrictedCompanyAccess(Role $role): bool
    {
        return $this->isAdminRole($role) || ! (bool) $role->company_access_restricted;
    }

    private function roleHasUnrestrictedBranchAccess(Role $role): bool
    {
        return $this->isAdminRole($role) || ! (bool) $role->branch_access_restricted;
    }

    private function roleHasUnrestrictedFinancialPeriodAccess(Role $role): bool
    {
        return $this->isAdminRole($role) || ! (bool) $role->financial_period_access_restricted;
    }

    private function isAdminRole(Role $role): bool
    {
        return $role->name === 'admin' && $role->guard_name === 'web';
    }

    /**
     * @param  list<string>  $docNums
     * @return list<string>
     */
    private function normalizeDocNums(array $docNums): array
    {
        return collect($docNums)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }
}
