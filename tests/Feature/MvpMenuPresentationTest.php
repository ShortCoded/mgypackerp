<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function mvpMenuActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function mvpAdminActor(): User
{
    app(PermissionSeeder::class)->run();

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail());

    return $admin;
}

function mvpFlattenMenu(array $items): array
{
    $flat = [];

    foreach ($items as $item) {
        $flat[] = $item;
        $flat = [
            ...$flat,
            ...mvpFlattenMenu($item['children'] ?? []),
        ];
    }

    return $flat;
}

function mvpFindMenuItem(array $items, string $label): ?array
{
    foreach (mvpFlattenMenu($items) as $item) {
        if (($item['label'] ?? null) === $label) {
            return $item;
        }
    }

    return null;
}

test('admin sees the clean MVP top level menu in the requested order', function (): void {
    $admin = mvpAdminActor();
    $menu = app(MenuService::class)->getMenu($admin);

    expect(collect($menu)->pluck('label')->all())->toBe([
        'dashboard',
        'basic_data',
        'sales',
        'purchases',
        'inventory',
        'production',
        'accounting_costing',
        'human_resources',
        'tools',
    ]);

    $dashboard = collect($menu)->firstWhere('label', 'dashboard');

    expect($dashboard['route'])->toBe('dashboard')
        ->and($dashboard['children'])->toBe([])
        ->and(collect($menu)->pluck('label')->all())->not->toContain(
            'administration',
            'fixed_assets',
            'finance',
            'general_ledger',
            'item_data',
            'planning_production',
            'reports',
        );
});

test('real MVP menu entries keep their existing routes and expanded entries use their UI shell routes', function (): void {
    $admin = mvpAdminActor();
    $menu = app(MenuService::class)->getMenu($admin);

    expect(mvpFindMenuItem($menu, 'chart_of_accounts')['route'])->toBe('admin.accounting.accounts.index')
        ->and(mvpFindMenuItem($menu, 'cost_centers')['route'])->toBe('admin.accounting.cost-centers.index')
        ->and(mvpFindMenuItem($menu, 'opening_balances')['route'])->toBe('admin.finance.opening-balances.index')
        ->and(mvpFindMenuItem($menu, 'customers')['route'])->toBe('admin.sales.customers.index')
        ->and(mvpFindMenuItem($menu, 'suppliers')['route'])->toBe('admin.purchases.suppliers.index')
        ->and(mvpFindMenuItem($menu, 'cashboxes')['route'])->toBe('admin.finance.cashboxes.index')
        ->and(mvpFindMenuItem($menu, 'bank_accounts')['route'])->toBe('admin.finance.bank-accounts.index')
        ->and(mvpFindMenuItem($menu, 'fixed_assets_register')['route'])->toBe('admin.fixed-assets.assets.index')
        ->and(mvpFindMenuItem($menu, 'opening_stocks')['route'])->toBe('admin.inventory.opening-stocks.index')
        ->and(mvpFindMenuItem($menu, 'opening_stock_pricings')['route'])->toBe('admin.inventory.opening-stock-pricings.index')
        ->and(mvpFindMenuItem($menu, 'hr_employees')['route'])->toBe('admin.hr.employees.index')
        ->and(mvpFindMenuItem($menu, 'file_manager')['route'])->toBe('admin.file-manager.index')
        ->and(mvpFindMenuItem($menu, 'calendar')['route'])->toBe('admin.calendar.index')
        ->and(mvpFindMenuItem($menu, 'my_board')['route'])->toBe('admin.my-board.index')
        ->and(mvpFindMenuItem($menu, 'team_board')['route'])->toBe('admin.tools.team-board.index')
        ->and(mvpFindMenuItem($menu, 'pwa_settings')['route'])->toBe('admin.settings.pwa');

    foreach ([
        'costing_work_order_estimated_cost',
        'production_work_orders',
        'quality_incoming_material_inspection',
        'maintenance_maintenance_work_orders',
        'hr_payroll_preparation',
        'reports_finance_cashbox_balances',
    ] as $label) {
        $item = mvpFindMenuItem($menu, $label);

        expect($item)->not->toBeNull()
            ->and($item['route'])->not->toBe('admin.mvp.placeholder')
            ->and(Route::has($item['route']))->toBeTrue()
            ->and($item['url'])->not->toBe('#!');
    }
});

test('former placeholder route aliases render their current UI shell target', function (): void {
    $legacyPermission = 'costing.estimated_cost_sheets.view';
    $actor = mvpMenuActor([$legacyPermission]);
    $screen = app(ErpUiScreenRegistry::class)->find('costing_work_order_estimated_cost');

    app()->setLocale('en');
    $actor->forceFill(['locale' => 'en'])->save();

    $this->actingAs($actor)
        ->get(route('admin.costing.estimated-cost-sheets.index'))
        ->assertOk()
        ->assertSee($screen->title());

    app()->setLocale('ar');
    $actor->forceFill(['locale' => 'ar'])->save();

    $this->withSession(['locale' => 'ar'])
        ->actingAs($actor)
        ->get(route('admin.costing.estimated-cost-sheets.index'))
        ->assertOk()
        ->assertSee($screen->title());
});

test('HR menu keeps detailed HR screens under its independent domain', function (): void {
    $admin = mvpAdminActor();
    $menu = app(MenuService::class)->getMenu($admin);
    $humanResources = collect($menu)->firstWhere('label', 'human_resources');

    expect($humanResources)->not->toBeNull()
        ->and(collect($humanResources['children'])->pluck('label')->all())->toContain(
            'hr_employees',
            'hr_departments',
            'hr_countries',
            'hr_employee_attendance',
            'hr_payroll_preparation',
        );

    $registry = app(PermissionRegistryService::class);

    expect($registry->all())->toContain('hr.departments.view', 'hr.payroll_preparation.view')
        ->and($registry->formAssignablePermissions())->toContain('hr.departments.view', 'hr.payroll_preparation.view')
        ->and(Role::query()->where('name', 'admin')->firstOrFail()->hasPermissionTo('hr.departments.view'))->toBeTrue();

    $lookupOnly = mvpMenuActor(['hr.departments.view']);
    $lookupOnlyHr = collect(app(MenuService::class)->getMenu($lookupOnly))->firstWhere('label', 'human_resources');

    expect($lookupOnlyHr)->not->toBeNull()
        ->and(collect($lookupOnlyHr['children'])->pluck('label')->all())->toBe(['hr_departments']);
});

test('legacy phase-gated permission still gates its route alias and remains assigned to admin', function (): void {
    $admin = mvpAdminActor();
    $legacyPermission = 'maintenance.work_orders.view';

    expect(Permission::query()->where('name', $legacyPermission)->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'admin')->firstOrFail()->hasPermissionTo($legacyPermission))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.maintenance.work-orders.index'))
        ->assertOk();

    $unauthorized = User::factory()->create();

    $this->actingAs($unauthorized)
        ->get(route('admin.maintenance.work-orders.index'))
        ->assertForbidden();
});

test('visible menu links resolve to existing routes without duplicate route label pairs', function (): void {
    $admin = mvpAdminActor();
    $menu = app(MenuService::class)->getMenu($admin);
    $seen = [];

    foreach (mvpFlattenMenu($menu) as $item) {
        $route = $item['route'] ?? null;

        if (! is_string($route) || $route === '') {
            continue;
        }

        expect(Route::has($route))->toBeTrue()
            ->and($item['url'])->not->toBe('#!');

        $fingerprint = $route.'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR).'|'.$item['label'];

        expect($seen)->not->toHaveKey($fingerprint);

        $seen[$fingerprint] = true;
    }
});

test('Tools keeps working utility and log screens as real links', function (): void {
    $admin = mvpAdminActor();
    $tools = collect(app(MenuService::class)->getMenu($admin))->firstWhere('label', 'tools');
    $children = collect($tools['children']);

    foreach ([
        'open_documents' => 'admin.tools.open-documents.index',
        'file_manager' => 'admin.file-manager.index',
        'calendar' => 'admin.calendar.index',
        'my_board' => 'admin.my-board.index',
        'team_board' => 'admin.tools.team-board.index',
        'pwa_settings' => 'admin.settings.pwa',
        'auth_logs' => 'admin.auth-logs.index',
        'activity_logs' => 'admin.activity-logs.index',
        'auth_sessions' => 'admin.auth-sessions.index',
    ] as $label => $route) {
        expect($children->firstWhere('label', $label)['route'])->toBe($route);
    }

    expect($children->firstWhere('label', 'tools_numbering_review')['route'])->toBe('admin.tools.numbering-review.index');
});
