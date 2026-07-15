<?php

return [
    [
        'label' => 'planning_production',
        'title' => 'Planning & Production',
        'icon' => 'industry',
        'route' => null,
        'permission' => null,
        'keywords' => ['planning', 'production', 'identifiers', 'identifier types', 'التخطيط', 'الإنتاج', 'المعرفات', 'أنواع المعرفات'],
        'active' => [
            'admin.production.*',
        ],
        'children' => [
            [
                'label' => 'production_identifier_types',
                'title' => 'Identifier Types',
                'icon' => 'tags',
                'route' => 'admin.production.identifier-types.index',
                'permission' => 'production.identifier_types.view',
                'keywords' => ['identifier types', 'identifier type', 'أنواع المعرفات', 'نوع معرف'],
                'actions' => [
                    'view' => 'production.identifier_types.view',
                    'create' => 'production.identifier_types.create',
                    'clone' => 'production.identifier_types.clone',
                    'edit' => 'production.identifier_types.edit',
                    'delete' => 'production.identifier_types.delete',
                    'view_trashed' => 'production.identifier_types.view_trashed',
                    'restore' => 'production.identifier_types.restore',
                    'document_number_control' => 'production.identifier_types.document_number.control',
                    'document_number_settings_update' => 'production.identifier_types.document_number_settings.update',
                ],
                'active' => [
                    'admin.production.identifier-types.*',
                ],
                'children' => [],
            ],
            [
                'label' => 'production_identifiers',
                'title' => 'Identifiers',
                'icon' => 'sitemap',
                'route' => 'admin.production.identifiers.index',
                'permission' => 'production.identifiers.view',
                'keywords' => ['identifiers', 'identifier tree', 'المعرفات', 'معرف'],
                'actions' => [
                    'view' => 'production.identifiers.view',
                    'create' => 'production.identifiers.create',
                    'clone' => 'production.identifiers.clone',
                    'edit' => 'production.identifiers.edit',
                    'delete' => 'production.identifiers.delete',
                    'view_trashed' => 'production.identifiers.view_trashed',
                    'restore' => 'production.identifiers.restore',
                    'document_number_control' => 'production.identifiers.document_number.control',
                    'document_number_settings_update' => 'production.identifiers.document_number_settings.update',
                ],
                'active' => [
                    'admin.production.identifiers.*',
                ],
                'children' => [],
            ],
        ],
    ],
];
