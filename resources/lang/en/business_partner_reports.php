<?php

return [
    'customers' => [
        'title' => 'Customers Report',
        'description' => 'Inquire about registered customer master data in the current company.',
        'table_title' => 'Customer master data',
        'placeholders' => [
            'doc_num' => 'Search customer document number',
            'name' => 'Search customer name',
        ],
    ],
    'suppliers' => [
        'title' => 'Suppliers Report',
        'description' => 'Inquire about registered supplier master data in the current company.',
        'table_title' => 'Supplier master data',
        'placeholders' => [
            'doc_num' => 'Search supplier document number',
            'name' => 'Search supplier name',
        ],
    ],
    'actions' => [
        'toggle_filters' => 'Report Filters',
    ],
    'filter_groups' => [
        'identification' => 'Partner identification',
        'accounting' => 'Classification and accounting',
        'dates' => 'Date filters',
    ],
    'filters' => [
        'phone_or_mobile' => 'Phone or mobile',
        'created_from' => 'Created from',
        'created_to' => 'Created to',
    ],
    'placeholders' => [
        'phone' => 'Search phone or mobile',
        'select_group' => 'Select group',
        'select_account' => 'Select accounting account',
    ],
    'pdf' => [
        'contact' => 'Phone / Mobile',
        'location' => 'Location',
    ],
];
