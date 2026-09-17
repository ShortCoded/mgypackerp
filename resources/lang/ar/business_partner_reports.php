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
        'geography' => 'الموقع الجغرافي وجودة البيانات',
        'dates' => 'فلاتر التاريخ',
    ],
    'filters' => [
        'phone_or_mobile' => 'الهاتف أو الجوال',
        'created_from' => 'تاريخ الإنشاء من',
        'created_to' => 'تاريخ الإنشاء إلى',
        'data_completeness' => 'اكتمال البيانات',
    ],
    'placeholders' => [
        'phone' => 'البحث بالهاتف أو الجوال',
        'select_group' => 'اختر المجموعة',
        'select_account' => 'اختر الحساب المحاسبي',
        'select_country' => 'اختر الدولة',
        'select_governorate' => 'اختر المحافظة',
        'select_city' => 'اختر المدينة',
        'select_area' => 'اختر المنطقة',
    ],
    'completeness' => [
        'complete' => 'سجل مكتمل ومرتبط',
        'any_issue' => 'أي مشكلة في جودة البيانات',
        'missing_location' => 'موقع جغرافي مرتبط غير مكتمل',
        'missing_address' => 'العنوان غير مسجل',
        'missing_contact' => 'بيانات الاتصال غير مسجلة',
        'legacy_unlinked_location' => 'موقع نصي قديم غير مرتبط',
    ],
    'pdf' => [
        'contact' => 'الهاتف / الجوال',
        'location' => 'الموقع',
    ],
];
