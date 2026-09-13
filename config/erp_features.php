<?php

return [
    'screen_data_visibility_rules' => [
        'enabled' => env('ERP_SCREEN_DATA_VISIBILITY_RULES_ENABLED', false),
    ],
    'fixed_assets' => [
        'allow_full_master_crud' => env('ERP_FIXED_ASSETS_FULL_MASTER_CRUD', true),
    ],
    'sales' => [
        'allow_full_invoice_crud' => env('ERP_SALES_FULL_INVOICE_CRUD', true),
    ],
];
