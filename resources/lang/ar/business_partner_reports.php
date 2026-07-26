<?php

return [
    'customers' => [
        'title' => 'تقرير العملاء',
        'description' => 'الاستعلام عن البيانات الأساسية للعملاء المسجلين ضمن الشركة الحالية.',
        'table_title' => 'البيانات الأساسية للعملاء',
        'placeholders' => [
            'doc_num' => 'البحث برقم مستند العميل',
            'name' => 'البحث باسم العميل',
        ],
    ],
    'suppliers' => [
        'title' => 'تقرير الموردين',
        'description' => 'الاستعلام عن البيانات الأساسية للموردين المسجلين ضمن الشركة الحالية.',
        'table_title' => 'البيانات الأساسية للموردين',
        'placeholders' => [
            'doc_num' => 'البحث برقم مستند المورد',
            'name' => 'البحث باسم المورد',
        ],
    ],
    'actions' => [
        'toggle_filters' => 'فلاتر التقرير',
    ],
    'filter_groups' => [
        'identification' => 'تعريف الطرف',
        'accounting' => 'التصنيف والحسابات',
        'dates' => 'فلاتر التاريخ',
    ],
    'filters' => [
        'phone_or_mobile' => 'الهاتف أو الجوال',
        'created_from' => 'تاريخ الإنشاء من',
        'created_to' => 'تاريخ الإنشاء إلى',
    ],
    'placeholders' => [
        'phone' => 'البحث بالهاتف أو الجوال',
        'select_group' => 'اختر المجموعة',
        'select_account' => 'اختر الحساب المحاسبي',
    ],
    'pdf' => [
        'contact' => 'الهاتف / الجوال',
        'location' => 'الموقع',
    ],
];
