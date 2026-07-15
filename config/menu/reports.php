<?php

return [
    [
        'label' => 'reports',
        'title' => 'Reports',
        'icon' => 'chart-bar',
        'route' => null,
        'permission' => null,
        'keywords' => ['reports', 'master data reports', 'product reports', 'تقارير', 'تقارير المنتجات'],
        'active' => [
            'admin.reports.*',
        ],
        'children' => [
            [
                'label' => 'products_data_report',
                'title' => 'Products Data Report',
                'icon' => 'clipboard-list',
                'route' => 'admin.reports.products-data.index',
                'permission' => 'reports.products_data.view',
                'keywords' => ['products data report', 'product master data', 'bom report', 'components report', 'تقرير بيانات المنتجات', 'مكونات المنتجات'],
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
];
