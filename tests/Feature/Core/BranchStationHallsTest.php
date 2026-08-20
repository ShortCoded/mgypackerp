<?php

use App\Models\User;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function branchStationHallsActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function branchStationHallsCompany(): Company
{
    return Company::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'Company-08801',
        'name' => 'Branch Halls Company',
        'status' => 'active',
    ]);
}

test('factory branch can store multiple unique halls', function () {
    $actor = branchStationHallsActor(['branches.create', 'branches.edit']);
    $company = branchStationHallsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'submit_action' => 'save_edit',
            'company_doc_num' => $company->doc_num,
            'name' => 'Factory Branch',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => ['Hall A', 'Hall B'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $branch = Branch::query()->where('name', 'Factory Branch')->firstOrFail();

    expect($branch->type)->toBe('factory')
        ->and($branch->halls()->pluck('name')->all())->toBe(['Hall A', 'Hall B']);
});

test('station branch type is rejected', function () {
    $actor = branchStationHallsActor(['branches.create']);
    $company = branchStationHallsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Legacy Station Branch',
            'type' => 'station',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('station branch type rows are migrated to factory', function () {
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8890,
        'doc_num' => 'Branch-08890',
        'company_id' => $company->getKey(),
        'name' => 'Legacy Station Migration Branch',
        'type' => 'station',
        'status' => 'active',
    ]);
    $migration = require base_path('modules/Core/Database/Migrations/2026_05_14_095042_replace_station_branch_type_with_factory.php');

    $migration->up();

    expect($branch->refresh()->type)->toBe('factory');

    $migration->down();

    expect($branch->refresh()->type)->toBe('station');
});

test('branch create form renders factory and halls labels without refrigerator or station wording', function () {
    $actor = branchStationHallsActor(['branches.create']);

    $this->actingAs($actor)
        ->withSession(['locale' => 'en'])
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertSee('value="factory"', false)
        ->assertDontSee('value="station"', false)
        ->assertSee('Factory')
        ->assertSee('Halls')
        ->assertSee('name="station_halls[0][name]"', false)
        ->assertSee('name="branch_stores[0][name]"', false)
        ->assertSee('js-add-station-hall', false)
        ->assertSee('js-add-branch-store', false)
        ->assertDontSee('Station Halls')
        ->assertDontSee('Refrigerators')
        ->assertDontSee('branch-refrigerators-tab', false)
        ->assertDontSee('js-refrigerators-section', false);

    $this->actingAs($actor)
        ->withSession(['locale' => 'ar'])
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertSee('مصنع')
        ->assertSee('الصالات')
        ->assertDontSee('محطة')
        ->assertDontSee('صالات المحطة')
        ->assertDontSee('التلاجات');

    $this->actingAs($actor)
        ->withSession([
            'locale' => 'en',
            '_old_input' => [
                'type' => 'factory',
                'station_halls' => [['name' => 'Recovered Hall']],
                'branch_stores' => [['name' => 'Recovered Store']],
            ],
        ])
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertSee('value="Recovered Hall"', false)
        ->assertSee('value="Recovered Store"', false);
});

test('branch company validation uses human labels in english and arabic', function () {
    $actor = branchStationHallsActor(['branches.create']);

    $this->actingAs($actor)
        ->withSession(['locale' => 'en'])
        ->postJson(route('admin.branches.store'), [
            'name' => 'Missing Company Branch',
            'type' => 'factory',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_doc_num'])
        ->assertJsonPath('errors.company_doc_num.0', 'The Company field is required.');

    $this->actingAs($actor)
        ->withSession(['locale' => 'ar'])
        ->postJson(route('admin.branches.store'), [
            'name' => 'فرع بدون شركة',
            'type' => 'factory',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_doc_num'])
        ->assertJsonPath('errors.company_doc_num.0', 'حقل الشركة مطلوب.');
});

test('factory branch can be created and updated without halls', function () {
    $actor = branchStationHallsActor(['branches.create', 'branches.edit']);
    $company = branchStationHallsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Factory Without Halls',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $branch = Branch::query()->where('name', 'Factory Without Halls')->firstOrFail();

    expect($branch->halls()->count())->toBe(0);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Factory Without Halls Updated',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['name' => ''],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($branch->refresh()->name)->toBe('Factory Without Halls Updated')
        ->and($branch->halls()->count())->toBe(0);
});

test('factory branch rejects duplicate hall names', function () {
    $actor = branchStationHallsActor(['branches.create']);
    $company = branchStationHallsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Duplicate Hall Factory',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => ['Main Hall', 'main hall'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('station_halls.1');
});

test('non-factory branch ignores submitted halls', function () {
    $actor = branchStationHallsActor(['branches.create']);
    $company = branchStationHallsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Administrative Branch',
            'type' => 'administrative',
            'status' => 'active',
            'station_halls' => ['Should not persist'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $branch = Branch::query()->where('name', 'Administrative Branch')->firstOrFail();

    expect($branch->type)->toBe('administrative')
        ->and($branch->halls()->count())->toBe(0);
});

test('changing factory branch to non-factory removes halls', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8802,
        'doc_num' => 'Branch-08802',
        'company_id' => $company->getKey(),
        'name' => 'Converted Factory',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $branch->halls()->createMany([
        ['name' => 'Hall A', 'position' => 1],
        ['name' => 'Hall B', 'position' => 2],
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Converted Factory',
            'type' => 'warehouse',
            'status' => 'active',
            'station_halls' => ['Hall A', 'Hall B'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($branch->refresh()->type)->toBe('warehouse')
        ->and($branch->halls()->count())->toBe(0)
        ->and(BranchHall::withTrashed()->where('branch_id', $branch->getKey())->whereNotNull('deleted_at')->count())->toBe(2);
});

test('hall update syncs existing new and removed rows', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8803,
        'doc_num' => 'Branch-08803',
        'company_id' => $company->getKey(),
        'name' => 'Hall Sync Factory',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $hallA = $branch->halls()->create(['name' => 'Hall A', 'position' => 1]);
    $hallB = $branch->halls()->create(['name' => 'Hall B', 'position' => 2]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Hall Sync Factory',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['key' => $hallA->public_uuid, 'name' => 'Hall Alpha'],
                ['name' => 'Hall C'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($hallA->refresh()->name)->toBe('Hall Alpha')
        ->and($hallA->trashed())->toBeFalse()
        ->and(BranchHall::withTrashed()->find($hallB->getKey())?->trashed())->toBeTrue()
        ->and($branch->halls()->pluck('name')->all())->toBe(['Hall Alpha', 'Hall C']);
});

test('editing factory branch with unchanged existing hall keeps same row without duplicate insert', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8804,
        'doc_num' => 'Branch-08804',
        'company_id' => $company->getKey(),
        'name' => 'Unchanged Hall Factory',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $hall = $branch->halls()->create(['name' => 'cvxvb', 'position' => 1]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Unchanged Hall Factory Updated',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['key' => $hall->public_uuid, 'name' => 'cvxvb'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($branch->halls()->count())->toBe(1)
        ->and($branch->halls()->first()?->is($hall->refresh()))->toBeTrue()
        ->and($hall->name)->toBe('cvxvb');
});

test('editing factory branch with missing hall key matches existing name instead of inserting duplicate', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8805,
        'doc_num' => 'Branch-08805',
        'company_id' => $company->getKey(),
        'name' => 'Legacy Hall Payload Factory',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $hall = $branch->halls()->create(['name' => 'cvxvb', 'position' => 1]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Legacy Hall Payload Factory Updated',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['name' => 'cvxvb'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($branch->halls()->count())->toBe(1)
        ->and($branch->halls()->first()?->is($hall->refresh()))->toBeTrue();
});

test('renaming hall to another active hall name fails validation before database insert', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8806,
        'doc_num' => 'Branch-08806',
        'company_id' => $company->getKey(),
        'name' => 'Hall Rename Conflict Factory',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $hallA = $branch->halls()->create(['name' => 'Hall A', 'position' => 1]);
    $hallB = $branch->halls()->create(['name' => 'Hall B', 'position' => 2]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $branch->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Hall Rename Conflict Factory',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['key' => $hallA->public_uuid, 'name' => 'Hall B'],
                ['key' => $hallB->public_uuid, 'name' => 'Hall B'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['station_halls.1', 'station_halls.0.name']);
});

test('same hall name remains allowed under different branches', function () {
    $actor = branchStationHallsActor(['branches.edit']);
    $company = branchStationHallsCompany();
    $first = Branch::query()->create([
        'doc_number' => 8807,
        'doc_num' => 'Branch-08807',
        'company_id' => $company->getKey(),
        'name' => 'First Shared Hall Branch',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $second = Branch::query()->create([
        'doc_number' => 8808,
        'doc_num' => 'Branch-08808',
        'company_id' => $company->getKey(),
        'name' => 'Second Shared Hall Branch',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $first->halls()->create(['name' => 'Shared Hall', 'position' => 1]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $second->doc_num), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Second Shared Hall Branch',
            'type' => 'factory',
            'status' => 'active',
            'station_halls' => [
                ['name' => 'Shared Hall'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($first->halls()->where('name', 'Shared Hall')->count())->toBe(1)
        ->and($second->halls()->where('name', 'Shared Hall')->count())->toBe(1);
});

test('factory branch show page renders read only halls tab', function () {
    $actor = branchStationHallsActor(['branches.view']);
    $company = branchStationHallsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8809,
        'doc_num' => 'Branch-08809',
        'company_id' => $company->getKey(),
        'name' => 'Factory View Details Branch',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $branch->halls()->create(['name' => 'View Hall', 'position' => 1]);

    $this->actingAs($actor)
        ->get(route('admin.branches.show', $branch->doc_num))
        ->assertOk()
        ->assertSee('data-branch-type="factory"', false)
        ->assertSee('id="branch-station-halls-tab"', false)
        ->assertSee('View Hall')
        ->assertDontSee('js-add-station-hall', false)
        ->assertDontSee('js-remove-station-hall', false);
});
