<?php

return [
    [
        'label' => 'accounting_costing',
        'title' => 'Accounting & Costing',
        'icon' => 'calculator',
        'route' => null,
        'permission' => null,
        'keywords' => ['general ledger', 'accounting', 'accounts', 'chart of accounts', 'cost centers', 'الحسابات', 'شجرة الحسابات', 'مراكز التكلفة'],
        'active' => [
            'admin.accounting.*',
        ],
        'children' => [
            [
                'label' => 'chart_of_accounts',
                'title' => 'Chart of Accounts',
                'icon' => 'sitemap',
                'route' => 'admin.accounting.accounts.index',
                'permission' => 'accounts.view',
                'keywords' => ['chart of accounts', 'accounts', 'ledger', 'شجرة الحسابات', 'الحسابات'],
                'actions' => [
                    'view' => 'accounts.view',
                    'create' => 'accounts.create',
                    'clone' => 'accounts.clone',
                    'edit' => 'accounts.edit',
                    'delete' => 'accounts.delete',
                    'view_trashed' => 'accounts.view_trashed',
                    'restore' => 'accounts.restore',
                    'export' => 'accounts.export',
                    'document_number_control' => 'accounts.document_number.control',
                    'document_number_settings_update' => 'accounts.document_number_settings.update',
                    'account_code_control' => 'accounts.account_code.control',
                ],
                'active' => [
                    'admin.accounting.accounts.*',
                ],
                'children' => [],
            ],
            [
                'label' => 'cost_centers',
                'title' => 'Cost Centers',
                'icon' => 'project-diagram',
                'route' => 'admin.accounting.cost-centers.index',
                'permission' => 'cost_centers.view',
                'keywords' => ['cost centers', 'cost center tree', 'مراكز التكلفة', 'مركز تكلفة'],
                'actions' => [
                    'view' => 'cost_centers.view',
                    'create' => 'cost_centers.create',
                    'clone' => 'cost_centers.clone',
                    'edit' => 'cost_centers.edit',
                    'delete' => 'cost_centers.delete',
                    'view_trashed' => 'cost_centers.view_trashed',
                    'restore' => 'cost_centers.restore',
                    'print' => 'cost_centers.print',
                    'export' => 'cost_centers.export',
                    'document_number_control' => 'cost_centers.document_number.control',
                    'document_number_settings_update' => 'cost_centers.document_number_settings.update',
                ],
                'active' => [
                    'admin.accounting.cost-centers.*',
                ],
                'children' => [],
            ],
        ],
    ],
];
