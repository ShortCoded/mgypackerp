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

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function mvpMenuDestinations(array $items): array
{
    $destinations = collect(mvpFlattenMenu($items))
        ->filter(fn (array $item): bool => is_string($item['route'] ?? null) && $item['route'] !== '')
        ->map(fn (array $item): string => $item['route'].'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR))
        ->sort()
        ->values()
        ->all();

    return $destinations;
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
        'reports',
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
        );
});

test('top navigation moves complete fixed assets and maintenance nodes under their related parents', function (): void {
    app()->setLocale('en');

    $admin = mvpAdminActor();
    $menuService = app(MenuService::class);
    $sourceMenu = $menuService->structure();
    $menu = $menuService->getMenu($admin);
    $sourceLabels = collect($sourceMenu)->pluck('label')->all();
    $topLevelLabels = collect($menu)->pluck('label')->all();
    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    $production = collect($menu)->firstWhere('label', 'production');
    $sourceAccounting = collect($sourceMenu)->firstWhere('label', 'accounting_costing');
    $sourceProduction = collect($sourceMenu)->firstWhere('label', 'production');
    $sourceFixedAssets = collect($sourceMenu)->firstWhere('label', 'fixed_assets');
    $sourceMaintenance = collect($sourceMenu)->firstWhere('label', 'maintenance');
    $fixedAssets = collect($accounting['children'])->firstWhere('label', 'fixed_assets');
    $maintenance = collect($production['children'])->firstWhere('label', 'maintenance');
    $sourceQuality = collect($sourceMenu)->firstWhere('label', 'quality');
    $expectedAccountingChildren = [
        ...collect($sourceAccounting['children'])->pluck('label')->all(),
        'fixed_assets',
    ];
    $expectedProductionChildren = [
        ...collect($sourceProduction['children'])->pluck('label')->all(),
        'maintenance',
        ...($sourceQuality === null ? [] : ['quality']),
    ];
    $movedSourceDestinations = mvpMenuDestinations(array_values(array_filter([
        $sourceFixedAssets,
        $sourceMaintenance,
        $sourceQuality,
    ])));
    $movedMenuDestinations = mvpMenuDestinations(array_values(array_filter([
        $fixedAssets,
        $maintenance,
        mvpFindMenuItem($menu, 'quality'),
    ])));
    $menuDestinations = mvpMenuDestinations($menu);

    expect($topLevelLabels)->not->toContain('fixed_assets', 'maintenance', 'quality')
        ->and($fixedAssets)->toBe($sourceFixedAssets)
        ->and($maintenance)->toBe($sourceMaintenance)
        ->and(collect($accounting['children'])->pluck('label')->all())->toBe($expectedAccountingChildren)
        ->and(collect($production['children'])->pluck('label')->all())->toBe($expectedProductionChildren)
        ->and($sourceLabels)->not->toContain('quality')
        ->and(mvpFindMenuItem($menu, 'quality'))->toBeNull()
        ->and($movedMenuDestinations)->toBe($movedSourceDestinations)
        ->and(array_unique($menuDestinations))->toHaveCount(count($menuDestinations));

    collect($movedSourceDestinations)->each(
        fn (string $destination) => expect(collect($menuDestinations)->filter(fn (string $candidate): bool => $candidate === $destination))->toHaveCount(1),
    );

    $englishTopHtml = view('layouts.partials.menu.top-items', [
        'items' => $menu,
        'menuPath' => [],
    ])->render();
    $englishVerticalHtml = view('layouts.partials.menu.vertical-items', [
        'items' => $menu,
        'menuPath' => [],
    ])->render();

    app()->setLocale('ar');
    $arabicMenu = $menuService->getMenu($admin);
    $arabicAccounting = collect($arabicMenu)->firstWhere('label', 'accounting_costing');
    $arabicProduction = collect($arabicMenu)->firstWhere('label', 'production');
    $arabicFixedAssets = collect($arabicAccounting['children'])->firstWhere('label', 'fixed_assets');
    $arabicMaintenance = collect($arabicProduction['children'])->firstWhere('label', 'maintenance');
    $arabicTopHtml = view('layouts.partials.menu.top-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();
    $arabicVerticalHtml = view('layouts.partials.menu.vertical-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();

    expect($fixedAssets['text'])->toBe('Fixed Assets')
        ->and($maintenance['text'])->toBe('Maintenance')
        ->and($englishTopHtml)->toContain('data-menu-depth="2"', 'Fixed Assets', 'Maintenance')
        ->and($englishVerticalHtml)->toContain('Fixed Assets', 'Maintenance')
        ->and($arabicFixedAssets['text'])->toBe('الأصول الثابتة')
        ->and($arabicMaintenance['text'])->toBe('الصيانة')
        ->and($arabicTopHtml)->toContain('data-menu-depth="2"', 'الأصول الثابتة', 'الصيانة')
        ->and($arabicVerticalHtml)->toContain('الأصول الثابتة', 'الصيانة');

    app()->setLocale('en');
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
    $humanResourceLabels = collect(mvpFlattenMenu([$humanResources]))->pluck('label')->all();

    expect($humanResources)->not->toBeNull()
        ->and($humanResourceLabels)->toContain(
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
        ->and(collect(mvpFlattenMenu([$lookupOnlyHr]))->pluck('label'))->toContain('employee_self_service', 'hr_departments')
        ->and(mvpMenuDestinations([$lookupOnlyHr]))->toHaveCount(2);
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

test('visible menu links resolve to existing routes without duplicate routes or URLs', function (): void {
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

        $fingerprint = $route.'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR);
        $normalizedUrl = rtrim((string) parse_url($item['url'], PHP_URL_PATH), '/') ?: '/';

        expect($seen)->not->toHaveKey($fingerprint);
        expect($seen)->not->toHaveKey($normalizedUrl);

        $seen[$fingerprint] = true;
        $seen[$normalizedUrl] = true;
    }
});

test('Tools keeps working utility and log screens as real links', function (): void {
    $admin = mvpAdminActor();
    $tools = collect(app(MenuService::class)->getMenu($admin))->firstWhere('label', 'tools');
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
        expect(mvpFindMenuItem([$tools], $label)['route'])->toBe($route);
    }

    foreach ([
        'tools_workflow_designer',
        'tools_approval_matrix',
        'tools_notification_center_settings',
        'tools_email_template_settings',
        'tools_sms_template_settings',
        'tools_whatsapp_template_settings',
        'tools_import_templates',
        'tools_data_import',
        'tools_data_export',
        'tools_integration_settings',
        'tools_api_settings',
        'tools_barcode_settings',
        'tools_label_templates',
        'tools_print_template_designer',
        'tools_integration_logs',
        'tools_background_job_monitor',
        'tools_system_health',
        'tools_backup_settings',
        'tools_numbering_review',
        'tools_permission_review',
        'tools_menu_review',
    ] as $removedLabel) {
        expect(mvpFindMenuItem([$tools], $removedLabel))->toBeNull();
    }
});
