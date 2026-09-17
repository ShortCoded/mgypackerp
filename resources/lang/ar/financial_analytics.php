<?php

return [
    'types' => [
        'expense_analysis' => [
            'title' => 'تحليل المصروفات',
            'description' => 'تحليل المصروفات التشغيلية المرحلة حسب الحساب والتصنيف ومركز التكلفة والفرع والمستند المصدر والعملة.',
        ],
        'financial_ratios' => [
            'title' => 'النسب المالية',
            'description' => 'نسب محسوبة من المصدر الرسمي للقوائم المالية والقيود المرحلة.',
        ],
    ],
    'filters_title' => 'مرشحات التحليل',
    'filters' => [
        'from_date' => 'من تاريخ', 'to_date' => 'إلى تاريخ',
        'comparison_from_date' => 'المقارنة من', 'comparison_to_date' => 'المقارنة إلى',
        'view_mode' => 'طريقة العرض', 'account_doc_num' => 'حساب المصروف',
        'classification_code' => 'تصنيف المصروف', 'cost_center_doc_num' => 'مركز التكلفة',
        'branch_doc_num' => 'الفرع', 'source_type' => 'نوع المصدر',
        'source_doc_num' => 'المستند المصدر', 'currency_doc_num' => 'العملة الأصلية',
    ],
    'columns' => [
        'date' => 'التاريخ', 'journal' => 'قيد اليومية', 'source_document' => 'المستند المصدر',
        'account' => 'حساب المصروف', 'classification' => 'التصنيف', 'cost_center' => 'مركز التكلفة',
        'branch' => 'الفرع', 'currency' => 'العملة الأصلية', 'debit' => 'مدين', 'credit' => 'دائن',
        'amount_base' => 'المصروف بالعملة الأساسية', 'source_type' => 'نوع المصدر', 'ratio' => 'النسبة',
        'value' => 'قيمة الفترة الحالية', 'comparison' => 'قيمة فترة المقارنة', 'formula' => 'المعادلة',
        'numerator' => 'البسط', 'denominator' => 'المقام', 'status' => 'حالة الاحتساب',
    ],
    'ratios' => [
        'current_ratio' => 'نسبة التداول', 'gross_profit_margin' => 'هامش مجمل الربح',
        'net_profit_margin' => 'هامش صافي الربح', 'inventory_turnover' => 'معدل دوران المخزون',
    ],
    'formulas' => [
        'current_ratio' => 'الأصول المتداولة ÷ الالتزامات المتداولة',
        'gross_profit_margin' => 'مجمل الربح ÷ صافي الإيراد × 100',
        'net_profit_margin' => 'صافي الربح ÷ صافي الإيراد × 100',
        'inventory_turnover' => 'تكلفة المبيعات ÷ متوسط المخزون',
    ],
    'values' => [
        'summary' => 'ملخص', 'detail' => 'تفصيلي', 'manual' => 'قيد يدوي',
        'calculable' => 'تم الاحتساب', 'not_calculable' => 'غير قابل للاحتساب', 'unspecified' => 'غير محدد',
    ],
    'notices' => [
        'expense_posted_base_currency' => 'تشمل النتائج القيود المرحلة فقط، وتستبعد تحويلات إقفال الفترة. تُجمع الإجماليات بالعملة الأساسية حتى لا تختلط العملات الأصلية.',
        'ratios_no_benchmark' => 'لا يُصدر التقرير حكمًا بالجودة أو السوء. تظهر النسبة كغير قابلة للاحتساب عند غياب مكوّن مربوط أو عند انعدام المقام.',
    ],
    'comparison_total' => 'إجمالي فترة المقارنة بالعملة الأساسية',
    'no_results' => 'لا توجد بيانات محاسبية مرحلة مطابقة.',
];
