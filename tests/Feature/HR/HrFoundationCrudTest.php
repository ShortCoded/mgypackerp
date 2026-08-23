<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrGrade;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Models\HrShift;
use Modules\HR\Models\HrSocialInsurancePolicy;
use Modules\HR\Services\HrSocialInsuranceContributionCalculator;
use Modules\HR\Services\HrStatutoryPolicyResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    if (! Route::has('admin.hr.insurance-offices.index')) {
        $this->markTestSkipped('HR admin routes are disabled from the active app surface.');
    }
});

function hrFoundationActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function hrFoundationPermissions(string $prefix): array
{
    return [
        "{$prefix}.view",
        "{$prefix}.create",
        "{$prefix}.edit",
        "{$prefix}.delete",
        "{$prefix}.clone",
        "{$prefix}.view_trashed",
        "{$prefix}.restore",
        "{$prefix}.document_number.control",
        "{$prefix}.document_number_settings.update",
    ];
}

test('current HR foundation permissions are discovered and obsolete HR foundation permissions are absent', function () {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminPermissionNames = $admin->permissions()->pluck('name')->all();

    foreach ([
        'hr.departments',
        'hr.sections',
        'hr.jobs',
        'hr.document_types',
        'hr.shifts',
        'hr.biometric_devices',
        'hr.grades',
        'hr.employment_types',
        'hr.insurance_offices',
        'hr.social_insurance_policies',
        'hr.employment_tax_policies',
    ] as $prefix) {
        foreach (hrFoundationPermissions($prefix) as $permission) {
            expect($registryPermissions)->toContain($permission)
                ->and($adminPermissionNames)->toContain($permission);
        }
    }

    expect($registryPermissions)
        ->not->toContain('hr.regulations.view')
        ->not->toContain('hr.attendance_rules.view')
        ->not->toContain('hr.org_units.view')
        ->not->toContain('hr.org_unit_types.view')
        ->not->toContain('hr.positions.view')
        ->not->toContain('hr.cost_centers.view')
        ->not->toContain('hr.work_locations.view')
        ->not->toContain('hr.contract_types.view');
});

test('statutory policies are company scoped effective dated and tax brackets are relational', function (): void {
    $actor = hrFoundationActor([
        ...hrFoundationPermissions('hr.social_insurance_policies'),
        ...hrFoundationPermissions('hr.employment_tax_policies'),
    ]);
    $company = Company::factory()->create([
        'doc_number' => 751,
        'doc_num' => 'Company-00751',
        'name' => 'Statutory Policy Company',
        'status' => 'active',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 752,
        'doc_num' => 'Branch-00752',
        'company_id' => $company->getKey(),
        'name' => 'Statutory Policy Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
    ];
    $insurancePayload = [
        'name' => 'Insurance Policy 2026',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'minimum_contribution_wage' => '2,000.00',
        'maximum_contribution_wage' => '20,000.00',
        'rounding_rule' => 'nearest',
        'status' => 'active',
        'insurance_components' => [
            [
                'name' => 'TEST Component A',
                'employee_rate' => '5.0000',
                'employer_rate' => '10.0000',
                'calculation_basis' => 'contribution_wage',
                'is_active' => true,
                'notes' => 'Neutral test fixture; not a statutory rate.',
            ],
            [
                'name' => 'TEST Component B',
                'employee_rate' => '2.0000',
                'employer_rate' => '3.0000',
                'calculation_basis' => 'contribution_wage',
                'is_active' => true,
                'notes' => 'Neutral test fixture; not a statutory rate.',
            ],
        ],
    ];

    $this->actingAs($actor)
        ->withSession($session)
        ->get(route('admin.hr.social-insurance-policies.create'))
        ->assertOk()
        ->assertSee('insurance_components[0][employee_rate]', false)
        ->assertSee(__('hr.foundation.insurance_components.total_employee'));

    $this->withSession($session)
        ->get(route('admin.hr.employment-tax-policies.create'))
        ->assertOk()
        ->assertSee('tax_brackets[0][from_amount]', false);

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.social-insurance-policies.store'), $insurancePayload)
        ->assertOk();

    $insurancePolicy = HrSocialInsurancePolicy::query()->with('components')->firstOrFail();
    $insurancePreview = app(HrSocialInsuranceContributionCalculator::class)->preview($insurancePolicy, '10000');

    expect($insurancePolicy->company_id)->toBe($company->getKey())
        ->and($insurancePolicy->employee_contribution_rate)->toBe('7.0000')
        ->and($insurancePolicy->employer_contribution_rate)->toBe('13.0000')
        ->and($insurancePolicy->maximum_contribution_wage)->toBe('20000.00')
        ->and($insurancePolicy->components)->toHaveCount(2)
        ->and($insurancePreview['employee_contribution'] ?? null)->toBe('700.00')
        ->and($insurancePreview['employer_contribution'] ?? null)->toBe('1300.00')
        ->and($insurancePreview['combined_contribution'] ?? null)->toBe('2000.00');

    $this->withSession($session)
        ->postJson(route('admin.hr.social-insurance-policies.store'), [
            ...$insurancePayload,
            'name' => 'Overlapping Insurance Policy',
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_from']);

    $this->withSession($session)
        ->postJson(route('admin.hr.social-insurance-policies.store'), [
            ...$insurancePayload,
            'name' => 'Invalid Combined Contribution Policy',
            'effective_from' => '2029-01-01',
            'effective_to' => '2029-12-31',
            'insurance_components' => [
                ...$insurancePayload['insurance_components'],
                [
                    'name' => 'TEST Excess Component',
                    'employee_rate' => '50.0000',
                    'employer_rate' => '50.0000',
                    'calculation_basis' => 'contribution_wage',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['insurance_components']);

    $taxPayload = [
        'name' => 'Employment Tax Policy 2026',
        'tax_year' => '2026',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'annual_exemption_amount' => '15,000.00',
        'rounding_rule' => 'down',
        'status' => 'active',
        'tax_brackets' => [
            ['from_amount' => '0', 'to_amount' => '20,000.00', 'rate' => '0', 'notes' => 'Neutral test bracket.'],
            ['from_amount' => '20,000.00', 'to_amount' => '40,000.00', 'rate' => '5.0000', 'notes' => 'Neutral test bracket.'],
            ['from_amount' => '40,000.00', 'to_amount' => null, 'rate' => '12.5000', 'notes' => 'Neutral test bracket.'],
        ],
    ];

    $this->withSession($session)
        ->postJson(route('admin.hr.employment-tax-policies.store'), $taxPayload)
        ->assertOk();

    $taxPolicy = HrEmploymentTaxPolicy::query()->with('brackets')->firstOrFail();

    expect($taxPolicy->company_id)->toBe($company->getKey())
        ->and($taxPolicy->brackets)->toHaveCount(3)
        ->and($taxPolicy->brackets->first()->from_amount)->toBe('0.00')
        ->and($taxPolicy->brackets->get(1)?->notes)->toBe('Neutral test bracket.')
        ->and($taxPolicy->brackets->last()->rate)->toBe('12.5000');

    $resolvedInsurance = app(HrStatutoryPolicyResolver::class)->socialInsuranceAt('2026-06-30');
    $resolvedTax = app(HrStatutoryPolicyResolver::class)->employmentTaxAt('2026-06-30');

    expect($resolvedInsurance?->is($insurancePolicy))->toBeTrue()
        ->and($resolvedTax?->is($taxPolicy))->toBeTrue()
        ->and($resolvedTax?->brackets)->toHaveCount(3);

    $futureInsurancePolicy = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 754,
        'doc_num' => 'HSIP-00754',
        'company_id' => $company->getKey(),
        'name' => 'TEST Insurance Policy 2027',
        'effective_from' => '2027-01-01',
        'effective_to' => '2027-12-31',
        'employee_contribution_rate' => '1.0000',
        'employer_contribution_rate' => '2.0000',
        'rounding_rule' => 'nearest',
        'status' => 'active',
    ]);
    HrSocialInsurancePolicy::query()->create([
        'doc_number' => 755,
        'doc_num' => 'HSIP-00755',
        'company_id' => $company->getKey(),
        'name' => 'TEST Inactive Insurance Policy 2028',
        'effective_from' => '2028-01-01',
        'effective_to' => '2028-12-31',
        'employee_contribution_rate' => '1.0000',
        'employer_contribution_rate' => '2.0000',
        'rounding_rule' => 'nearest',
        'status' => 'inactive',
    ]);

    expect(app(HrStatutoryPolicyResolver::class)->socialInsuranceAt('2026-06-30')?->is($insurancePolicy))->toBeTrue()
        ->and(app(HrStatutoryPolicyResolver::class)->socialInsuranceAt('2027-06-30')?->is($futureInsurancePolicy))->toBeTrue()
        ->and(app(HrStatutoryPolicyResolver::class)->socialInsuranceAt('2028-06-30'))->toBeNull();

    $updatePayload = [
        ...$taxPayload,
        'tax_brackets' => $taxPolicy->brackets->map(fn ($bracket): array => [
            'public_uuid' => $bracket->public_uuid,
            'from_amount' => $bracket->from_amount,
            'to_amount' => $bracket->to_amount,
            'rate' => $bracket->rate,
            'notes' => $bracket->notes,
        ])->all(),
    ];

    $this->withSession($session)
        ->putJson(route('admin.hr.employment-tax-policies.update', $taxPolicy->doc_num), $updatePayload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $activityCount = DB::table(config('activitylog.table_name', 'activity_log'))->count();

    $this->withSession($session)
        ->putJson(route('admin.hr.employment-tax-policies.update', $taxPolicy->doc_num), [
            ...$updatePayload,
            'tax_brackets' => [
                $updatePayload['tax_brackets'][0],
                $updatePayload['tax_brackets'][1],
                [...$updatePayload['tax_brackets'][2], 'rate' => '13.0000'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $policyActivityProperties = json_decode((string) DB::table(config('activitylog.table_name', 'activity_log'))
        ->where('event', 'hr.employment_tax_policies.update')
        ->latest('id')
        ->value('properties'), true, flags: JSON_THROW_ON_ERROR);

    expect(DB::table(config('activitylog.table_name', 'activity_log'))->count())->toBe($activityCount + 1)
        ->and($taxPolicy->brackets()->orderBy('sort_order')->get()->last()->rate)->toBe('13.0000')
        ->and(data_get($policyActivityProperties, 'changes.tax_brackets.new.bracket_3.rate'))->toBe('13.0000');

    $this->withSession($session)
        ->postJson(route('admin.hr.employment-tax-policies.store'), [
            ...$taxPayload,
            'name' => 'Invalid Open-ended Tax Policy',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'tax_brackets' => [
                ['from_amount' => '0', 'to_amount' => null, 'rate' => '0'],
                ['from_amount' => '40,000.00', 'to_amount' => null, 'rate' => '12.5000'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_brackets.0.to_amount', 'tax_brackets.1.from_amount']);

    $this->withSession($session)
        ->postJson(route('admin.hr.employment-tax-policies.store'), [
            ...$taxPayload,
            'name' => 'Invalid Overlapping Tax Policy',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'tax_brackets' => [
                ['from_amount' => '0', 'to_amount' => '30,000.00', 'rate' => '0'],
                ['from_amount' => '20,000.00', 'to_amount' => null, 'rate' => '5.0000'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_brackets.1.from_amount']);

    $this->withSession($session)
        ->postJson(route('admin.hr.employment-tax-policies.store'), [
            ...$taxPayload,
            'name' => 'Invalid Reversed Tax Policy',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'tax_brackets' => [
                ['from_amount' => '0', 'to_amount' => '20,000.00', 'rate' => '0'],
                ['from_amount' => '20,000.00', 'to_amount' => '10,000.00', 'rate' => '5.0000'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_brackets.1.to_amount']);

    $this->withSession($session)
        ->postJson(route('admin.hr.employment-tax-policies.store'), [
            ...$taxPayload,
            'name' => 'Invalid Gap Tax Policy',
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'tax_brackets' => [
                ['from_amount' => '0', 'to_amount' => '20,000.00', 'rate' => '0'],
                ['from_amount' => '30,000.00', 'to_amount' => null, 'rate' => '5.0000'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tax_brackets.1.from_amount']);

    $otherCompany = Company::factory()->create([
        'doc_number' => 753,
        'doc_num' => 'Company-00753',
        'name' => 'Other Statutory Company',
        'status' => 'active',
        'is_main' => 2,
    ]);
    $otherPolicy = HrSocialInsurancePolicy::query()->create([
        'doc_number' => 999,
        'doc_num' => 'HSIP-00999',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Company Policy',
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-12-31',
        'employee_contribution_rate' => '0',
        'employer_contribution_rate' => '0',
        'rounding_rule' => 'nearest',
        'status' => 'active',
    ]);

    $this->withSession($session)
        ->get(route('admin.hr.social-insurance-policies.edit', $otherPolicy->doc_num))
        ->assertNotFound();

    $this->withSession($session)
        ->deleteJson(route('admin.hr.social-insurance-policies.destroy', $insurancePolicy->doc_num))
        ->assertOk();
    $this->withSession($session)
        ->postJson(route('admin.hr.social-insurance-policies.store'), [
            ...$insurancePayload,
            'name' => 'Replacement Insurance Policy',
        ])
        ->assertOk();
    $this->withSession($session)
        ->patchJson(route('admin.hr.social-insurance-policies.restore', $insurancePolicy->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('data.conflict_type', 'effective_period_conflict')
        ->assertJsonPath('data.conflict_fields.0', 'effective_period');
});

test('attendance device setup assigns the operating company and optional code is selectable by employees', function (): void {
    $actor = hrFoundationActor([
        ...hrFoundationPermissions('hr.biometric_devices'),
        'hr.employees.create',
    ]);
    $company = Company::factory()->create([
        'doc_number' => 761,
        'doc_num' => 'Company-00761',
        'name' => 'Attendance Device Company',
        'status' => 'active',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 762,
        'doc_num' => 'Branch-00762',
        'company_id' => $company->getKey(),
        'name' => 'Attendance Device Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
    ];

    $this->actingAs($actor)
        ->withSession($session)
        ->postJson(route('admin.hr.biometric-devices.store'), [
            'name' => 'North Gate Device',
            'device_uid' => null,
            'serial_number' => 'SERIAL-001',
            'location' => 'North Gate',
            'status' => 'active',
        ])
        ->assertOk();

    $device = HrBiometricDevice::query()->firstOrFail();

    expect($device->company_id)->toBe($company->getKey())
        ->and($device->device_uid)->toBeNull();

    $this->withSession($session)
        ->getJson(route('admin.hr.select2.foundation', 'biometric-devices', ['q' => 'North Gate']))
        ->assertOk()
        ->assertJsonPath('results.0.id', $device->doc_num);

    $this->withSession($session)
        ->get(route('admin.hr.biometric-devices.edit', $device->doc_num))
        ->assertOk()
        ->assertSee(__('hr.foundation.help.device_uid'))
        ->assertDontSee('connection_password', false);
});

test('shift break minutes and grade rank use grouped integer presentation and schema bounds', function () {
    $actor = hrFoundationActor([
        ...hrFoundationPermissions('hr.shifts'),
        ...hrFoundationPermissions('hr.grades'),
    ]);

    $this->actingAs($actor)
        ->get(route('admin.hr.shifts.create'))
        ->assertOk()
        ->assertSee('name="break_minutes"', false)
        ->assertSee('data-numeric-input', false)
        ->assertSee('data-numeric-scale="0"', false)
        ->assertSee('data-numeric-min="0"', false)
        ->assertSee('data-numeric-max="65535"', false);

    $this->postJson(route('admin.hr.shifts.store'), [
        'name' => 'Maximum Break Shift',
        'start_time' => '08:00',
        'end_time' => '16:00',
        'break_minutes' => '65,535',
        'crosses_midnight' => false,
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $shift = HrShift::query()->where('name', 'Maximum Break Shift')->firstOrFail();

    expect($shift->break_minutes)->toBe(65535);

    $this->get(route('admin.hr.shifts.show', $shift->doc_num))
        ->assertOk()
        ->assertSee('value="65,535"', false)
        ->assertSee('dir="ltr"', false);

    $shiftRow = $this->getJson(route('admin.hr.shifts.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
    ]))
        ->assertOk()
        ->json('data.0');

    expect($shiftRow['break_minutes'] ?? null)->toBe('65,535');

    $this->postJson(route('admin.hr.shifts.store'), [
        'name' => 'Out Of Range Break Shift',
        'break_minutes' => '65,536',
        'status' => 'active',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['break_minutes']);

    $this->get(route('admin.hr.grades.create'))
        ->assertOk()
        ->assertSee('name="rank"', false)
        ->assertSee('data-numeric-input', false)
        ->assertSee('data-numeric-max="65535"', false);

    $this->postJson(route('admin.hr.grades.store'), [
        'name' => 'Maximum Rank Grade',
        'code' => 'MAX-RANK',
        'rank' => '65,535',
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $grade = HrGrade::query()->where('name', 'Maximum Rank Grade')->firstOrFail();

    expect($grade->rank)->toBe(65535);

    $this->get(route('admin.hr.grades.show', $grade->doc_num))
        ->assertOk()
        ->assertSee('value="65,535"', false);

    $gradeRow = $this->getJson(route('admin.hr.grades.data', [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
    ]))
        ->assertOk()
        ->json('data.0');

    expect($gradeRow['rank'] ?? null)->toBe('65,535');

    $this->postJson(route('admin.hr.grades.store'), [
        'name' => 'Malformed Rank Grade',
        'code' => 'BAD-RANK',
        'rank' => '1,2,3',
        'status' => 'active',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rank']);
});

test('HrInsuranceOffice crud stores validates deletes restores and hides internal ids', function () {
    $actor = hrFoundationActor(hrFoundationPermissions('hr.insurance_offices'));

    $this->actingAs($actor)
        ->get(route('admin.hr.insurance-offices.index'))
        ->assertOk()
        ->assertSee(__('hr.insurance_offices.title'))
        ->assertDontSee('data-id=', false);

    $this->get(route('admin.hr.insurance-offices.create'))
        ->assertOk()
        ->assertSee('insurance_office_code', false)
        ->assertSee('contact_person', false);

    $response = $this->postJson(route('admin.hr.insurance-offices.store'), [
        'name' => 'Nasr City Office',
        'insurance_office_code' => 'IOF-NC',
        'address' => 'Nasr City, Cairo',
        'phone' => '+20200000001',
        'email' => 'nasr.office@example.test',
        'contact_person' => 'Mona Salem',
        'status' => 'active',
        'notes' => 'Main insurance office contact.',
    ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'InsOffice-00001')
        ->assertJsonMissingPath('data.id');

    expect($response->json('data.doc_num'))->toBe('InsOffice-00001');

    $record = HrInsuranceOffice::query()->firstOrFail();

    expect($record->insurance_office_code)->toBe('IOF-NC')
        ->and($record->address)->toBe('Nasr City, Cairo')
        ->and($record->phone)->toBe('+20200000001')
        ->and($record->email)->toBe('nasr.office@example.test')
        ->and($record->contact_person)->toBe('Mona Salem')
        ->and($record->created_by)->toBe($actor->id);

    $this->postJson(route('admin.hr.insurance-offices.store'), [
        'name' => 'Duplicate Office',
        'insurance_office_code' => 'IOF-NC',
        'status' => 'active',
    ])->assertUnprocessable();

    $row = $this->getJson(route('admin.hr.insurance-offices.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->json('data.0');

    expect(implode(' ', $row))->toContain('IOF-NC')
        ->toContain('nasr.office@example.test');

    $this->get(route('admin.hr.insurance-offices.show', $record->doc_num))->assertOk();
    $this->get(route('admin.hr.insurance-offices.edit', $record->doc_num))->assertOk();
    $this->get(route('admin.hr.insurance-offices.clone', $record->doc_num))
        ->assertOk()
        ->assertSee(__('hr.defaults.clone_name', ['name' => 'Nasr City Office']));

    $this->putJson(route('admin.hr.insurance-offices.update', $record->doc_num), [
        'name' => 'Giza Office',
        'insurance_office_code' => 'IOF-GZ',
        'address' => 'Giza',
        'phone' => '+20200000002',
        'email' => 'giza.office@example.test',
        'contact_person' => 'Omar Hassan',
        'status' => 'active',
        'doc_number' => 9,
        'submit_action' => 'save_view',
    ])
        ->assertOk()
        ->assertJsonPath('data.old_doc_num', 'InsOffice-00001')
        ->assertJsonPath('data.doc_num', 'InsOffice-00009')
        ->assertJsonPath('redirect', route('admin.hr.insurance-offices.show', 'InsOffice-00009'));

    $record->refresh();

    $second = HrInsuranceOffice::query()->create([
        'doc_number' => 20,
        'doc_num' => 'InsOffice-00020',
        'name' => 'Alex Office',
        'status' => 'active',
    ]);
    $third = HrInsuranceOffice::query()->create([
        'doc_number' => 21,
        'doc_num' => 'InsOffice-00021',
        'name' => 'Delta Office',
        'status' => 'active',
    ]);

    $this->deleteJson(route('admin.hr.insurance-offices.bulk-delete'), [
        'doc_nums' => [$second->doc_num, $third->doc_num],
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);

    $this->deleteJson(route('admin.hr.insurance-offices.destroy', $record->doc_num))->assertOk();

    expect(HrInsuranceOffice::query()->count())->toBe(0)
        ->and(HrInsuranceOffice::withTrashed()->count())->toBe(3);

    $this->patchJson(route('admin.hr.insurance-offices.restore', $record->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);
});
