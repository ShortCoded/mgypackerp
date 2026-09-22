<?php

$hrCrudActions = static fn (string $prefix): array => [
    'view' => "{$prefix}.view", 'create' => "{$prefix}.create", 'clone' => "{$prefix}.clone",
    'edit' => "{$prefix}.edit", 'delete' => "{$prefix}.delete", 'view_trashed' => "{$prefix}.view_trashed",
    'restore' => "{$prefix}.restore", 'document_number_control' => "{$prefix}.document_number.control",
    'document_number_settings_update' => "{$prefix}.document_number_settings.update",
];

$hrScreen = static function (string $label, string $title, string $routeKey, string $permissionPrefix, string $icon, ?string $subgroup, array $extraActions = []) use ($hrCrudActions): array {
    return [
        'label' => $label, 'title' => $title, 'icon' => $icon,
        'route' => "admin.hr.{$routeKey}.index", 'permission' => "{$permissionPrefix}.view",
        'subgroup' => $subgroup, 'actions' => [...$hrCrudActions($permissionPrefix), ...$extraActions],
        'active' => ["admin.hr.{$routeKey}.*"], 'children' => [],
    ];
};

$employmentData = [
    $hrScreen('hr_departments', 'Departments', 'departments', 'hr.departments', 'building', 'hr_employment_data'),
    $hrScreen('hr_sections', 'Job Sections', 'sections', 'hr.sections', 'sitemap', 'hr_employment_data'),
    $hrScreen('hr_jobs', 'Jobs', 'jobs', 'hr.jobs', 'briefcase', 'hr_employment_data'),
    $hrScreen('hr_employment_types', 'Employment Types', 'employment-types', 'hr.employment_types', 'id-badge', 'hr_employment_data'),
    $hrScreen('hr_grades', 'Grades', 'grades', 'hr.grades', 'layer-group', 'hr_employment_data'),
    $hrScreen('hr_hiring_statuses', 'Hiring Statuses', 'hiring-statuses', 'hr.hiring_statuses', 'user-check', 'hr_employment_data'),
    $hrScreen('hr_document_types', 'Employee Document Types', 'document-types', 'hr.document_types', 'file-alt', 'hr_employment_data'),
    [
        'label' => 'hr_leave_types', 'title' => 'Leave Types', 'icon' => 'calendar-check',
        'route' => 'admin.hr.leave-types.index', 'permission' => 'hr.leave_types.view', 'subgroup' => 'hr_employment_data',
        'actions' => ['view' => 'hr.leave_types.view', 'create' => 'hr.leave_types.create', 'edit' => 'hr.leave_types.update', 'delete' => 'hr.leave_types.delete', 'view_deleted' => 'hr.leave_types.view_deleted', 'restore' => 'hr.leave_types.restore'],
        'active' => ['admin.hr.leave-types.*'], 'children' => [],
    ],
    $hrScreen('hr_allowances', 'Allowances', 'allowances', 'hr.allowances', 'plus-circle', 'hr_employment_data'),
    $hrScreen('hr_insurance_offices', 'Insurance Offices', 'insurance-offices', 'hr.insurance_offices', 'shield-alt', 'hr_employment_data'),
    $hrScreen('hr_social_insurance_policies', 'Social Insurance Policies', 'social-insurance-policies', 'hr.social_insurance_policies', 'shield-alt', 'hr_employment_data'),
    $hrScreen('hr_employment_tax_policies', 'Employment Tax Policies', 'employment-tax-policies', 'hr.employment_tax_policies', 'percentage', 'hr_employment_data'),
    $hrScreen('hr_identifications', 'Identifications', 'identifications', 'hr.identifications', 'id-card', 'hr_employment_data'),
    $hrScreen('hr_nationalities', 'Nationalities', 'nationalities', 'hr.nationalities', 'flag', 'hr_employment_data'),
    $hrScreen('hr_religions', 'Religions', 'religions', 'hr.religions', 'star-and-crescent', 'hr_employment_data'),
    $hrScreen('hr_qualifications', 'Qualifications', 'qualifications', 'hr.qualifications', 'graduation-cap', 'hr_employment_data'),
    $hrScreen('hr_universities', 'Universities', 'universities', 'hr.universities', 'university', 'hr_employment_data'),
    $hrScreen('hr_faculties', 'Faculties', 'faculties', 'hr.faculties', 'university', 'hr_employment_data'),
    $hrScreen('hr_specializations', 'Specializations', 'specializations', 'hr.specializations', 'certificate', 'hr_employment_data'),
    $hrScreen('hr_military_services', 'Military Services', 'military-services', 'hr.military_services', 'medal', 'hr_employment_data'),
];

$hiddenGeographyScreens = collect([
    $hrScreen('hr_countries', 'Countries', 'countries', 'hr.countries', 'globe', null),
    $hrScreen('hr_governorates', 'Governorates', 'governorates', 'hr.governorates', 'map', null),
    $hrScreen('hr_cities', 'Cities', 'cities', 'hr.cities', 'city', null),
    $hrScreen('hr_areas', 'Areas', 'areas', 'hr.areas', 'map-marker-alt', null),
])->map(fn (array $screen): array => [...$screen, 'hidden' => true])->all();

return [[
    'label' => 'human_resources', 'title' => 'Human Resources', 'icon' => 'users', 'route' => null,
    'permission' => null, 'active' => ['admin.hr.*', 'employee.hr.*'],
    'children' => [
        $hrScreen('hr_employees', 'Employees', 'employees', 'hr.employees', 'user-tie', null, [
            'documents_view' => 'hr.employees.documents.view', 'documents_manage' => 'hr.employees.documents.manage',
            'documents_delete' => 'hr.employees.documents.delete',
        ]),
        ...$employmentData,
        ...$hiddenGeographyScreens,
        $hrScreen('hr_shifts', 'Work Shifts', 'shifts', 'hr.shifts', 'clock', 'hr_attendance_management'),
        $hrScreen('hr_biometric_devices', 'Attendance Devices', 'biometric-devices', 'hr.biometric_devices', 'fingerprint', 'hr_attendance_management'),
        [
            'label' => 'hr_attendance_settings', 'title' => 'Attendance Settings', 'icon' => 'map-marked-alt',
            'route' => 'admin.hr.attendance-settings.index', 'permission' => 'hr.attendance_settings.view', 'subgroup' => 'hr_attendance_management',
            'actions' => ['view' => 'hr.attendance_settings.view', 'manage' => 'hr.attendance_settings.manage'],
            'active' => ['admin.hr.attendance-settings.*'], 'children' => [],
        ],
        [
            'label' => 'hr_shift_assignments', 'title' => 'Shift Assignments', 'icon' => 'calendar-alt',
            'route' => 'admin.hr.shift-assignments.index', 'permission' => 'hr.shift_assignments.view', 'subgroup' => 'hr_attendance_management',
            'actions' => ['view' => 'hr.shift_assignments.view', 'manage' => 'hr.shift_assignments.manage'],
            'active' => ['admin.hr.shift-assignments.*'], 'children' => [],
        ],
        [
            'label' => 'hr_employee_attendance', 'title' => 'Employee Attendance', 'icon' => 'user-clock',
            'route' => 'admin.hr.employee-attendance.index', 'permission' => 'hr.employee_attendance.view', 'subgroup' => 'hr_attendance_management',
            'actions' => ['view' => 'hr.employee_attendance.view', 'manage' => 'hr.employee_attendance.manage', 'correct' => 'hr.employee_attendance.correct', 'import' => 'hr.employee_attendance.import', 'export' => 'hr.employee_attendance.export'],
            'active' => ['admin.hr.employee-attendance.index', 'admin.hr.employee-attendance.export.*', 'admin.hr.employee-attendance.manual.*'], 'children' => [],
        ],
        [
            'label' => 'hr_attendance_import', 'title' => 'Import Attendance', 'icon' => 'file-import',
            'route' => 'admin.hr.employee-attendance.import.index', 'permission' => 'hr.employee_attendance.import', 'subgroup' => 'hr_attendance_management',
            'actions' => ['import' => 'hr.employee_attendance.import'],
            'active' => ['admin.hr.employee-attendance.import.*'], 'children' => [],
        ],
        [
            'label' => 'hr_requests', 'title' => 'HR Requests', 'icon' => 'clipboard-check',
            'route' => 'admin.hr.hr-requests.index', 'permission' => 'hr.hr_requests.view', 'subgroup' => 'hr_employee_requests',
            'actions' => ['view' => 'hr.hr_requests.view', 'manage' => 'hr.hr_requests.manage'],
            'active' => ['admin.hr.hr-requests.*'], 'children' => [],
        ],
        [
            'label' => 'hr_payroll_preparation', 'title' => 'Payroll', 'icon' => 'money-check-alt',
            'route' => 'admin.hr.payroll-preparation.index', 'permission' => 'hr.payroll_preparation.view', 'subgroup' => 'hr_payroll',
            'actions' => ['view' => 'hr.payroll_preparation.view', 'calculate' => 'hr.payroll_preparation.calculate', 'review' => 'hr.payroll_approval.review', 'approve' => 'hr.payroll_approval.approve', 'create_payment' => 'hr.payroll_payment.create', 'reconcile' => 'hr.payroll_reconciliation.view'],
            'active' => ['admin.hr.payroll-preparation.*', 'admin.hr.payroll-runs.*'], 'children' => [],
        ],
        [
            'label' => 'hr_payroll_attendance_policies', 'title' => 'Payroll and Attendance Settings', 'icon' => 'sliders-h',
            'route' => 'admin.hr.payroll-attendance-policies.index', 'permission' => 'hr.payroll_attendance_policies.view', 'subgroup' => 'hr_payroll',
            'actions' => ['view' => 'hr.payroll_attendance_policies.view', 'manage' => 'hr.payroll_attendance_policies.manage'],
            'active' => ['admin.hr.payroll-attendance-policies.*'], 'children' => [],
        ],
        [
            'label' => 'employee_self_service', 'title' => 'Employee Self Service', 'icon' => 'mobile-alt',
            'route' => 'employee.hr.self-service.index', 'permission' => null, 'subgroup' => 'hr_self_service',
            'active' => ['employee.hr.*'], 'children' => [],
        ],
        [
            'label' => 'hr_employee_report', 'title' => 'Employee Report', 'icon' => 'users',
            'route' => 'admin.hr.reports.employees', 'permission' => 'hr.employee_reports.view', 'subgroup' => 'hr_reports',
            'actions' => ['view' => 'hr.employee_reports.view', 'export' => 'hr.employee_reports.export'],
            'active' => ['admin.hr.reports.employees'], 'children' => [],
        ],
        [
            'label' => 'hr_attendance_report', 'title' => 'Attendance Report', 'icon' => 'user-clock',
            'route' => 'admin.hr.reports.attendance', 'permission' => 'hr.employee_attendance.view', 'subgroup' => 'hr_reports',
            'actions' => ['view' => 'hr.employee_attendance.view', 'export' => 'hr.employee_attendance.export'],
            'active' => ['admin.hr.reports.attendance'], 'children' => [],
        ],
        [
            'label' => 'hr_leave_requests_report', 'title' => 'Employee Requests and Leave Report', 'icon' => 'calendar-alt',
            'route' => 'admin.hr.reports.leave-requests', 'permission' => 'hr.leave_reports.view', 'subgroup' => 'hr_reports',
            'actions' => ['view' => 'hr.leave_reports.view', 'export' => 'hr.leave_reports.export'],
            'active' => ['admin.hr.reports.leave-requests'], 'children' => [],
        ],
        [
            'label' => 'hr_payroll_report', 'title' => 'Payroll Report', 'icon' => 'file-invoice-dollar',
            'route' => 'admin.hr.reports.payroll', 'permission' => 'hr.payroll_reports.view', 'subgroup' => 'hr_reports',
            'actions' => ['view' => 'hr.payroll_reports.view', 'export' => 'hr.payroll_reports.export', 'payslip_view' => 'hr.payslips.view'],
            'active' => ['admin.hr.reports.payroll', 'admin.hr.payslips.*'], 'children' => [],
        ],
        [
            'label' => 'hr_payroll_payment_report', 'title' => 'Payroll Payment Report', 'icon' => 'money-check-alt',
            'route' => 'admin.hr.reports.payments', 'permission' => 'hr.payroll_payment_reports.view', 'subgroup' => 'hr_reports',
            'actions' => ['view' => 'hr.payroll_payment_reports.view', 'export' => 'hr.payroll_payment_reports.export'],
            'active' => ['admin.hr.reports.payments'], 'children' => [],
        ],
    ],
]];
