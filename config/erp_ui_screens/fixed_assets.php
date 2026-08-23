<?php

$screen = static fn (string $slug, string $en, string $ar, string $group = 'transactions', string $profile = 'document', array $extra = []): array => [
    'key' => 'fixed_assets_'.str_replace('-', '_', $slug),
    'slug' => $slug,
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $group,
    'profile' => $profile,
    ...$extra,
];

return [
    'module' => 'fixed_assets',
    'title' => ['en' => 'Fixed Assets', 'ar' => 'الأصول الثابتة'],
    'route_segment' => 'fixed-assets',
    'route_name' => 'fixed-assets',
    'permission_prefix' => 'fixed_assets',
    'menu' => ['label' => 'fixed_assets', 'title' => ['en' => 'Fixed Assets', 'ar' => 'الأصول الثابتة'], 'icon' => 'building', 'order' => 110],
    'groups' => [
        'setup' => ['title' => ['en' => 'Asset Setup', 'ar' => 'إعدادات الأصول'], 'icon' => 'cogs', 'order' => 10],
        'transactions' => ['title' => ['en' => 'Asset Transactions', 'ar' => 'حركات الأصول'], 'icon' => 'exchange-alt', 'order' => 20],
        'depreciation' => ['title' => ['en' => 'Depreciation', 'ar' => 'الإهلاك'], 'icon' => 'chart-line', 'order' => 30],
        'valuation' => ['title' => ['en' => 'Valuation and Disposal', 'ar' => 'التقييم والاستبعاد'], 'icon' => 'balance-scale', 'order' => 40],
        'support' => ['title' => ['en' => 'Asset Support', 'ar' => 'خدمات الأصول'], 'icon' => 'folder-open', 'order' => 50],
    ],
    'screens' => [
        $screen('asset-inspection', 'Asset Inspection', 'فحص الأصل', 'support'),
        $screen('asset-documents', 'Asset Documents', 'مستندات الأصل', 'support'),
        $screen('asset-insurance', 'Asset Insurance', 'تأمين الأصل', 'support'),
    ],
];
