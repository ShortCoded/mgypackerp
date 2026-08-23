<?php

return [
    [
        'label' => 'inventory',
        'title' => 'Inventory',
        'icon' => 'warehouse',
        'route' => null,
        'permission' => null,
        'active' => [
            'admin.reports.products-data.*',
        ],
        'children' => [
            [
                'label' => 'products_data_report',
                'title' => 'Products and Materials Data Report',
                'icon' => 'clipboard-list',
                'route' => 'admin.reports.products-data.index',
                'permission' => 'reports.products_data.view',
                'keywords' => ['products data report', 'materials data report', 'product master data', 'bom report', 'components report', 'تقرير بيانات المنتجات', 'تقرير بيانات الخامات والتعبئة والتغليف', 'مكونات المنتجات'],
                'actions' => [
                    'view' => 'reports.products_data.view',
                    'export' => 'reports.products_data.export',
                    'pdf' => 'reports.products_data.pdf',
                ],
                'active' => [
                    'admin.reports.products-data.*',
                ],
                'children' => [],
            ],
        ],
    ],
    [
        'label' => 'sales',
        'title' => 'Sales',
        'icon' => 'shopping-cart',
        'route' => null,
        'permission' => null,
        'active' => [
            'admin.reports.customers.*',
        ],
        'children' => [
            [
                'label' => 'customers_report',
                'title' => 'Customers Report',
                'icon' => 'user-friends',
                'route' => 'admin.reports.customers.index',
                'permission' => 'reports.customers.view',
                'keywords' => ['customers report', 'customer master data', 'تقرير العملاء', 'بيانات العملاء'],
                'actions' => [
                    'view' => 'reports.customers.view',
                    'export' => 'reports.customers.export',
                    'pdf' => 'reports.customers.pdf',
                ],
                'active' => [
                    'admin.reports.customers.*',
                ],
                'children' => [],
            ],
        ],
    ],
    [
        'label' => 'purchases',
        'title' => 'Purchases',
        'icon' => 'shopping-bag',
        'route' => null,
        'permission' => null,
        'active' => [
            'admin.reports.suppliers.*',
            'admin.purchases.procurement-cycle-report.*',
        ],
        'children' => [
            [
                'label' => 'suppliers_report',
                'title' => 'Suppliers Report',
                'icon' => 'truck',
                'route' => 'admin.reports.suppliers.index',
                'permission' => 'reports.suppliers.view',
                'keywords' => ['suppliers report', 'supplier master data', 'تقرير الموردين', 'بيانات الموردين'],
                'actions' => [
                    'view' => 'reports.suppliers.view',
                    'export' => 'reports.suppliers.export',
                    'pdf' => 'reports.suppliers.pdf',
                ],
                'active' => [
                    'admin.reports.suppliers.*',
                ],
                'children' => [],
            ],
            [
                'label' => 'procurement_cycle_report',
                'title' => 'Procurement Cycle Report',
                'icon' => 'project-diagram',
                'route' => 'admin.purchases.procurement-cycle-report.index',
                'permission' => 'reports.purchases.view',
                'keywords' => ['procurement cycle', 'purchasing status', 'purchase requirements', 'دورة المشتريات'],
                'actions' => [
                    'view' => 'reports.purchases.view',
                    'export' => 'reports.purchases.export',
                ],
                'active' => [
                    'admin.purchases.procurement-cycle-report.*',
                ],
                'children' => [],
            ],
        ],
    ],
];
