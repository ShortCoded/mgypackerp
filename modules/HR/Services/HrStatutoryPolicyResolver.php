<?php

namespace Modules\HR\Services;

use Carbon\CarbonInterface;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrSocialInsurancePolicy;

class HrStatutoryPolicyResolver
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function socialInsuranceAt(CarbonInterface|string $date): ?HrSocialInsurancePolicy
    {
        return HrSocialInsurancePolicy::query()
            ->with('components')
            ->where('company_id', $this->companies->requireCompanyId())
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from')
            ->latest('id')
            ->first();
    }

    public function employmentTaxAt(CarbonInterface|string $date): ?HrEmploymentTaxPolicy
    {
        return HrEmploymentTaxPolicy::query()
            ->with('brackets')
            ->where('company_id', $this->companies->requireCompanyId())
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from')
            ->latest('id')
            ->first();
    }
}
