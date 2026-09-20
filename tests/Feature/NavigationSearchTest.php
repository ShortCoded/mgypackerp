<?php

use App\Models\User;
use Modules\Core\Models\UserNavigationSearch;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function navigationSearchActor(array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

test('authenticated user only sees permitted menu search results', function () {
    $user = navigationSearchActor(['users.view']);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'users']))
        ->assertOk()
        ->json('data.results');

    expect($response)
        ->toHaveCount(1)
        ->and($response[0]['route_name'])->toBe('admin.users.index')
        ->and(json_encode($response))->not->toContain('admin.roles.index')
        ->and(json_encode($response))->not->toContain('admin.users.store')
        ->and(json_encode($response))->not->toContain('/data');
});

test('unauthorized menu item is not returned', function () {
    $user = navigationSearchActor(['users.view']);

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'roles']))
        ->assertOk()
        ->assertJsonPath('data.results', []);
});

test('navigation search excludes pending generic shells and retains proven workflows', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $registry = app(ErpUiScreenRegistry::class);
    $permissions = [
        'cost_centers.view',
        $registry->find('costing_overhead_allocation_rules')->permission('view'),
        $registry->find('costing_overhead_allocation_run')->permission('view'),
        $registry->find('finance_cashbox_count')->permission('view'),
        $registry->find('reports_costing_product_cost')->permission('view'),
        $registry->find('costing_work_order_estimated_cost')->permission('view'),
        $registry->find('finance_bank_reconciliation')->permission('view'),
        $registry->find('core_tax_definitions')->permission('view'),
    ];
    $user = navigationSearchActor($permissions);

    foreach (['Work Order Estimated Cost', 'Bank Reconciliation', 'Tax Definitions'] as $query) {
        $this->actingAs($user)->getJson(route('admin.navigation-search', ['q' => $query]))
            ->assertOk()->assertJsonPath('data.results', []);
    }

    foreach (['Cost Centers', 'Overhead Allocation Rules', 'Overhead Allocation Run', 'Cashbox Count', 'Product Cost'] as $query) {
        $this->actingAs($user)->getJson(route('admin.navigation-search', ['q' => $query]))
            ->assertOk()->assertJsonCount(1, 'data.results');
    }
});

test('navigation search matches arabic and english labels and aliases', function () {
    $user = navigationSearchActor(['companies.view', 'calendar.view']);

    $this->withSession(['locale' => 'ar'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'الشركات']))
        ->assertOk()
        ->assertJsonPath('data.results.0.route_name', 'admin.companies.index');

    $this->withSession(['locale' => 'ar'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'events']))
        ->assertOk()
        ->assertJsonPath('data.results.0.route_name', 'admin.calendar.index');
});

test('navigation search keeps moved fixed asset routes under their nested localized path', function () {
    $user = navigationSearchActor(['fixed_assets.view']);

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'fixed assets register']))
        ->assertOk()
        ->assertJsonPath('data.results.0.route_name', 'admin.fixed-assets.assets.index')
        ->assertJsonPath('data.results.0.parent_path', 'Accounting & Costing / Fixed Assets');

    $this->withSession(['locale' => 'ar'])
        ->actingAs($user)
        ->getJson(route('admin.navigation-search', ['q' => 'دليل الأصول الثابتة']))
        ->assertOk()
        ->assertJsonPath('data.results.0.route_name', 'admin.fixed-assets.assets.index')
        ->assertJsonPath('data.results.0.parent_path', 'الحسابات والتكاليف / الأصول الثابتة');
});

test('empty query returns current user recent searches only', function () {
    $first = navigationSearchActor(['users.view']);
    $second = navigationSearchActor(['users.view']);

    UserNavigationSearch::query()->create([
        'user_id' => $first->getKey(),
        'title' => 'Users',
        'url' => route('admin.users.index'),
        'route_name' => 'admin.users.index',
        'icon' => 'fas fa-users',
        'parent_path' => 'Administration',
        'last_used_at' => now(),
    ]);
    UserNavigationSearch::query()->create([
        'user_id' => $second->getKey(),
        'title' => 'Other Users',
        'url' => route('admin.users.index'),
        'route_name' => 'admin.users.index',
        'icon' => 'fas fa-users',
        'parent_path' => 'Administration',
        'last_used_at' => now(),
    ]);

    $this->actingAs($first)
        ->getJson(route('admin.navigation-search'))
        ->assertOk()
        ->assertJsonPath('data.section', 'recent')
        ->assertJsonPath('data.results.0.route_name', 'admin.users.index');

    expect(UserNavigationSearch::query()->where('user_id', $first->getKey())->count())->toBe(1)
        ->and(UserNavigationSearch::query()->where('user_id', $second->getKey())->count())->toBe(1);
});

test('saving recent search rechecks permitted menu item', function () {
    $allowed = navigationSearchActor(['users.view']);
    $blocked = navigationSearchActor();

    $this->actingAs($allowed)
        ->postJson(route('admin.navigation-search.recent.store'), [
            'route_name' => 'admin.users.index',
            'url' => route('admin.users.index'),
        ])
        ->assertOk();

    expect(UserNavigationSearch::query()->where('user_id', $allowed->getKey())->count())->toBe(1);

    $this->actingAs($blocked)
        ->postJson(route('admin.navigation-search.recent.store'), [
            'route_name' => 'admin.users.index',
            'url' => route('admin.users.index'),
        ])
        ->assertUnprocessable();
});

test('recent search disappears when permission is removed', function () {
    $user = navigationSearchActor(['users.view']);

    $this->actingAs($user)
        ->postJson(route('admin.navigation-search.recent.store'), [
            'route_name' => 'admin.users.index',
            'url' => route('admin.users.index'),
        ])
        ->assertOk();

    $user->revokePermissionTo('users.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)
        ->getJson(route('admin.navigation-search'))
        ->assertOk()
        ->assertJsonPath('data.results', []);
});

test('clearing recent searches affects only current user', function () {
    $first = navigationSearchActor(['users.view']);
    $second = navigationSearchActor(['users.view']);

    foreach ([$first, $second] as $user) {
        UserNavigationSearch::query()->create([
            'user_id' => $user->getKey(),
            'title' => 'Users',
            'url' => route('admin.users.index'),
            'route_name' => 'admin.users.index',
            'last_used_at' => now(),
        ]);
    }

    $this->actingAs($first)
        ->deleteJson(route('admin.navigation-search.recent.clear'))
        ->assertOk();

    expect(UserNavigationSearch::query()->where('user_id', $first->getKey())->count())->toBe(0)
        ->and(UserNavigationSearch::query()->where('user_id', $second->getKey())->count())->toBe(1);
});
