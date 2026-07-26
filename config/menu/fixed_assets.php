<?php

return [
    [
        'label' => 'accounting_costing',
        'title' => 'Accounting & Costing',
        'icon' => 'calculator',
        'route' => null,
        'permission' => null,
        'keywords' => ['fixed assets', 'assets', 'register', 'الأصول الثابتة', 'دليل الأصول'],
        'active' => ['admin.fixed-assets.*'],
        'children' => [
            [
                'label' => 'fixed_assets_register',
                'title' => 'Fixed Assets Register',
                'icon' => 'clipboard-list',
                'route' => 'admin.fixed-assets.assets.index',
                'permission' => 'fixed_assets.view',
                'keywords' => ['fixed assets register', 'fixed assets directory', 'assets', 'دليل الأصول الثابتة'],
                'actions' => [
                    'view' => 'fixed_assets.view',
                    'create' => 'fixed_assets.create',
                    'clone' => 'fixed_assets.clone',
                    'edit' => 'fixed_assets.edit',
                    'delete' => 'fixed_assets.delete',
                    'view_trashed' => 'fixed_assets.view_trashed',
                    'restore' => 'fixed_assets.restore',
                    'document_number_control' => 'fixed_assets.document_number.control',
                    'document_number_settings_update' => 'fixed_assets.document_number_settings.update',
                ],
                'active' => ['admin.fixed-assets.assets.*'],
                'children' => [],
            ],
        ],
    ],
];
