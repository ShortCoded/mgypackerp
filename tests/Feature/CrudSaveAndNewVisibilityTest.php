<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function crudSaveAndNewActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function crudSaveAndNewCompany(array $overrides = []): Company
{
    return Company::query()->create([
        'doc_number' => 6101,
        'doc_num' => 'Company-06101',
        'name' => 'Save New Company',
        'status' => 'active',
        ...$overrides,
    ]);
}

function crudSaveAndNewOperatingContext(object $test, ?Company $company = null): Company
{
    $company ??= crudSaveAndNewCompany([
        'doc_number' => 6102,
        'doc_num' => 'Company-06102',
        'name' => 'Save New Context Company',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 6102,
        'doc_num' => 'Branch-06102',
        'company_id' => $company->getKey(),
        'name' => 'Save New Context Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 6102,
        'doc_num' => 'Period-06102',
        'name' => 'Save New Context Period',
        'from_date' => CarbonImmutable::create(2039, 1, 1)->toDateString(),
        'to_date' => CarbonImmutable::create(2039, 12, 31)->toDateString(),
        'is_closed' => false,
    ]);

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return $company;
}

test('User create and edit modes do not show Save and New', function () {
    $actor = crudSaveAndNewActor(['users.view', 'users.create', 'users.edit', 'users.clone']);
    $record = User::factory()->create();

    $this->actingAs($actor)
        ->get(route('admin.users.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('Role create and edit modes do not show Save and New', function () {
    $actor = crudSaveAndNewActor(['roles.view', 'roles.create', 'roles.edit', 'roles.clone']);
    $record = Role::query()->create([
        'name' => 'save-new-role',
        'guard_name' => 'web',
        'doc_number' => 6201,
        'doc_num' => 'Role-06201',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.roles.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.roles.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('Company create and edit modes do not show Save and New', function () {
    config()->set('companies.max_companies', null);

    $actor = crudSaveAndNewActor(['companies.view', 'companies.create', 'companies.edit', 'companies.clone']);
    $record = crudSaveAndNewCompany();

    $this->actingAs($actor)
        ->get(route('admin.companies.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('Branch create and edit modes do not show Save and New', function () {
    $actor = crudSaveAndNewActor(['branches.view', 'branches.create', 'branches.edit', 'branches.clone']);
    $company = crudSaveAndNewCompany([
        'doc_number' => 6301,
        'doc_num' => 'Company-06301',
        'name' => 'Save New Branch Company',
    ]);
    $record = Branch::query()->create([
        'doc_number' => 6302,
        'doc_num' => 'Branch-06302',
        'company_id' => $company->getKey(),
        'name' => 'Save New Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.branches.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.branches.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('FinancialPeriod create and edit modes do not show Save and New', function () {
    $actor = crudSaveAndNewActor(['financial_periods.view', 'financial_periods.create', 'financial_periods.edit']);
    $company = crudSaveAndNewOperatingContext($this);
    $record = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 6401,
        'doc_num' => 'Period-06401',
        'name' => 'FY 2040',
        'from_date' => CarbonImmutable::create(2040, 1, 1)->toDateString(),
        'to_date' => CarbonImmutable::create(2040, 12, 31)->toDateString(),
        'is_closed' => false,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.financial-periods.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_edit'));

    $this->actingAs($actor)
        ->get(route('admin.financial-periods.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('Item Data create and edit modes do not show Save and New', function () {
    $actor = crudSaveAndNewActor(['item_units.view', 'item_units.create', 'item_units.edit', 'item_units.clone']);
    $company = crudSaveAndNewOperatingContext($this);
    $record = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 6601,
        'doc_num' => 'Unit-06601',
        'name' => 'Save New Unit',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.item-units.edit', $record->doc_num))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee('data-submit-action="save_edit"', false)
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.item-units.create'))
        ->assertOk()
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertSee('data-submit-action="save_edit"', false);
});

test('Branch update falls back safely when old Save and New submit action is posted', function () {
    $actor = crudSaveAndNewActor(['branches.edit']);
    $company = crudSaveAndNewCompany([
        'doc_number' => 6501,
        'doc_num' => 'Company-06501',
        'name' => 'Rejected Save New Company',
    ]);
    $record = Branch::query()->create([
        'doc_number' => 6502,
        'doc_num' => 'Branch-06502',
        'company_id' => $company->getKey(),
        'name' => 'Rejected Save New Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.branches.update', $record->doc_num), [
            'submit_action' => 'save_new',
            'company_doc_num' => $company->doc_num,
            'name' => 'Accepted Legacy Save New Branch',
            'type' => 'administrative',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('submit_action', 'save');
});
