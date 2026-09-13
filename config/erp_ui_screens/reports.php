<?php

$screen = static fn (string $domain, string $slug, string $en, string $ar): array => [
    'key' => 'reports_'.$domain.'_'.str_replace('-', '_', $slug),
    'slug' => $domain.'/'.$slug,
    'permission_prefix' => 'reports.'.$domain.'.'.str_replace('-', '_', $slug),
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $domain,
    'profile' => 'report',
    'kind' => 'report',
];

return [
    'module' => 'reports',
    'title' => ['en' => 'Reports', 'ar' => 'التقارير'],
    'route_segment' => 'reports',
    'route_name' => 'reports',
    'permission_prefix' => 'reports',
    'menu' => ['label' => 'reports', 'title' => ['en' => 'Reports', 'ar' => 'التقارير'], 'icon' => 'chart-bar', 'order' => 140],
    'groups' => [
        'finance' => ['title' => ['en' => 'Finance Reports', 'ar' => 'تقارير المالية'], 'icon' => 'money-check-alt', 'order' => 10],
        'sales' => ['title' => ['en' => 'Sales Reports', 'ar' => 'تقارير المبيعات'], 'icon' => 'shopping-cart', 'order' => 20],
        'purchases' => ['title' => ['en' => 'Purchases Reports', 'ar' => 'تقارير المشتريات'], 'icon' => 'shopping-bag', 'order' => 30],
        'costing' => ['title' => ['en' => 'Costing Reports', 'ar' => 'تقارير التكاليف'], 'icon' => 'calculator', 'order' => 80],
    ],
    'screens' => [
        $screen('finance', 'cashbox-balances', 'Cashbox Balances', 'أرصدة الخزائن'),
        $screen('finance', 'cashbox-statement', 'Cashbox Statement', 'كشف حساب الخزينة'),
        $screen('finance', 'bank-account-balances', 'Bank Account Balances', 'أرصدة الحسابات البنكية'),
        $screen('finance', 'bank-account-statement', 'Bank Account Statement', 'كشف الحساب البنكي'),
        $screen('finance', 'cheque-transit', 'Cheque Transit', 'الشيكات بالطريق'),
        $screen('finance', 'treasury-transfers', 'Treasury Transfers', 'تحويلات الخزينة'),
        $screen('finance', 'customer-aging', 'Customer Aging', 'أعمار ديون العملاء'),
        $screen('finance', 'supplier-aging', 'Supplier Aging', 'أعمار ديون الموردين'),
        [...$screen('sales', 'sales-orders', 'Sales Orders', 'أوامر البيع'), 'shell_enabled' => false],
        [...$screen('purchases', 'purchases-by-supplier', 'Purchases by Supplier', 'المشتريات حسب المورد'), 'menu_visible' => false, 'shell_enabled' => false],
        [...$screen('purchases', 'purchase-orders', 'Purchase Orders', 'أوامر الشراء'), 'menu_visible' => false, 'shell_enabled' => false],
        [...$screen('purchases', 'purchase-invoice-details', 'Purchase Invoice Details', 'تفاصيل فواتير المشتريات'), 'menu_visible' => false, 'shell_enabled' => false],
        [...$screen('purchases', 'goods-receipts', 'Goods Receipts', 'استلامات البضاعة'), 'menu_visible' => false, 'shell_enabled' => false],
        [...$screen('purchases', 'purchase-returns', 'Purchase Returns', 'مردودات المشتريات'), 'menu_visible' => false, 'shell_enabled' => false],
        $screen('costing', 'product-cost', 'Product Cost', 'تكلفة المنتج'),
        $screen('costing', 'work-order-cost', 'Work Order Cost', 'تكلفة أمر التشغيل'),
        $screen('costing', 'estimated-vs-actual', 'Estimated vs Actual', 'التقديري مقابل الفعلي'),
        $screen('costing', 'cost-variance', 'Cost Variance', 'انحراف التكلفة'),
        $screen('costing', 'profitability', 'Profitability', 'الربحية'),
    ],
];
