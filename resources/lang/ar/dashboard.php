<?php

return [
    'title' => 'لوحة التحكم',
    'create_something_beautiful' => 'أنشئ شيئًا جميلًا.',
    'getting_started' => 'ابدأ الآن',
    'plastics' => [
        'title' => 'لوحة تحكم',
        'last_updated' => 'آخر تحديث: :time',
        'actions' => [
            'open' => 'فتح',
        ],
        'context' => [
            'not_selected' => 'غير محدد',
            'period_dates' => ':from - :to',
        ],
        'sections' => [
            'operational_overview' => 'نظرة عامة تشغيلية',
            'product_master' => 'بيانات المنتجات والخامات',
            'purchasing' => 'المشتريات والموردون',
            'inventory' => 'المخازن والمستودعات',
            'sales' => 'المبيعات والعملاء',
            'follow_up' => 'المتابعة التشغيلية',
            'attention' => 'بحاجة إلى متابعة',
            'quick_actions' => 'إجراءات سريعة',
            'charts' => 'نظرة على البيانات',
        ],
        'metrics' => [
            'products' => [
                'title' => 'المنتجات النشطة',
                'meta' => 'سجلات المنتجات فقط',
            ],
            'raw_materials' => [
                'title' => 'الخامات النشطة',
                'meta' => 'سجلات الخامات فقط',
            ],
            'packaging_materials' => [
                'title' => 'مواد التعبئة والتغليف النشطة',
                'meta' => 'سجلات مواد التعبئة والتغليف فقط',
            ],
            'products_with_bom' => [
                'title' => 'منتجات لها مكونات',
                'meta' => ':without بدون مكونات',
            ],
            'bom_lines' => [
                'title' => 'سطور مكونات المنتجات',
                'meta' => 'سطور المكونات النشطة',
            ],
            'suppliers' => [
                'title' => 'الموردون النشطون',
                'meta' => 'سجلات موردي الشركة',
            ],
            'purchase_invoices' => [
                'title' => 'فواتير المشتريات',
                'meta' => ':count غير مدفوعة أو مدفوعة جزئيًا خلال الفترة',
            ],
            'purchase_requisitions' => [
                'title' => 'طلبات الشراء',
                'meta' => ':count طلبات بانتظار الاعتماد خلال الفترة',
            ],
            'purchase_orders' => [
                'title' => 'أوامر الشراء',
                'meta' => ':count مسودة خلال الفترة',
            ],
            'stores' => [
                'title' => 'مخازن الفرع',
                'meta' => 'سجلات مخازن الفرع الحالي',
            ],
            'opening_stocks' => [
                'title' => 'مستندات مخزون أول المدة',
                'meta' => ':count معتمد خلال الفترة',
            ],
            'unpriced_receipts' => [
                'title' => 'توريدات بانتظار التسعير',
                'meta' => 'توريدات مخزنية غير مسعرة خلال الفترة',
            ],
            'customers' => [
                'title' => 'العملاء النشطون',
                'meta' => 'سجلات عملاء الشركة',
            ],
            'quotations' => [
                'title' => 'عروض الأسعار',
                'meta' => ':count مقبول خلال الفترة',
            ],
            'my_tasks' => [
                'title' => 'مهامي المفتوحة',
                'meta' => ':count متأخرة',
            ],
            'team_tasks' => [
                'title' => 'مهام الفريق المفتوحة',
                'meta' => 'سجلات المهام النشطة الظاهرة',
            ],
        ],
        'charts' => [
            'product_types' => 'المنتجات والخامات ومواد التعبئة والتغليف حسب النوع',
            'bom_coverage' => 'تغطية مكونات المنتجات',
            'raw_material_units' => 'الخامات حسب الوحدة',
            'packaging_material_units' => 'مواد التعبئة والتغليف حسب الوحدة',
            'task_status' => 'المهام حسب الحالة',
        ],
        'chart_labels' => [
            'with_components' => 'لها مكونات',
            'without_components' => 'بدون مكونات',
            'unspecified' => 'غير محدد',
        ],
        'alerts' => [
            'products_without_components' => [
                'title' => 'تغطية المكونات',
                'body' => ':count منتجات نشطة لا تحتوي على سطور مكونات.',
            ],
            'unpriced_receipts' => [
                'title' => 'تسعير المخزون',
                'body' => ':count توريدات مخزنية ما زالت غير مسعرة.',
            ],
            'my_tasks_overdue' => [
                'title' => 'تواريخ المهام',
                'body' => ':count من مهامك المفتوحة متأخرة.',
            ],
        ],
        'quick_actions' => [
            'product' => 'منتج',
            'raw_material' => 'خامة',
            'packaging_material' => 'مادة تعبئة وتغليف',
            'products_report' => 'تقرير بيانات المنتجات',
            'task' => 'مهمة',
        ],
        'empty' => [
            'no_alerts' => 'لا توجد استثناءات مبنية على البيانات حاليًا.',
            'no_quick_actions' => 'لا توجد إجراءات سريعة مسموح بها.',
            'no_chart_data' => 'لا توجد بيانات متاحة حاليًا للرسوم البيانية.',
        ],
        'limitations' => [
            'context_required' => 'اختر شركة وفرعًا وفترة مالية لعرض بيانات لوحة التحكم.',
            'no_visible_metrics' => 'لا توجد مؤشرات لوحة تحكم ظاهرة لصلاحياتك الحالية.',
        ],
    ],
    'expanded' => [
        'title' => 'لوحة التحكم المؤسسية',
        'last_updated' => 'آخر تحديث: :time',
        'range_label' => 'من :from إلى :to',
        'currency_unspecified' => 'عملة غير محددة',
        'no_due_date' => 'بدون تاريخ استحقاق',
        'filters' => [
            'range' => 'النطاق',
            'date_from' => 'من',
            'date_to' => 'إلى',
        ],
        'ranges' => [
            'today' => 'اليوم',
            'last_7' => 'آخر 7 أيام',
            'last_30' => 'آخر 30 يومًا',
            'current_period' => 'الفترة الحالية',
            'custom' => 'مخصص',
        ],
        'actions' => [
            'refresh' => 'تحديث',
            'open' => 'فتح',
        ],
        'context' => [
            'not_selected' => 'غير محدد',
            'period_dates' => ':from - :to',
        ],
        'sections' => [
            'exceptions' => 'الاستثناءات التشغيلية',
            'quick_actions' => 'إجراءات سريعة',
        ],
        'empty' => [
            'no_alerts' => 'لا توجد استثناءات ظاهرة للسياق المحدد.',
            'no_quick_actions' => 'لا توجد إجراءات سريعة مسموح بها.',
            'no_recent_records' => 'لا توجد سجلات حديثة للسياق المحدد.',
        ],
        'limitations' => [
            'context_required' => 'اختر شركة وفرعًا وفترة مالية لعرض بيانات لوحة التحكم الموسعة.',
            'no_visible_widgets' => 'لا توجد عناصر لوحة تحكم ظاهرة لصلاحياتك الحالية.',
        ],
        'validation' => [
            'date_range_invalid' => 'أدخل نطاق تاريخ صالحًا للوحة التحكم.',
            'date_to_before_from' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساويًا له.',
        ],
        'kpis' => [
            'purchase_orders' => [
                'title' => 'أوامر الشراء',
                'meta' => ':count مسودة بانتظار الاعتماد',
            ],
            'purchase_invoices' => [
                'title' => 'فواتير المشتريات',
                'meta' => ':count غير مدفوعة أو مدفوعة جزئيًا',
            ],
            'suppliers' => [
                'title' => 'الموردون النشطون',
                'meta' => 'سجلات موردي الشركة',
            ],
            'unpriced_receipts' => [
                'title' => 'توريدات بانتظار التسعير',
                'meta' => 'توريدات كمية لم يتم تسعيرها',
            ],
            'opening_stock' => [
                'title' => 'مستندات مخزون أول المدة',
                'meta' => ':count معتمد',
            ],
            'branch_storage' => [
                'title' => 'مخازن الفرع',
                'meta' => ':count قاعات في الفرع الحالي',
            ],
            'bom_readiness' => [
                'title' => 'أصناف بلا مكونات',
                'meta' => 'أصناف تامة ونصف مصنعة',
            ],
            'raw_material_readiness' => [
                'title' => 'خامات بلا وحدات',
                'meta' => ':count بلا تصنيف أو بلد منشأ',
            ],
            'customers' => [
                'title' => 'العملاء النشطون',
                'meta' => 'سجلات عملاء الشركة',
            ],
            'quotations' => [
                'title' => 'عروض الأسعار',
                'meta' => ':count مقبول',
            ],
            'bank_accounts' => [
                'title' => 'حسابات بنكية نشطة',
                'meta' => 'عدد فقط وليس أرصدة',
            ],
            'cashboxes' => [
                'title' => 'خزائن نشطة',
                'meta' => 'عدد الفرع الحالي',
            ],
            'opening_balances' => [
                'title' => 'الأرصدة الافتتاحية',
                'meta' => ':count مسودة',
            ],
            'fixed_assets' => [
                'title' => 'أصول ثابتة نشطة',
                'meta' => ':count ينقصها إعداد الإهلاك',
            ],
            'my_tasks' => [
                'title' => 'مهامي المفتوحة',
                'meta' => ':overdue متأخرة، :due_soon قريبة الاستحقاق',
            ],
            'team_tasks' => [
                'title' => 'مهام الفريق المفتوحة',
                'meta' => 'عدد المهام حسب الصلاحية',
            ],
        ],
        'charts' => [
            'purchase_order_status' => 'حالات أوامر الشراء',
            'purchase_invoice_status' => 'حالات فواتير المشتريات',
            'quotation_status' => 'حالات عروض الأسعار',
        ],
        'alerts' => [
            'purchase_orders_awaiting_approval' => [
                'title' => 'اعتماد أوامر الشراء',
                'body' => ':count أوامر شراء ما زالت مسودة.',
            ],
            'purchase_orders_overdue' => [
                'title' => 'تسليم أوامر الشراء',
                'body' => ':count أوامر شراء معتمدة تجاوزت تاريخ التسليم المتوقع وبها كمية متبقية.',
            ],
            'unpriced_receipts' => [
                'title' => 'تسعير المخزون',
                'body' => ':count توريدات كمية ما زالت غير مسعرة.',
            ],
            'products_without_components' => [
                'title' => 'جاهزية الأصناف',
                'body' => ':count أصناف تامة أو نصف مصنعة بلا مكونات.',
            ],
            'raw_materials_missing_metadata' => [
                'title' => 'بيانات الخامات',
                'body' => ':units خامات بلا وحدة؛ :metadata بلا تصنيف أو بلد منشأ.',
            ],
            'factory_branches_without_storage' => [
                'title' => 'إعداد المصانع',
                'body' => ':count فروع مصنع نشطة بلا قاعات أو بلا مخازن.',
            ],
            'opening_balances_draft' => [
                'title' => 'الأرصدة الافتتاحية',
                'body' => ':count مستندات أرصدة افتتاحية ما زالت مسودة.',
            ],
            'assets_missing_depreciation' => [
                'title' => 'إعداد الأصول',
                'body' => ':count أصول نشطة قابلة للإهلاك ينقصها إعداد الإهلاك.',
            ],
            'my_tasks_overdue' => [
                'title' => 'تواريخ المهام',
                'body' => ':count من مهامك المفتوحة متأخرة.',
            ],
        ],
        'recent' => [
            'purchase_order_totals' => 'إجماليات أوامر الشراء حسب العملة',
            'purchase_invoice_totals' => 'إجماليات فواتير المشتريات حسب العملة',
            'purchase_orders' => 'أحدث أوامر الشراء',
            'purchase_invoices' => 'أحدث فواتير المشتريات',
            'unpriced_receipts' => 'أحدث التوريدات غير المسعرة',
            'quotations' => 'أحدث عروض الأسعار',
            'fixed_assets' => 'أحدث الأصول الثابتة',
            'my_tasks' => 'مهامي المستحقة',
        ],
        'quick_actions' => [
            'purchase_order' => 'أمر شراء',
            'purchase_invoice' => 'فاتورة مشتريات',
            'supplier' => 'مورد',
            'unpriced_receipt' => 'توريد غير مسعر',
            'customer' => 'عميل',
            'quotation' => 'عرض سعر',
            'bank_account' => 'حساب بنكي',
            'cashbox' => 'خزينة',
            'opening_balance' => 'رصيد افتتاحي',
            'fixed_asset' => 'أصل ثابت',
            'task' => 'مهمة',
        ],
        'sources' => [
            'purchase_orders' => 'أوامر الشراء',
            'purchase_invoices' => 'فواتير المشتريات',
            'suppliers' => 'الموردون',
            'unpriced_inventory_receipts' => 'توريدات مخزنية بدون أسعار',
            'opening_stock' => 'مخزون أول المدة',
            'branch_storage' => 'مخازن وقاعات الفروع',
            'product_components' => 'الأصناف والمكونات',
            'raw_materials' => 'الخامات',
            'factory_branches' => 'فروع المصانع',
            'customers' => 'العملاء',
            'quotations' => 'عروض الأسعار',
            'bank_accounts' => 'الحسابات البنكية',
            'cashboxes' => 'الخزائن',
            'opening_balances' => 'الأرصدة الافتتاحية',
            'fixed_assets' => 'الأصول الثابتة',
            'my_tasks' => 'مهام لوحتي',
            'team_tasks' => 'مهام فريق العمل',
        ],
    ],
];
