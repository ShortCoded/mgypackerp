<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;

test('ERP UI shell registry exposes unique metadata-driven screens for every planned module', function (): void {
    $screens = collect(app(ErpUiScreenRegistry::class)->screens());

    expect($screens->count())->toBeGreaterThan(400)
        ->and($screens->map->key()->unique()->count())->toBe($screens->count())
        ->and($screens->map->routeNamePrefix()->unique()->count())->toBe($screens->count())
        ->and($screens->map->routePath()->unique()->count())->toBe($screens->count())
        ->and($screens->map->module()->unique()->sort()->values()->all())->toBe([
            'core',
            'costing',
            'finance',
            'fixed_assets',
            'hr',
            'inventory',
            'maintenance',
            'product_data',
            'production',
            'purchases',
            'quality',
            'reports',
            'sales',
            'tools',
        ]);

    $screens->each(function ($screen): void {
        expect($screen->title('en'))->not->toBe('')
            ->and($screen->title('ar'))->not->toBe('')
            ->and($screen->permission('view'))->toEndWith('.view')
            ->and($screen->get('index_columns'))->not->toBeEmpty()
            ->and($screen->get('tabs'))->not->toBeEmpty();
    });
});

test('ERP UI shell uses specialized quality and maintenance document metadata', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $quality = $registry->find('quality_incoming_material_inspection');
    $maintenance = $registry->find('maintenance_maintenance_work_orders');

    expect(collect($quality?->get('tabs'))->pluck('key')->all())->toBe([
        'basic',
        'source',
        'characteristics',
        'samples',
        'results',
        'defects',
        'decision',
        'attachments',
        'history',
    ])->and(collect($maintenance?->get('tabs'))->pluck('key')->all())->toBe([
        'basic',
        'asset',
        'failure',
        'tasks',
        'technicians',
        'spare_parts',
        'downtime',
        'costs',
        'attachments',
        'history',
    ]);
});

test('ERP UI shell routes return empty DataTables JSON and UI-only form modes without persistence', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $screen = app(ErpUiScreenRegistry::class)->find('sales_sales_orders');
    expect($screen)->not->toBeNull();

    $permissions = [
        $screen->permission('view'),
        $screen->permission('create'),
        $screen->permission('edit'),
        $screen->permission('clone'),
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $this->actingAs($actor)
        ->get(route($screen->route('index')))
        ->assertOk()
        ->assertSee(__('erp_ui_shell.ui_only'))
        ->assertSee($screen->title());

    $this->actingAs($actor)
        ->getJson(route($screen->route('data'), ['draw' => 7]))
        ->assertOk()
        ->assertExactJson([
            'draw' => 7,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ]);

    foreach (['create', 'show', 'edit', 'clone'] as $routeAction) {
        $parameters = $routeAction === 'create' ? [] : ['doc_num' => 'UI-00001'];

        $this->actingAs($actor)
            ->get(route($screen->route($routeAction), $parameters))
            ->assertOk()
            ->assertSee(__('erp_ui_shell.ui_only'));
    }
});

test('ERP UI shell permissions are discoverable and completed routes keep precedence', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $permissions = app(PermissionRegistryService::class)->all();

    expect($permissions)->toContain('sales_orders.view')
        ->and($permissions)->toContain('quality.incoming_material_inspection.approve')
        ->and(Route::has('admin.sales.customers.index'))->toBeTrue()
        ->and(Route::has('admin.sales.quotations.index'))->toBeTrue()
        ->and(Route::has($registry->find('sales_sales_orders')?->route('index')))->toBeTrue();
});

test('ERP UI shell menu leaves reference registered routes and matching view permissions', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $leaves = collect();

    $collectLeaves = function (array $items) use (&$collectLeaves, $leaves): void {
        foreach ($items as $item) {
            $children = $item['children'] ?? [];

            if (is_array($children) && $children !== []) {
                $collectLeaves($children);

                continue;
            }

            if (is_string($item['route'] ?? null)) {
                $leaves->push($item);
            }
        }
    };

    $collectLeaves($registry->menuItems());

    expect($leaves)->toHaveCount(502);

    $leaves->each(function (array $item): void {
        expect(Route::has($item['route']))->toBeTrue()
            ->and(collect((array) $item['permission'])->every(
                fn (string $permission): bool => str_ends_with($permission, '.view'),
            ))->toBeTrue();
    });
});

test('expanded menu merges preserved real screens with authorized UI shell groups', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $permissions = ['customers.view', 'sales_orders.view'];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $sales = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'sales');
    $labels = collect();

    $collectLabels = function (array $items) use (&$collectLabels, $labels): void {
        foreach ($items as $item) {
            $labels->push($item['label']);
            $collectLabels($item['children'] ?? []);
        }
    };

    expect($sales)->not->toBeNull();

    $collectLabels($sales['children']);

    expect($labels)->toContain('customers', 'sales_sales_orders');
});

test('expanded navigation uses the flat business domain hierarchy without duplicate screens', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $registry = app(ErpUiScreenRegistry::class);
    $modulePermissions = collect([
        'core',
        'inventory',
        'production',
        'quality',
        'maintenance',
        'costing',
    ])->map(function (string $module) use ($registry): string {
        $screen = collect($registry->screens())->first(
            fn ($candidate): bool => $candidate->module() === $module,
        );

        return $screen->permission('view');
    })->all();

    $permissions = [
        ...$modulePermissions,
        'roles.view',
        'products.view',
        'customers.view',
        'suppliers.view',
        'accounts.view',
        'bank_accounts.view',
        'fixed_assets.view',
        'hr.employees.view',
        'reports.products_data.view',
        'activity.logs.view',
        'auth.logs.view',
        'auth.sessions.view',
        'file_manager.view',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);
    $menu = app(MenuService::class)->getMenu($actor);
    $topLevel = collect($menu)->keyBy('label');

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

    $flatten = function (array $items) use (&$flatten): array {
        $flattened = [];

        foreach ($items as $item) {
            $flattened[] = $item;
            $flattened = [...$flattened, ...$flatten($item['children'] ?? [])];
        }

        return $flattened;
    };

    $basicDataLabels = collect($flatten($topLevel['basic_data']['children']))->pluck('label');
    $productionLabels = collect($flatten($topLevel['production']['children']))->pluck('label');
    $accountingLabels = collect($flatten($topLevel['accounting_costing']['children']))->pluck('label');
    $toolsLabels = collect($flatten($topLevel['tools']['children']))->pluck('label');
    $routeFingerprints = collect($flatten($menu))
        ->filter(fn (array $item): bool => is_string($item['route'] ?? null))
        ->map(fn (array $item): string => $item['route'].'|'.json_encode($item['route_params'] ?? []));

    expect($basicDataLabels)->toContain('roles', 'core_erp_general_settings')
        ->and($basicDataLabels)->not->toContain('activity_logs', 'auth_logs', 'auth_sessions', 'products')
        ->and($productionLabels)->toContain('production_production_settings', 'quality_quality_settings', 'maintenance_maintenance_settings')
        ->and($accountingLabels)->toContain('chart_of_accounts', 'bank_accounts', 'costing_costing_settings', 'fixed_assets_register')
        ->and($toolsLabels)->toContain('activity_logs', 'auth_logs', 'auth_sessions', 'file_manager')
        ->and($routeFingerprints->duplicates())->toBeEmpty();
});

test('expanded navigation keeps unauthorized parent groups hidden', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    Permission::findOrCreate('sales.leads.view', 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo('sales.leads.view');

    expect(collect(app(MenuService::class)->getMenu($actor))->pluck('label')->all())->toBe([
        'dashboard',
        'sales',
    ]);
});

test('expanded screen breadcrumbs follow the accounting and costing domain hierarchy', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    app()->setLocale('en');

    $screen = app(ErpUiScreenRegistry::class)->find('fixed_assets_asset_categories');
    $breadcrumbs = app(BreadcrumbService::class)->forMenuRoute($screen->route('index'));

    expect(collect($breadcrumbs)->pluck('label')->all())->toBe([
        'Dashboard',
        'Accounting & Costing',
        'Asset Categories',
    ])->and($breadcrumbs[array_key_last($breadcrumbs)]['active'])->toBeTrue()
        ->and($breadcrumbs[array_key_last($breadcrumbs)]['url'])->toBeNull();
});

test('navigation styling provides readable interactive nested menus in both directions', function (): void {
    $dropdownTemplate = file_get_contents(resource_path('views/layouts/partials/menu/top-dropdown-items.blade.php'));
    $topTemplate = file_get_contents(resource_path('views/layouts/partials/menu/top-items.blade.php'));
    $navigationStyles = file_get_contents(public_path('assets/css/user.css'));
    $navigationScript = file_get_contents(public_path('assets/js/modules/Core/layout.js'));

    expect($dropdownTemplate)->not->toContain('link-600')
        ->and($dropdownTemplate)->toContain(
            'erp-top-nav-branch',
            'erp-top-nav-item',
            'erp-top-nav-submenu',
            'data-bs-display="static"',
            'aria-current="page"',
        )
        ->and($topTemplate)->toContain('erp-top-nav-menu', 'erp-top-nav-panel')
        ->and($navigationStyles)->toContain(
            '--erp-navigation-link-color: var(--falcon-gray-700)',
            '--erp-navigation-submenu-color: var(--falcon-gray-800)',
            'color: var(--erp-navigation-submenu-color)',
            '--erp-navigation-submenu-active-bg: rgba(var(--falcon-primary-rgb), .12)',
            '.erp-top-nav-item:focus-visible',
            '.erp-top-nav-item[aria-disabled="true"]',
            'html[dir="ltr"] .navbar-top',
            'html[dir="rtl"] .navbar-top',
            '@media (max-width: 991.98px)',
            '#navbarVerticalNav .nav-link.dropdown-indicator::after',
        )
        ->and($navigationStyles)->not->toContain('calc(100% + .25rem)')
        ->and($navigationStyles)->toContain(
            'calc(100% - .125rem)',
            '.erp-top-nav-branch-flipped',
        )
        ->and($navigationScript)->toContain(
            'positionNestedTopMenu',
            'erp-top-nav-branch-flipped',
            "navigation.addEventListener('focusin'",
            "window.addEventListener('resize'",
        );
});

test('all former placeholder routes resolve to full metadata screens', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $aliases = collect($registry->legacyPlaceholderAliases());

    expect($aliases)->toHaveCount(52)
        ->and($aliases->pluck('key')->unique())->toHaveCount(52);

    $aliases->each(function (array $alias): void {
        expect(Route::has($alias['route']))->toBeTrue()
            ->and($alias['target'])->not->toBeNull()
            ->and($alias['target']->get('tabs'))->not->toBeEmpty();
    });
});

test('legacy placeholder view permissions authorize mapped index and data screens only', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $registry = app(ErpUiScreenRegistry::class);
    $alias = collect($registry->legacyPlaceholderAliases())->firstWhere('key', 'customer_invoices');
    $target = $alias['target'];

    Permission::findOrCreate($alias['permission'], 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo($alias['permission']);

    $this->actingAs($actor)
        ->get(route($alias['route']))
        ->assertOk()
        ->assertSee($target->title());

    $this->actingAs($actor)
        ->getJson(route($target->route('data'), ['draw' => 3]))
        ->assertExactJson([
            'draw' => 3,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ]);

    $this->actingAs($actor)
        ->get(route($target->route('create')))
        ->assertForbidden();
});
