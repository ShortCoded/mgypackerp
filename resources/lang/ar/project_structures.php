<?php

return [
    'title' => 'هياكل المشاريع',
    'singular' => 'هيكل مشروع',
    'create' => 'إنشاء هيكل مشروع',
    'edit' => 'تعديل هيكل مشروع',
    'view' => 'عرض هيكل مشروع',
    'clone' => 'نسخ هيكل مشروع',
    'tree_view' => 'عرض الشجرة',
    'list_view' => 'عرض القائمة',
    'show_tree' => 'عرض الشجرة',
    'show_list' => 'عرض القائمة',
    'expand_all' => 'فتح الكل',
    'collapse_all' => 'طي الكل',
    'empty_tree' => 'لا توجد هياكل مشاريع.',
    'bulk_action' => 'إجراء جماعي',
    'select_all' => 'تحديد كل هياكل المشاريع',

    'attributes' => [
        'doc_number' => 'رقم المستند',
        'doc_num' => 'كود المستند',
        'name' => 'الاسم',
        'code' => 'الكود',
        'parent' => 'الأصل',
        'no_parent' => 'جذر',
        'status' => 'الحالة',
        'notes' => 'ملاحظات',
        'sort_order' => 'ترتيب العرض',
    ],

    'defaults' => [
        'clone_name' => ':name - نسخة',
    ],

    'statuses' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ],

    'actions' => [
        'expand_all' => 'فتح الكل',
        'collapse_all' => 'طي الكل',
        'expand_branch' => 'فتح الفرع',
        'collapse_branch' => 'طي الفرع',
    ],

    'trash' => [
        'filter_label' => 'السجلات',
        'active' => 'النشطة',
        'trashed' => 'المحذوفة',
        'all' => 'الكل',
        'restore' => 'استعادة',
    ],

    'messages' => [
        'created' => 'تم إنشاء هيكل المشروع بنجاح.',
        'cloned' => 'تم نسخ هيكل المشروع بنجاح.',
        'updated' => 'تم تحديث هيكل المشروع بنجاح.',
        'deleted' => 'تم حذف هيكل المشروع بنجاح.',
        'bulk_deleted' => 'تم حذف :count من هياكل المشاريع بنجاح.',
        'restored' => 'تم استعادة هيكل المشروع بنجاح.',
        'restore_not_allowed' => 'هيكل المشروع هذا غير محذوف.',
        'restore_conflict' => 'يتعارض هيكل المشروع هذا مع سجل نشط.',
        'parent_restore_unavailable' => 'استعد الأصل أولاً.',
        'delete_blocked_children' => 'احذف أو انقل الهياكل الفرعية قبل حذف هيكل المشروع.',
        'code_used' => 'هذا الكود مستخدم في هيكل مشروع نشط آخر.',
        'doc_number_unique' => 'رقم المستند مستخدم في هيكل مشروع نشط آخر.',
        'self_parent' => 'لا يمكن أن يكون هيكل المشروع أصلاً لنفسه.',
        'parent_cycle' => 'هذا الأصل سيؤدي إلى علاقة دائرية في هيكل المشروع.',
        'clone_not_allowed' => 'لا يمكن نسخ هيكل المشروع المحدد.',
        'action_forbidden' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
        'no_data_found' => 'لا توجد هياكل مشاريع.',
        'delete_confirm_title' => 'حذف هيكل المشروع؟',
        'delete_confirm_text' => 'سيتم نقل هيكل المشروع إلى السجلات المحذوفة.',
        'delete_confirm_yes' => 'نعم، احذفه',
        'bulk_delete_confirm_title' => 'حذف هياكل المشاريع المحددة؟',
        'bulk_delete_confirm_text' => 'أنت على وشك حذف :count من هياكل المشاريع.',
        'bulk_delete_confirm_yes' => 'نعم، احذف المحدد',
        'restore_confirm_title' => 'استعادة هيكل المشروع؟',
        'restore_confirm_text' => 'سيتم استعادة هيكل المشروع إلى السجلات النشطة.',
        'restore_confirm_yes' => 'نعم، استعده',
    ],

    'document_number_settings' => [
        'updated_successfully' => 'تم تحديث إعدادات رقم مستند هياكل المشاريع بنجاح.',
    ],
];
