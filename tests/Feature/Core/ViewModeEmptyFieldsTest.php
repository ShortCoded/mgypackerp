<?php

use App\Models\User;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function viewModeEmptyFieldsActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('Branch view shows shared placeholders for empty optional fields', function () {
    $actor = viewModeEmptyFieldsActor(['branches.view']);
    $company = Company::factory()->create([
        'name' => 'Placeholder Company',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 7101,
        'doc_num' => 'Branch-07101',
        'company_id' => $company->id,
        'name' => 'Placeholder Branch',
        'type' => 'warehouse',
        'address' => null,
        'camera_url' => null,
        'phone' => null,
        'mobile' => null,
        'email' => null,
        'hotline' => null,
        'contact_person' => null,
        'notes' => null,
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.branches.show', $branch->doc_num))
        ->assertOk()
        ->assertSee(__('branches.attributes.company'))
        ->assertSee('Placeholder Company')
        ->assertSee(__('branches.attributes.address'))
        ->assertSee(__('branches.attributes.camera_url'))
        ->assertSee(__('branches.attributes.phone'))
        ->assertSee(__('branches.attributes.mobile'))
        ->assertSee(__('branches.attributes.email'))
        ->assertSee(__('branches.attributes.hotline'))
        ->assertSee(__('branches.attributes.contact_person'))
        ->assertSee(__('branches.attributes.notes'))
        ->assertSee('id="address"', false)
        ->assertSee('id="camera_url"', false)
        ->assertSee('id="phone"', false)
        ->assertSee('id="mobile"', false)
        ->assertSee('id="email"', false)
        ->assertSee('id="hotline"', false)
        ->assertSee('id="contact_person"', false)
        ->assertSee('id="notes"', false)
        ->assertSee('value="'.__('common.empty_value').'"', false)
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertDontSee('href="mailto:', false)
        ->assertDontSee('js-user-phone-contact', false)
        ->assertDontSee(__('common.messages.not_available'));
});

test('FinancialPeriod view shows shared placeholders for empty optional fields', function () {
    $actor = viewModeEmptyFieldsActor(['financial_periods.view']);
    $company = Company::factory()->create([
        'name' => 'Financial Period Placeholder Company',
        'status' => 'active',
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 7200,
        'doc_num' => 'Branch-07200',
        'company_id' => $company->getKey(),
        'name' => 'Financial Period Placeholder Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 7201,
        'doc_num' => 'Period-07201',
        'name' => 'Placeholder Period',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'notes' => null,
    ]);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ])
        ->actingAs($actor)
        ->get(route('admin.financial-periods.show', $period->doc_num))
        ->assertOk()
        ->assertSee(__('financial_periods.attributes.name'))
        ->assertSee(__('financial_periods.attributes.from_date'))
        ->assertSee(__('financial_periods.attributes.to_date'))
        ->assertSee(__('financial_periods.attributes.is_closed'))
        ->assertSee(__('financial_periods.attributes.notes'))
        ->assertSee('id="name"', false)
        ->assertSee('id="from_date"', false)
        ->assertSee('id="to_date"', false)
        ->assertSee('id="is_closed"', false)
        ->assertSee('id="notes"', false)
        ->assertSee(__('financial_periods.statuses.open'))
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertDontSee(__('common.messages.not_available'));
});
