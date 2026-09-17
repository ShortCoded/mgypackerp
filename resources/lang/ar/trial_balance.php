<?php

return [
    'title' => 'ميزان المراجعة',
    'actions' => [
        'run' => 'تشغيل التقرير',
    ],
    'filters' => [
        'from_date' => 'من تاريخ',
        'to_date' => 'إلى تاريخ',
        'branch' => 'الفرع',
        'cost_center' => 'مركز التكلفة',
        'include_zero' => 'إظهار الحسابات بلا رصيد أو حركة',
        'all' => 'الكل',
    ],
    'columns' => [
        'account_code' => 'كود الحساب',
        'account_name' => 'اسم الحساب',
        'status' => 'الحالة',
        'opening' => 'الرصيد الافتتاحي',
        'period' => 'حركة الفترة',
        'ending' => 'الرصيد الختامي',
        'opening_debit' => 'مدين',
        'opening_credit' => 'دائن',
        'period_debit' => 'مدين',
        'period_credit' => 'دائن',
        'ending_debit' => 'مدين',
        'ending_credit' => 'دائن',
    ],
    'status' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط / تاريخي',
    ],
    'audit' => [
        'generated_by' => 'أُنشئ بواسطة',
        'generated_at' => 'وقت الإنشاء',
    ],
    'messages' => [
        'posted_source_only' => 'يُحتسب حصراً من سطور القيود المرحلة في شركة التشغيل، ويشمل الرصيد الافتتاحي كل القيود المرحلة السابقة لبداية النطاق.',
        'balanced' => 'متوازن',
        'unbalanced' => 'غير متوازن',
        'no_accounts' => 'لا توجد أرصدة أو حركات مرحلة تطابق عوامل التصفية المحددة.',
        'operating_context_required' => 'اختر شركة وفترة مالية للتشغيل أولاً.',
        'date_outside_period' => 'يجب أن يكون نطاق التقرير داخل الفترة المالية المحددة.',
        'filter_invalid' => 'عامل التصفية المحدد غير متاح في شركة التشغيل.',
    ],
    'total' => 'الإجمالي العام',
];
