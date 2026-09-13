<?php

use Illuminate\Support\Facades\Schema;

test('hr enterprise schema keeps current screens and removes obsolete organization resources', function (): void {
    $keptTables = [
        'hr_departments',
        'hr_sections',
        'hr_jobs',
        'hr_employment_types',
        'hr_biometric_devices',
        'hr_shifts',
        'hr_document_types',
        'hr_insurance_offices',
        'workflow_definitions',
        'workflow_versions',
        'workflow_instances',
        'hr_leave_requests',
        'hr_payroll_runs',
        'hr_final_settlements',
    ];

    foreach ($keptTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table [{$table}]");
    }

    foreach ([
        'hr_attendance_rules',
        'hr_contract_types',
        'hr_cost_centers',
        'hr_employee_categories',
        'hr_employee_assignments',
        'hr_employee_insurance_policies',
        'hr_insurances',
        'hr_job_families',
        'hr_job_levels',
        'hr_org_units',
        'hr_org_unit_types',
        'hr_positions',
        'hr_professions',
        'hr_regulations',
        'hr_work_locations',
        'hr_work_permissions',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Obsolete table still exists [{$table}]");
    }

    expect(Schema::hasColumn('hr_leave_requests', 'workflow_instance_id'))->toBeTrue()
        ->and(Schema::hasTable('hr_attendance_sessions'))->toBeTrue()
        ->and(Schema::hasTable('hr_attendance_events'))->toBeTrue()
        ->and(Schema::hasTable('hr_attendance_open_sessions'))->toBeTrue()
        ->and(Schema::hasTable('hr_employee_service_requests'))->toBeTrue()
        ->and(Schema::hasColumns('hr_attendance_sessions', [
            'scheduled_start_time',
            'scheduled_end_time',
            'scheduled_crosses_midnight',
            'allowed_late_minutes',
            'allowed_early_leave_minutes',
            'overtime_enabled',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('hr_employees', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('branches', 'attendance_latitude'))->toBeTrue()
        ->and(Schema::hasColumn('hr_employees', 'work_email'))->toBeTrue()
        ->and(Schema::hasColumn('hr_employees', 'personal_email'))->toBeTrue()
        ->and(Schema::hasColumn('hr_employees', 'primary_assignment_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_employees', 'insurance_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_employees', 'work_permission_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_employees', 'profession_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_employees', 'attendance_rule_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_departments', 'org_unit_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_insurance_offices', 'insurance_office_code'))->toBeTrue()
        ->and(Schema::hasColumn('hr_insurance_offices', 'contact_person'))->toBeTrue();
});
