<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ScreenDataVisibilityRegistry;
use Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array{company: Company, branch: Branch, period: FinancialPeriod, session: array<string, mixed>} */
function visibilityRuleContext(): array
{
    $company = Company::factory()->create(['status' => 'active']);
    $branch = Branch::query()->create([
        'doc_number' => 91001,
        'doc_num' => 'Branch-91001',
        'company_id' => $company->getKey(),
        'name' => 'Visibility Branch',
        'type' => 'factory',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => 91001,
        'doc_num' => 'Period-91001',
        'company_id' => $company->getKey(),
        'name' => 'Visibility Period',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    return compact('company', 'branch', 'period', 'session');
}

function visibilityRuleUser(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function visibilityCustomer(Company $company, User $creator, int $number, CarbonImmutable $createdAt): Customer
{
    $customer = Customer::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Customer-'.$number,
        'company_id' => $company->getKey(),
        'name' => 'Visibility Customer '.$number,
        'status' => 'active',
        'created_by' => $creator->getKey(),
    ]);
    $customer->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    return $customer;
}

function visibilityProduct(Company $company, User $creator, int $number, string $classification, CarbonImmutable $createdAt): Product
{
    $product = Product::query()->create([
        'doc_number' => $number,
        'doc_num' => 'VisibilityItem-'.$number,
        'company_id' => $company->getKey(),
        'name' => 'Visibility Item '.$number,
        'item_classification' => $classification,
        'status' => 'active',
        'created_by' => $creator->getKey(),
    ]);
    $product->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    return $product;
}

beforeEach(function (): void {
    config()->set('erp_features.screen_data_visibility_rules.enabled', true);
});

test('customer visibility rule restricts totals search select2 and direct routes to the latest authorized set', function () {
    CarbonImmutable::setTestNow('2026-07-19 12:00:00');
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser([
        'customers.view',
        'customers.edit',
        'customers.delete',
        'quotations.view',
        'reports.customers.view',
    ]);
    $other = User::factory()->create();
    $recent = collect();

    foreach (range(1, 12) as $offset) {
        $recent->push(visibilityCustomer($context['company'], $actor, 92000 + $offset, now()->toImmutable()->subDays($offset)));
    }

    $old = visibilityCustomer($context['company'], $actor, 92100, now()->toImmutable()->subDays(40));
    visibilityCustomer($context['company'], $actor, 92101, now()->toImmutable()->subMonths(3));

    foreach (range(1, 5) as $offset) {
        visibilityCustomer($context['company'], $other, 92200 + $offset, now()->toImmutable()->subDays($offset));
    }

    ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $actor->getKey(),
        'screen_key' => 'customers',
        'record_scope' => 'own_records',
        'max_visible_records' => 10,
        'duration_value' => 30,
        'duration_unit' => 'days',
        'is_active' => true,
    ]);

    $payload = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 1, 'start' => 0, 'length' => 25]))
        ->assertOk()
        ->json();

    expect($payload['recordsTotal'])->toBe(10)
        ->and($payload['recordsFiltered'])->toBe(10)
        ->and(collect($payload['data'])->pluck('doc_num')->implode(' '))->not->toContain($recent->last()->doc_num);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => $old->doc_num],
        ]))
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 0);

    $select2 = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.select2.customers', ['per_page' => 50]))
        ->assertOk()
        ->json('results');

    expect($select2)->toHaveCount(10)
        ->and(collect($select2)->pluck('id')->all())->not->toContain($old->doc_num);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.reports.customers.data', ['draw' => 1, 'start' => 0, 'length' => 25]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 10);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            __('dashboard.plastics.metrics.customers.title'),
            '<div class="mb-1 fw-semibold text-900 plastics-dashboard-metric-value dt-number-value" dir="ltr">10</div>',
        ], false);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.sales.customers.show', $old->doc_num))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.sales.customers.edit', $old->doc_num))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->deleteJson(route('admin.sales.customers.destroy', $old->doc_num))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.sales.customers.show', $recent->first()->doc_num))
        ->assertOk();
});

test('bypass permission ignores visibility limits while preserving company context', function () {
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser(['customers.view', 'screen_data_visibility_rules.bypass']);
    $other = User::factory()->create();

    visibilityCustomer($context['company'], $actor, 93001, now()->toImmutable());
    visibilityCustomer($context['company'], $other, 93002, now()->toImmutable());
    visibilityCustomer(Company::factory()->create(['is_main' => true]), $other, 93003, now()->toImmutable());

    ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $actor->getKey(),
        'screen_key' => 'customers',
        'max_visible_records' => 1,
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 1, 'start' => 0, 'length' => 25]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 2);
});

test('nullable maximum and duration restrictions retain their independent meanings', function () {
    CarbonImmutable::setTestNow('2026-07-19 12:00:00');
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser(['customers.view']);
    $other = User::factory()->create();

    foreach (range(1, 25) as $offset) {
        visibilityCustomer($context['company'], $actor, 94000 + $offset, now()->toImmutable()->subDays($offset * 2));
    }
    visibilityCustomer($context['company'], $actor, 94100, now()->toImmutable()->subMonths(5));
    visibilityCustomer($context['company'], $other, 94101, now()->toImmutable());

    $rule = ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $actor->getKey(),
        'screen_key' => 'customers',
        'record_scope' => 'own_records',
        'max_visible_records' => null,
        'duration_value' => 3,
        'duration_unit' => 'months',
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 1, 'start' => 0, 'length' => 50]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 25);

    $rule->forceFill([
        'max_visible_records' => 20,
        'duration_value' => null,
        'duration_unit' => null,
    ])->save();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 2, 'start' => 0, 'length' => 50]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 20);
});

test('product report rows and filter options combine product and material policies without leaking scopes', function () {
    CarbonImmutable::setTestNow('2026-07-19 12:00:00');
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser([
        'reports.products_data.view',
        'purchase_orders.view',
        'inventory.opening_stocks.view',
    ]);
    $other = User::factory()->create();

    $latestProduct = visibilityProduct($context['company'], $actor, 96001, Product::ClassificationFinishedProduct, now()->toImmutable());
    $olderProduct = visibilityProduct($context['company'], $actor, 96002, Product::ClassificationFinishedProduct, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96003, Product::ClassificationFinishedProduct, now()->toImmutable()->addMinute());

    $latestRawMaterial = visibilityProduct($context['company'], $actor, 96101, Product::ClassificationRawMaterial, now()->toImmutable());
    $olderRawMaterial = visibilityProduct($context['company'], $actor, 96102, Product::ClassificationRawMaterial, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96103, Product::ClassificationRawMaterial, now()->toImmutable()->addMinute());

    $latestPackagingMaterial = visibilityProduct($context['company'], $actor, 96201, Product::ClassificationPackaging, now()->toImmutable());
    $olderPackagingMaterial = visibilityProduct($context['company'], $actor, 96202, Product::ClassificationPackaging, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96203, Product::ClassificationPackaging, now()->toImmutable()->addMinute());

    foreach (['products', 'raw_materials', 'packaging_materials'] as $screenKey) {
        ScreenDataVisibilityRule::factory()->create([
            'company_id' => $context['company']->getKey(),
            'user_id' => $actor->getKey(),
            'screen_key' => $screenKey,
            'record_scope' => 'own_records',
            'max_visible_records' => 1,
        ]);
    }

    $payload = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.reports.products-data.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'item_scope' => 'all',
        ]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 3)
        ->json();

    expect(collect($payload['data'])->pluck('doc_num')->all())
        ->toContain($latestProduct->doc_num, $latestRawMaterial->doc_num, $latestPackagingMaterial->doc_num)
        ->not->toContain($olderProduct->doc_num, $olderRawMaterial->doc_num, $olderPackagingMaterial->doc_num);

    $options = $this->getJson(route('admin.reports.products-data.filter-options.products', [
        'item_scope' => 'all',
        'per_page' => 50,
    ]))
        ->assertOk()
        ->json('results');

    expect(collect($options)->pluck('id')->all())
        ->toEqualCanonicalizing([$latestProduct->doc_num, $latestRawMaterial->doc_num, $latestPackagingMaterial->doc_num]);

    foreach (['admin.purchases.select2.products', 'admin.inventory.select2.opening-stock-products'] as $routeName) {
        $lookupOptions = $this->getJson(route($routeName, ['per_page' => 50]))
            ->assertOk()
            ->json('results');

        expect(collect($lookupOptions)->pluck('id')->all())
            ->toEqualCanonicalizing([$latestProduct->doc_num, $latestRawMaterial->doc_num, $latestPackagingMaterial->doc_num]);
    }

    $this->getJson(route('admin.inventory.products.details', $olderProduct->doc_num))
        ->assertNotFound();
});

test('component selector combines product and material visibility policies', function () {
    CarbonImmutable::setTestNow('2026-07-19 12:00:00');
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser(['products.view']);
    $other = User::factory()->create();

    $latestProduct = visibilityProduct($context['company'], $actor, 96301, Product::ClassificationFinishedProduct, now()->toImmutable());
    $olderProduct = visibilityProduct($context['company'], $actor, 96302, Product::ClassificationFinishedProduct, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96303, Product::ClassificationFinishedProduct, now()->toImmutable()->addMinute());

    $latestRawMaterial = visibilityProduct($context['company'], $actor, 96401, Product::ClassificationRawMaterial, now()->toImmutable());
    $olderRawMaterial = visibilityProduct($context['company'], $actor, 96402, Product::ClassificationRawMaterial, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96403, Product::ClassificationRawMaterial, now()->toImmutable()->addMinute());

    $latestPackagingMaterial = visibilityProduct($context['company'], $actor, 96501, Product::ClassificationPackaging, now()->toImmutable());
    $olderPackagingMaterial = visibilityProduct($context['company'], $actor, 96502, Product::ClassificationPackaging, now()->toImmutable()->subDay());
    visibilityProduct($context['company'], $other, 96503, Product::ClassificationPackaging, now()->toImmutable()->addMinute());

    foreach (['products', 'raw_materials', 'packaging_materials'] as $screenKey) {
        ScreenDataVisibilityRule::factory()->create([
            'company_id' => $context['company']->getKey(),
            'user_id' => $actor->getKey(),
            'screen_key' => $screenKey,
            'record_scope' => 'own_records',
            'max_visible_records' => 1,
        ]);
    }

    $options = $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.select2.component-products', ['per_page' => 50]))
        ->assertOk()
        ->json('results');

    expect(collect($options)->pluck('id')->all())
        ->toEqualCanonicalizing([$latestProduct->doc_num, $latestRawMaterial->doc_num, $latestPackagingMaterial->doc_num])
        ->not->toContain($olderProduct->doc_num, $olderRawMaterial->doc_num, $olderPackagingMaterial->doc_num);
});

test('missing and inactive rules preserve existing authorized access without granting screen permission', function () {
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser(['customers.view']);
    $unauthorized = visibilityRuleUser([]);
    $other = User::factory()->create();

    visibilityCustomer($context['company'], $actor, 95001, now()->toImmutable());
    visibilityCustomer($context['company'], $other, 95002, now()->toImmutable());

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 1, 'start' => 0, 'length' => 25]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 2);

    ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $actor->getKey(),
        'screen_key' => 'customers',
        'max_visible_records' => 1,
        'is_active' => false,
    ]);

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->getJson(route('admin.sales.customers.data', ['draw' => 2, 'start' => 0, 'length' => 25]))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 2);

    ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $unauthorized->getKey(),
        'screen_key' => 'customers',
        'record_scope' => 'authorized_scope',
        'is_active' => true,
    ]);

    $this->actingAs($unauthorized)
        ->withSession($context['session'])
        ->get(route('admin.sales.customers.index'))
        ->assertForbidden();
});

test('rule management is company scoped registry driven and rejects unsafe hierarchical screens', function () {
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser(['screen_data_visibility_rules.view', 'screen_data_visibility_rules.create']);
    $target = User::factory()->create();

    expect(app(ScreenDataVisibilityRegistry::class)->selectorOptions())
        ->toHaveCount(36)
        ->and(app(ScreenDataVisibilityRegistry::class)->isSupported('customers'))->toBeTrue()
        ->and(app(ScreenDataVisibilityRegistry::class)->isSupported('packaging_materials'))->toBeTrue()
        ->and(app(ScreenDataVisibilityRegistry::class)->isSupported('accounts'))->toBeFalse();

    $this->actingAs($actor)
        ->withSession($context['session'])
        ->get(route('admin.screen-data-visibility-rules.index', ['user' => $target->doc_num]))
        ->assertOk()
        ->assertSee(__('screen_data_visibility_rules.title'));

    $this->postJson(route('admin.screen-data-visibility-rules.store'), [
        'user_doc_num' => $target->doc_num,
        'screen_key' => 'accounts',
        'record_scope' => 'own_records',
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('screen_key');

    $this->postJson(route('admin.screen-data-visibility-rules.store'), [
        'user_doc_num' => $target->doc_num,
        'screen_key' => 'customers',
        'record_scope' => 'authorized_scope',
        'max_visible_records' => 20,
        'duration_value' => 3,
        'duration_unit' => 'months',
        'is_active' => true,
        'submit_action' => 'save_back',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson(route('admin.screen-data-visibility-rules.store'), [
        'user_doc_num' => $target->doc_num,
        'screen_key' => 'customers',
        'record_scope' => 'own_records',
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('screen_key');

    expect(ScreenDataVisibilityRule::query()->forCompany($context['company']->getKey())->where('user_id', $target->getKey())->where('screen_key', 'customers')->exists())->toBeTrue();
});

test('rule management supports view edit clone soft delete and restore with public identifiers', function () {
    $context = visibilityRuleContext();
    $actor = visibilityRuleUser([
        'screen_data_visibility_rules.view',
        'screen_data_visibility_rules.create',
        'screen_data_visibility_rules.edit',
        'screen_data_visibility_rules.clone',
        'screen_data_visibility_rules.delete',
        'screen_data_visibility_rules.view_trashed',
        'screen_data_visibility_rules.restore',
    ]);
    $target = User::factory()->create();
    $rule = ScreenDataVisibilityRule::factory()->create([
        'company_id' => $context['company']->getKey(),
        'user_id' => $target->getKey(),
        'screen_key' => 'customers',
        'record_scope' => 'own_records',
        'is_active' => true,
    ]);

    $this->actingAs($actor)->withSession($context['session']);

    $this->get(route('admin.screen-data-visibility-rules.show', $rule->doc_num))
        ->assertOk()
        ->assertSee($rule->doc_num);
    $this->get(route('admin.screen-data-visibility-rules.edit', $rule->doc_num))->assertOk();
    $this->get(route('admin.screen-data-visibility-rules.clone', $rule->doc_num))->assertOk();

    $this->putJson(route('admin.screen-data-visibility-rules.update', $rule->doc_num), [
        'user_doc_num' => $target->doc_num,
        'screen_key' => 'customers',
        'record_scope' => 'authorized_scope',
        'max_visible_records' => 5,
        'duration_value' => null,
        'duration_unit' => null,
        'is_active' => false,
        'submit_action' => 'save_view',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->deleteJson(route('admin.screen-data-visibility-rules.destroy', $rule->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);
    $this->assertSoftDeleted('screen_data_visibility_rules', ['id' => $rule->getKey()]);

    $this->get(route('admin.screen-data-visibility-rules.show', $rule->doc_num))->assertOk();
    $this->patchJson(route('admin.screen-data-visibility-rules.restore', $rule->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($rule->fresh()?->trashed())->toBeFalse()
        ->and($rule->fresh()?->record_scope->value)->toBe('authorized_scope')
        ->and($rule->fresh()?->max_visible_records)->toBe(5);
});
