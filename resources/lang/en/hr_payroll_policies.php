<?php

return [
    'title' => 'Payroll Attendance Policies',
    'create' => 'Add policy version',
    'scope' => 'Scope',
    'company_scope' => 'All company branches',
    'fields' => [
        'branch' => 'Branch',
        'effective_from' => 'Effective from',
        'effective_to' => 'Effective to',
        'deduct_absence' => 'Deduct explicit absence records',
        'deduct_late' => 'Deduct late minutes',
        'deduct_early_leave' => 'Deduct early-leave minutes',
        'deduct_unpaid_leave' => 'Deduct approved unpaid leave',
        'salary_day_divisor' => 'Monthly salary day divisor',
        'standard_day_minutes' => 'Standard working minutes per day',
        'deduction_payroll_item_code' => 'Deduction payroll item',
    ],
    'help' => 'New versions close the previous version automatically. All deductions are disabled until explicitly enabled.',
    'messages' => ['created' => 'Payroll attendance policy version created.', 'empty' => 'No policy versions exist. Payroll deductions remain disabled.'],
    'validation' => [
        'deduction_item_required' => 'Select a deduction payroll item when any deduction is enabled.',
        'company_policy_forbidden' => 'Only a user with unrestricted branch access may create a company-wide policy.',
        'branch_forbidden' => 'The selected branch is outside your allowed operating scope.',
        'effective_from_unique' => 'A policy version already starts on this date for the selected scope.',
        'effective_period_overlap' => 'Existing policy versions overlap for the selected scope. Resolve them before adding another version.',
    ],
];
