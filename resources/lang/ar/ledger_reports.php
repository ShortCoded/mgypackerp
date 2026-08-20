<?php

return [
    'types' => [
        'account_ledger' => 'كشف حساب الأستاذ',
        'customer_statement' => 'كشف حساب عميل',
        'supplier_statement' => 'كشف حساب مورد',
    ],
    'filters' => [
        'account_doc_num' => 'الحساب',
        'customer_doc_num' => 'العميل',
        'supplier_doc_num' => 'المورد',
        'from_date' => 'من تاريخ',
        'to_date' => 'إلى تاريخ',
        'branch' => 'الفرع',
        'cost_center' => 'مركز التكلفة',
        'all' => 'الكل',
    ],
    'actions' => ['run' => 'تشغيل التقرير'],
    'summary' => [
        'opening' => 'الرصيد الافتتاحي',
        'period' => 'حركة الفترة',
        'ending' => 'الرصيد الختامي',
    ],
    'columns' => [
        'date' => 'التاريخ',
        'source_type' => 'نوع المصدر',
        'document' => 'المستند',
        'reference' => 'المرجع',
        'description' => 'البيان',
        'cost_center' => 'مركز التكلفة',
        'branch' => 'الفرع',
        'debit' => 'مدين',
        'credit' => 'دائن',
        'running_debit' => 'الرصيد المدين',
        'running_credit' => 'الرصيد الدائن',
    ],
    'sources' => [
        'manual' => 'قيد يدوي',
        'opening_balance' => 'رصيد افتتاحي',
        'purchase_invoice' => 'فاتورة مشتريات',
    ],
    'audit' => [
        'generated_by' => 'أُنشئ بواسطة',
        'generated_at' => 'وقت الإنشاء',
    ],
    'messages' => [
        'posted_source_only' => 'يُحتسب هذا التقرير من سطور القيود المرحلة في شركة وفترة التشغيل الحاليتين.',
        'no_movements' => 'لا توجد حركات مرحلة تطابق عوامل التصفية المحددة.',
        'operating_context_required' => 'اختر شركة وفترة مالية للتشغيل أولاً.',
        'date_outside_period' => 'يجب أن يكون نطاق التقرير داخل الفترة المالية المحددة.',
        'filter_invalid' => 'عامل التصفية المحدد غير متاح في شركة التشغيل.',
        'partner_account_missing' => 'لا يملك طرف التعامل المحدد حساباً محاسبياً مرتبطاً.',
    ],
];
