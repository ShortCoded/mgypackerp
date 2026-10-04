<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrSocialInsurancePolicy;

final readonly class PayrollStatutoryCalculationService
{
    public function __construct(private HrSocialInsuranceContributionCalculator $insuranceCalculator) {}

    /** @return array{employee_insurance: string, employer_insurance: string, tax: string, insurance_sources: list<array<string, mixed>>, tax_sources: list<array<string, mixed>>} */
    /** @param array<string, string> $earningByDate */
    public function calculate(int $companyId, HrEmployee $employee, string $start, string $end, string $gross, array $earningByDate = []): array
    {
        $periodStart = CarbonImmutable::parse($start);
        $periodEnd = CarbonImmutable::parse($end);
        $periodDays = (int) $periodStart->diffInDays($periodEnd) + 1;
        if ((int) $employee->company_id !== $companyId || $periodEnd->lessThan($periodStart) || bccomp($gross, '0', 4) < 0) {
            throw new DomainException(__('hr_payroll.messages.statutory_period_invalid'));
        }

        $result = [
            'employee_insurance' => '0.0000',
            'employer_insurance' => '0.0000',
            'tax' => '0.0000',
            'insurance_sources' => [],
            'tax_sources' => [],
        ];

        if ($employee->insurance_status === 'subject') {
            if ($employee->insurance_start_date === null || bccomp((string) $employee->insurance_contribution_wage, '0', 2) <= 0) {
                throw new DomainException(__('hr_payroll.messages.insurance_configuration_required', ['employee' => $employee->doc_num]));
            }
            $policies = HrSocialInsurancePolicy::query()->with('components')->where('company_id', $companyId)
                ->where('status', 'active')->whereDate('effective_from', '<=', $end)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->get();
            $insuranceStart = max(array_filter([
                $employee->insurance_start_date->toDateString(), $employee->hire_date?->toDateString(), $employee->contract_start_date?->toDateString(),
            ]));
            $insuranceEnd = $this->coverageEnd($employee->insurance_end_date?->toDateString(), $employee);
            foreach ($this->policySpans($insuranceStart, $insuranceEnd, $periodStart, $periodEnd, $policies) as $span) {
                $policy = $span['policy'];
                $preview = $this->insuranceCalculator->preview($policy, $employee->insurance_contribution_wage);
                if ($preview === null) {
                    throw new DomainException(__('hr_payroll.messages.insurance_configuration_required', ['employee' => $employee->doc_num]));
                }
                $employeeAmount = bcdiv(bcmul($preview['employee_contribution'], (string) $span['days'], 8), (string) $periodDays, 4);
                $employerAmount = bcdiv(bcmul($preview['employer_contribution'], (string) $span['days'], 8), (string) $periodDays, 4);
                $result['employee_insurance'] = bcadd($result['employee_insurance'], $employeeAmount, 4);
                $result['employer_insurance'] = bcadd($result['employer_insurance'], $employerAmount, 4);
                $result['insurance_sources'][] = [
                    'policy_id' => $policy->getKey(), 'policy_doc_num' => $policy->doc_num,
                    'from' => $span['from'], 'to' => $span['to'], 'covered_days' => $span['days'], 'period_days' => $periodDays,
                    'components' => $policy->components->map->only(['name', 'employee_rate', 'employer_rate', 'calculation_basis', 'is_active'])->all(),
                    'calculation' => $preview, 'employee_amount' => $employeeAmount, 'employer_amount' => $employerAmount,
                ];
            }
        }

        if ($employee->tax_status === 'subject') {
            if ($employee->tax_start_date === null) {
                throw new DomainException(__('hr_payroll.messages.tax_configuration_required', ['employee' => $employee->doc_num]));
            }
            $policies = HrEmploymentTaxPolicy::query()->with('brackets')->where('company_id', $companyId)
                ->where('status', 'active')->whereDate('effective_from', '<=', $end)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->get();
            $datedTotal = '0.0000';
            foreach ($earningByDate as $date => $amount) {
                if ($date < $start || $date > $end || bccomp($amount, '0', 4) < 0) {
                    throw new DomainException(__('hr_payroll.messages.statutory_earning_dates_required'));
                }
                $datedTotal = bcadd($datedTotal, $amount, 4);
            }
            if ($earningByDate === [] || bccomp($datedTotal, $gross, 4) !== 0) {
                throw new DomainException(__('hr_payroll.messages.statutory_earning_dates_required'));
            }
            $taxStart = max(array_filter([
                $employee->tax_start_date->toDateString(), $employee->hire_date?->toDateString(), $employee->contract_start_date?->toDateString(),
            ]));
            $taxEnd = $this->coverageEnd($employee->tax_end_date?->toDateString(), $employee);
            foreach ($this->policySpans($taxStart, $taxEnd, $periodStart, $periodEnd, $policies) as $span) {
                $policy = $span['policy'];
                if (! in_array($policy->taxable_basis, ['gross', 'gross_after_employee_insurance'], true)
                    || ! in_array($policy->annualization_method, ['calendar_days', 'twelve_equal_periods'], true)
                    || (int) $policy->tax_year !== CarbonImmutable::parse($span['from'])->year) {
                    throw new DomainException(__('hr_payroll.messages.tax_configuration_required', ['employee' => $employee->doc_num]));
                }
                $coveredGross = '0.000000000000';
                foreach ($earningByDate as $date => $earnedAmount) {
                    if ($date >= $span['from'] && $date <= $span['to']) {
                        $coveredGross = bcadd($coveredGross, $earnedAmount, 12);
                    }
                }
                $earnedGross = $coveredGross;
                $coveredInsurance = '0.000000000000';
                if ($policy->taxable_basis === 'gross_after_employee_insurance') {
                    foreach ($result['insurance_sources'] as $insuranceSource) {
                        $overlapStart = max(CarbonImmutable::parse($span['from']), CarbonImmutable::parse($insuranceSource['from']));
                        $overlapEnd = min(CarbonImmutable::parse($span['to']), CarbonImmutable::parse($insuranceSource['to']));
                        if ($overlapStart->lessThanOrEqualTo($overlapEnd)) {
                            $overlapDays = (int) $overlapStart->diffInDays($overlapEnd) + 1;
                            $coveredInsurance = bcadd($coveredInsurance, bcdiv(
                                bcmul($insuranceSource['employee_amount'], (string) $overlapDays, 12),
                                (string) $insuranceSource['covered_days'], 12,
                            ), 12);
                        }
                    }
                    $coveredGross = bcsub($coveredGross, $coveredInsurance, 12);
                    if (bccomp($coveredGross, '0', 12) < 0) {
                        $coveredGross = '0.000000000000';
                    }
                }
                if ($policy->annualization_method === 'twelve_equal_periods') {
                    if ($periodStart->day !== 1 || ! $periodEnd->isLastOfMonth() || $periodStart->format('Y-m') !== $periodEnd->format('Y-m')) {
                        throw new DomainException(__('hr_payroll.messages.tax_monthly_period_required'));
                    }
                    $annualGross = bcdiv(bcmul($coveredGross, (string) ($periodDays * 12), 12), (string) $span['days'], 12);
                    $periodFraction = bcdiv((string) $span['days'], (string) ($periodDays * 12), 12);
                } else {
                    $yearDays = CarbonImmutable::parse($span['from'])->isLeapYear() ? 366 : 365;
                    $annualGross = bcdiv(bcmul($coveredGross, (string) $yearDays, 12), (string) $span['days'], 12);
                    $periodFraction = bcdiv((string) $span['days'], (string) $yearDays, 12);
                }
                $taxableAnnual = bcsub($annualGross, (string) $policy->annual_exemption_amount, 12);
                if (bccomp($taxableAnnual, '0', 12) < 0) {
                    $taxableAnnual = '0.000000000000';
                }
                $annualTax = $this->bracketTax($policy, $taxableAnnual);
                $amount = $this->roundMoney(bcmul($annualTax, $periodFraction, 12), (string) $policy->rounding_rule);
                $result['tax'] = bcadd($result['tax'], $amount, 4);
                $result['tax_sources'][] = [
                    'policy_id' => $policy->getKey(), 'policy_doc_num' => $policy->doc_num,
                    'from' => $span['from'], 'to' => $span['to'], 'covered_days' => $span['days'], 'period_days' => $periodDays,
                    'taxable_basis' => $policy->taxable_basis, 'annualization_method' => $policy->annualization_method,
                    'annual_exemption_amount' => (string) $policy->annual_exemption_amount,
                    'covered_gross' => $earnedGross,
                    'covered_insurance' => $coveredInsurance,
                    'brackets' => $policy->brackets->map->only(['from_amount', 'to_amount', 'rate'])->all(),
                    'annual_gross' => $annualGross, 'taxable_annual' => $taxableAnnual, 'annual_tax' => $annualTax, 'amount' => $amount,
                ];
            }
        }

        return $result;
    }

    private function coverageEnd(?string $statutoryEnd, HrEmployee $employee): ?string
    {
        $ends = array_filter([
            $statutoryEnd, $employee->contract_end_date?->toDateString(), $employee->termination_date?->toDateString(),
        ]);

        return $ends === [] ? null : min($ends);
    }

    /**
     * @param  Collection<int, HrSocialInsurancePolicy|HrEmploymentTaxPolicy>  $policies
     * @return list<array{policy: HrSocialInsurancePolicy|HrEmploymentTaxPolicy, from: string, to: string, days: int}>
     */
    private function policySpans(?string $coverageStart, ?string $coverageEnd, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, Collection $policies): array
    {
        $from = $coverageStart === null ? $periodStart : max($periodStart, CarbonImmutable::parse($coverageStart));
        $to = $coverageEnd === null ? $periodEnd : min($periodEnd, CarbonImmutable::parse($coverageEnd));
        if ($from->greaterThan($to)) {
            return [];
        }
        $spans = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $matching = $policies->filter(fn ($policy): bool => $policy->effective_from->lessThanOrEqualTo($day)
                && ($policy->effective_to === null || $policy->effective_to->greaterThanOrEqualTo($day)));
            if ($matching->count() !== 1) {
                throw new DomainException(__('hr_payroll.messages.statutory_policy_coverage_required', ['date' => $day->toDateString()]));
            }
            $policy = $matching->first();
            $last = array_key_last($spans);
            if ($last !== null && $spans[$last]['policy']->is($policy)) {
                $spans[$last]['to'] = $day->toDateString();
                $spans[$last]['days']++;
            } else {
                $spans[] = ['policy' => $policy, 'from' => $day->toDateString(), 'to' => $day->toDateString(), 'days' => 1];
            }
        }

        return $spans;
    }

    private function bracketTax(HrEmploymentTaxPolicy $policy, string $taxableAnnual): string
    {
        $total = '0.000000000000';
        $previousUpper = '0.00';
        foreach ($policy->brackets as $bracket) {
            if (bccomp((string) $bracket->from_amount, $previousUpper, 2) !== 0
                || bccomp((string) $bracket->rate, '0', 4) < 0
                || bccomp((string) $bracket->rate, '100', 4) > 0) {
                throw new DomainException(__('hr_payroll.messages.tax_brackets_invalid'));
            }
            $upper = $bracket->to_amount === null || bccomp($taxableAnnual, (string) $bracket->to_amount, 12) < 0
                ? $taxableAnnual : (string) $bracket->to_amount;
            if (bccomp($upper, (string) $bracket->from_amount, 12) > 0) {
                $total = bcadd($total, bcdiv(bcmul(bcsub($upper, (string) $bracket->from_amount, 12), (string) $bracket->rate, 12), '100', 12), 12);
            }
            if ($bracket->to_amount === null) {
                return $total;
            }
            $previousUpper = (string) $bracket->to_amount;
        }
        throw new DomainException(__('hr_payroll.messages.tax_brackets_invalid'));
    }

    private function roundMoney(string $amount, string $rule): string
    {
        $truncated = bcadd($amount, '0', 2);

        return match ($rule) {
            'none' => bcadd($amount, '0', 4),
            'down' => $truncated,
            'up' => bccomp($amount, $truncated, 12) > 0 ? bcadd($truncated, '0.01', 2) : $truncated,
            'nearest' => bcadd($amount, '0.005', 2),
            default => throw new DomainException(__('hr_payroll.messages.tax_brackets_invalid')),
        };
    }
}
