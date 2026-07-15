<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Company;
use Modules\HR\Models\HrDepartment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    if (! Route::has('admin.hr.select2.inline.lookups.store')) {
        $this->markTestSkipped('HR admin routes are disabled from the active app surface.');
    }
});

function hrSelect2InlineActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('hr select2 inline lookup creates a country and returns a select2 option', function (): void {
    $user = hrSelect2InlineActor(['hr.countries.create']);

    $this->actingAs($user)
        ->postJson(route('admin.hr.select2.inline.lookups.store', 'countries'), [
            'name' => 'Inline Country '.uniqid('', true),
            'notes' => 'Created from inline form',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['option' => ['id', 'text']]]);
});

test('hr select2 inline foundation creates a department when quick create is supported', function (): void {
    $user = hrSelect2InlineActor(['hr.departments.create']);

    $this->actingAs($user)
        ->postJson(route('admin.hr.select2.inline.foundation.store', 'departments'), [
            'name' => 'Inline Department '.uniqid('', true),
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['option' => ['id', 'text']]]);

    expect(HrDepartment::query()->where('name', 'like', 'Inline Department %')->exists())->toBeTrue();
});

test('hr select2 inline creates a company and returns a select2 option', function (): void {
    $user = hrSelect2InlineActor(['companies.create']);

    $this->actingAs($user)
        ->postJson(route('admin.hr.select2.inline.companies.store'), [
            'name' => 'Inline Company '.uniqid('', true),
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['option' => ['id', 'text']]]);
});

test('hr select2 inline creates a branch for the selected company', function (): void {
    $company = Company::factory()->create(['status' => 'active']);
    $user = hrSelect2InlineActor(['branches.create']);

    $this->actingAs($user)
        ->postJson(route('admin.hr.select2.inline.branches.store'), [
            'name' => 'Inline Branch '.uniqid('', true),
            'company_doc_num' => $company->doc_num,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['option' => ['id', 'text']]]);
});
