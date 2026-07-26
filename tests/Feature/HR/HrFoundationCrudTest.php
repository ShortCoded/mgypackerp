<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\HR\Models\HrGrade;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Models\HrShift;
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
