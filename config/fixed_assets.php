<?php

return [
    'depreciation_periodicity' => 'monthly',
    'first_period_policy' => 'daily_prorata',
    'day_count_convention' => 'actual_days_fixed_365',
    'day_basis' => 365,
    'activation_date_inclusive' => true,
    'disposal_cutoff_policy' => 'start_of_disposal_month',
    'opening_boundary_policy' => 'day_after_previous_depreciation_until_date',
    'precision' => 4,
];
