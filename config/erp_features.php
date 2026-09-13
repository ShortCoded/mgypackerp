<?php

return [
    'fixed_assets' => [
        'allow_full_master_crud' => env('ERP_FIXED_ASSETS_FULL_MASTER_CRUD', true),
    ],
    'sales' => [
        'allow_full_invoice_crud' => env('ERP_SALES_FULL_INVOICE_CRUD', true),
    ],
];
