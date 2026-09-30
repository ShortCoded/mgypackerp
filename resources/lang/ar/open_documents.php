<?php

return [
    'title' => 'فتح مستند',
    'fields' => [
        'document_type' => 'المستند',
        'from_number' => 'من رقم',
        'to_number' => 'إلى رقم',
        'reason' => 'سبب الفتح للمستندات المعتمدة',
    ],
    'actions' => [
        'open' => 'فتح',
    ],
    'documents' => [
        'opening_balances' => 'الأرصدة الافتتاحية',
        'opening_stocks' => 'مخزون أول المدة',
        'opening_stock_pricings' => 'تسعير مخزون أول المدة',
        'sales_orders' => 'أوامر البيع',
        'sales_requests' => 'طلبات المبيعات',
        'customer_invoices' => 'فواتير المبيعات',
        'purchase_orders' => 'أوامر الشراء',
        'purchase_requisitions' => 'طلبات الشراء',
        'production_material_requests' => 'طلبات خامات الإنتاج',
    ],
    'messages' => [
        'confirm' => 'هل تريد فتح المستندات المحددة؟',
        'opened' => 'تم فتح :count مستند بنجاح.',
        'none_reopenable' => 'لا توجد سندات يمكن فتحها.',
        'skipped_approved' => 'تم تخطي :count مستند معتمد.',
        'skipped_already_open' => 'تم تخطي :count مستند مفتوح بالفعل.',
        'skipped_deleted' => 'تم تخطي :count مستند محذوف.',
        'skipped_blocked' => 'تم تخطي :count مستند مرتبط بحركات لاحقة أو لا يمكن فتحه بأمان.',
        'not_found' => 'لم يتم العثور على :count رقم مستند في الفترة الحالية.',
    ],
    'validation' => [
        'from_lte_to' => 'يجب أن يكون رقم البداية أقل من أو يساوي رقم النهاية.',
        'invalid_document_type' => 'نوع المستند المحدد غير مدعوم.',
        'reason_required' => 'سبب الفتح مطلوب.',
        'range_too_large' => 'يمكن فتح :count مستندات بحد أقصى في المرة الواحدة.',
        'reopen_context_or_reason' => 'يلزم اختيار الفرع الصحيح وكتابة سبب فتح المستند.',
    ],
];
