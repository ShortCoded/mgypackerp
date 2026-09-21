<?php

$screen = static fn (string $slug, string $en, string $ar, string $group = 'treasury', string $profile = 'document', array $extra = []): array => [
    'key' => 'finance_'.str_replace('-', '_', $slug),
    'slug' => $slug,
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $group,
    'profile' => $profile,
    ...$extra,
];

return [
    'module' => 'finance',
    'title' => ['en' => 'Finance and Treasury', 'ar' => 'المالية والخزينة'],
    'route_segment' => 'finance',
    'route_name' => 'finance',
    'permission_prefix' => 'finance',
    'menu' => ['label' => 'finance', 'title' => ['en' => 'Finance and Treasury', 'ar' => 'المالية والخزينة'], 'icon' => 'money-check-alt', 'order' => 100],
    'groups' => [
        'bank_vouchers' => ['title' => ['en' => 'Bank Vouchers', 'ar' => 'سندات البنوك'], 'icon' => 'university', 'order' => 10],
        'cheques' => ['title' => ['en' => 'Cheque Controls', 'ar' => 'إدارة الشيكات'], 'icon' => 'money-check', 'order' => 20],
        'treasury' => ['title' => ['en' => 'Treasury Operations', 'ar' => 'عمليات الخزينة'], 'icon' => 'cash-register', 'order' => 30],
        'reconciliation' => ['title' => ['en' => 'Reconciliation and Closing', 'ar' => 'التسويات والإقفالات'], 'icon' => 'balance-scale', 'order' => 40],
        'collections' => ['title' => ['en' => 'Collections and Payments', 'ar' => 'التحصيل والمدفوعات'], 'icon' => 'hand-holding-usd', 'order' => 50],
        'expenses' => ['title' => ['en' => 'Expenses and Custody', 'ar' => 'المصروفات والعهد'], 'icon' => 'wallet', 'order' => 60],
        'currency' => ['title' => ['en' => 'Currency Operations', 'ar' => 'عمليات العملات'], 'icon' => 'coins', 'order' => 70],
        'history' => ['title' => ['en' => 'Financial History', 'ar' => 'السجل المالي'], 'icon' => 'history', 'order' => 80],
    ],
    'screens' => [
        $screen('bank-reconciliation', 'Bank Reconciliation', 'تسوية البنك', 'reconciliation', 'document', [
            'classification' => 'DUPLICATE',
            'menu_visible' => false,
            'shell_enabled' => false,
            'canonical_route' => 'admin.reports.finance.bank-reconciliation.index',
        ]),
        $screen('cashbox-count', 'Cashbox Count', 'جرد الخزينة', 'reconciliation', 'document', [
            'classification' => 'WORKING_REAL_SCREEN',
            'shell_enabled' => false,
            'actions' => ['view', 'create', 'edit', 'reopen', 'print'],
            'modes' => ['index', 'data'],
        ]),
        $screen('supplier-advances', 'Supplier Advances', 'دفعات الموردين المقدمة', 'collections', 'document', ['classification' => 'DUPLICATE', 'menu_visible' => false, 'shell_enabled' => false, 'canonical_route' => 'admin.purchases.supplier-advances.index']),
        $screen('customer-receipts', 'Customer Receipts', 'متحصلات العملاء', 'collections', 'document', [
            'classification' => 'DUPLICATE',
            'menu_visible' => false,
            'shell_enabled' => false,
            'canonical_route' => 'admin.sales.customer-receipts.index',
        ]),
        $screen('supplier-payments', 'Supplier Payments', 'مدفوعات الموردين', 'collections', 'document', ['classification' => 'DUPLICATE', 'menu_visible' => false, 'shell_enabled' => false, 'canonical_route' => 'admin.purchases.supplier-payments.index']),
        $screen('payment-allocations', 'Payment Allocations', 'تخصيص المدفوعات', 'collections', 'document', [
            'classification' => 'DUPLICATE',
            'menu_visible' => false,
            'shell_enabled' => false,
            'canonical_route' => 'admin.purchases.supplier-payments.index',
        ]),
        $screen('employee-custody', 'Employee Custody', 'عهد الموظفين', 'expenses', 'document', ['classification' => 'DUPLICATE', 'menu_visible' => false, 'shell_enabled' => false]),
    ],
];
