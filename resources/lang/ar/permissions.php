<?php

$hrLookupPermissionLabels = [];

foreach ([
    'hr.countries' => ['الدول', 'الدولة'],
    'hr.governorates' => ['المحافظات', 'المحافظة'],
    'hr.cities' => ['المدن', 'المدينة'],
    'hr.areas' => ['المناطق', 'المنطقة'],
    'hr.nationalities' => ['الجنسيات', 'الجنسية'],
    'hr.religions' => ['الديانات', 'الديانة'],
    'hr.qualifications' => ['المؤهلات', 'المؤهل'],
    'hr.universities' => ['الجامعات', 'الجامعة'],
    'hr.faculties' => ['الكليات', 'الكلية'],
    'hr.specializations' => ['التخصصات', 'التخصص'],
    'hr.military_services' => ['الموقف من التجنيد', 'موقف التجنيد'],
    'hr.allowances' => ['البدلات', 'البدل'],
    'hr.hiring_statuses' => ['حالات التعيين', 'حالة التعيين'],
    'hr.identifications' => ['الهويات', 'الهوية'],
] as $prefix => [$plural, $singular]) {
    $hrLookupPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $hrLookupPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $hrLookupPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $hrLookupPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $hrLookupPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $hrLookupPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $hrLookupPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $hrLookupPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $hrLookupPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$hrFoundationPermissionLabels = [];

foreach ([
    'hr.departments' => ['الإدارات', 'إدارة'],
    'hr.sections' => ['الأقسام', 'قسم'],
    'hr.jobs' => ['المسميات الوظيفية', 'مسمى وظيفي'],
    'hr.grades' => ['الدرجات الوظيفية', 'درجة وظيفية'],
    'hr.employment_types' => ['أنواع التوظيف', 'نوع توظيف'],
    'hr.document_types' => ['أنواع مستندات الموظفين', 'نوع مستند موظف'],
    'hr.shifts' => ['الورديات', 'وردية'],
    'hr.biometric_devices' => ['أجهزة البصمة', 'جهاز بصمة'],
    'hr.insurance_offices' => ['مكاتب التأمين', 'مكتب تأمين'],
] as $prefix => [$plural, $singular]) {
    $hrFoundationPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $hrFoundationPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $hrFoundationPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$hrOrgStructurePermissionExtras = [];

$hrEmployeePermissionLabels = [
    'hr.employees.view' => 'عرض الموظفين',
    'hr.employees.create' => 'إنشاء الموظفين',
    'hr.employees.edit' => 'تعديل الموظفين',
    'hr.employees.delete' => 'حذف الموظفين',
    'hr.employees.clone' => 'نسخ الموظفين',
    'hr.employees.view_trashed' => 'عرض السجلات المحذوفة',
    'hr.employees.restore' => 'استعادة الموظفين',
    'hr.employees.document_number.control' => 'التحكم في رقم مستند الموظف',
    'hr.employees.document_number_settings.update' => 'تحديث إعدادات رقم مستند الموظف',
    'hr.employees.documents.view' => 'عرض مستندات الموظف',
    'hr.employees.documents.manage' => 'إدارة مستندات الموظف',
    'hr.employees.documents.delete' => 'حذف مستندات الموظف',
];

$costCenterPermissionLabels = [];

foreach (['cost_centers' => ['مراكز التكلفة', 'مركز تكلفة']] as $prefix => [$plural, $singular]) {
    $costCenterPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $costCenterPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $costCenterPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $costCenterPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $costCenterPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $costCenterPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $costCenterPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $costCenterPermissionLabels["{$prefix}.print"] = "طباعة {$plural}";
    $costCenterPermissionLabels["{$prefix}.export"] = "تصدير {$plural}";
    $costCenterPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $costCenterPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$customerPermissionLabels = [];

foreach (['customers' => ['العملاء', 'العميل']] as $prefix => [$plural, $singular]) {
    $customerPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $customerPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $customerPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $customerPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $customerPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $customerPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $customerPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $customerPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $customerPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$quotationPermissionLabels = [
    'quotations.view' => 'عرض عروض الأسعار',
    'quotations.create' => 'إنشاء عروض الأسعار',
    'quotations.clone' => 'نسخ عروض الأسعار',
    'quotations.edit' => 'تعديل عروض الأسعار',
    'quotations.delete' => 'حذف عروض الأسعار',
    'quotations.view_trashed' => 'عرض عروض الأسعار المحذوفة',
    'quotations.restore' => 'استعادة عروض الأسعار',
    'quotations.document_number.control' => 'التحكم في رقم مستند عرض السعر',
    'quotations.document_number_settings.update' => 'تحديث إعدادات رقم مستند عرض السعر',
    'quotations.revisions.view' => 'عرض مراجعات عروض الأسعار',
    'quotations.revisions.create' => 'إنشاء مراجعات عروض الأسعار',
    'quotations.mark_sent' => 'تعليم عروض الأسعار كمرسلة',
    'quotations.accept' => 'قبول عروض الأسعار',
    'quotations.reject' => 'رفض عروض الأسعار',
    'quotations.cancel' => 'إلغاء عروض الأسعار',
    'quotations.print' => 'طباعة عروض الأسعار',
    'quotations.attachments.manage' => 'إدارة مرفقات عروض الأسعار',
];

$projectStructurePermissionLabels = [];

foreach ([
    'project_structures' => ['هياكل المشاريع', 'هيكل المشروع'],
    'project_structure_models' => ['نماذج الهيكل', 'نموذج الهيكل'],
] as $prefix => [$plural, $singular]) {
    $projectStructurePermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $projectStructurePermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $projectStructurePermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $projectStructurePermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $projectStructurePermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $projectStructurePermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $projectStructurePermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $projectStructurePermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $projectStructurePermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$projectStructurePermissionLabels['project_structures.tree.view'] = 'عرض شجرة هياكل المشاريع';
$projectStructurePermissionLabels['project_structures.tree.manage'] = 'إدارة شجرة هياكل المشاريع';

$supplierPermissionLabels = [];

foreach (['suppliers' => ['الموردين', 'المورد']] as $prefix => [$plural, $singular]) {
    $supplierPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $supplierPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $supplierPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $supplierPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $supplierPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $supplierPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $supplierPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $supplierPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $supplierPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

foreach ([
    'purchase_orders' => ['أوامر الشراء', 'أمر الشراء'],
    'purchase_invoices' => ['فواتير المشتريات', 'فاتورة المشتريات'],
] as $prefix => [$plural, $singular]) {
    $supplierPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $supplierPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $supplierPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $supplierPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $supplierPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $supplierPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $supplierPermissionLabels["{$prefix}.approve"] = "اعتماد {$plural}";
    $supplierPermissionLabels["{$prefix}.close"] = "إغلاق {$plural}";
    $supplierPermissionLabels["{$prefix}.cancel"] = "إلغاء {$plural}";
    $supplierPermissionLabels["{$prefix}.print"] = "طباعة {$plural}";
    $supplierPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $supplierPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$supplierPermissionLabels['purchase_invoices.clone'] = 'نسخ فواتير المشتريات';

$productPermissionLabels = [];

foreach ([
    'products' => ['المنتجات', 'المنتج'],
    'raw_materials' => ['الخامات', 'الخامة'],
    'packaging_materials' => ['مواد التعبئة والتغليف', 'مادة التعبئة والتغليف'],
] as $prefix => [$plural, $singular]) {
    $productPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $productPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $productPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $productPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $productPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $productPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $productPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $productPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $productPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$reportPermissionLabels = [
    'reports.products_data.view' => 'عرض تقرير بيانات المنتجات والخامات',
    'reports.products_data.export' => 'تصدير تقرير بيانات المنتجات والخامات',
    'reports.products_data.pdf' => 'تصدير PDF لتقرير بيانات المنتجات والخامات',
    'reports.customers.view' => 'عرض تقرير العملاء',
    'reports.customers.export' => 'تصدير تقرير العملاء',
    'reports.customers.pdf' => 'تصدير PDF لتقرير العملاء',
    'reports.suppliers.view' => 'عرض تقرير الموردين',
    'reports.suppliers.export' => 'تصدير تقرير الموردين',
    'reports.suppliers.pdf' => 'تصدير PDF لتقرير الموردين',
];

$accountPermissionLabels = [
    'accounts.view' => 'عرض الحسابات',
    'accounts.create' => 'إنشاء الحسابات',
    'accounts.clone' => 'نسخ الحسابات',
    'accounts.edit' => 'تعديل الحسابات',
    'accounts.delete' => 'حذف الحسابات',
    'accounts.view_trashed' => 'عرض الحسابات المحذوفة',
    'accounts.restore' => 'استعادة الحسابات',
    'accounts.export' => 'تصدير الحسابات',
    'accounts.document_number.control' => 'التحكم في رقم مستند الحساب',
    'accounts.document_number_settings.update' => 'تحديث إعدادات رقم مستند الحساب',
    'accounts.account_code.control' => 'التحكم في كود الحساب',
];

$financePermissionLabels = [];

foreach ([
    'currencies' => ['العملات', 'العملة'],
    'bank_accounts' => ['حسابات البنوك', 'حساب البنك'],
    'cashboxes' => ['الخزائن', 'الخزنة'],
    'cash_receipt_vouchers' => ['سندات استلام نقدية', 'سند استلام نقدية'],
    'cash_payment_vouchers' => ['سندات صرف نقدية', 'سند صرف نقدية'],
    'cheques' => ['الشيكات', 'الشيك'],
    'fund_transfers' => ['التحويلات بين الخزائن والبنوك', 'تحويل بين الخزائن والبنوك'],
    'opening_balances' => ['الأرصدة الافتتاحية', 'الرصيد الافتتاحي'],
] as $prefix => [$plural, $singular]) {
    $financePermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $financePermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $financePermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $financePermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $financePermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $financePermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $financePermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $financePermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $financePermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$financePermissionLabels['opening_balances.approve'] = 'اعتماد الرصيد الافتتاحي';
$financePermissionLabels['opening_balances.cancel'] = 'إلغاء اعتماد الرصيد الافتتاحي';
$financePermissionLabels['cash_receipt_vouchers.approve'] = 'اعتماد سند استلام نقدية';
$financePermissionLabels['cash_receipt_vouchers.cancel'] = 'إلغاء سند استلام نقدية';
$financePermissionLabels['cash_receipt_vouchers.print'] = 'طباعة سند استلام نقدية';
$financePermissionLabels['cash_payment_vouchers.approve'] = 'اعتماد سند صرف نقدية';
$financePermissionLabels['cash_payment_vouchers.cancel'] = 'إلغاء سند صرف نقدية';
$financePermissionLabels['cash_payment_vouchers.print'] = 'طباعة سند صرف نقدية';
$financePermissionLabels['cheques.mark_deposited'] = 'تعليم الشيكات الواردة كمودعة';
$financePermissionLabels['cheques.mark_collected'] = 'تعليم الشيكات الواردة كمحصلة';
$financePermissionLabels['cheques.mark_returned'] = 'تعليم الشيكات كمرتجعة';
$financePermissionLabels['cheques.mark_issued'] = 'تعليم الشيكات الصادرة كمصدرة';
$financePermissionLabels['cheques.mark_delivered'] = 'تعليم الشيكات الصادرة كمسلمة';
$financePermissionLabels['cheques.mark_cleared'] = 'تعليم الشيكات الصادرة كمخالصة';
$financePermissionLabels['cheques.cancel'] = 'إلغاء الشيكات';
$financePermissionLabels['cheques.print'] = 'طباعة الشيكات';
$financePermissionLabels['fund_transfers.approve'] = 'اعتماد التحويلات بين الخزائن والبنوك';
$financePermissionLabels['fund_transfers.cancel'] = 'إلغاء التحويلات بين الخزائن والبنوك';
$financePermissionLabels['fund_transfers.print'] = 'طباعة التحويلات بين الخزائن والبنوك';
$financePermissionLabels['outgoing_payable_cheques.print'] = 'حجز الأرقام وطباعة الشيكات';

$fixedAssetPermissionLabels = [];

foreach (['fixed_assets' => ['الأصول الثابتة', 'الأصل الثابت']] as $prefix => [$plural, $singular]) {
    $fixedAssetPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $fixedAssetPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $fixedAssetPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$inventoryPermissionLabels = [];

foreach ([
    'inventory.opening_stocks' => ['مخزون أول المدة', 'مستند مخزون أول المدة'],
    'inventory.opening_stock_pricings' => ['تسعير مخزون أول المدة', 'مستند تسعير مخزون أول المدة'],
] as $prefix => [$plural, $singular]) {
    $inventoryPermissionLabels["{$prefix}.view"] = "عرض {$plural}";
    $inventoryPermissionLabels["{$prefix}.create"] = "إنشاء {$plural}";
    $inventoryPermissionLabels["{$prefix}.clone"] = "نسخ {$plural}";
    $inventoryPermissionLabels["{$prefix}.edit"] = "تعديل {$plural}";
    $inventoryPermissionLabels["{$prefix}.delete"] = "حذف {$plural}";
    $inventoryPermissionLabels["{$prefix}.view_trashed"] = "عرض {$plural} المحذوفة";
    $inventoryPermissionLabels["{$prefix}.restore"] = "استعادة {$plural}";
    $inventoryPermissionLabels["{$prefix}.document_number.control"] = "التحكم في رقم مستند {$singular}";
    $inventoryPermissionLabels["{$prefix}.document_number_settings.update"] = "تحديث إعدادات رقم مستند {$singular}";
}

$inventoryPermissionLabels['inventory.opening_stocks.approve'] = 'اعتماد مخزون أول المدة';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.view'] = 'عرض توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.create'] = 'إنشاء توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.edit'] = 'تعديل توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.delete'] = 'حذف توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.view_trashed'] = 'عرض توريد مخزني بدون أسعار المحذوف';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.restore'] = 'استعادة توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.approve'] = 'اعتماد توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.close'] = 'إغلاق توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.cancel'] = 'إلغاء توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.document_number.control'] = 'التحكم في رقم مستند توريد مخزني بدون أسعار';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.document_number_settings.update'] = 'تحديث إعدادات رقم مستند توريد مخزني بدون أسعار';

return [
    'activity.logs.details' => 'عرض تفاصيل سجل النشاط',
    'activity.logs.export' => 'تصدير سجلات النشاط',
    'activity.logs.pdf' => 'تصدير سجلات النشاط PDF',
    'activity.logs.view' => 'عرض سجلات النشاط',
    'auth.logs.details' => 'عرض تفاصيل حركات الدخول',
    'auth.logs.export' => 'تصدير حركات الدخول',
    'auth.logs.pdf' => 'تصدير حركات الدخول PDF',
    'auth.logs.view' => 'عرض حركات الدخول',
    'auth.sessions.details' => 'عرض تفاصيل الجلسات النشطة',
    'auth.sessions.export' => 'تصدير الجلسات النشطة',
    'auth.sessions.force_logout' => 'إنهاء الجلسات إجباريًا',
    'auth.sessions.pdf' => 'تصدير الجلسات النشطة PDF',
    'auth.sessions.view' => 'عرض الجلسات النشطة',
    'calendar.complete' => 'إكمال أحداث التقويم',
    'calendar.create' => 'إنشاء أحداث التقويم',
    'calendar.delete' => 'حذف أحداث التقويم',
    'calendar.edit' => 'تعديل أحداث التقويم',
    'calendar.view' => 'عرض التقويم',
    'branches.clone' => 'نسخ الفروع',
    'branches.create' => 'إنشاء الفروع',
    'branches.delete' => 'حذف الفروع',
    'branches.document_number.control' => 'التحكم في رقم مستند الفرع',
    'branches.document_number_settings.update' => 'تحديث إعدادات رقم مستند الفرع',
    'branches.edit' => 'تعديل الفروع',
    'branches.restore' => 'استعادة الفروع',
    'branches.view' => 'عرض الفروع',
    'branches.view_trashed' => 'عرض الفروع المحذوفة',
    'companies.clone' => 'نسخ الشركات',
    'companies.create' => 'إنشاء الشركات',
    'companies.delete' => 'حذف الشركات',
    'companies.document_number.control' => 'التحكم في رقم مستند الشركة',
    'companies.document_number_settings.update' => 'تحديث إعدادات رقم مستند الشركة',
    'companies.edit' => 'تعديل الشركات',
    'companies.files.delete' => 'حذف ملفات الشركة',
    'companies.files.download' => 'تحميل ملفات الشركة',
    'companies.files.folders.create' => 'إنشاء مجلدات ملفات الشركة',
    'companies.files.folders.delete' => 'حذف مجلدات ملفات الشركة',
    'companies.files.folders.rename' => 'إعادة تسمية مجلدات ملفات الشركة',
    'companies.files.public_links.create' => 'إنشاء رابط عام لملفات الشركة',
    'companies.files.public_links.revoke' => 'إلغاء رابط عام لملفات الشركة',
    'companies.files.public_links.view' => 'عرض رابط عام لملفات الشركة',
    'companies.files.upload' => 'رفع ملفات الشركة',
    'companies.files.view' => 'عرض ملفات الشركة',
    'companies.main.control' => 'التحكم في الشركة الرئيسية',
    'companies.restore' => 'استعادة الشركات',
    'companies.view' => 'عرض الشركات',
    'companies.view_trashed' => 'عرض الشركات المحذوفة',
    'dashboard.view' => 'عرض لوحة التحكم',
    'file_manager.delete' => 'حذف الملفات',
    'file_manager.document_number_settings.update' => 'تحديث إعدادات رقم مستند مدير الملفات',
    'file_manager.download' => 'تحميل الملفات',
    'file_manager.move' => 'نقل الملفات والمجلدات',
    'file_manager.folders.create' => 'إنشاء المجلدات',
    'file_manager.folders.delete' => 'حذف المجلدات',
    'file_manager.folders.rename' => 'إعادة تسمية المجلدات',
    'file_manager.public_links.create' => 'إنشاء رابط عام',
    'file_manager.public_links.revoke' => 'إلغاء الرابط العام',
    'file_manager.public_links.view' => 'عرض الرابط العام',
    'file_manager.update_picker_visibility' => 'تحديث الظهور في نافذة الاختيار',
    'file_manager.upload' => 'رفع الملفات',
    'file_manager.view' => 'عرض مدير الملفات',
    'financial_periods.clone' => 'نسخ الفترات المالية',
    'financial_periods.create' => 'إنشاء الفترات المالية',
    'financial_periods.delete' => 'حذف الفترات المالية',
    'financial_periods.document_number.control' => 'التحكم في رقم مستند الفترة المالية',
    'financial_periods.document_number_settings.update' => 'تحديث إعدادات رقم مستند الفترة المالية',
    'financial_periods.edit' => 'تعديل الفترات المالية',
    'financial_periods.restore' => 'استعادة الفترات المالية',
    'financial_periods.view' => 'عرض الفترات المالية',
    'financial_periods.view_trashed' => 'عرض الفترات المالية المحذوفة',
    ...$hrLookupPermissionLabels,
    ...$hrFoundationPermissionLabels,
    ...$hrOrgStructurePermissionExtras,
    ...$hrEmployeePermissionLabels,
    ...$costCenterPermissionLabels,
    ...$customerPermissionLabels,
    ...$quotationPermissionLabels,
    ...$projectStructurePermissionLabels,
    ...$supplierPermissionLabels,
    ...$productPermissionLabels,
    ...$reportPermissionLabels,
    ...$accountPermissionLabels,
    ...$financePermissionLabels,
    ...$fixedAssetPermissionLabels,
    ...$inventoryPermissionLabels,
    'my_board.create' => 'إنشاء عناصر لوحتي',
    'my_board.delete' => 'حذف عناصر لوحتي',
    'my_board.edit' => 'تعديل عناصر لوحتي',
    'my_board.clone' => 'نسخ عناصر لوحتي',
    'my_board.assign' => 'إسناد مهام لوحتي',
    'my_board.comments.create' => 'إنشاء تعليقات لوحتي',
    'my_board.comments.delete' => 'حذف تعليقات لوحتي',
    'my_board.lists.create' => 'إنشاء قوائم لوحتي',
    'my_board.lists.delete' => 'حذف قوائم لوحتي',
    'my_board.lists.edit' => 'تعديل قوائم لوحتي',
    'my_board.lists.reorder' => 'ترتيب قوائم لوحتي',
    'my_board.manage_any' => 'إدارة لوحة أي مستخدم',
    'my_board.notes.view_all' => 'عرض كل ملاحظات لوحتي',
    'my_board.reorder' => 'ترتيب عناصر لوحتي',
    'my_board.restore' => 'استعادة عناصر لوحتي',
    'my_board.tasks.view_all' => 'عرض كل مهام لوحتي',
    'my_board.view' => 'عرض لوحتي',
    'my_board.view_any' => 'عرض لوحة أي مستخدم',
    'my_board.view_trashed' => 'عرض عناصر لوحتي المحذوفة',
    'quick_tasks.board' => 'عرض شاشة عرض المهام',
    'quick_tasks.change_status' => 'تغيير حالة المهام السريعة',
    'quick_tasks.create' => 'إنشاء المهام السريعة',
    'quick_tasks.delete' => 'حذف المهام السريعة',
    'quick_tasks.manage_attachments' => 'إدارة مرفقات المهام السريعة',
    'quick_tasks.mark_done' => 'تعليم المهام السريعة كمنتهية',
    'quick_tasks.mark_ready' => 'تعليم المهام السريعة كجاهزة',
    'quick_tasks.restore' => 'استعادة المهام السريعة',
    'quick_tasks.start' => 'بدء المهام السريعة',
    'quick_tasks.update' => 'تعديل المهام السريعة',
    'quick_tasks.view' => 'عرض المهام السريعة',
    'task_boards.create' => 'إنشاء لوحات المهام',
    'task_boards.delete' => 'حذف لوحات المهام',
    'task_boards.display' => 'فتح رابط شاشة عرض لوحة المهام',
    'task_boards.view_trashed' => 'عرض لوحات المهام المحذوفة',
    'task_boards.restore' => 'استعادة لوحات المهام',
    'task_boards.bulk_activate' => 'تفعيل لوحات المهام المحددة',
    'task_boards.bulk_deactivate' => 'إلغاء تفعيل لوحات المهام المحددة',
    'task_boards.public_settings' => 'إدارة إعدادات العرض العام للوحة المهام',
    'task_boards.regenerate_public_url' => 'إعادة توليد رابط شاشة عرض لوحة المهام',
    'task_boards.update' => 'تعديل لوحات المهام',
    'task_boards.view' => 'عرض لوحات المهام',
    'permissions.create' => 'إنشاء الصلاحيات',
    'permissions.delete' => 'حذف الصلاحيات',
    'permissions.edit' => 'تعديل الصلاحيات',
    'permissions.view' => 'عرض الصلاحيات',
    'profile.delete' => 'حذف الحساب',
    'profile.edit' => 'تعديل الملف الشخصي',
    'profile.auth_logs.view' => 'عرض نشاط دخول الملف الشخصي',
    'profile.password.update' => 'تحديث كلمة المرور',
    'profile.sessions.view' => 'عرض جلسات الملف الشخصي',
    'profile.view' => 'عرض الملف الشخصي',
    'settings.pwa.update' => 'تحديث إعدادات تطبيق الويب',
    'settings.pwa.view' => 'عرض إعدادات تطبيق الويب',
    'tools.open_documents.execute' => 'تنفيذ فتح مستند',
    'tools.open_documents.view' => 'عرض فتح مستند',
    'roles.clone' => 'نسخ مجموعات المستخدمين',
    'roles.create' => 'إنشاء مجموعات المستخدمين',
    'roles.delete' => 'حذف مجموعات المستخدمين',
    'roles.document_number.control' => 'التحكم في رقم مستند مجموعة المستخدمين',
    'roles.document_number_settings.update' => 'تحديث إعدادات رقم مستند مجموعة المستخدمين',
    'roles.edit' => 'تعديل مجموعات المستخدمين',
    'roles.operating_scope.manage' => 'إدارة نطاق تشغيل مجموعات المستخدمين',
    'roles.restore' => 'استعادة مجموعات المستخدمين',
    'roles.view' => 'عرض مجموعات المستخدمين',
    'roles.view_trashed' => 'عرض مجموعات المستخدمين المحذوفة',
    'tasks.assign' => 'إسناد المهام',
    'tasks.bulk_delete' => 'حذف المهام بالجملة',
    'tasks.clone' => 'نسخ المهام',
    'tasks.create' => 'إنشاء المهام',
    'tasks.delete' => 'حذف المهام',
    'tasks.document_number.control' => 'التحكم في رقم مستند المهمة',
    'tasks.document_number_settings.update' => 'تحديث إعدادات رقم مستند المهمة',
    'tasks.edit' => 'تعديل المهام',
    'tasks.restore' => 'استعادة المهام',
    'tasks.view' => 'عرض المهام',
    'tasks.view_trashed' => 'عرض المهام المحذوفة',
    'users.clone' => 'نسخ المستخدمين',
    'users.create' => 'إنشاء المستخدمين',
    'users.delete' => 'حذف المستخدمين',
    'users.document_number.control' => 'التحكم في رقم مستند المستخدم',
    'users.document_number_settings.update' => 'تحديث إعدادات رقم مستند المستخدم',
    'users.edit' => 'تعديل المستخدمين',
    'users.restore' => 'استعادة المستخدمين',
    'users.roles.manage' => 'إدارة مجموعات المستخدمين',
    'users.view' => 'عرض المستخدمين',
    'users.view_trashed' => 'عرض المستخدمين المحذوفين',
    'screen_data_visibility_rules.view' => 'عرض سياسات رؤية البيانات',
    'screen_data_visibility_rules.create' => 'إنشاء سياسات رؤية البيانات',
    'screen_data_visibility_rules.edit' => 'تعديل سياسات رؤية البيانات',
    'screen_data_visibility_rules.clone' => 'نسخ سياسات رؤية البيانات',
    'screen_data_visibility_rules.delete' => 'حذف سياسات رؤية البيانات',
    'screen_data_visibility_rules.view_trashed' => 'عرض سياسات رؤية البيانات المحذوفة',
    'screen_data_visibility_rules.restore' => 'استعادة سياسات رؤية البيانات',
    'screen_data_visibility_rules.bypass' => 'تجاوز قيود رؤية البيانات',
];
