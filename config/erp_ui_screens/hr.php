<?php

$screen = static fn (string $slug, string $en, string $ar, string $group = 'employee_relations', string $profile = 'document', array $extra = []): array => [
    'key' => 'hr_'.str_replace('-', '_', $slug),
    'slug' => $slug,
    'title' => ['en' => $en, 'ar' => $ar],
    'group' => $group,
    'profile' => $profile,
    'menu_visible' => false,
    ...$extra,
];

return [
    'module' => 'hr',
    'title' => ['en' => 'Human Resources', 'ar' => 'الموارد البشرية'],
    'route_segment' => 'hr',
    'route_name' => 'hr',
    'permission_prefix' => 'hr',
    'menu' => ['label' => 'human_resources', 'title' => ['en' => 'Human Resources', 'ar' => 'الموارد البشرية'], 'icon' => 'users', 'order' => 130],
    'groups' => [
        'contracts' => ['title' => ['en' => 'Contracts', 'ar' => 'العقود'], 'icon' => 'file-contract', 'order' => 10],
        'attendance' => ['title' => ['en' => 'Attendance and Time', 'ar' => 'الحضور والوقت'], 'icon' => 'clock', 'order' => 20],
        'leave' => ['title' => ['en' => 'Leave and Permissions', 'ar' => 'الإجازات والأذونات'], 'icon' => 'calendar-check', 'order' => 30],
        'employee_relations' => ['title' => ['en' => 'Employee Relations', 'ar' => 'شؤون العاملين'], 'icon' => 'user-tie', 'order' => 40],
        'development' => ['title' => ['en' => 'Performance and Development', 'ar' => 'الأداء والتطوير'], 'icon' => 'graduation-cap', 'order' => 50],
        'payroll' => ['title' => ['en' => 'Payroll', 'ar' => 'الرواتب'], 'icon' => 'money-bill-wave', 'order' => 60],
        'requests' => ['title' => ['en' => 'HR Requests and History', 'ar' => 'طلبات وسجل الموارد البشرية'], 'icon' => 'folder-open', 'order' => 70],
    ],
    'screens' => [
        $screen('employee-attendance', 'Employee Attendance', 'حضور الموظفين', 'attendance', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'actions' => ['view', 'manage', 'correct', 'export']]),
        $screen('shift-assignments', 'Shift Assignments', 'تخصيص الورديات', 'attendance', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'actions' => ['view', 'manage']]),
        $screen('payroll-preparation', 'Payroll Preparation', 'إعداد الرواتب', 'payroll', 'document', [
            'shell_enabled' => false,
            'menu_visible' => false,
            'classification' => 'CANONICAL',
            'actions' => ['view', 'calculate'],
        ]),
        $screen('hr-requests', 'HR Requests', 'طلبات الموارد البشرية', 'requests', 'document', ['shell_enabled' => false, 'classification' => 'CANONICAL', 'actions' => ['view', 'manage']]),
    ],
];
