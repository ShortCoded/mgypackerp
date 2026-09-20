<?php

return [
    'title' => 'أنواع الإجازات',
    'create' => 'إضافة نوع إجازة',
    'show' => 'تفاصيل نوع الإجازة',
    'trash_filters' => ['active' => 'السجلات النشطة', 'with' => 'كل السجلات', 'only' => 'السجلات المحذوفة'],
    'fields' => [
        'code' => 'الكود',
        'name' => 'الاسم',
        'payment_status' => 'المعاملة في الرواتب',
        'requires_balance' => 'يتطلب رصيد إجازات',
        'annual_entitlement_days' => 'الاستحقاق السنوي بالأيام',
        'carry_forward_max_days' => 'الحد الأقصى للترحيل',
        'status' => 'الحالة',
        'notes' => 'ملاحظات',
    ],
    'payment_statuses' => ['paid' => 'إجازة مدفوعة', 'unpaid' => 'إجازة غير مدفوعة'],
    'statuses' => ['active' => 'نشط', 'inactive' => 'غير نشط'],
    'messages' => [
        'created' => 'تمت إضافة نوع الإجازة.',
        'updated' => 'تم تحديث نوع الإجازة.',
        'deleted' => 'تم حذف نوع الإجازة.',
        'restored' => 'تمت استعادة نوع الإجازة.',
        'empty' => 'لا توجد أنواع إجازات مسجلة.',
    ],
];
