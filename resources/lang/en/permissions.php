<?php

$hrLookupPermissionLabels = [];

foreach ([
    'hr.countries' => ['Countries', 'Country'],
    'hr.governorates' => ['Governorates', 'Governorate'],
    'hr.cities' => ['Cities', 'City'],
    'hr.areas' => ['Areas', 'Area'],
    'hr.nationalities' => ['Nationalities', 'Nationality'],
    'hr.religions' => ['Religions', 'Religion'],
    'hr.qualifications' => ['Qualifications', 'Qualification'],
    'hr.universities' => ['Universities', 'University'],
    'hr.faculties' => ['Faculties', 'Faculty'],
    'hr.specializations' => ['Specializations', 'Specialization'],
    'hr.military_services' => ['Military Services', 'Military Service'],
    'hr.allowances' => ['Allowances', 'Allowance'],
    'hr.hiring_statuses' => ['Hiring Statuses', 'Hiring Status'],
    'hr.identifications' => ['Identifications', 'Identification'],
] as $prefix => [$plural, $singular]) {
    $hrLookupPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $hrLookupPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $hrLookupPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $hrLookupPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $hrLookupPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $hrLookupPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $hrLookupPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $hrLookupPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $hrLookupPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$hrFoundationPermissionLabels = [];

foreach ([
    'hr.departments' => ['Departments', 'Department'],
    'hr.sections' => ['Sections', 'Section'],
    'hr.jobs' => ['Job Titles', 'Job Title'],
    'hr.grades' => ['Grades', 'Grade'],
    'hr.employment_types' => ['Employment Types', 'Employment Type'],
    'hr.document_types' => ['Employee Document Types', 'Employee Document Type'],
    'hr.shifts' => ['Work Shifts', 'Work Shift'],
    'hr.biometric_devices' => ['Attendance Devices', 'Attendance Device'],
    'hr.insurance_offices' => ['Insurance Offices', 'Insurance Office'],
    'hr.social_insurance_policies' => ['Social Insurance Policies', 'Social Insurance Policy'],
    'hr.employment_tax_policies' => ['Employment Tax Policies', 'Employment Tax Policy'],
] as $prefix => [$plural, $singular]) {
    $hrFoundationPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $hrFoundationPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $hrFoundationPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$hrOrgStructurePermissionExtras = [];

$hrEmployeePermissionLabels = [
    'hr.employees.view' => 'View Employees',
    'hr.employees.create' => 'Create Employees',
    'hr.employees.edit' => 'Edit Employees',
    'hr.employees.delete' => 'Delete Employees',
    'hr.employees.clone' => 'Clone Employees',
    'hr.employees.view_trashed' => 'View trashed records',
    'hr.employees.restore' => 'Restore Employees',
    'hr.employees.document_number.control' => 'Control Employee Document Number',
    'hr.employees.document_number_settings.update' => 'Update Employee Document Number Settings',
    'hr.employees.documents.view' => 'View Employee Documents',
    'hr.employees.documents.manage' => 'Manage Employee Documents',
    'hr.employees.documents.delete' => 'Delete Employee Documents',
];

$hrAttendanceAndRequestPermissionLabels = [
    'hr.attendance_settings.view' => 'View Attendance Settings',
    'hr.attendance_settings.manage' => 'Manage Attendance Settings',
    'hr.shift_assignments.view' => 'View Shift Assignments',
    'hr.shift_assignments.manage' => 'Manage Shift Assignments',
    'hr.employee_attendance.view' => 'View Employee Attendance',
    'hr.employee_attendance.manage' => 'Manage Employee Attendance Events',
    'hr.employee_attendance.correct' => 'Correct Employee Attendance Events',
    'hr.employee_attendance.import' => 'Import Attendance from Biometric Devices',
    'hr.payslips.view' => 'View Employee Payslips',
    'hr.payroll_reports.view' => 'View Payroll Report',
    'hr.payroll_reports.export' => 'Export Payroll Report',
    'hr.payroll_payment_reports.view' => 'View Payroll Payment Report',
    'hr.payroll_payment_reports.export' => 'Export Payroll Payment Report',
    'hr.employee_attendance.export' => 'Export Employee Attendance Report',
    'hr.hr_requests.view' => 'View HR Requests',
    'hr.hr_requests.manage' => 'Review, Approve, or Reject HR Requests',
    'hr.leave_types.view' => 'View Leave Types',
    'hr.leave_types.create' => 'Create Leave Types',
    'hr.leave_types.update' => 'Update Leave Types',
    'hr.leave_types.delete' => 'Delete Leave Types',
    'hr.leave_types.view_deleted' => 'View Deleted Leave Types',
    'hr.leave_types.restore' => 'Restore Leave Types',
    'hr.payroll_attendance_policies.view' => 'View Payroll and Attendance Settings',
    'hr.payroll_attendance_policies.manage' => 'Manage Payroll and Attendance Settings',
    'hr.employee_reports.view' => 'View Employee Report',
    'hr.employee_reports.export' => 'Export Employee Report',
    'hr.leave_reports.view' => 'View Employee Requests and Leave Report',
    'hr.leave_reports.export' => 'Export Employee Requests and Leave Report',
];

$hrPayrollPermissionLabels = [
    'hr.payroll_preparation.view' => 'View Payroll Preparation',
    'hr.payroll_preparation.calculate' => 'Calculate Payroll',
    'hr.payroll_approval.review' => 'Submit Payroll for Review',
    'hr.payroll_approval.approve' => 'Approve and Post Payroll',
    'hr.payroll_payment.create' => 'Create Payroll Payment',
    'hr.payroll_reconciliation.view' => 'View Payroll Reconciliation',
];

$costCenterPermissionLabels = [];

foreach (['cost_centers' => ['Cost Centers', 'Cost Center']] as $prefix => [$plural, $singular]) {
    $costCenterPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $costCenterPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $costCenterPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $costCenterPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $costCenterPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $costCenterPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $costCenterPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $costCenterPermissionLabels["{$prefix}.print"] = "Print {$plural}";
    $costCenterPermissionLabels["{$prefix}.export"] = "Export {$plural}";
    $costCenterPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $costCenterPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$customerPermissionLabels = [];

foreach (['customers' => ['Customers', 'Customer']] as $prefix => [$plural, $singular]) {
    $customerPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $customerPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $customerPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $customerPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $customerPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $customerPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $customerPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $customerPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $customerPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$quotationPermissionLabels = [
    'quotations.view' => 'View Quotations',
    'quotations.create' => 'Create Quotations',
    'quotations.clone' => 'Clone Quotations',
    'quotations.edit' => 'Edit Quotations',
    'quotations.delete' => 'Delete Quotations',
    'quotations.view_trashed' => 'View Deleted Quotations',
    'quotations.restore' => 'Restore Quotations',
    'quotations.document_number.control' => 'Control Quotation Document Number',
    'quotations.document_number_settings.update' => 'Update Quotation Document Number Settings',
    'quotations.revisions.view' => 'View Quotation Revisions',
    'quotations.revisions.create' => 'Create Quotation Revisions',
    'quotations.mark_sent' => 'Mark Quotations as Sent',
    'quotations.accept' => 'Accept Quotations',
    'quotations.reject' => 'Reject Quotations',
    'quotations.cancel' => 'Cancel Quotations',
    'quotations.print' => 'Print Quotations',
    'quotations.attachments.manage' => 'Manage Quotation Attachments',
];

$supplierPermissionLabels = [];

foreach (['suppliers' => ['Suppliers', 'Supplier']] as $prefix => [$plural, $singular]) {
    $supplierPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $supplierPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $supplierPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $supplierPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $supplierPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $supplierPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $supplierPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $supplierPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $supplierPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

foreach ([
    'purchase_orders' => ['Purchase Orders', 'Purchase Order'],
    'purchase_invoices' => ['Purchase Invoices', 'Purchase Invoice'],
] as $prefix => [$plural, $singular]) {
    $supplierPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $supplierPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $supplierPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $supplierPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $supplierPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $supplierPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $supplierPermissionLabels["{$prefix}.approve"] = "Approve {$plural}";
    $supplierPermissionLabels["{$prefix}.close"] = "Close {$plural}";
    $supplierPermissionLabels["{$prefix}.cancel"] = "Cancel {$plural}";
    $supplierPermissionLabels["{$prefix}.print"] = "Print {$plural}";
    $supplierPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $supplierPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$supplierPermissionLabels['purchase_invoices.clone'] = 'Clone Purchase Invoices';

$productPermissionLabels = [];

foreach ([
    'products' => ['Products', 'Product'],
    'raw_materials' => ['Raw Materials', 'Raw Material'],
    'packaging_materials' => ['Packaging Materials', 'Packaging Material'],
] as $prefix => [$plural, $singular]) {
    $productPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $productPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $productPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $productPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $productPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $productPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $productPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $productPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $productPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$reportPermissionLabels = [
    'reports.products_data.view' => 'View Products and Raw Materials Data Report',
    'reports.products_data.export' => 'Export Products and Raw Materials Data Report',
    'reports.products_data.pdf' => 'Export Products and Raw Materials Data Report PDF',
    'reports.customers.view' => 'View Customers Report',
    'reports.customers.export' => 'Export Customers Report',
    'reports.customers.pdf' => 'Export Customers Report PDF',
    'reports.suppliers.view' => 'View Suppliers Report',
    'reports.suppliers.export' => 'Export Suppliers Report',
    'reports.suppliers.pdf' => 'Export Suppliers Report PDF',
];

$accountPermissionLabels = [
    'accounts.view' => 'View Accounts',
    'accounts.create' => 'Create Accounts',
    'accounts.clone' => 'Clone Accounts',
    'accounts.edit' => 'Edit Accounts',
    'accounts.delete' => 'Delete Accounts',
    'accounts.view_trashed' => 'View Deleted Accounts',
    'accounts.restore' => 'Restore Accounts',
    'accounts.export' => 'Export Accounts',
    'accounts.document_number.control' => 'Control Account Document Number',
    'accounts.document_number_settings.update' => 'Update Account Document Number Settings',
    'accounts.account_code.control' => 'Control account code',
];

$financePermissionLabels = [];

foreach ([
    'currencies' => ['Currencies', 'Currency'],
    'bank_accounts' => ['Bank Accounts', 'Bank Account'],
    'cashboxes' => ['Cashboxes', 'Cashbox'],
    'cash_receipt_vouchers' => ['Cash Receipt Vouchers', 'Cash Receipt Voucher'],
    'cash_payment_vouchers' => ['Cash Payment Vouchers', 'Cash Payment Voucher'],
    'cheques' => ['Cheques', 'Cheque'],
    'fund_transfers' => ['Fund Transfers', 'Fund Transfer'],
    'opening_balances' => ['Opening Balances', 'Opening Balance'],
] as $prefix => [$plural, $singular]) {
    $financePermissionLabels["{$prefix}.view"] = "View {$plural}";
    $financePermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $financePermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $financePermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $financePermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $financePermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $financePermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $financePermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $financePermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$financePermissionLabels['opening_balances.approve'] = 'Approve opening balance';
$financePermissionLabels['opening_balances.cancel'] = 'Cancel opening balance';
$financePermissionLabels['cash_receipt_vouchers.approve'] = 'Approve cash receipt voucher';
$financePermissionLabels['cash_receipt_vouchers.cancel'] = 'Cancel cash receipt voucher';
$financePermissionLabels['cash_receipt_vouchers.print'] = 'Print cash receipt voucher';
$financePermissionLabels['cash_payment_vouchers.approve'] = 'Approve cash payment voucher';
$financePermissionLabels['cash_payment_vouchers.cancel'] = 'Cancel cash payment voucher';
$financePermissionLabels['cash_payment_vouchers.print'] = 'Print cash payment voucher';
$financePermissionLabels['cheques.mark_deposited'] = 'Mark received cheques deposited';
$financePermissionLabels['cheques.mark_collected'] = 'Mark received cheques collected';
$financePermissionLabels['cheques.mark_returned'] = 'Mark cheques returned';
$financePermissionLabels['cheques.mark_issued'] = 'Mark issued cheques issued';
$financePermissionLabels['cheques.mark_delivered'] = 'Mark issued cheques delivered';
$financePermissionLabels['cheques.mark_cleared'] = 'Mark issued cheques cleared';
$financePermissionLabels['cheques.cancel'] = 'Cancel cheques';
$financePermissionLabels['cheques.print'] = 'Print cheques';
$financePermissionLabels['fund_transfers.approve'] = 'Approve fund transfers';
$financePermissionLabels['fund_transfers.cancel'] = 'Cancel fund transfers';
$financePermissionLabels['fund_transfers.print'] = 'Print fund transfers';
$financePermissionLabels['outgoing_payable_cheques.print'] = 'Reserve numbers and print cheques';

$fixedAssetPermissionLabels = [];

foreach (['fixed_assets' => ['Fixed Assets', 'Fixed Asset']] as $prefix => [$plural, $singular]) {
    $fixedAssetPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $fixedAssetPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $fixedAssetPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$inventoryPermissionLabels = [];

foreach ([
    'inventory.opening_stocks' => ['Opening Stock', 'Opening Stock Document'],
    'inventory.opening_stock_pricings' => ['Opening Stock Pricing', 'Opening Stock Pricing Document'],
] as $prefix => [$plural, $singular]) {
    $inventoryPermissionLabels["{$prefix}.view"] = "View {$plural}";
    $inventoryPermissionLabels["{$prefix}.create"] = "Create {$plural}";
    $inventoryPermissionLabels["{$prefix}.clone"] = "Clone {$plural}";
    $inventoryPermissionLabels["{$prefix}.edit"] = "Edit {$plural}";
    $inventoryPermissionLabels["{$prefix}.delete"] = "Delete {$plural}";
    $inventoryPermissionLabels["{$prefix}.view_trashed"] = "View Deleted {$plural}";
    $inventoryPermissionLabels["{$prefix}.restore"] = "Restore {$plural}";
    $inventoryPermissionLabels["{$prefix}.document_number.control"] = "Control {$singular} Document Number";
    $inventoryPermissionLabels["{$prefix}.document_number_settings.update"] = "Update {$singular} Document Number Settings";
}

$inventoryPermissionLabels['inventory.opening_stocks.approve'] = 'Approve opening stock';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.view'] = 'View Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.create'] = 'Create Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.edit'] = 'Edit Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.delete'] = 'Delete Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.view_trashed'] = 'View Deleted Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.restore'] = 'Restore Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.approve'] = 'Approve Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.close'] = 'Close Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.cancel'] = 'Cancel Unpriced Inventory Receipts';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.document_number.control'] = 'Control Unpriced Inventory Receipt Document Number';
$inventoryPermissionLabels['inventory.unpriced_inventory_receipts.document_number_settings.update'] = 'Update Unpriced Inventory Receipt Document Number Settings';

return [
    'activity.logs.details' => 'View Activity Log Details',
    'activity.logs.export' => 'Export Activity Logs',
    'activity.logs.pdf' => 'Export Activity Logs PDF',
    'activity.logs.view' => 'View Activity Logs',
    'auth.logs.details' => 'View Login Activity Details',
    'auth.logs.export' => 'Export Login Activity',
    'auth.logs.pdf' => 'Export Login Activity PDF',
    'auth.logs.view' => 'View Login Activity',
    'auth.sessions.details' => 'View Active Session Details',
    'auth.sessions.export' => 'Export Active Sessions',
    'auth.sessions.force_logout' => 'Force Logout Sessions',
    'auth.sessions.pdf' => 'Export Active Sessions PDF',
    'auth.sessions.view' => 'View Active Sessions',
    'calendar.complete' => 'Complete Calendar Events',
    'calendar.create' => 'Create Calendar Events',
    'calendar.delete' => 'Delete Calendar Events',
    'calendar.edit' => 'Edit Calendar Events',
    'calendar.view' => 'View Calendar',
    'branches.clone' => 'Clone Branches',
    'branches.create' => 'Create Branches',
    'branches.delete' => 'Delete Branches',
    'branches.document_number.control' => 'Control Branch Document Number',
    'branches.document_number_settings.update' => 'Update Branch Document Number Settings',
    'branches.edit' => 'Edit Branches',
    'branches.restore' => 'Restore Branches',
    'branches.view' => 'View Branches',
    'branches.view_trashed' => 'View Deleted Branches',
    'companies.clone' => 'Clone Companies',
    'companies.create' => 'Create Companies',
    'companies.delete' => 'Delete Companies',
    'companies.document_number.control' => 'Control Company Document Number',
    'companies.document_number_settings.update' => 'Update Company Document Number Settings',
    'companies.edit' => 'Edit Companies',
    'companies.files.delete' => 'Delete Company Files',
    'companies.files.download' => 'Download Company Files',
    'companies.files.folders.create' => 'Create Company File Folders',
    'companies.files.folders.delete' => 'Delete Company File Folders',
    'companies.files.folders.rename' => 'Rename Company File Folders',
    'companies.files.public_links.create' => 'Create Company File Public Link',
    'companies.files.public_links.revoke' => 'Revoke Company File Public Link',
    'companies.files.public_links.view' => 'View Company File Public Link',
    'companies.files.upload' => 'Upload Company Files',
    'companies.files.view' => 'View Company Files',
    'companies.main.control' => 'Control Main Company',
    'companies.restore' => 'Restore Companies',
    'companies.view' => 'View Companies',
    'companies.view_trashed' => 'View Deleted Companies',
    'dashboard.view' => 'View Dashboard',
    'dashboard.summaries.sales.view' => 'View Dashboard Sales Summary',
    'dashboard.summaries.purchases.view' => 'View Dashboard Purchases Summary',
    'price_lists.review' => 'Review Price Lists',
    'price_lists.approve' => 'Approve Price Lists',
    'file_manager.delete' => 'Delete Files',
    'file_manager.document_number_settings.update' => 'Update File Manager Document Number Settings',
    'file_manager.download' => 'Download Files',
    'file_manager.move' => 'Move Files and Folders',
    'file_manager.folders.create' => 'Create Folders',
    'file_manager.folders.delete' => 'Delete Folders',
    'file_manager.folders.rename' => 'Rename Folders',
    'file_manager.public_links.create' => 'Create Public Link',
    'file_manager.public_links.revoke' => 'Revoke Public Link',
    'file_manager.public_links.view' => 'View Public Link',
    'file_manager.update_picker_visibility' => 'Update Picker Visibility',
    'file_manager.upload' => 'Upload Files',
    'file_manager.view' => 'View File Manager',
    'chat.reports.view' => 'View Chat Report',
    'chat.reports.export' => 'Export Chat Report to Excel',
    'chat.reports.pdf' => 'Export Chat Report to PDF',
    'chat.reports.print' => 'Print Chat Report',
    'financial_periods.clone' => 'Clone Financial Periods',
    'financial_periods.create' => 'Create Financial Periods',
    'financial_periods.delete' => 'Delete Financial Periods',
    'financial_periods.document_number.control' => 'Control Financial Period Document Number',
    'financial_periods.document_number_settings.update' => 'Update Financial Period Document Number Settings',
    'financial_periods.edit' => 'Edit Financial Periods',
    'financial_periods.restore' => 'Restore Financial Periods',
    'financial_periods.view' => 'View Financial Periods',
    'financial_periods.view_trashed' => 'View Deleted Financial Periods',
    ...$hrLookupPermissionLabels,
    ...$hrFoundationPermissionLabels,
    ...$hrOrgStructurePermissionExtras,
    ...$hrEmployeePermissionLabels,
    ...$hrAttendanceAndRequestPermissionLabels,
    ...$hrPayrollPermissionLabels,
    ...$costCenterPermissionLabels,
    ...$customerPermissionLabels,
    ...$quotationPermissionLabels,
    ...$supplierPermissionLabels,
    ...$productPermissionLabels,
    ...$reportPermissionLabels,
    ...$accountPermissionLabels,
    ...$financePermissionLabels,
    ...$fixedAssetPermissionLabels,
    ...$inventoryPermissionLabels,
    'my_board.create' => 'Create My Board Items',
    'my_board.delete' => 'Delete My Board Items',
    'my_board.edit' => 'Edit My Board Items',
    'my_board.clone' => 'Clone My Board Items',
    'my_board.assign' => 'Assign My Board Tasks',
    'my_board.comments.create' => 'Create My Board Comments',
    'my_board.comments.delete' => 'Delete My Board Comments',
    'my_board.lists.create' => 'Create My Board Lists',
    'my_board.lists.delete' => 'Delete My Board Lists',
    'my_board.lists.edit' => 'Edit My Board Lists',
    'my_board.lists.reorder' => 'Reorder My Board Lists',
    'my_board.manage_any' => 'Manage Any User Board',
    'my_board.notes.view_all' => 'View All My Board Notes',
    'my_board.reorder' => 'Reorder My Board Items',
    'my_board.restore' => 'Restore My Board Items',
    'my_board.tasks.view_all' => 'View All My Board Tasks',
    'my_board.view' => 'View My Board',
    'my_board.view_any' => 'View Any User Board',
    'my_board.view_trashed' => 'View Deleted My Board Items',
    'quick_tasks.board' => 'View Task Display Board',
    'quick_tasks.change_status' => 'Change Quick Task Status',
    'quick_tasks.create' => 'Create Quick Tasks',
    'quick_tasks.delete' => 'Delete Quick Tasks',
    'quick_tasks.manage_attachments' => 'Manage Quick Task Attachments',
    'quick_tasks.mark_done' => 'Mark Quick Tasks Done',
    'quick_tasks.mark_ready' => 'Mark Quick Tasks Ready',
    'quick_tasks.restore' => 'Restore Quick Tasks',
    'quick_tasks.start' => 'Start Quick Tasks',
    'quick_tasks.update' => 'Update Quick Tasks',
    'quick_tasks.view' => 'View Quick Tasks',
    'task_boards.create' => 'Create Task Boards',
    'task_boards.delete' => 'Delete Task Boards',
    'task_boards.display' => 'Open Task Board Display URL',
    'task_boards.view_trashed' => 'View Deleted Task Boards',
    'task_boards.restore' => 'Restore Task Boards',
    'task_boards.bulk_activate' => 'Activate Selected Task Boards',
    'task_boards.bulk_deactivate' => 'Deactivate Selected Task Boards',
    'task_boards.public_settings' => 'Manage Task Board Public Settings',
    'task_boards.regenerate_public_url' => 'Regenerate Task Board Display URL',
    'task_boards.update' => 'Update Task Boards',
    'task_boards.view' => 'View Task Boards',
    'permissions.create' => 'Create Permissions',
    'permissions.delete' => 'Delete Permissions',
    'permissions.edit' => 'Edit Permissions',
    'permissions.view' => 'View Permissions',
    'profile.delete' => 'Delete Account',
    'profile.edit' => 'Edit Profile',
    'profile.auth_logs.view' => 'View Profile Login Activity',
    'profile.password.update' => 'Update Password',
    'profile.sessions.view' => 'View Profile Sessions',
    'profile.view' => 'View Profile',
    'settings.pwa.update' => 'Update PWA Settings',
    'settings.pwa.view' => 'View PWA Settings',
    'tools.open_documents.execute' => 'Execute Open Document',
    'tools.open_documents.view' => 'View Open Document',
    'roles.clone' => 'Clone User Groups',
    'roles.create' => 'Create User Groups',
    'roles.delete' => 'Delete User Groups',
    'roles.document_number.control' => 'Control User Group Document Number',
    'roles.document_number_settings.update' => 'Update User Group Document Number Settings',
    'roles.edit' => 'Edit User Groups',
    'roles.operating_scope.manage' => 'Manage User Group Operating Scope',
    'roles.restore' => 'Restore User Groups',
    'roles.view' => 'View User Groups',
    'roles.view_trashed' => 'View Deleted User Groups',
    'tasks.assign' => 'Assign Tasks',
    'tasks.bulk_delete' => 'Bulk Delete Tasks',
    'tasks.clone' => 'Clone Tasks',
    'tasks.create' => 'Create Tasks',
    'tasks.delete' => 'Delete Tasks',
    'tasks.document_number.control' => 'Control Task Document Number',
    'tasks.document_number_settings.update' => 'Update Task Document Number Settings',
    'tasks.edit' => 'Edit Tasks',
    'tasks.restore' => 'Restore Tasks',
    'tasks.view' => 'View Tasks',
    'tasks.view_trashed' => 'View Deleted Tasks',
    'users.clone' => 'Clone Users',
    'users.create' => 'Create Users',
    'users.delete' => 'Delete Users',
    'users.document_number.control' => 'Control User Document Number',
    'users.document_number_settings.update' => 'Update User Document Number Settings',
    'users.edit' => 'Edit Users',
    'users.restore' => 'Restore Users',
    'users.roles.manage' => 'Manage User Groups',
    'users.view' => 'View Users',
    'users.view_trashed' => 'View Deleted Users',
    'screen_data_visibility_rules.view' => 'View Data Visibility Rules',
    'screen_data_visibility_rules.create' => 'Create Data Visibility Rules',
    'screen_data_visibility_rules.edit' => 'Edit Data Visibility Rules',
    'screen_data_visibility_rules.clone' => 'Clone Data Visibility Rules',
    'screen_data_visibility_rules.delete' => 'Delete Data Visibility Rules',
    'screen_data_visibility_rules.view_trashed' => 'View Deleted Data Visibility Rules',
    'screen_data_visibility_rules.restore' => 'Restore Data Visibility Rules',
    'screen_data_visibility_rules.bypass' => 'Bypass Data Visibility Restrictions',
];
