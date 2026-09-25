<?php

namespace Modules\Maintenance\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Models\AccountClassification;
use Modules\FixedAssets\Models\FixedAsset;

class MaintenanceAssetEligibilityService
{
    /** @return Builder<FixedAsset> */
    public function queryForContext(int $companyId, int $branchId): Builder
    {
        return FixedAsset::query()
            ->where('fixed_assets.company_id', $companyId)
            ->where('fixed_assets.branch_id', $branchId)
            ->whereNull('fixed_assets.disposed_at')
            ->whereNotIn('fixed_assets.status', FixedAsset::dispositionStatuses())
            ->whereHas('account', fn (Builder $account): Builder => $account
                ->where('accounts.company_id', $companyId)
                ->where('accounts.status', 'active')
                ->whereNull('accounts.deleted_at')
                ->whereHas('classification', fn (Builder $classification): Builder => $classification
                    ->where('account_classifications.code', AccountClassification::FixedAssets)
                    ->where('account_classifications.status', 'active')
                    ->whereNull('account_classifications.deleted_at')));
    }

    /** @param array{company_id: int, branch_id: int} $context */
    public function findForUpdate(array $context, int $assetId): FixedAsset
    {
        $asset = $this->queryForContext($context['company_id'], $context['branch_id'])
            ->lockForUpdate()
            ->find($assetId);

        if (! $asset instanceof FixedAsset) {
            throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
        }

        return $asset;
    }
}
