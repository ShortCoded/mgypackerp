<?php

return [
    'payroll' => ['title' => 'تقرير الرواتب', 'description' => 'نتائج الرواتب المحفوظة حسب الموظف وفترة الرواتب.'],
    'payments' => ['title' => 'تقرير مدفوعات الرواتب', 'description' => 'صرف الرواتب لكل موظف مع سند الصرف والقيد والحالة.'],
    'payslip' => ['title' => 'قسيمة الراتب', 'my_payslips' => 'قسائم رواتبي', 'no_payslips' => 'لا توجد قسائم رواتب معتمدة متاحة.', 'items' => 'تفاصيل الراتب', 'default_item' => 'بند راتب', 'no_items' => 'لا توجد بنود راتب.', 'attendance_summary' => 'ملخص الحضور', 'payment_references' => 'سندات صرف هذا الموظف'],
    'attendance' => ['finalized_days' => 'الأيام المعتمدة', 'worked_minutes' => 'دقائق العمل', 'late_minutes' => 'دقائق التأخير', 'early_leave_minutes' => 'دقائق الانصراف المبكر', 'recorded_overtime_minutes' => 'دقائق الوقت الإضافي'],
    'item_names' => ['BASIC' => 'الراتب الأساسي', 'OVERTIME' => 'الوقت الإضافي', 'ATTENDANCE-DEDUCTION' => 'خصومات الحضور والانصراف', 'PAYROLL-TAX' => 'ضريبة الرواتب', 'SALARY-ADVANCE' => 'سلفة الراتب'],
    'filters' => ['period_from' => 'الفترة من', 'period_to' => 'الفترة إلى'],
    'columns' => ['run' => 'المسير', 'period' => 'الفترة', 'branch' => 'الفرع', 'currency' => 'العملة', 'employee_code' => 'كود الموظف', 'employee' => 'الموظف', 'gross' => 'إجمالي المستحقات', 'deductions' => 'الخصومات', 'net' => 'الصافي', 'status' => 'الحالة', 'payslip' => 'قسيمة الراتب', 'voucher' => 'سند المالية', 'payment_date' => 'تاريخ الدفع', 'amount' => 'القيمة', 'journal' => 'القيد', 'item' => 'بند الراتب', 'direction' => 'الاتجاه', 'source' => 'المصدر المحفوظ'],
    'totals' => ['export_label' => 'إجماليات التقرير', 'gross' => 'إجمالي المستحقات', 'deductions' => 'إجمالي الخصومات', 'net' => 'إجمالي الصافي', 'amount' => 'إجمالي المدفوعات', 'approved' => 'المدفوعات المعتمدة', 'cancelled' => 'المدفوعات الملغاة'],
    'actions' => ['view_payslip' => 'عرض', 'print' => 'طباعة'],
    'directions' => ['earning' => 'استحقاق', 'deduction' => 'خصم'],
    'unknown_currency' => 'عملة غير معروفة',
    'empty' => 'لا توجد سجلات مطابقة للمرشحات المحددة.',
];
