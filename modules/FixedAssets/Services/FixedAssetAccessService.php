<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetAccessService
{
    public function __construct(private readonly OperatingCompanyContextService $companies, private readonly OperatingScopeAccessService $access) {}

    public function scopeAssets(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.company_id', $this->companies->requireCompanyId())
            ->whereIn($query->getModel()->getTable().'.branch_id', $this->branchIds());
    }

    public function branchIds(): array
    {
        return auth()->user() ? $this->access->allowedBranchQuery(auth()->user())->pluck('branches.id')->all() : [];
    }

    public function assertAsset(FixedAsset $asset): void
    {
        abort_unless((int) $asset->company_id === $this->companies->requireCompanyId() && in_array((int) $asset->branch_id, $this->branchIds(), true), 403);
    }

    public function assertBranch(int $branchId): void
    {
        abort_unless(in_array($branchId, $this->branchIds(), true), 403);
    }

    public function assertAssetHistory(FixedAsset $asset): void
    {
        abort_unless((int) $asset->company_id === $this->companies->requireCompanyId(), 403);
        abort_unless(in_array((int) $asset->branch_id, $this->branchIds(), true)
            || $asset->movements()->where('status', 'posted')->whereIn('source_branch_id', $this->branchIds())->exists(), 403);
    }

    public function assertPeriod(FinancialPeriod $period): void
    {
        abort_unless(auth()->user() && $this->access->allowedFinancialPeriodQuery(auth()->user())->where('financial_periods.id', $period->getKey())->exists(), 403);
    }
}
