<?php

return [
    'payroll' => ['title' => 'Payroll Report', 'description' => 'Persisted payroll results by employee and payroll period.'],
    'payments' => ['title' => 'Payroll Payment Report', 'description' => 'Employee salary payments with their payment voucher, journal, and status.'],
    'payslip' => ['title' => 'Payslip', 'my_payslips' => 'My Payslips', 'no_payslips' => 'No approved payslips are available.', 'items' => 'Salary details', 'default_item' => 'Salary item', 'no_items' => 'No salary items.', 'attendance_summary' => 'Attendance summary', 'payment_references' => 'This employee payment vouchers'],
    'attendance' => ['finalized_days' => 'Finalized days', 'worked_minutes' => 'Worked minutes', 'late_minutes' => 'Late minutes', 'early_leave_minutes' => 'Early leave minutes', 'recorded_overtime_minutes' => 'Overtime minutes'],
    'item_names' => ['BASIC' => 'Basic salary', 'OVERTIME' => 'Overtime', 'ATTENDANCE-DEDUCTION' => 'Attendance deductions', 'PAYROLL-TAX' => 'Payroll tax', 'SALARY-ADVANCE' => 'Salary advance'],
    'filters' => ['period_from' => 'Period from', 'period_to' => 'Period to'],
    'columns' => ['run' => 'Run', 'period' => 'Period', 'branch' => 'Branch', 'currency' => 'Currency', 'employee_code' => 'Employee Code', 'employee' => 'Employee', 'gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net', 'status' => 'Status', 'payslip' => 'Payslip', 'voucher' => 'Finance Voucher', 'payment_date' => 'Payment Date', 'amount' => 'Amount', 'journal' => 'Journal', 'item' => 'Payroll Item', 'direction' => 'Direction', 'source' => 'Persisted Source'],
    'totals' => ['export_label' => 'Report totals', 'gross' => 'Total Gross', 'deductions' => 'Total Deductions', 'net' => 'Total Net', 'amount' => 'Total Payments', 'approved' => 'Approved Payments', 'cancelled' => 'Cancelled Payments'],
    'actions' => ['view_payslip' => 'View', 'print' => 'Print'],
    'directions' => ['earning' => 'Earning', 'deduction' => 'Deduction'],
    'unknown_currency' => 'Unknown currency',
    'empty' => 'No records match the selected filters.',
];
