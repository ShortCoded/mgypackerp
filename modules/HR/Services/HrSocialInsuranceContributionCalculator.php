<?php

namespace Modules\HR\Services;

use Modules\Core\Services\NumericFormatService;
use Modules\HR\Models\HrSocialInsurancePolicy;

final readonly class HrSocialInsuranceContributionCalculator
{
    public function __construct(
        private NumericFormatService $numbers,
    ) {}

    /**
     * @return array{
     *     entered_wage: string,
     *     applicable_wage: string,
     *     employee_rate: string,
     *     employer_rate: string,
     *     combined_rate: string,
     *     employee_contribution: string,
     *     employer_contribution: string,
     *     combined_contribution: string,
     *     was_clamped: bool
     * }|null
     */
    public function preview(HrSocialInsurancePolicy $policy, mixed $contributionWage): ?array
    {
        $enteredWage = $this->numbers->normalizeToScale($contributionWage, 2);

        if ($enteredWage === null) {
            return null;
        }

        $applicableWage = $this->clampWage($policy, $enteredWage);
        $employeeRate = '0.0000';
        $employerRate = '0.0000';

        foreach ($policy->components->where('is_active', true) as $component) {
            $employeeRate = bcadd($employeeRate, (string) $component->employee_rate, 4);
            $employerRate = bcadd($employerRate, (string) $component->employer_rate, 4);
        }

        $employeeContribution = $this->contributionAmount($applicableWage, $employeeRate, $policy->rounding_rule);
        $employerContribution = $this->contributionAmount($applicableWage, $employerRate, $policy->rounding_rule);

        return [
            'entered_wage' => $enteredWage,
            'applicable_wage' => $applicableWage,
            'employee_rate' => $employeeRate,
            'employer_rate' => $employerRate,
            'combined_rate' => bcadd($employeeRate, $employerRate, 4),
            'employee_contribution' => $employeeContribution,
            'employer_contribution' => $employerContribution,
            'combined_contribution' => bcadd($employeeContribution, $employerContribution, $this->moneyScale($policy->rounding_rule)),
            'was_clamped' => bccomp($enteredWage, $applicableWage, 2) !== 0,
        ];
    }

    private function clampWage(HrSocialInsurancePolicy $policy, string $enteredWage): string
    {
        $minimum = $policy->minimum_contribution_wage;
        $maximum = $policy->maximum_contribution_wage;

        if ($minimum !== null && bccomp($enteredWage, (string) $minimum, 2) < 0) {
            return (string) $minimum;
        }

        if ($maximum !== null && bccomp($enteredWage, (string) $maximum, 2) > 0) {
            return (string) $maximum;
        }

        return $enteredWage;
    }

    private function contributionAmount(string $wage, string $rate, string $roundingRule): string
    {
        $unrounded = bcdiv(bcmul($wage, $rate, 8), '100', 8);
        $truncated = bcadd($unrounded, '0', 2);

        return match ($roundingRule) {
            'up' => bccomp($unrounded, $truncated, 8) > 0 ? bcadd($truncated, '0.01', 2) : $truncated,
            'down' => $truncated,
            'none' => bcadd($unrounded, '0', 4),
            default => bcadd($unrounded, '0.005', 2),
        };
    }

    private function moneyScale(string $roundingRule): int
    {
        return $roundingRule === 'none' ? 4 : 2;
    }
}
