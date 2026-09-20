<?php

return [
    'title' => 'Leave Types',
    'create' => 'Create leave type',
    'show' => 'Leave type details',
    'trash_filters' => ['active' => 'Active records', 'with' => 'All records', 'only' => 'Deleted records'],
    'fields' => [
        'code' => 'Code',
        'name' => 'Name',
        'payment_status' => 'Payroll treatment',
        'requires_balance' => 'Requires leave balance',
        'annual_entitlement_days' => 'Annual entitlement days',
        'carry_forward_max_days' => 'Maximum carry-forward days',
        'status' => 'Status',
        'notes' => 'Notes',
    ],
    'payment_statuses' => ['paid' => 'Paid leave', 'unpaid' => 'Unpaid leave'],
    'statuses' => ['active' => 'Active', 'inactive' => 'Inactive'],
    'messages' => [
        'created' => 'Leave type created.',
        'updated' => 'Leave type updated.',
        'deleted' => 'Leave type deleted.',
        'restored' => 'Leave type restored.',
        'empty' => 'No leave types are configured.',
    ],
];
