<?php

return [
    'title' => 'Dated organization assignments',
    'initial' => 'Register verified initial assignment',
    'transfer' => 'Dated transfer',
    'history' => 'Assignment history',
    'empty' => 'No verified assignment exists. Legacy employee history is never generated automatically.',
    'labels' => [
        'branch' => 'Branch',
        'department' => 'Department',
        'cost_center' => 'Cost center',
        'effective_from' => 'Effective from',
        'effective_to' => 'Effective to',
        'reason' => 'Reason and source reference',
        'source' => 'Source',
    ],
    'sources' => [
        'employee_creation' => 'Employee creation',
        'initial_verified' => 'Verified initial assignment',
        'transfer' => 'Dated transfer',
    ],
    'messages' => [
        'saved' => 'The dated assignment was saved.',
        'initial_unavailable' => 'Another initial assignment already exists, or the employee has no verified current branch.',
        'transfer_sequence_invalid' => 'Register the initial assignment first, then choose a later non-overlapping transfer date.',
        'branch_invalid' => 'The branch is unavailable for this company.',
        'department_invalid' => 'The department is unavailable.',
        'cost_center_invalid' => 'The cost center is unavailable for this company.',
        'date_invalid' => 'The assignment date is invalid or predates hiring.',
        'card_edit_requires_transfer' => 'Change branch or department through the dated transfer history, not the employee card.',
    ],
];
