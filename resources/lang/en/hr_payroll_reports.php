<?php

return [
    'payroll' => ['title' => 'Payroll Report', 'description' => 'Persisted payroll results by employee and payroll period.'],
    'payments' => ['title' => 'Payroll Payment Report', 'description' => 'Canonical run-level payroll settlements in the company main currency, linked to Finance vouchers and journals. These are not employee allocations.'],
    'payslip' => ['title' => 'Payslip', 'my_payslips' => 'My Payslips', 'no_payslips' => 'No approved payslips are available.', 'items' => 'Earnings and Deductions', 'payroll_snapshot' => 'Persisted Payroll Snapshot', 'salary_source' => 'Salary Source', 'approved_requests' => 'Approved Requests', 'attendance_snapshot' => 'Attendance Snapshot', 'payment_references' => 'Aggregate Payroll Run Payment References (not allocated per employee)'],
    'filters' => ['period_from' => 'Period from', 'period_to' => 'Period to'],
    'columns' => ['run' => 'Run', 'period' => 'Period', 'branch' => 'Branch', 'currency' => 'Currency', 'employee_code' => 'Employee Code', 'employee' => 'Employee', 'gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net', 'status' => 'Status', 'payslip' => 'Payslip', 'voucher' => 'Finance Voucher', 'payment_date' => 'Payment Date', 'amount' => 'Amount', 'journal' => 'Journal', 'item' => 'Payroll Item', 'direction' => 'Direction', 'source' => 'Persisted Source'],
    'totals' => ['export_label' => 'Report totals', 'gross' => 'Total Gross', 'deductions' => 'Total Deductions', 'net' => 'Total Net', 'amount' => 'Total Payments', 'approved' => 'Approved Payments', 'cancelled' => 'Cancelled Payments'],
    'actions' => ['view_payslip' => 'View', 'print' => 'Print'],
    'directions' => ['earning' => 'Earning', 'deduction' => 'Deduction'],
    'unknown_currency' => 'Unknown currency',
    'empty' => 'No records match the selected filters.',
];
