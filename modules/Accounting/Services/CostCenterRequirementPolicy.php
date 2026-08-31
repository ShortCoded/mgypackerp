<?php

namespace Modules\Accounting\Services;

final class CostCenterRequirementPolicy
{
    /** @var list<string> */
    private const RequiredClassificationCodes = [
        'direct_labor_cost',
        'indirect_labor_cost',
        'manufacturing_overhead',
        'applied_manufacturing_overhead',
        'salary_expense',
        'selling_marketing_expense',
        'sales_commissions_expense',
        'rent_expense',
        'utilities_expense',
        'depreciation_expense',
        'repairs_maintenance_expense',
        'professional_fees_expense',
        'other_expense',
    ];

    public function requires(string $classificationCode): bool
    {
        return in_array($classificationCode, self::RequiredClassificationCodes, true);
    }

    /** @return list<string> */
    public function requiredClassificationCodes(): array
    {
        return self::RequiredClassificationCodes;
    }
}
