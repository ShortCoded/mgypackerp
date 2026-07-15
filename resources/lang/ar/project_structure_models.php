<?php

return [
    'title' => 'نماذج الهيكل',
    'singular' => 'نموذج هيكل',
    'create' => 'إنشاء نموذج هيكل',
    'edit' => 'تعديل نموذج هيكل',
    'view' => 'عرض نموذج هيكل',
    'clone' => 'نسخ نموذج هيكل',
    'bulk_action' => 'إجراء جماعي',
    'select_all' => 'تحديد كل نماذج الهيكل',

    'attributes' => [
        'doc_number' => 'رقم المستند',
        'doc_num' => 'كود المستند',
        'name' => 'الاسم',
        'code' => 'الكود',
        'short_name' => 'الاسم المختصر',
        'status' => 'الحالة',
        'notes' => 'ملاحظات',
    ],

    'defaults' => [
        'clone_name' => ':name - نسخة',
    ],

    'statuses' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ],

    'trash' => [
        'filter_label' => 'السجلات',
        'active' => 'النشطة',
        'trashed' => 'المحذوفة',
        'all' => 'الكل',
        'restore' => 'استعادة',
    ],

    'messages' => [
        'created' => 'تم إنشاء نموذج الهيكل بنجاح.',
        'cloned' => 'تم نسخ نموذج الهيكل بنجاح.',
        'updated' => 'تم تحديث نموذج الهيكل بنجاح.',
        'deleted' => 'تم حذف نموذج الهيكل بنجاح.',
        'bulk_deleted' => 'تم حذف :count من نماذج الهيكل بنجاح.',
        'restored' => 'تم استعادة نموذج الهيكل بنجاح.',
        'restore_not_allowed' => 'نموذج الهيكل هذا غير محذوف.',
        'restore_conflict' => 'يتعارض نموذج الهيكل هذا مع سجل نشط.',
        'code_used' => 'هذا الكود مستخدم في نموذج هيكل نشط آخر.',
        'short_name_used' => 'هذا الاسم المختصر مستخدم في نموذج هيكل نشط آخر.',
        'doc_number_unique' => 'رقم المستند مستخدم في نموذج هيكل نشط آخر.',
        'clone_not_allowed' => 'لا يمكن نسخ نموذج الهيكل المحدد.',
        'action_forbidden' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
        'delete_confirm_title' => 'حذف نموذج الهيكل؟',
        'delete_confirm_text' => 'سيتم نقل نموذج الهيكل إلى السجلات المحذوفة.',
        'delete_confirm_yes' => 'نعم، احذفه',
        'bulk_delete_confirm_title' => 'حذف نماذج الهيكل المحددة؟',
        'bulk_delete_confirm_text' => 'أنت على وشك حذف :count من نماذج الهيكل.',
        'bulk_delete_confirm_yes' => 'نعم، احذف المحدد',
        'restore_confirm_title' => 'استعادة نموذج الهيكل؟',
        'restore_confirm_text' => 'سيتم استعادة نموذج الهيكل إلى السجلات النشطة.',
        'restore_confirm_yes' => 'نعم، استعده',
    ],

    'document_number_settings' => [
        'updated_successfully' => 'تم تحديث إعدادات رقم مستند نماذج الهيكل بنجاح.',
    ],
];
