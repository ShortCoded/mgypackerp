<?php

use App\Models\User;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchRefrigerator;
use Modules\Core\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function branchRefrigeratorsActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function branchRefrigeratorsCompany(): Company
{
    return Company::query()->create([
        'doc_number' => 8901,
        'doc_num' => 'Company-08901',
        'name' => 'Branch Refrigerator Removal Company',
        'status' => 'active',
    ]);
}

test('branch create form no longer renders refrigerator section or controls', function () {
    $actor = branchRefrigeratorsActor(['branches.create']);

    $this->actingAs($actor)
        ->withSession(['locale' => 'en'])
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertDontSee('Refrigerators')
        ->assertDontSee('branch-refrigerators-tab', false)
        ->assertDontSee('branch-refrigerators-pane', false)
        ->assertDontSee('data-shortcut-action="branch.add_refrigerator"', false)
        ->assertDontSee('js-add-refrigerator', false)
        ->assertDontSee('js-branch-capacity-unit', false);

    $this->actingAs($actor)
        ->withSession(['locale' => 'ar'])
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertDontSee('التلاجات')
        ->assertDontSee('الثلاجات')
        ->assertDontSee('سعة التخزين');
});

test('branch store ignores submitted refrigerator payload after branch-side removal', function () {
    $actor = branchRefrigeratorsActor(['branches.create']);
    $company = branchRefrigeratorsCompany();

    $this->actingAs($actor)
        ->postJson(route('admin.branches.store'), [
            'company_doc_num' => $company->doc_num,
            'name' => 'Factory Without Refrigerator Feature',
            'type' => 'factory',
            'status' => 'active',
            'refrigerators' => [
                [
                    'name' => 'Removed Cold Room',
                    'capacities' => [
                        ['max_capacity' => '10', 'unit_doc_num' => 'Unit-99999'],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $branch = Branch::query()->where('name', 'Factory Without Refrigerator Feature')->firstOrFail();

    expect($branch->type)->toBe('factory')
        ->and(BranchRefrigerator::query()->where('branch_id', $branch->getKey())->count())->toBe(0);
});

test('branch edit and show pages no longer render refrigerator details', function () {
    $actor = branchRefrigeratorsActor(['branches.view', 'branches.edit']);
    $company = branchRefrigeratorsCompany();
    $branch = Branch::query()->create([
        'doc_number' => 8902,
        'doc_num' => 'Branch-08902',
        'company_id' => $company->getKey(),
        'name' => 'Branch Without Refrigerator UI',
        'type' => 'warehouse',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.branches.edit', $branch->doc_num))
        ->assertOk()
        ->assertDontSee('branch-refrigerators-tab', false)
        ->assertDontSee('js-refrigerators-section', false)
        ->assertDontSee('js-add-refrigerator', false);

    $this->actingAs($actor)
        ->get(route('admin.branches.show', $branch->doc_num))
        ->assertOk()
        ->assertDontSee('branch-refrigerators-tab', false)
        ->assertDontSee('js-refrigerators-section', false)
        ->assertDontSee('js-remove-refrigerator', false);
});
