<?php

namespace Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
            'company_access_restricted' => 'boolean',
            'branch_access_restricted' => 'boolean',
            'financial_period_access_restricted' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companyAccessCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'role_company_access', 'role_id', 'company_id')
            ->withTimestamps()
            ->withTrashed();
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function accessibleCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'role_company_access', 'role_id', 'company_id')
            ->withTimestamps()
            ->where('companies.status', 'active');
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branchAccessBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'role_branch_access', 'role_id', 'branch_id')
            ->withTimestamps()
            ->withTrashed();
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function accessibleBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'role_branch_access', 'role_id', 'branch_id')
            ->withTimestamps()
            ->where('branches.status', 'active');
    }

    /**
     * @return BelongsToMany<FinancialPeriod, $this>
     */
    public function financialPeriodAccessPeriods(): BelongsToMany
    {
        return $this->belongsToMany(FinancialPeriod::class, 'role_financial_period_access', 'role_id', 'financial_period_id')
            ->withTimestamps()
            ->withTrashed();
    }

    /**
     * @return BelongsToMany<FinancialPeriod, $this>
     */
    public function accessibleFinancialPeriods(): BelongsToMany
    {
        return $this->belongsToMany(FinancialPeriod::class, 'role_financial_period_access', 'role_id', 'financial_period_id')
            ->withTimestamps();
    }
}
