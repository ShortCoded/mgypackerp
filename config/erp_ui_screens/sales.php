<?php

$screen = static fn (string $slug, string $en, string $ar, string $group = 'orders', string $profile = 'document', array $extra = []): array => [
    'key' => 'sales_'.str_replace('-', '_', $slug),
    'slug' => $slug,
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $group,
    'profile' => $profile,
    'shell_enabled' => true,
    'classification' => 'UI_SURFACE_PENDING_DEEP_WORKFLOW',
    ...$extra,
];

return [
    'module' => 'sales',
    'title' => ['en' => 'Sales', 'ar' => 'المبيعات'],
    'route_segment' => 'sales',
    'route_name' => 'sales',
    'permission_prefix' => 'sales',
    'menu' => ['label' => 'sales', 'title' => ['en' => 'Sales', 'ar' => 'المبيعات'], 'icon' => 'shopping-cart', 'order' => 40],
    'groups' => [
        'crm' => ['title' => ['en' => 'CRM and Customer Activity', 'ar' => 'إدارة علاقات العملاء'], 'icon' => 'users', 'order' => 10],
        'customer_data' => ['title' => ['en' => 'Customer Commercial Data', 'ar' => 'بيانات العميل التجارية'], 'icon' => 'address-card', 'order' => 20],
        'quotations' => ['title' => ['en' => 'Quotations', 'ar' => 'عروض الأسعار'], 'icon' => 'file-invoice-dollar', 'order' => 30],
        'orders' => ['title' => ['en' => 'Sales Orders', 'ar' => 'أوامر البيع'], 'icon' => 'clipboard-list', 'order' => 40],
        'contracts' => ['title' => ['en' => 'Sales Contracts', 'ar' => 'عقود المبيعات'], 'icon' => 'file-contract', 'order' => 50],
        'billing' => ['title' => ['en' => 'Billing and Collections', 'ar' => 'الفواتير والتحصيل'], 'icon' => 'money-check-alt', 'order' => 60],
        'delivery' => ['title' => ['en' => 'Delivery', 'ar' => 'التسليم'], 'icon' => 'truck', 'order' => 70],
        'controls' => ['title' => ['en' => 'Sales Controls', 'ar' => 'ضوابط المبيعات'], 'icon' => 'user-shield', 'order' => 80],
    ],
    'screens' => [
        $screen('customer-requests', 'Sales Requests', 'طلبات المبيعات', 'orders', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'sales_requests', 'actions' => ['delete', 'restore', 'view_trashed', 'view', 'create', 'edit', 'approve', 'cancel', 'convert', 'print']]),
        $screen('sales-orders', 'Sales Orders', 'أوامر البيع', 'orders', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'sales_orders', 'actions' => ['delete', 'restore', 'view_trashed', 'view', 'create', 'edit', 'approve', 'reject', 'cancel', 'reopen', 'print', 'view_prices', 'credit_override', 'reserve', 'deliver', 'invoice', 'production']]),
        $screen('sales-invoices', 'Sales Invoices', 'فواتير المبيعات', 'billing', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'customer_invoices', 'actions' => ['view', 'create', 'edit', 'delete', 'post', 'cancel', 'reopen', 'print', 'view_prices']]),
        $screen('sales-returns', 'Sales Returns', 'مرتجعات المبيعات', 'billing', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'sales_returns', 'actions' => ['view', 'create', 'authorize', 'receive', 'inspect', 'close', 'cancel', 'print']]),
        $screen('customer-receipts', 'Customer Receipts', 'متحصلات العملاء', 'billing', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'customer_receipts', 'actions' => ['view', 'create', 'cancel', 'reopen', 'print', 'allocate']]),
        $screen('delivery-notes', 'Issue Orders', 'أوامر الصرف', 'delivery', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'permission_prefix' => 'sales_deliveries', 'actions' => ['view', 'create', 'print']]),
    ],
];
