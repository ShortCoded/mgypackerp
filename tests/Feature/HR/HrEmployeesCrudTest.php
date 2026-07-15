<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeDocument;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    if (! Route::has('admin.hr.employees.index')) {
        $this->markTestSkipped('HR admin routes are disabled from the active app surface.');
    }
});

function hrEmployeeActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function hrEmployeePermissions(): array
{
    return [
        'hr.employees.view',
        'hr.employees.create',
        'hr.employees.edit',
        'hr.employees.delete',
        'hr.employees.clone',
        'hr.employees.view_trashed',
        'hr.employees.restore',
        'hr.employees.document_number.control',
        'hr.employees.document_number_settings.update',
        'hr.employees.documents.view',
        'hr.employees.documents.manage',
        'hr.employees.documents.delete',
    ];
}

function hrEmployeeLookupCreatePermissions(): array
{
    return [
        'branches.create',
        'hr.departments.create',
        'hr.sections.create',
        'hr.jobs.create',
        'hr.employment_types.create',
        'hr.document_types.create',
    ];
}

function hrEmployeeDataTableColumns(): array
{
    return [
        ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false'],
        ['data' => 'doc_num', 'name' => 'hr_employees.doc_number', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'avatar', 'name' => 'avatar', 'searchable' => 'false', 'orderable' => 'false'],
        ['data' => 'full_name', 'name' => 'hr_employees.full_name', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'national_id', 'name' => 'hr_employees.national_id', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'person_type', 'name' => 'hr_employees.person_type', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'branch', 'name' => 'branch', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'department', 'name' => 'department', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'job_section', 'name' => 'job_section', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'job', 'name' => 'job', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'job_type', 'name' => 'job_type', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'pay_basis', 'name' => 'hr_employees.pay_basis', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'pay_amount', 'name' => 'pay_amount', 'searchable' => 'false', 'orderable' => 'true'],
        ['data' => 'payroll_currency', 'name' => 'payroll_currency', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'status', 'name' => 'hr_employees.status', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'biometric_indicator', 'name' => 'biometric_indicator', 'searchable' => 'false', 'orderable' => 'false'],
        ['data' => 'end_date', 'name' => 'hr_employees.end_date', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'created_by', 'name' => 'created_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'created_at', 'name' => 'hr_employees.created_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'updated_by', 'name' => 'updated_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'updated_at', 'name' => 'hr_employees.updated_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'deleted_by', 'name' => 'deleted_by', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'deleted_at', 'name' => 'hr_employees.deleted_at', 'searchable' => 'true', 'orderable' => 'true'],
        ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false'],
    ];
}

function hrEmployeeDataTableQuery(array $overrides = []): array
{
    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'columns' => hrEmployeeDataTableColumns(),
        ...$overrides,
    ];
}

function hrSimplifiedHrViewPermissions(): array
{
    return [
        'hr.departments.view',
        'hr.sections.view',
        'hr.jobs.view',
        'hr.employment_types.view',
        'hr.biometric_devices.view',
        'hr.shifts.view',
        'hr.document_types.view',
        'hr.insurance_offices.view',
        'hr.employees.view',
    ];
}

function hrRestoredLegacyHrViewPermissions(): array
{
    return [
        'hr.allowances.view',
        'hr.areas.view',
        'hr.cities.view',
        'hr.countries.view',
        'hr.faculties.view',
        'hr.governorates.view',
        'hr.grades.view',
        'hr.hiring_statuses.view',
        'hr.identifications.view',
        'hr.military_services.view',
        'hr.nationalities.view',
        'hr.qualifications.view',
        'hr.religions.view',
        'hr.specializations.view',
        'hr.universities.view',
    ];
}

function hrAllReviewHrViewPermissions(): array
{
    return [
        ...hrSimplifiedHrViewPermissions(),
        ...hrRestoredLegacyHrViewPermissions(),
    ];
}

function hrSimplifiedHrIndexRoutes(): array
{
    return [
        'admin.hr.employees.index',
        'admin.hr.departments.index',
        'admin.hr.sections.index',
        'admin.hr.jobs.index',
        'admin.hr.employment-types.index',
        'admin.hr.biometric-devices.index',
        'admin.hr.shifts.index',
        'admin.hr.document-types.index',
        'admin.hr.insurance-offices.index',
    ];
}

function hrRestoredLegacyHrIndexRoutes(): array
{
    return [
        'admin.hr.allowances.index',
        'admin.hr.areas.index',
        'admin.hr.cities.index',
        'admin.hr.countries.index',
        'admin.hr.faculties.index',
        'admin.hr.governorates.index',
        'admin.hr.grades.index',
        'admin.hr.hiring-statuses.index',
        'admin.hr.identifications.index',
        'admin.hr.military-services.index',
        'admin.hr.nationalities.index',
        'admin.hr.qualifications.index',
        'admin.hr.religions.index',
        'admin.hr.specializations.index',
        'admin.hr.universities.index',
    ];
}

function hrAllReviewHrIndexRoutes(): array
{
    return [
        ...hrSimplifiedHrIndexRoutes(),
        ...hrRestoredLegacyHrIndexRoutes(),
    ];
}

function hrSimplifiedHrMenuLabels(): array
{
    return [
        'hr_employees',
        'hr_departments',
        'hr_sections',
        'hr_jobs',
        'hr_employment_types',
        'hr_biometric_devices',
        'hr_shifts',
        'hr_document_types',
        'hr_insurance_offices',
    ];
}

function hrRestoredLegacyHrMenuLabels(): array
{
    return [
        'hr_allowances',
        'hr_areas',
        'hr_cities',
        'hr_countries',
        'hr_faculties',
        'hr_governorates',
        'hr_grades',
        'hr_hiring_statuses',
        'hr_identifications',
        'hr_military_services',
        'hr_nationalities',
        'hr_qualifications',
        'hr_religions',
        'hr_specializations',
        'hr_universities',
    ];
}

function hrMenuRoutes(array $items): array
{
    $routes = [];

    foreach ($items as $item) {
        $route = $item['route'] ?? null;

        if (is_string($route) && $route !== '') {
            $routes[] = $route;
        }

        $routes = [
            ...$routes,
            ...hrMenuRoutes($item['children'] ?? []),
        ];
    }

    return $routes;
}

function hrEmployeeFixtures(): array
{
    Storage::fake('public');

    $company = Company::factory()->create(['doc_number' => 201, 'doc_num' => 'Company-00201', 'name' => 'HR Company']);
    $branch = Branch::query()->create([
        'doc_number' => 301,
        'doc_num' => 'Branch-00301',
        'company_id' => $company->getKey(),
        'name' => 'Main HR Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $currency = Currency::query()->create([
        'doc_number' => 701,
        'doc_num' => 'CUR-00701',
        'company_id' => $company->getKey(),
        'name' => 'Egyptian Pound',
        'code' => 'EGP',
        'is_main' => true,
        'status' => 'active',
    ]);
    $department = HrDepartment::query()->create([
        'doc_number' => 801,
        'doc_num' => 'HRD-00801',
        'name' => 'Operations',
        'status' => 'active',
    ]);
    $section = HrSection::query()->create([
        'doc_number' => 802,
        'doc_num' => 'HRS-00802',
        'name' => 'Assembly',
        'department_id' => $department->getKey(),
        'status' => 'active',
    ]);
    $job = HrJob::query()->create([
        'doc_number' => 803,
        'doc_num' => 'HRJ-00803',
        'name' => 'Carpenter',
        'status' => 'active',
    ]);
    $employmentType = HrEmploymentType::query()->create(['doc_number' => 804, 'doc_num' => 'HRT-00804', 'name' => 'Full Time', 'status' => 'active']);
    $shift = HrShift::query()->create(['doc_number' => 805, 'doc_num' => 'HSH-00805', 'name' => 'Morning', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => 30, 'status' => 'active']);
    $device = HrBiometricDevice::query()->create(['doc_number' => 806, 'doc_num' => 'HBD-00806', 'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(), 'name' => 'Main Gate', 'device_uid' => 'GATE-01', 'status' => 'active']);
    $documentType = HrDocumentType::query()->create(['doc_number' => 807, 'doc_num' => 'HDT-00807', 'name' => 'Contract', 'status' => 'active']);

    Storage::disk('public')->put('tests/hr/contract.pdf', 'contract');
    $archiveFile = ArchiveFile::query()->create([
        'doc_number' => 901,
        'doc_num' => 'ARCH-00901',
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'hr',
        'record_type' => 'employee_document',
        'title' => 'Contract',
        'hidden_from_picker' => false,
        'original_name' => 'contract.pdf',
        'stored_name' => 'contract.pdf',
        'disk' => 'public',
        'path' => 'tests/hr/contract.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 8,
    ]);

    return compact('company', 'branch', 'currency', 'department', 'section', 'job', 'employmentType', 'shift', 'device', 'documentType', 'archiveFile');
}

function hrOperatingSession(array $fixtures): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixtures['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixtures['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixtures['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixtures['branch']->doc_num,
    ];
}

function hrEmployeePayload(array $fixtures, array $overrides = []): array
{
    return [
        'full_name' => 'Nadia Ahmed',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'gender' => 'female',
        'birth_date' => '1991-04-15',
        'marital_status' => 'single',
        'national_id' => '29104151234567',
        'hire_date' => '2026-01-01',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'branch_doc_num' => $fixtures['branch']->doc_num,
        'department_doc_num' => $fixtures['department']->doc_num,
        'section_doc_num' => $fixtures['section']->doc_num,
        'job_doc_num' => $fixtures['job']->doc_num,
        'employment_type_doc_num' => $fixtures['employmentType']->doc_num,
        'work_email' => 'nadia.ahmed@company.example.test',
        'email' => 'nadia.ahmed@example.test',
        'phone' => '+201000000001',
        'mobile' => '+201000000002',
        'address' => 'Cairo',
        'attendance_tracking_enabled' => true,
        'attendance_policy_type' => 'fixed_shift',
        'default_shift_doc_num' => $fixtures['shift']->doc_num,
        'allow_late_minutes' => 10,
        'allow_early_leave_minutes' => 5,
        'overtime_enabled' => true,
        'pay_basis' => 'monthly_salary',
        'payroll_currency_doc_num' => $fixtures['currency']->doc_num,
        'exchange_rate' => 1,
        'basic_salary' => 12000,
        'payment_method' => 'cash',
        'emergency_contact_name' => 'Omar Ahmed',
        'emergency_contact_phone' => '+201000000003',
        'biometric_mappings' => [
            [
                'device_doc_num' => $fixtures['device']->doc_num,
                'biometric_code' => 'FP-1001',
                'is_active' => true,
                'notes' => 'Main gate code',
            ],
        ],
        'notes' => 'Employee profile foundation only.',
        ...$overrides,
    ];
}

test('HrEmployee permissions are discovered and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    foreach (hrEmployeePermissions() as $permission) {
        expect(Permission::query()->where('name', $permission)->exists())->toBeTrue()
            ->and($admin->hasPermissionTo($permission))->toBeTrue();
    }
});

test('Human Resources menu exposes current screens and retained lookup screens', function () {
    app()->setLocale('en');

    $actor = hrEmployeeActor(hrAllReviewHrViewPermissions());

    $this->withSession(['locale' => 'en'])
        ->actingAs($actor)
        ->get(route('admin.hr.employees.index'))
        ->assertOk()
        ->assertSee(__('menu.human_resources'))
        ->assertSee(__('menu.hr_employees'))
        ->assertSee(__('menu.hr_departments'))
        ->assertSee(__('menu.hr_sections'))
        ->assertSee(__('menu.hr_jobs'))
        ->assertSee(__('menu.hr_employment_types'))
        ->assertSee(__('menu.hr_biometric_devices'))
        ->assertSee(__('menu.hr_shifts'))
        ->assertSee(__('menu.hr_document_types'))
        ->assertSee(__('menu.hr_insurance_offices'))
        ->assertSee(__('menu.hr_countries'))
        ->assertSee(__('menu.hr_grades'));

    $menu = app(MenuService::class)->getMenu($actor);
    $humanResources = collect($menu)->firstWhere('label', 'human_resources');
    $routes = hrMenuRoutes([$humanResources]);
    $labels = collect($humanResources['children'])->pluck('label')->all();

    expect($humanResources)->not->toBeNull()
        ->and($humanResources['text'])->toBe('Human Resources')
        ->and($labels)->toBe([
            ...hrSimplifiedHrMenuLabels(),
            ...hrRestoredLegacyHrMenuLabels(),
        ])
        ->and(collect($humanResources['children'])->firstWhere('label', 'hr_employees')['text'])->toBe('Employees')
        ->and(collect($humanResources['children'])->firstWhere('label', 'hr_sections')['text'])->toBe('Job Sections')
        ->and(collect($humanResources['children'])->firstWhere('label', 'hr_jobs')['text'])->toBe('Jobs')
        ->and(collect($humanResources['children'])->firstWhere('label', 'hr_employment_types')['text'])->toBe('Job Types')
        ->and($routes)->toBe(hrAllReviewHrIndexRoutes())
        ->and($routes)->not->toContain('admin.hr.select2.lookups')
        ->and($routes)->not->toContain('admin.hr.select2.foundation');

    foreach ($routes as $route) {
        expect(Route::has($route))->toBeTrue();
    }

    app()->setLocale('ar');

    $arabicMenu = app(MenuService::class)->getMenu($actor);
    $arabicHumanResources = collect($arabicMenu)->firstWhere('label', 'human_resources');

    expect($arabicHumanResources)->not->toBeNull()
        ->and($arabicHumanResources['text'])->toBe('الموارد البشرية')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_employees')['text'])->toBe('الموظفون')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_sections')['text'])->toBe('الأقسام الوظيفية')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_jobs')['text'])->toBe('الوظائف')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_employment_types')['text'])->toBe('أنواع الوظائف')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_document_types')['text'])->toBe('أنواع مستندات الموظفين')
        ->and(collect($arabicHumanResources['children'])->firstWhere('label', 'hr_insurance_offices')['text'])->toBe('مكاتب التأمين');

    app()->setLocale('en');

    $lookupOnly = hrEmployeeActor(['hr.countries.view']);
    $lookupOnlyHr = collect(app(MenuService::class)->getMenu($lookupOnly))->firstWhere('label', 'human_resources');

    expect($lookupOnlyHr)->not->toBeNull();
    expect(collect($lookupOnlyHr['children'])->pluck('label')->all())->toBe(['hr_countries']);
});

test('current and legacy review HR index routes are accessible to admin role and forbidden without permissions', function () {
    $this->seed(PermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail());

    foreach (hrAllReviewHrIndexRoutes() as $route) {
        $this->actingAs($admin)
            ->get(route($route))
            ->assertOk();
    }

    $unauthorized = User::factory()->create();

    foreach (hrAllReviewHrIndexRoutes() as $route) {
        $this->actingAs($unauthorized)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('HrEmployee form is tabbed and uses public select2 doc nums', function () {
    $fixtures = hrEmployeeFixtures();
    $actor = hrEmployeeActor([
        'hr.employees.view',
        'hr.employees.create',
        'hr.employees.document_number_settings.update',
        ...hrEmployeeLookupCreatePermissions(),
    ]);

    $this->actingAs($actor)
        ->get(route('admin.hr.employees.create'))
        ->assertOk()
        ->assertSee(__('hr.employees.sections.basic_info'))
        ->assertSee(__('hr.employees.sections.work_info'))
        ->assertSee(__('hr.employees.sections.attendance_biometric'))
        ->assertSee(__('hr.employees.sections.salary_payment'))
        ->assertSee(__('hr.employees.sections.documents'))
        ->assertSee(__('hr.employees.sections.notes'))
        ->assertSee('product-image-picker-panel', false)
        ->assertSee(route('admin.hr.select2.foundation', 'departments'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'sections'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'jobs'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'employment-types'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'document-types'), false)
        ->assertSee(route('admin.branches.create'), false)
        ->assertSee(route('admin.hr.departments.create'), false)
        ->assertSee(route('admin.hr.sections.create'), false)
        ->assertSee(route('admin.hr.jobs.create'), false)
        ->assertSee(route('admin.hr.employment-types.create'), false)
        ->assertSee(route('admin.hr.document-types.create'), false)
        ->assertSee('data-picker-accept="document"', false)
        ->assertDontSee('profession_doc_num', false)
        ->assertDontSee('attendance_tracking_enabled', false)
        ->assertDontSee('default_shift_doc_num', false)
        ->assertDontSee('allow_late_minutes', false)
        ->assertDontSee('allow_early_leave_minutes', false)
        ->assertDontSee('overtime_enabled', false)
        ->assertSee('biometric_mappings[__INDEX__][device_doc_num]', false)
        ->assertSee('hr-biometric-row-template', false)
        ->assertDontSee(route('admin.hr.select2.foundation', 'shifts'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'biometric-devices'), false)
        ->assertDontSee('documents[__INDEX__][document_number_text]', false)
        ->assertDontSee('dropdown-toggle-split', false)
        ->assertDontSee('hr-inline-select2.js', false)
        ->assertDontSee('data-target-select=', false)
        ->assertDontSee('data-id=', false);

    $this->getJson(route('admin.hr.select2.foundation', 'departments').'?q=Operations')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['department']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'sections').'?q=Assembly')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['section']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'jobs').'?q=Carpenter')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['job']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'employment-types').'?q=Full%20Time')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['employmentType']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'shifts').'?q=Morning')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['shift']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'biometric-devices').'?q=Main%20Gate')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['device']->doc_num);

    $this->getJson(route('admin.hr.select2.foundation', 'document-types').'?q=Contract')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['documentType']->doc_num);
});

test('HrEmployee datatable supports active inactive trashed lookup search ordering and bulk actions', function () {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures))
        ->assertOk();

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Youssef Hassan',
            'status' => 'stopped',
            'national_id' => '29104151234570',
            'email' => 'youssef.hassan@example.test',
            'work_email' => 'youssef.hassan@company.example.test',
            'biometric_mappings' => [],
        ]))
        ->assertOk();

    $activeEmployee = HrEmployee::query()->where('national_id', '29104151234567')->firstOrFail();
    $stoppedEmployee = HrEmployee::query()->where('national_id', '29104151234570')->firstOrFail();

    $activePayload = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'order' => [
                ['column' => 7, 'dir' => 'asc'],
            ],
            'search' => [
                'value' => 'Operations',
            ],
        ])))
        ->assertOk()
        ->json('data');

    $activeJson = json_encode($activePayload, JSON_THROW_ON_ERROR);
    expect($activeJson)->toContain($activeEmployee->doc_num)
        ->and($activeJson)->not->toContain($stoppedEmployee->doc_num);

    $inactivePayload = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'trash_filter' => 'inactive',
            'order' => [
                ['column' => 8, 'dir' => 'asc'],
            ],
            'search' => [
                'value' => 'Assembly',
            ],
        ])))
        ->assertOk()
        ->json('data');

    $inactiveJson = json_encode($inactivePayload, JSON_THROW_ON_ERROR);
    expect($inactiveJson)->toContain($stoppedEmployee->doc_num)
        ->and($inactiveJson)->not->toContain($activeEmployee->doc_num);

    $this->withSession($session)
        ->deleteJson(route('admin.hr.employees.destroy', $activeEmployee->doc_num))
        ->assertOk();

    $trashedPayload = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'trash_filter' => 'trashed',
            'order' => [
                ['column' => 21, 'dir' => 'asc'],
            ],
            'search' => [
                'value' => 'Nadia',
            ],
        ])))
        ->assertOk()
        ->json('data');

    $trashedJson = json_encode($trashedPayload, JSON_THROW_ON_ERROR);
    expect($trashedJson)->toContain($activeEmployee->doc_num)
        ->and($trashedJson)->not->toContain($stoppedEmployee->doc_num);

    $allPayload = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'trash_filter' => 'all',
            'order' => [
                ['column' => 22, 'dir' => 'desc'],
            ],
        ])))
        ->assertOk()
        ->json('data');

    $allJson = json_encode($allPayload, JSON_THROW_ON_ERROR);
    expect($allJson)->toContain($activeEmployee->doc_num)
        ->and($allJson)->toContain($stoppedEmployee->doc_num);

    $this->withSession($session)
        ->patchJson(route('admin.hr.employees.bulk-restore'), [
            'doc_nums' => [$activeEmployee->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('data.restored', 1);

    $this->withSession($session)
        ->patchJson(route('admin.hr.employees.bulk-status'), [
            'doc_nums' => [$activeEmployee->doc_num],
            'status' => 'inactive',
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.status', 'inactive');

    $activeEmployee->refresh();
    expect($activeEmployee->status)->toBe('inactive')
        ->and($activeEmployee->restored_by)->toBe($actor->getKey())
        ->and($activeEmployee->restored_at)->not->toBeNull();

    $this->withSession($session)
        ->patchJson(route('admin.hr.employees.bulk-status'), [
            'doc_nums' => [$activeEmployee->doc_num],
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.status', 'active');

    $activeEmployee->refresh();
    expect($activeEmployee->status)->toBe('active');
});

test('file picker document mode accepts employee attachment files', function () {
    Storage::fake((string) config('archive.disk', 'local'));

    $actor = hrEmployeeActor(['file_manager.view', 'file_manager.upload']);
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.file-manager.picker.files.store'), [
            'accept' => 'document',
            'file' => UploadedFile::fake()->create('employee-contract.pdf', 12, 'application/pdf'),
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('archive.picker.file_uploaded'))
        ->assertJsonMissingPath('data.file.id')
        ->assertJsonMissingPath('data.file.path');

    $file = ArchiveFile::query()->where('original_name', 'employee-contract.pdf')->firstOrFail();

    expect($file->extension)->toBe('pdf')
        ->and($file->hidden_from_picker)->toBeFalse();

    $pickerPayload = $this->withSession($session)
        ->getJson(route('admin.file-manager.picker.items', ['accept' => 'document']))
        ->assertOk()
        ->json('data.files');

    expect(json_encode($pickerPayload, JSON_THROW_ON_ERROR))->toContain('employee-contract.pdf');
});

test('HrEmployee crud stores relations by public doc nums manages documents and hides internal ids', function () {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures))
        ->assertOk()
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('data.doc_num', 'Emp-00001')
        ->assertJsonMissingPath('redirect')
        ->assertJsonMissingPath('data.id');

    $employee = HrEmployee::query()->firstOrFail();

    expect($employee->company_id)->toBe($fixtures['company']->getKey())
        ->and($employee->branch_id)->toBe($fixtures['branch']->getKey())
        ->and($employee->department_id)->toBe($fixtures['department']->getKey())
        ->and($employee->section_id)->toBe($fixtures['section']->getKey())
        ->and($employee->job_id)->toBe($fixtures['job']->getKey())
        ->and($employee->employment_type_id)->toBe($fixtures['employmentType']->getKey())
        ->and($employee->default_shift_id)->toBe($fixtures['shift']->getKey())
        ->and($employee->payroll_currency_id)->toBe($fixtures['currency']->getKey())
        ->and($employee->biometricMappings()->count())->toBe(1)
        ->and($employee->work_email)->toBe('nadia.ahmed@company.example.test')
        ->and($employee->email)->toBe('nadia.ahmed@example.test');

    $this->withSession($session)->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
        'order' => [
            ['column' => 7, 'dir' => 'asc'],
        ],
        'search' => [
            'value' => 'Operations',
        ],
    ])))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id');

    $this->withSession($session)->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
        'national_id' => '29104151234568',
        'email' => 'nadia.updated@example.test',
        'work_email' => 'nadia.updated.work@company.example.test',
        'doc_number' => 7,
        'biometric_mappings' => [
            [
                'id' => $employee->biometricMappings()->firstOrFail()->getKey(),
                'device_doc_num' => $fixtures['device']->doc_num,
                'biometric_code' => 'FP-1001',
                'is_active' => true,
                'notes' => 'Main gate code',
            ],
        ],
    ]))->assertOk()->assertJsonPath('data.doc_num', 'Emp-00007');

    $employee->refresh();

    $this->withSession($session)->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
        'national_id' => '29104151234569',
        'email' => 'nadia.updated@example.test',
        'work_email' => 'another.work@company.example.test',
    ]))->assertUnprocessable();

    $this->withSession($session)->postJson(route('admin.hr.employees.documents.store', $employee->doc_num), [
        'document_type_doc_num' => $fixtures['documentType']->doc_num,
        'document_type' => 'contract',
        'title' => 'Contract',
        'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
    ])->assertOk()->assertJsonMissingPath('data.id');

    $document = HrEmployeeDocument::query()->firstOrFail();
    expect($document->document_type)->toBe('contract')
        ->and($document->document_type_id)->toBe($fixtures['documentType']->getKey())
        ->and($document->archive_file_id)->toBe($fixtures['archiveFile']->getKey());

    $viewer = hrEmployeeActor(['hr.employees.view', 'hr.employees.documents.view']);
    $this->actingAs($viewer)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.documents.store', $employee->doc_num), [
            'document_type_doc_num' => $fixtures['documentType']->doc_num,
            'document_type' => 'other',
            'title' => 'Blocked',
            'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
        ])->assertForbidden();

    $this->actingAs($actor)->withSession($session)->deleteJson(route('admin.hr.employees.destroy', $employee->doc_num))->assertOk();
    $this->withSession($session)->patchJson(route('admin.hr.employees.restore', $employee->doc_num))->assertOk();
});
