<?php

return [
    'bulk_action' => 'إجراء جماعي',
    'select_all' => 'تحديد كل السجلات',
    'selected_records' => 'السجلات المحددة',
    'defaults' => [
        'clone_name' => 'نسخة من :name',
    ],
    'document_number_control' => [
        'helper' => 'اتركه فارغًا للإنشاء التلقائي. سيتم تطبيق البادئة وعدد الخانات تلقائيًا.',
        'placeholder' => 'تلقائي',
    ],
    'fields' => [
        'name' => 'الاسم',
        'notes' => 'الملاحظات',
        'status' => 'الحالة',
    ],
    'titles' => [
        'clone' => 'نسخ السجل',
    ],
    'trash' => [
        'active' => 'السجلات الفعلية',
        'all' => 'كل السجلات',
        'filter_label' => 'السجلات',
        'restore' => 'استعادة',
        'restore_confirm_text' => 'هل أنت متأكد من استعادة هذا السجل؟',
        'restore_confirm_title' => 'استعادة السجل',
        'restore_confirm_yes' => 'نعم، استعادة',
        'trashed' => 'السجلات المحذوفة',
        'view_forbidden' => 'غير مسموح لك بعرض السجلات المحذوفة.',
    ],
    'statuses' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ],
    'messages' => [
        'action_forbidden' => 'ليس لديك صلاحية لاستخدام إجراء الحفظ هذا.',
        'bulk_deleted' => 'تم حذف :count سجل بنجاح.',
        'bulk_delete_confirm_text' => 'أنت على وشك حذف :count سجل.',
        'bulk_delete_confirm_title' => 'حذف السجلات المحددة؟',
        'bulk_delete_confirm_yes' => 'نعم، احذف المحدد',
        'clone_not_allowed' => 'لا يمكن نسخ هذا السجل.',
        'cloned' => 'تم نسخ السجل بنجاح.',
        'created' => 'تم إنشاء السجل بنجاح.',
        'delete_confirm_text' => 'لا يمكن التراجع عن هذا الإجراء.',
        'delete_confirm_title' => 'حذف السجل؟',
        'delete_confirm_yes' => 'نعم، احذف',
        'deleted' => 'تم حذف السجل بنجاح.',
        'no_rows_selected' => 'حدد سجلًا واحدًا على الأقل.',
        'restore_not_allowed' => 'لا يمكن استعادة هذا السجل.',
        'restore_conflict' => 'يوجد سجل نشط بنفس القيم.',
        'restored_successfully' => 'تم استعادة السجل بنجاح.',
        'updated' => 'تم تحديث السجل بنجاح.',
    ],
    'validation' => [
        'doc_number_numeric' => 'يجب أن يحتوي رقم المستند على أرقام فقط.',
        'doc_number_unique' => 'رقم المستند موجود بالفعل.',
        'name_unique' => 'الاسم موجود بالفعل.',
    ],
];
