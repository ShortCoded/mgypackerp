<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
use Modules\HR\Models\HrAllowance;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeDocument;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrNationality;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;
use Modules\HR\Models\HrSocialInsurancePolicy;
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
        'hr.social_insurance_policies.view',
        'hr.employment_tax_policies.view',
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
        'admin.hr.document-types.index',
        'admin.hr.insurance-offices.index',
        'admin.hr.social-insurance-policies.index',
        'admin.hr.employment-tax-policies.index',
        ...hrRestoredLegacyHrIndexRoutes(),
        'admin.hr.biometric-devices.index',
        'admin.hr.shifts.index',
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
    return hrSimplifiedHrIndexRoutes();
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
        'hr_social_insurance_policies',
        'hr_employment_tax_policies',
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

function hrMenuItemByLabel(array $items, string $label): ?array
{
    foreach ($items as $item) {
        if (($item['label'] ?? null) === $label) {
            return $item;
        }

        $match = hrMenuItemByLabel($item['children'] ?? [], $label);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
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
    $nationality = HrNationality::query()->create(['doc_number' => 811, 'doc_num' => 'HRN-00811', 'name' => 'Egyptian']);
    $hiringStatus = HrHiringStatus::query()->create(['doc_number' => 812, 'doc_num' => 'HHS-00812', 'name' => 'Appointed']);
    $allowance = HrAllowance::query()->create(['doc_number' => 813, 'doc_num' => 'HAL-00813', 'name' => 'Transportation']);
    $shift = HrShift::query()->create(['doc_number' => 805, 'doc_num' => 'HSH-00805', 'name' => 'Morning', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => 30, 'status' => 'active']);
    $device = HrBiometricDevice::query()->create(['doc_number' => 806, 'doc_num' => 'HBD-00806', 'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(), 'name' => 'Main Gate', 'device_uid' => 'GATE-01', 'status' => 'active']);
    $documentType = HrDocumentType::query()->create(['doc_number' => 807, 'doc_num' => 'HDT-00807', 'name' => 'Contract', 'status' => 'active']);
    $insuranceOffice = HrInsuranceOffice::query()->create(['doc_number' => 808, 'doc_num' => 'HIO-00808', 'name' => 'Nasr City Insurance Office', 'status' => 'active']);

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

    return compact('company', 'branch', 'currency', 'department', 'section', 'job', 'employmentType', 'nationality', 'hiringStatus', 'allowance', 'shift', 'device', 'documentType', 'insuranceOffice', 'archiveFile');
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
        'contract_start_date' => '2026-01-01',
        'contract_end_date' => '2026-12-31',
        'probation_end_date' => '2026-03-31',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'branch_doc_num' => $fixtures['branch']->doc_num,
        'department_doc_num' => $fixtures['department']->doc_num,
        'section_doc_num' => $fixtures['section']->doc_num,
        'job_doc_num' => $fixtures['job']->doc_num,
        'employment_type_doc_num' => $fixtures['employmentType']->doc_num,
        'nationality_doc_num' => $fixtures['nationality']->doc_num,
        'hiring_status_doc_num' => $fixtures['hiringStatus']->doc_num,
        'allowance_doc_num' => $fixtures['allowance']->doc_num,
        'work_email' => 'nadia.ahmed@company.example.test',
        'email' => 'nadia.ahmed@example.test',
        'personal_email' => 'nadia.personal@example.test',
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

function hrEmployeeSignatureImage(Company $company): ArchiveFile
{
    Storage::disk('public')->put('tests/hr/employee-signature.png', 'image-content');

    return ArchiveFile::query()->create([
        'doc_number' => 990,
        'doc_num' => 'ARCH-EMP-SIGN-990',
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'hr',
        'record_type' => 'employee_signature',
        'hidden_from_picker' => false,
        'original_name' => 'employee-signature.png',
        'stored_name' => 'employee-signature.png',
        'disk' => 'public',
        'path' => 'tests/hr/employee-signature.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 13,
    ]);
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
        ->and($labels)->toBe(['employee_data', 'hr_setup', 'attendance_leave'])
        ->and(hrMenuItemByLabel([$humanResources], 'hr_employees')['text'])->toBe('Employees')
        ->and(hrMenuItemByLabel([$humanResources], 'hr_sections')['text'])->toBe('Job Sections')
        ->and(hrMenuItemByLabel([$humanResources], 'hr_jobs')['text'])->toBe('Jobs')
        ->and(hrMenuItemByLabel([$humanResources], 'hr_employment_types')['text'])->toBe('Job Types')
        ->and(hrMenuItemByLabel([$humanResources], 'hr_social_insurance_policies')['text'])->toBe('Social Insurance Policies')
        ->and(hrMenuItemByLabel([$humanResources], 'hr_employment_tax_policies')['text'])->toBe('Employment Tax Policies')
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
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_employees')['text'])->toBe('الموظفون')
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_sections')['text'])->toBe('الأقسام الوظيفية')
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_jobs')['text'])->toBe('الوظائف')
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_employment_types')['text'])->toBe('أنواع الوظائف')
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_document_types')['text'])->toBe('أنواع مستندات الموظفين')
        ->and(hrMenuItemByLabel([$arabicHumanResources], 'hr_insurance_offices')['text'])->toBe('مكاتب التأمين');

    app()->setLocale('en');

    $lookupOnly = hrEmployeeActor(['hr.countries.view']);
    $lookupOnlyHr = collect(app(MenuService::class)->getMenu($lookupOnly))->firstWhere('label', 'human_resources');

    expect($lookupOnlyHr)->not->toBeNull();
    expect(collect($lookupOnlyHr['children'])->pluck('label')->all())->toBe(['hr_setup'])
        ->and(hrMenuItemByLabel([$lookupOnlyHr], 'hr_countries'))->not->toBeNull();
});

test('HrEmployee index uses the shared wide table usability contract', function (): void {
    $actor = hrEmployeeActor(['hr.employees.view']);

    $this->actingAs($actor)
        ->get(route('admin.hr.employees.index'))
        ->assertOk()
        ->assertSee('js-hr-employees-filters', false)
        ->assertSee('erp-datatable-scroll', false)
        ->assertSee('erp-datatable-wide', false)
        ->assertSee('erp-datatable-sticky-columns', false);

    $script = file_get_contents(public_path('assets/js/modules/HR/hr-employees.js'));
    $sharedScript = file_get_contents(public_path('assets/js/modules/Core/datatables-defaults.js'));
    $stylesheet = file_get_contents(public_path('assets/css/user.css'));

    expect($script)->toContain(
        'window.AppDataTables.wideOptions',
        'const protectedColumns = [0, 1, -1]',
        "className: 'dt-select no-colvis all",
        "className: 'dt-code no-colvis all",
        "className: 'dt-actions no-colvis all",
    )->not->toContain('responsiveControlTarget')
        ->and($sharedScript)->toContain(
            'scrollX: true',
            'responsive: false',
            'bindDropdownOverflow',
        )
        ->and($stylesheet)->toContain(
            '.erp-datatable-scroll',
            'overflow-x: auto',
            '.erp-datatable.erp-datatable-wide .dt-text',
            '.erp-datatable.erp-datatable-sticky-columns .dt-select',
            'inset-inline-start: 3rem',
            'inset-inline-end: 0',
        );
});

test('current and legacy review HR index routes are accessible to admin role and forbidden without permissions', function () {
    $this->seed(PermissionSeeder::class);

    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail());

    foreach (hrAllReviewHrIndexRoutes() as $route) {
        $response = $this->withSession($session)
            ->actingAs($admin)
            ->followingRedirects()
            ->get(route($route));

        expect($response->status(), "Route [{$route}] should be accessible to the admin role.")->toBe(200);
    }

    $unauthorized = User::factory()->create();

    foreach (hrAllReviewHrIndexRoutes() as $route) {
        $response = $this->withSession($session)
            ->actingAs($unauthorized)
            ->get(route($route));

        expect($response->status(), "Route [{$route}] should be forbidden without its view permission.")->toBe(403);
    }
});

test('HrEmployee form is tabbed and uses public select2 doc nums', function () {
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $actor = hrEmployeeActor([
        ...hrEmployeePermissions(),
        'file_manager.view',
        ...hrEmployeeLookupCreatePermissions(),
    ]);

    $this->actingAs($actor)
        ->withSession($session)
        ->get(route('admin.hr.employees.create'))
        ->assertOk()
        ->assertSee(__('hr.employees.sections.basic_info'))
        ->assertSee(__('hr.employees.sections.work_info'))
        ->assertSee(__('hr.employees.sections.attendance_biometric'))
        ->assertSee(__('hr.employees.sections.salary_payment'))
        ->assertSee(__('hr.employees.sections.insurance_taxes'))
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
        ->assertSee('attendance_tracking_enabled', false)
        ->assertSee('default_shift_doc_num', false)
        ->assertSee('allow_late_minutes', false)
        ->assertSee('allow_early_leave_minutes', false)
        ->assertSee('overtime_enabled', false)
        ->assertSee('biometric_mappings[__INDEX__][device_doc_num]', false)
        ->assertSee('hr-biometric-row-template', false)
        ->assertSee(route('admin.hr.select2.foundation', 'shifts'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'biometric-devices'), false)
        ->assertSee(route('admin.hr.select2.foundation', 'insurance-offices'), false)
        ->assertSee('data-depends-on="#hr-employee-department-doc-num"', false)
        ->assertSee('data-dependent-result-field="department_doc_num"', false)
        ->assertSee('hr-employee-form-tabs', false)
        ->assertSee('hr-employee-form-grid', false)
        ->assertSee('col-12 col-md-6 col-xl-4 col-xxl-3', false)
        ->assertSee('col-12 col-md-6 col-xl-8 col-xxl-6', false)
        ->assertDontSee('cost_center_doc_num', false)
        ->assertDontSee('Alternative Cost Center')
        ->assertDontSee('Cost Center Override')
        ->assertDontSee('مركز التكلفة البديل')
        ->assertSee('js-hr-biometric-card-title', false)
        ->assertSee('documents[__INDEX__][document_number_text]', false)
        ->assertSee('name="documents[__INDEX__][alert_before_expiry_days]"', false)
        ->assertSee('data-numeric-max="3650"', false)
        ->assertDontSee('dropdown-toggle-split', false)
        ->assertDontSee('hr-inline-select2.js', false)
        ->assertDontSee('data-target-select=', false)
        ->assertDontSee('data-id=', false);

    $stylesheet = file_get_contents(public_path('assets/css/user.css'));

    expect($stylesheet)->toContain(
        '.hr-employee-form-tabs',
        'flex-wrap: nowrap',
        'overflow-x: auto',
        '.hr-employee-form-card .row > *',
        '.hr-select2-inline-control .select2-container',
        'min-width: 0',
        'width: 100% !important',
    );

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'departments').'?q=Operations')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['department']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'sections').'?q=Assembly')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['section']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'jobs').'?q=Carpenter')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['job']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'employment-types').'?q=Full%20Time')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['employmentType']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'shifts').'?q=Morning')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['shift']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'biometric-devices').'?q=Main%20Gate')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['device']->doc_num);

    $this->withSession($session)->getJson(route('admin.hr.select2.foundation', 'document-types').'?q=Contract')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['documentType']->doc_num);
});

test('HrEmployee signature continues to use the shared archive image picker and validation', function (): void {
    Storage::fake('public');

    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $signature = hrEmployeeSignatureImage($fixtures['company']);
    $actor = hrEmployeeActor([...hrEmployeePermissions(), 'file_manager.view']);
    $payload = hrEmployeePayload($fixtures, [
        'signature_archive_file_doc_num' => $signature->doc_num,
    ]);

    $response = $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), $payload)
        ->assertOk();

    $employee = HrEmployee::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($employee->signature_archive_file_id)->toBe($signature->getKey());

    $this->withSession($session)
        ->get(route('admin.hr.employees.edit', $employee->doc_num))
        ->assertOk()
        ->assertSee('name="signature_archive_file_doc_num"', false)
        ->assertSee('data-picker-collection="employee_signature"', false)
        ->assertSee($signature->doc_num)
        ->assertSee('assets/js/modules/Core/archive-image-picker-field.js', false);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), [
            ...$payload,
            'biometric_mappings' => [[
                ...$payload['biometric_mappings'][0],
                'id' => $employee->biometricMappings()->firstOrFail()->getKey(),
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');
});

test('HrEmployee insurance and tax profiles validate persist hydrate filter and remain no-op safe', function (): void {
    $actor = hrEmployeeActor([
        ...hrEmployeePermissions(),
        'hr.social_insurance_policies.edit',
        'hr.employment_tax_policies.edit',
    ]);
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $insurancePolicy = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 831,
        'doc_num' => 'HSIP-00831',
        'company_id' => $fixtures['company']->getKey(),
        'name' => 'TEST Effective Insurance Policy',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'employee_contribution_rate' => '7.0000',
        'employer_contribution_rate' => '13.0000',
        'minimum_contribution_wage' => '2000.00',
        'maximum_contribution_wage' => '20000.00',
        'rounding_rule' => 'nearest',
        'status' => 'active',
    ]);
    $insurancePolicy->components()->createMany([
        ['name' => 'TEST Component A', 'employee_rate' => '5.0000', 'employer_rate' => '10.0000', 'calculation_basis' => 'contribution_wage', 'is_active' => true, 'sort_order' => 0],
        ['name' => 'TEST Component B', 'employee_rate' => '2.0000', 'employer_rate' => '3.0000', 'calculation_basis' => 'contribution_wage', 'is_active' => true, 'sort_order' => 1],
    ]);
    $taxPolicy = HrEmploymentTaxPolicy::query()->create([
        'doc_number' => 832,
        'doc_num' => 'HETP-00832',
        'company_id' => $fixtures['company']->getKey(),
        'name' => 'TEST Effective Tax Policy',
        'tax_year' => 2026,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'annual_exemption_amount' => '1000.00',
        'rounding_rule' => 'down',
        'status' => 'active',
    ]);
    $taxPolicy->brackets()->createMany([
        ['from_amount' => '0', 'to_amount' => '10000', 'rate' => '0', 'sort_order' => 0],
        ['from_amount' => '10000', 'to_amount' => '20000', 'rate' => '5', 'sort_order' => 1],
        ['from_amount' => '20000', 'to_amount' => null, 'rate' => '10', 'sort_order' => 2],
    ]);
    $payload = hrEmployeePayload($fixtures, [
        'insurance_status' => 'subject',
        'social_insurance_number' => '٠١٢٣٤٥٦٧٨٩',
        'insurance_office_doc_num' => $fixtures['insuranceOffice']->doc_num,
        'insurance_start_date' => '2026-01-01',
        'insurance_end_date' => '2026-12-31',
        'insurance_contribution_wage' => '10,000.00',
        'insurance_notes' => 'Active social insurance profile.',
        'tax_status' => 'subject',
        'tax_start_date' => '2026-01-01',
        'tax_notes' => 'Standard employment tax treatment.',
        'biometric_mappings' => [],
    ]);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), $payload)
        ->assertOk();

    $employee = HrEmployee::query()->firstOrFail();

    expect($employee->insurance_status)->toBe('subject')
        ->and($employee->social_insurance_number)->toBe('0123456789')
        ->and($employee->insurance_office_id)->toBe($fixtures['insuranceOffice']->getKey())
        ->and($employee->insurance_start_date?->toDateString())->toBe('2026-01-01')
        ->and($employee->insurance_end_date?->toDateString())->toBe('2026-12-31')
        ->and($employee->insurance_contribution_wage)->toBe('10000.00')
        ->and($employee->tax_status)->toBe('subject')
        ->and($employee->tax_start_date?->toDateString())->toBe('2026-01-01');

    $this->withSession($session)
        ->get(route('admin.hr.employees.edit', $employee->doc_num))
        ->assertOk()
        ->assertSee('value="0123456789"', false)
        ->assertSee('value="'.$fixtures['insuranceOffice']->doc_num.'" selected', false)
        ->assertSee('value="10,000"', false)
        ->assertSee('TEST Effective Insurance Policy')
        ->assertSee('TEST Component A')
        ->assertSee('700.00')
        ->assertSee(route('admin.hr.social-insurance-policies.edit', $insurancePolicy->doc_num), false)
        ->assertSee('TEST Effective Tax Policy')
        ->assertSee(__('hr.employees.statutory.tax_calculation_deferred'))
        ->assertSee(route('admin.hr.employment-tax-policies.edit', $taxPolicy->doc_num), false);

    $activityCount = DB::table(config('activitylog.table_name', 'activity_log'))->count();

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect(DB::table(config('activitylog.table_name', 'activity_log'))->count())->toBe($activityCount);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), [
            ...$payload,
            'insurance_contribution_wage' => '11,000.00',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $activityProperties = json_decode((string) DB::table(config('activitylog.table_name', 'activity_log'))
        ->where('event', 'hr.employees.update')
        ->latest('id')
        ->value('properties'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($activityProperties, 'changes.insurance_contribution_wage.old'))->toBe('10000.00')
        ->and(data_get($activityProperties, 'changes.insurance_contribution_wage.new'))->toBe('11000.00');

    $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'insurance_status' => 'subject',
            'tax_status' => 'subject',
            'insurance_office_doc_num' => $fixtures['insuranceOffice']->doc_num,
        ])))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Non-insured Employee',
            'national_id' => '29104150000999',
            'email' => 'not.insured@example.test',
            'work_email' => 'not.insured@company.example.test',
            'insurance_status' => 'not_subject',
            'insurance_non_coverage_reason' => 'Covered by another statutory arrangement.',
            'tax_status' => 'not_subject',
            'biometric_mappings' => [],
        ]))
        ->assertOk();

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Missing Insured Fields',
            'national_id' => '29104150000998',
            'email' => 'missing.insurance@example.test',
            'work_email' => 'missing.insurance@company.example.test',
            'insurance_status' => 'subject',
            'tax_status' => 'not_subject',
            'biometric_mappings' => [],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'social_insurance_number',
            'insurance_office_doc_num',
            'insurance_start_date',
            'insurance_contribution_wage',
        ]);
});

test('HrEmployee filters start collapsed and section lookup respects its department dependency', function (): void {
    $actor = hrEmployeeActor(['hr.employees.view', 'hr.employees.create']);
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $otherDepartment = HrDepartment::query()->create([
        'doc_number' => 901,
        'doc_num' => 'HRD-00901',
        'name' => 'Finance',
        'status' => 'active',
    ]);
    $otherSection = HrSection::query()->create([
        'doc_number' => 902,
        'doc_num' => 'HRS-00902',
        'name' => 'Accounts',
        'department_id' => $otherDepartment->getKey(),
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->withSession($session)
        ->get(route('admin.hr.employees.index'))
        ->assertOk()
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('class="collapse" id="hr-employees-filters"', false)
        ->assertSee('data-depends-on="#hr-employees-filter-department-doc-num"', false);

    $results = $this->withSession($session)
        ->getJson(route('admin.hr.select2.foundation', 'sections').'?department_doc_num='.urlencode($fixtures['department']->doc_num))
        ->assertOk()
        ->assertJsonPath('results.0.department_doc_num', $fixtures['department']->doc_num)
        ->json('results');

    expect(collect($results)->pluck('id')->all())
        ->toContain($fixtures['section']->doc_num)
        ->not->toContain($otherSection->doc_num);
});

test('HrEmployee branch Select2 uses the current company context and public branch doc nums', function (): void {
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $actor = hrEmployeeActor(['hr.employees.create', 'hr.employees.edit']);
    $arabicBranch = Branch::query()->create([
        'doc_number' => 302,
        'doc_num' => 'Branch-00302',
        'company_id' => $fixtures['company']->getKey(),
        'name' => 'فرع القاهرة الرئيسي',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $inactiveBranch = Branch::query()->create([
        'doc_number' => 303,
        'doc_num' => 'Branch-00303',
        'company_id' => $fixtures['company']->getKey(),
        'name' => 'Inactive HR Branch',
        'type' => 'administrative',
        'status' => 'inactive',
    ]);
    $deletedBranch = Branch::query()->create([
        'doc_number' => 304,
        'doc_num' => 'Branch-00304',
        'company_id' => $fixtures['company']->getKey(),
        'name' => 'Deleted HR Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $deletedBranch->delete();
    $foreignCompany = Company::factory()->create([
        'doc_number' => 202,
        'doc_num' => 'Company-00202',
        'name' => 'Foreign HR Select2 Company',
        'status' => 'active',
        'is_main' => 2,
    ]);
    $foreignBranch = Branch::query()->create([
        'doc_number' => 305,
        'doc_num' => 'Branch-00305',
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign HR Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $branchSelectUrl = route('admin.select2.branches', [
        'access_scope' => 'operating_scope',
        'company_doc_num' => $fixtures['company']->doc_num,
    ]);

    $this->actingAs($actor)
        ->withSession($session)
        ->get(route('admin.hr.employees.create'))
        ->assertOk()
        ->assertSee('data-url="'.e($branchSelectUrl).'"', false);

    $englishResponse = $this->withSession($session)
        ->getJson($branchSelectUrl.'&q=Main%20HR')
        ->assertOk()
        ->assertJsonPath('results.0.id', $fixtures['branch']->doc_num)
        ->assertJsonStructure(['results' => [['id', 'text', 'company_doc_num']], 'pagination' => ['more']]);

    expect($englishResponse->json('results.0.text'))->toContain($fixtures['branch']->name);

    $this->withSession($session)
        ->getJson($branchSelectUrl.'&q='.urlencode('القاهرة'))
        ->assertOk()
        ->assertJsonPath('results.0.id', $arabicBranch->doc_num);

    $resultDocNums = collect($this->withSession($session)
        ->getJson($branchSelectUrl)
        ->assertOk()
        ->json('results'))
        ->pluck('id')
        ->all();

    expect($resultDocNums)
        ->toContain($fixtures['branch']->doc_num, $arabicBranch->doc_num)
        ->not->toContain($inactiveBranch->doc_num, $deletedBranch->doc_num, $foreignBranch->doc_num);

    $this->withSession([
        ...$session,
        'locale' => 'ar',
        '_old_input' => ['branch_doc_num' => $arabicBranch->doc_num],
    ])
        ->get(route('admin.hr.employees.create'))
        ->assertOk()
        ->assertSee('value="'.$arabicBranch->doc_num.'" selected', false);

    $employee = HrEmployee::query()->create([
        'doc_number' => 998,
        'doc_num' => 'Emp-00998',
        'company_id' => $fixtures['company']->getKey(),
        'branch_id' => $fixtures['branch']->getKey(),
        'full_name' => 'Branch Hydration Employee',
        'name' => 'Branch Hydration Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
    ]);

    foreach (['en', 'ar'] as $locale) {
        $this->withSession([...$session, 'locale' => $locale, '_old_input' => []])
            ->get(route('admin.hr.employees.edit', $employee->doc_num))
            ->assertOk()
            ->assertSee('value="'.$fixtures['branch']->doc_num.'" selected', false);
    }
});

test('HrEmployee datatable supports active inactive trashed lookup search ordering and bulk actions', function () {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'cost_center_doc_num' => 'REMOVED-EMPLOYEE-OVERRIDE',
        ]))
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

    $structuredPayload = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'trash_filter' => 'all',
            'status' => 'stopped',
            'branch_doc_num' => $fixtures['branch']->doc_num,
            'job_doc_num' => $fixtures['job']->doc_num,
            'hire_from' => '2026-01-01',
            'hire_to' => '2026-01-31',
        ])))
        ->assertOk()
        ->json('data');

    $structuredJson = json_encode($structuredPayload, JSON_THROW_ON_ERROR);
    expect($structuredJson)->toContain($stoppedEmployee->doc_num)
        ->and($structuredJson)->not->toContain($activeEmployee->doc_num);

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
            'public_uuids' => [$activeEmployee->public_uuid],
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

test('HrEmployee pay fields use grouped presentation and canonical decimal persistence', function () {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Precision Wage Employee',
            'national_id' => '00123456789012',
            'email' => 'precision.wage@example.test',
            'work_email' => 'precision.wage@company.example.test',
            'phone' => '0012345000',
            'pay_basis' => 'hourly_wage',
            'basic_salary' => null,
            'hourly_wage' => '1,250.5001',
            'biometric_mappings' => [],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $employee = HrEmployee::query()->where('full_name', 'Precision Wage Employee')->firstOrFail();

    expect((string) $employee->hourly_wage)->toBe('1250.5001')
        ->and($employee->phone)->toBe('0012345000')
        ->and($employee->national_id)->toBe('00123456789012');

    $this->withSession($session)
        ->get(route('admin.hr.employees.show', $employee->doc_num))
        ->assertOk()
        ->assertSee('value="1,250.5001"', false)
        ->assertSee('dir="ltr"', false);

    $row = $this->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery([
            'search' => ['value' => 'Precision Wage Employee'],
        ])))
        ->assertOk()
        ->json('data.0');

    expect($row['pay_amount'] ?? null)->toBe('1,250.5001');

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Malformed Wage Employee',
            'national_id' => '00123456789013',
            'email' => 'malformed.wage@example.test',
            'work_email' => 'malformed.wage@company.example.test',
            'pay_basis' => 'hourly_wage',
            'basic_salary' => null,
            'hourly_wage' => '1,2,3',
            'biometric_mappings' => [],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hourly_wage']);
});

test('employee document alert days use grouped input display and canonical integer validation on both save paths', function () {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Document Alert Employee',
            'national_id' => '00123456789014',
            'email' => 'document.alert@example.test',
            'work_email' => 'document.alert@company.example.test',
            'basic_salary' => 250,
            'biometric_mappings' => [],
            'documents' => [
                [
                    'document_type_doc_num' => $fixtures['documentType']->doc_num,
                    'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
                    'file_label' => 'Nested Contract',
                    'alert_before_expiry_days' => '1,000',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $employee = HrEmployee::query()->where('full_name', 'Document Alert Employee')->firstOrFail();
    $nestedDocument = $employee->documents()->firstOrFail();

    expect($nestedDocument->alert_before_expiry_days)->toBe(1000);

    $this->withSession($session)
        ->get(route('admin.hr.employees.show', $employee->doc_num))
        ->assertOk()
        ->assertSee('dir="ltr">1,000</div>', false);

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Out Of Range Document Alert Employee',
            'national_id' => '00123456789015',
            'email' => 'document.alert.range@example.test',
            'work_email' => 'document.alert.range@company.example.test',
            'biometric_mappings' => [],
            'documents' => [
                [
                    'document_type_doc_num' => $fixtures['documentType']->doc_num,
                    'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
                    'alert_before_expiry_days' => '3,651',
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['documents.0.alert_before_expiry_days']);

    $documentResponse = $this->withSession($session)
        ->postJson(route('admin.hr.employees.documents.store', $employee->doc_num), [
            'document_type_doc_num' => $fixtures['documentType']->doc_num,
            'title' => 'Standalone Contract',
            'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
            'alert_before_expiry_days' => '1,200',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $standaloneDocument = $employee->documents()->latest('id')->firstOrFail();

    expect($standaloneDocument->alert_before_expiry_days)->toBe(1200)
        ->and($documentResponse->json('data.row'))->toContain('dir="ltr">1,200</div>');

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.documents.store', $employee->doc_num), [
            'document_type_doc_num' => $fixtures['documentType']->doc_num,
            'title' => 'Malformed Alert',
            'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
            'alert_before_expiry_days' => '1,2,3',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert_before_expiry_days']);
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

    $this->withSession($session)
        ->postJson(route('admin.file-manager.picker.files.store'), [
            'accept' => 'document',
            'file' => UploadedFile::fake()->create('disguised.pdf', 12, 'application/x-php'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('employee nested documents remain idempotent and enforce ownership and permissions', function (): void {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
        ]))
        ->assertOk();

    $employee = HrEmployee::query()->firstOrFail();
    $documentRow = [
        'document_type_doc_num' => $fixtures['documentType']->doc_num,
        'document_number_text' => 'CONTRACT-001',
        'title' => 'Signed Contract',
        'archive_file_doc_num' => $fixtures['archiveFile']->doc_num,
        'file_label' => 'Employment Contract',
        'issue_date' => '2026-01-01',
        'expires_at' => '2026-12-31',
        'alert_before_expiry_days' => 30,
        'notes' => 'Original attachment',
    ];

    $createdResponse = $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [$documentRow],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $documentId = $createdResponse->json('data.document_row_ids.0');
    expect($documentId)->toBeInt()
        ->and($employee->documents()->count())->toBe(1);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [[...$documentRow, 'id' => $documentId]],
        ]))
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect($employee->documents()->count())->toBe(1);

    $secondDocumentResponse = $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [
                [...$documentRow, 'id' => $documentId],
                [
                    ...$documentRow,
                    'document_number_text' => 'CONTRACT-002',
                    'title' => 'Second Contract Copy',
                    'sort_order' => 1,
                ],
            ],
        ]))
        ->assertOk();

    $secondDocumentId = $secondDocumentResponse->json('data.document_row_ids.1');
    expect($secondDocumentId)->toBeInt()
        ->and($employee->documents()->count())->toBe(2);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [
                [...$documentRow, 'id' => $documentId],
                ['id' => $secondDocumentId, '_delete' => true],
            ],
        ]))
        ->assertOk();

    expect($employee->documents()->count())->toBe(1);

    Storage::disk('public')->put('tests/hr/replacement.pdf', 'replacement');
    $replacement = ArchiveFile::query()->create([
        'doc_number' => 902,
        'doc_num' => 'ARCH-00902',
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $fixtures['company']->getKey(),
        'module' => 'hr',
        'record_type' => 'employee_document',
        'title' => 'Replacement Contract',
        'hidden_from_picker' => false,
        'original_name' => 'replacement.pdf',
        'stored_name' => 'replacement.pdf',
        'disk' => 'public',
        'path' => 'tests/hr/replacement.pdf',
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 11,
    ]);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [[
                ...$documentRow,
                'id' => $documentId,
                'archive_file_doc_num' => $replacement->doc_num,
                'title' => 'Replacement Contract',
            ]],
        ]))
        ->assertOk();

    expect($employee->documents()->firstOrFail()->archive_file_id)->toBe($replacement->getKey())
        ->and($employee->documents()->count())->toBe(1);

    $secondEmployee = HrEmployee::query()->create([
        'doc_number' => 99,
        'doc_num' => 'Emp-00099',
        'company_id' => $fixtures['company']->getKey(),
        'full_name' => 'Second Employee',
        'name' => 'Second Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
    ]);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $secondEmployee->doc_num), hrEmployeePayload($fixtures, [
            'full_name' => 'Second Employee',
            'national_id' => '29999999999999',
            'email' => 'second@example.test',
            'work_email' => 'second@company.example.test',
            'biometric_mappings' => [],
            'documents' => [[...$documentRow, 'id' => $documentId]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['documents.0.id']);

    $editor = hrEmployeeActor(['hr.employees.edit']);
    $this->actingAs($editor)
        ->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [$documentRow],
        ]))
        ->assertForbidden();

    $this->actingAs($actor)
        ->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [],
            'documents' => [['id' => $documentId, '_delete' => true]],
        ]))
        ->assertOk();

    expect($employee->documents()->count())->toBe(0)
        ->and($employee->documents()->withTrashed()->count())->toBe(2);
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
        ->and($employee->nationality_id)->toBe($fixtures['nationality']->getKey())
        ->and($employee->hiring_status_id)->toBe($fixtures['hiringStatus']->getKey())
        ->and($employee->allowance_id)->toBe($fixtures['allowance']->getKey())
        ->and($employee->default_shift_id)->toBe($fixtures['shift']->getKey())
        ->and($employee->payroll_currency_id)->toBe($fixtures['currency']->getKey())
        ->and($employee->biometricMappings()->count())->toBe(1)
        ->and($employee->attendance_tracking_enabled)->toBeTrue()
        ->and($employee->allow_late_minutes)->toBe(10)
        ->and($employee->allow_early_leave_minutes)->toBe(5)
        ->and($employee->overtime_enabled)->toBeTrue()
        ->and($employee->contract_start_date?->toDateString())->toBe('2026-01-01')
        ->and($employee->contract_end_date?->toDateString())->toBe('2026-12-31')
        ->and($employee->probation_end_date?->toDateString())->toBe('2026-03-31')
        ->and($employee->basic_salary)->toBe('12000.00')
        ->and($employee->work_email)->toBe('nadia.ahmed@company.example.test')
        ->and($employee->email)->toBe('nadia.ahmed@example.test')
        ->and($employee->personal_email)->toBe('nadia.personal@example.test')
        ->and($employee->notes)->toBe('Employee profile foundation only.');

    $this->withSession($session)
        ->get(route('admin.hr.employees.edit', $employee->doc_num))
        ->assertOk()
        ->assertSee('col-12 col-md-6 col-xl-4 col-xxl-3', false)
        ->assertDontSee('cost_center_doc_num', false)
        ->assertSee('value="'.$fixtures['nationality']->doc_num.'" selected', false)
        ->assertSee('value="'.$fixtures['hiringStatus']->doc_num.'" selected', false)
        ->assertSee('value="'.$fixtures['allowance']->doc_num.'" selected', false)
        ->assertSee('value="'.$fixtures['shift']->doc_num.'" selected', false)
        ->assertSee('name="attendance_tracking_enabled" type="checkbox"', false)
        ->assertSee('name="overtime_enabled" type="checkbox"', false)
        ->assertSee('nadia.personal@example.test');

    $this->withSession($session)
        ->get(route('admin.hr.employees.show', $employee->doc_num))
        ->assertOk()
        ->assertSee('col-12 col-md-6 col-xl-4 col-xxl-3', false)
        ->assertDontSee('cost_center_doc_num', false)
        ->assertDontSee('REMOVED-EMPLOYEE-OVERRIDE');

    $fixtures['device']->update(['status' => 'inactive']);

    $this->withSession($session)
        ->getJson(route('admin.hr.select2.foundation', 'biometric-devices').'?q=Main%20Gate')
        ->assertOk()
        ->assertJsonCount(0, 'results');

    $this->withSession($session)
        ->get(route('admin.hr.employees.edit', $employee->doc_num))
        ->assertOk()
        ->assertSee('value="'.$fixtures['device']->doc_num.'" selected', false);

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $employee->doc_num), hrEmployeePayload($fixtures, [
            'biometric_mappings' => [[
                'id' => $employee->biometricMappings()->firstOrFail()->getKey(),
                'device_doc_num' => $fixtures['device']->doc_num,
                'biometric_code' => 'FP-1001',
                'is_active' => true,
                'notes' => 'Main gate code',
            ]],
        ]))
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $fixtures['device']->update(['status' => 'active']);

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

    $this->withSession($session)->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
        'full_name' => 'Duplicate National ID Employee',
        'national_id' => '29104151234568',
        'email' => 'unique.duplicate@example.test',
        'work_email' => 'unique.duplicate@company.example.test',
        'biometric_mappings' => [],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['national_id']);

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
    $this->withSession($session)->patchJson(route('admin.hr.employees.restore', $employee->public_uuid))->assertOk();
});

test('HrEmployee routes prefer the active company record and restore collisions remain explicit', function (): void {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $payload = hrEmployeePayload($fixtures, [
        'full_name' => 'Historical Employee',
        'national_id' => '29104150000001',
        'email' => 'historical.employee@example.test',
        'work_email' => 'historical.employee@company.example.test',
        'biometric_mappings' => [],
        'doc_number' => 41,
    ]);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.employees.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Emp-00041');
    $historical = HrEmployee::query()->where('full_name', 'Historical Employee')->firstOrFail();
    $activityCountBeforeNoOp = DB::table(config('activitylog.table_name', 'activity_log'))->count();

    $this->withSession($session)
        ->putJson(route('admin.hr.employees.update', $historical->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    expect(DB::table(config('activitylog.table_name', 'activity_log'))->count())->toBe($activityCountBeforeNoOp);

    $this->withSession($session)
        ->deleteJson(route('admin.hr.employees.destroy', $historical->doc_num))
        ->assertOk();

    $activePayload = hrEmployeePayload($fixtures, [
        'full_name' => 'Active Employee',
        'national_id' => '29104150000002',
        'email' => 'active.employee@example.test',
        'work_email' => 'active.employee@company.example.test',
        'biometric_mappings' => [],
        'doc_number' => 41,
    ]);
    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), $activePayload)
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'Emp-00041');

    $this->withSession($session)
        ->get(route('admin.hr.employees.show', 'Emp-00041'))
        ->assertOk()
        ->assertSee('Active Employee')
        ->assertDontSee('Historical Employee');
    $this->withSession($session)
        ->get(route('admin.hr.employees.trashed.show', $historical->public_uuid))
        ->assertOk()
        ->assertSee('Historical Employee')
        ->assertSee(route('admin.hr.employees.restore', $historical->public_uuid), false)
        ->assertSee(route('admin.hr.employees.show', $historical->doc_num), false)
        ->assertDontSee(route('admin.hr.employees.restore', $historical->doc_num), false)
        ->assertDontSee('Active Employee');
    $this->withSession($session)
        ->patchJson(route('admin.hr.employees.restore', $historical->public_uuid))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('hr.employees.messages.restore_conflict'));

    expect($historical->refresh()->trashed())->toBeTrue();
});

test('HrEmployee list and branch validation are restricted to the operating company', function (): void {
    $actor = hrEmployeeActor(hrEmployeePermissions());
    $fixtures = hrEmployeeFixtures();
    $session = hrOperatingSession($fixtures);
    $foreignCompanyId = DB::table('companies')->insertGetId([
        'doc_number' => 9981,
        'doc_num' => 'Company-09981',
        'name' => 'Foreign HR Company',
        'status' => 'active',
        'is_main' => 2,
        'country' => 'Egypt',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $foreignCompany = Company::query()->findOrFail($foreignCompanyId);
    $foreignBranch = Branch::query()->create([
        'doc_number' => 9981,
        'doc_num' => 'Branch-09981',
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Foreign HR Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $foreignDevice = HrBiometricDevice::query()->create([
        'doc_number' => 9982,
        'doc_num' => 'HBD-09982',
        'company_id' => $foreignCompany->getKey(),
        'branch_id' => $foreignBranch->getKey(),
        'name' => 'Foreign Gate',
        'device_uid' => 'FOREIGN-GATE',
        'status' => 'active',
    ]);
    HrEmployee::query()->create([
        'doc_number' => 9981,
        'doc_num' => 'Emp-09981',
        'company_id' => $foreignCompany->getKey(),
        'branch_id' => $foreignBranch->getKey(),
        'full_name' => 'Foreign Company Employee',
        'name' => 'Foreign Company Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
    ]);

    $data = $this->actingAs($actor)
        ->withSession($session)
        ->getJson(route('admin.hr.employees.data', hrEmployeeDataTableQuery()))
        ->assertOk()
        ->json('data');

    expect(json_encode($data, JSON_THROW_ON_ERROR))->not->toContain('Foreign Company Employee');

    $deviceOptions = $this->withSession($session)
        ->getJson(route('admin.hr.select2.foundation', 'biometric-devices').'?q=Gate')
        ->assertOk()
        ->json('results');

    expect(json_encode($deviceOptions, JSON_THROW_ON_ERROR))
        ->toContain($fixtures['device']->doc_num)
        ->not->toContain($foreignDevice->doc_num);

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Invalid Branch Employee',
            'national_id' => '29104150000003',
            'email' => 'invalid.branch@example.test',
            'work_email' => 'invalid.branch@company.example.test',
            'branch_doc_num' => $foreignBranch->doc_num,
            'biometric_mappings' => [],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_doc_num']);

    $this->withSession($session)
        ->postJson(route('admin.hr.employees.store'), hrEmployeePayload($fixtures, [
            'full_name' => 'Invalid Biometric Employee',
            'national_id' => '29104150000004',
            'email' => 'invalid.biometric@example.test',
            'work_email' => 'invalid.biometric@company.example.test',
            'biometric_mappings' => [[
                'device_doc_num' => $foreignDevice->doc_num,
                'biometric_code' => 'FOREIGN-001',
                'is_active' => true,
            ]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['biometric_mappings.0.device_doc_num']);
});
