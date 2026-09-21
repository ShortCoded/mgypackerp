<?php

$screen = static fn (string $slug, string $en, string $ar, string $group = 'analysis', string $profile = 'document', array $extra = []): array => [
    'key' => 'costing_'.str_replace('-', '_', $slug),
    'slug' => $slug,
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $group,
    'profile' => $profile,
    ...$extra,
];

return [
    'module' => 'costing',
    'title' => ['en' => 'Costing', 'ar' => 'التكاليف'],
    'route_segment' => 'costing',
    'route_name' => 'costing',
    'permission_prefix' => 'costing',
    'menu' => ['label' => 'costing', 'title' => ['en' => 'Costing', 'ar' => 'التكاليف'], 'icon' => 'calculator', 'order' => 120],
    'groups' => [
        'setup' => ['title' => ['en' => 'Costing Setup', 'ar' => 'إعدادات التكاليف'], 'icon' => 'cogs', 'order' => 10],
        'standards' => ['title' => ['en' => 'Standard Costs', 'ar' => 'التكاليف المعيارية'], 'icon' => 'clipboard-list', 'order' => 20],
        'work_orders' => ['title' => ['en' => 'Work Order Costing', 'ar' => 'تكاليف أوامر التشغيل'], 'icon' => 'industry', 'order' => 30],
        'components' => ['title' => ['en' => 'Cost Components', 'ar' => 'مكونات التكلفة'], 'icon' => 'puzzle-piece', 'order' => 40],
        'allocation' => ['title' => ['en' => 'Overhead Allocation', 'ar' => 'توزيع التكاليف غير المباشرة'], 'icon' => 'project-diagram', 'order' => 50],
        'analysis' => ['title' => ['en' => 'Cost Analysis and Profitability', 'ar' => 'تحليل التكلفة والربحية'], 'icon' => 'chart-bar', 'order' => 60],
        'closing' => ['title' => ['en' => 'Cost Closing', 'ar' => 'إقفال التكاليف'], 'icon' => 'lock', 'order' => 70],
    ],
    'screens' => [
        $screen('overhead-allocation-rules', 'Overhead Allocation Rules', 'قواعد توزيع التكاليف غير المباشرة', 'allocation', 'setup', [
            'classification' => 'WORKING_REAL_SCREEN',
            'shell_enabled' => false,
            'actions' => ['view', 'create'],
            'modes' => ['index'],
        ]),
        $screen('overhead-allocation-run', 'Overhead Allocation Run', 'تشغيل توزيع التكاليف غير المباشرة', 'allocation', 'document', [
            'classification' => 'WORKING_REAL_SCREEN',
            'shell_enabled' => false,
            'actions' => ['view', 'create', 'approve', 'reverse'],
            'modes' => ['index'],
        ]),
        $screen('cost-closing', 'Cost Closing', 'إقفال التكاليف', 'closing', 'closing', ['shell_enabled' => false, 'menu_visible' => false]),
        [
            'key' => 'costing_work_orders_view',
            'slug' => 'work-orders-view',
            'title' => ['en' => 'Work Orders View', 'ar' => 'عرض أوامر التشغيل'],
            'group' => 'work_orders',
            'profile' => 'closing',
            'classification' => 'OUT_OF_SCOPE',
            'shell_enabled' => false,
            'menu_visible' => false,
            'permission_prefix' => 'production',
            'actions' => ['work_orders.view'],
        ],
    ],
];
