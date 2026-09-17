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
        'geography' => 'Geography and data quality',
        'dates' => 'Date filters',
    ],
    'filters' => [
        'phone_or_mobile' => 'Phone or mobile',
        'created_from' => 'Created from',
        'created_to' => 'Created to',
        'data_completeness' => 'Data completeness',
    ],
    'placeholders' => [
        'phone' => 'Search phone or mobile',
        'select_group' => 'Select group',
        'select_account' => 'Select accounting account',
        'select_country' => 'Select country',
        'select_governorate' => 'Select governorate',
        'select_city' => 'Select city',
        'select_area' => 'Select area',
    ],
    'completeness' => [
        'complete' => 'Complete normalized record',
        'any_issue' => 'Any data-quality issue',
        'missing_location' => 'Missing normalized location',
        'missing_address' => 'Missing address',
        'missing_contact' => 'Missing contact details',
        'legacy_unlinked_location' => 'Legacy location not linked',
    ],
    'pdf' => [
        'contact' => 'Phone / Mobile',
        'location' => 'Location',
    ],
];
