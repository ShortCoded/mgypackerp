<?php

return [
    'title' => 'Dated wage history',
    'intro' => 'Record the rate, effective date and source reference. Historical rates are never inferred from the current employee card.',
    'record' => 'Record new rate',
    'verify' => 'Verify previous rate',
    'history' => 'Wage history',
    'empty' => 'This employee has no dated wage records.',
    'legacy' => 'Previous rate not verified for the current pay basis',
    'labels' => [
        'pay_basis' => 'Pay basis',
        'rate' => 'Wage rate',
        'effective_from' => 'Effective from',
        'effective_to' => 'Effective to',
        'reason' => 'Reason and source reference',
        'status' => 'Verification',
    ],
    'messages' => [
        'saved' => 'The dated wage rate was saved.',
        'verify_legacy_first' => 'Verify the previous rate against its source before adding a new rate.',
        'sequence_invalid' => 'The new rate must follow the last open rate without overlap.',
        'legacy_unavailable' => 'The previous rate is unavailable for verification.',
        'date_invalid' => 'The wage date precedes the employee hire or contract start date.',
        'initial_date_invalid' => 'The initial verified rate must start on the hire or contract start date to avoid a gap.',
        'posted_payroll_requires_correction' => 'Approved payroll exists for this period. A documented financial correction is required before changing the rate.',
        'basis_invalid' => 'The pay basis is unsupported.',
        'rate_invalid' => 'Enter a valid positive wage rate.',
        'card_edit_requires_version' => 'Change a wage rate or pay basis through dated wage history, not the employee card.',
    ],
];
