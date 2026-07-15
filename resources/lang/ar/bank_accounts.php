<?php

return [
    'title' => 'حسابات البنوك', 'singular' => 'حساب بنكي', 'create' => 'إنشاء حساب بنكي', 'edit' => 'تعديل حساب بنكي', 'view' => 'عرض حساب بنكي', 'clone' => 'نسخ حساب بنكي',
    'attributes' => ['doc_number' => 'رقم المستند', 'doc_num' => 'رقم المستند', 'bank' => 'البنك', 'currency' => 'العملة', 'bank_name' => 'اسم البنك', 'account_name' => 'اسم الحساب', 'account_number' => 'رقم الحساب', 'iban' => 'IBAN', 'swift_code' => 'كود SWIFT', 'owner_name' => 'المالك', 'bank_branch_name' => 'فرع البنك', 'status' => 'الحالة', 'notes' => 'ملاحظات'],
    'actions' => ['add_bank' => 'إضافة بنك'],
    'messages' => ['created' => 'تم إنشاء الحساب البنكي بنجاح.', 'updated' => 'تم تحديث الحساب البنكي بنجاح.', 'deleted' => 'تم حذف الحساب البنكي بنجاح.', 'bulk_deleted' => 'تم حذف :count حساب بنكي بنجاح.', 'restored' => 'تم استعادة الحساب البنكي بنجاح.', 'bank_created' => 'تم إنشاء البنك بنجاح', 'account_outside_bank_accounts' => 'يجب اختيار بنك من شجرة الحسابات.', 'account_used' => 'يوجد حساب بنكي بنفس البيانات بالفعل.', 'doc_number_unique' => 'رقم المستند موجود بالفعل.', 'account_number_used' => 'رقم الحساب البنكي مستخدم بالفعل في حساب بنكي آخر.', 'iban_used' => 'رقم IBAN مستخدم بالفعل في حساب بنكي آخر.', 'currency_inactive' => 'يجب أن تكون العملة نشطة.', 'restore_conflict' => 'هذا الحساب البنكي مرتبط بالفعل بسجل نشط آخر.', 'linked_account_missing' => 'الحساب المحاسبي المرتبط غير موجود ولا يمكن استعادته تلقائياً.', 'main_bank_parent_missing' => 'حساب البنوك الرئيسي غير موجود في شجرة الحسابات.', 'child_account_failed' => 'تعذر إنشاء الحساب البنكي في شجرة الحسابات.', 'child_account_notice' => 'سيتم إنشاء حساب بنكي فرعي قابل للترحيل تحت البنك المحدد.'],
    'js' => ['bankCreated' => 'تم إنشاء البنك بنجاح'],
    'document_number_settings' => ['updated_successfully' => 'تم تحديث إعدادات رقم مستند حسابات البنوك بنجاح.'],
];
