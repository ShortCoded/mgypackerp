<?php

return [
    'self_service' => ['new_request' => 'طلب جديد', 'my_requests' => 'طلباتي', 'empty' => 'لم تقدم أي طلبات بعد.'],
    'admin' => ['title' => 'طلبات الموارد البشرية', 'empty' => 'لا توجد طلبات مطابقة.'],
    'types' => ['leave' => 'إجازة', 'attendance_adjustment' => 'تصحيح حضور أو انصراف', 'overtime' => 'عمل إضافي', 'remote_work' => 'عمل عن بُعد', 'salary_advance' => 'سلفة راتب', 'device_asset' => 'جهاز أو عهدة', 'employment_letter' => 'خطاب موارد بشرية', 'profile_update' => 'تعديل بياناتي', 'other' => 'طلب آخر'],
    'statuses' => ['submitted' => 'قيد المراجعة', 'approved' => 'مقبول', 'rejected' => 'مرفوض', 'cancelled' => 'ملغي'],
    'labels' => ['type' => 'نوع الطلب', 'subject' => 'عنوان مختصر', 'from' => 'من', 'to' => 'إلى', 'minutes' => 'عدد الدقائق', 'amount' => 'المبلغ', 'currency' => 'العملة', 'details' => 'تفاصيل الطلب', 'status' => 'الحالة', 'leave_type' => 'نوع الإجازة', 'requested_check_in' => 'الحضور المطلوب', 'requested_check_out' => 'الانصراف المطلوب', 'asset_type' => 'نوع الجهاز أو العهدة', 'letter_language' => 'لغة الخطاب', 'profile_field' => 'البيان المطلوب تعديله', 'profile_value' => 'القيمة الجديدة'],
    'letter_languages' => ['ar' => 'العربية', 'en' => 'الإنجليزية'],
    'placeholders' => ['type' => 'اختر نوع الطلب', 'currency' => 'اختر العملة', 'leave_type' => 'اختر نوع إجازة نشط', 'resolution_notes' => 'ملاحظات القرار (إلزامية عند الرفض)'],
    'balance' => ['year' => 'السنة', 'current' => 'الرصيد الحالي', 'pending' => 'قيد الاعتماد', 'available' => 'المتاح'],
    'actions' => ['submit' => 'إرسال الطلب', 'cancel' => 'إلغاء الطلب', 'approve' => 'موافقة', 'reject' => 'رفض'],
    'messages' => ['created' => 'تم إرسال الطلب للموارد البشرية.', 'cancelled' => 'تم إلغاء الطلب.', 'reviewed' => 'تم حفظ قرار الطلب.', 'not_owned' => 'هذا الطلب لا يخص حسابك.', 'cannot_cancel' => 'لا يمكن إلغاء الطلب بعد اتخاذ قرار فيه.', 'already_resolved' => 'تم اتخاذ قرار في هذا الطلب بالفعل.', 'self_review_not_allowed' => 'لا يمكنك الموافقة على طلبك أو رفضه بنفسك.', 'branch_scope_invalid' => 'هذا الطلب خارج نطاق الفروع المسموح لك بها.', 'leave_type_unavailable' => 'نوع الإجازة المحدد غير نشط.', 'overlapping_leave_request' => 'يوجد طلب نشط من نوع الإجازة نفسه يتداخل مع التواريخ المحددة.', 'leave_single_year' => 'يجب أن تقع الإجازة ذات الرصيد داخل سنة رصيد واحدة.', 'leave_range_too_long' => 'لا يمكن أن يتجاوز طلب الإجازة 366 يوماً تقويمياً.', 'no_chargeable_leave_days' => 'لا تحتوي التواريخ المحددة على أيام عمل قابلة للخصم.', 'insufficient_leave_balance' => 'الرصيد المتاح لا يكفي للتواريخ المطلوبة.'],
];
