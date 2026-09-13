<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;

test('ERP UI shell registry metadata remains internally unique', function (): void {
    $screens = collect(app(ErpUiScreenRegistry::class)->screens());

    expect($screens)->not->toBeEmpty()
        ->and($screens->map->key()->unique()->count())->toBe($screens->count())
        ->and($screens->map->routeNamePrefix()->unique()->count())->toBe($screens->count())
        ->and($screens->map->routePath()->unique()->count())->toBe($screens->count());

    $screens->each(function ($screen): void {
        expect($screen->title('en'))->not->toBe('')
            ->and($screen->title('ar'))->not->toBe('')
            ->and($screen->permission('view'))->toEndWith('.view')
            ->and($screen->get('index_columns'))->not->toBeEmpty()
            ->and($screen->get('tabs'))->not->toBeEmpty();
    });
});

test('production quality inventory and maintenance shells are removed from the runtime registry', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $screens = collect($registry->screens());
    $visibleLeaves = collect();
    $collectLeaves = function (array $items) use (&$collectLeaves, $visibleLeaves): void {
        foreach ($items as $item) {
            if (($item['children'] ?? []) !== []) {
                $collectLeaves($item['children']);
            } elseif (isset($item['route'])) {
                $visibleLeaves->push($item);
            }
        }
    };
    $collectLeaves($registry->menuItems());

    expect($registry->find('quality_incoming_material_inspection'))->toBeNull()
        ->and($registry->find('maintenance_maintenance_work_orders'))->toBeNull()
        ->and($screens->filter(fn ($screen): bool => in_array($screen->module(), ['inventory', 'production', 'quality', 'maintenance'], true)))->toBeEmpty()
        ->and($screens)->not->toBeEmpty()
        ->and($visibleLeaves)->not->toBeEmpty();

    $hiddenScreens = $screens->filter(fn ($screen): bool => $screen->get('menu_visible', true) === false);
    expect($hiddenScreens)->not->toBeEmpty()
        ->and($visibleLeaves->pluck('label')->intersect($hiddenScreens->map->key()))->toBeEmpty();
});

test('canonical routes keep precedence over colliding ERP UI shell route metadata', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $screen = app(ErpUiScreenRegistry::class)->find('sales_sales_orders');
    expect($screen)->not->toBeNull();

    expect(Route::getRoutes()->getByName($screen->route('index'))?->getActionName())
        ->toContain('SalesCycleController@orders')
        ->and(app(ErpUiScreenRegistry::class)->menuItems())->not->toBeEmpty();
});

test('ERP UI shell permissions are discoverable and completed routes keep precedence', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $permissions = app(PermissionRegistryService::class)->all();

    expect($permissions)->toContain('sales_orders.view')
        ->and($permissions)->toContain('production.orders.view')
        ->and(Route::has('admin.sales.customers.index'))->toBeTrue()
        ->and(Route::has('admin.sales.quotations.index'))->toBeTrue()
        ->and(Route::has($registry->find('sales_sales_orders')?->route('index')))->toBeTrue();
});

test('remaining ERP UI shell routes exclude retired operational domains', function (): void {
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

    expect($leaves)->not->toBeEmpty()
        ->and($leaves->pluck('label'))->not->toContain(
            'sales_sales_order_lines',
            'purchases_purchase_order_lines',
            'production_work_order_lines',
            'sales_sales_order_change_requests',
            'fixed_assets_asset_disposal',
            'purchases_supplier_contracts',
            'finance_supplier_payments',
            'inventory_stock_receipts',
            'production_material_requests',
            'quality_incoming_material_inspection',
            'maintenance_maintenance_work_orders',
        )
        ->and($leaves->every(fn (array $leaf): bool => Route::has($leaf['route'])))->toBeTrue();
});

test('expanded menu contains canonical screens and permission-scoped UI shell groups', function (): void {
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

    expect($labels)->toContain('customers', 'sales_orders')
        ->and($labels)->not->toContain('sales_sales_orders', 'sales_sales_order_lines');
});

test('expanded navigation uses canonical business screens without duplicate routes or UI-only shells', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $permissions = [
        'roles.view',
        'products.view',
        'customers.view',
        'suppliers.view',
        'sales_orders.view',
        'purchase_orders.view',
        'inventory.documents.view',
        'production.orders.view',
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

    $flatten = function (array $items) use (&$flatten): array {
        $flattened = [];

        foreach ($items as $item) {
            $flattened[] = $item;
            $flattened = [...$flattened, ...$flatten($item['children'] ?? [])];
        }

        return $flattened;
    };

    $labels = collect($flatten($menu))->pluck('label');
    $routeFingerprints = collect($flatten($menu))
        ->filter(fn (array $item): bool => is_string($item['route'] ?? null))
        ->map(fn (array $item): string => $item['route'].'|'.json_encode($item['route_params'] ?? []));

    expect($topLevel->keys()->all())->toContain('sales', 'purchases', 'inventory', 'production')
        ->and($labels)->toContain('customers', 'sales_orders', 'suppliers', 'purchase_orders', 'inventory_movements', 'production_work_orders')
        ->and($labels)->not->toContain(
            'sales_sales_orders',
            'sales_sales_order_lines',
            'purchases_purchase_order_lines',
            'inventory_inventory_transaction_lines',
            'production_production_run_lines',
        )
        ->and($routeFingerprints->duplicates())->toBeEmpty();
});

test('retired sales shell permissions do not expose an empty sales menu', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    Permission::findOrCreate('sales.leads.view', 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo('sales.leads.view');

    $menu = app(MenuService::class)->getMenu($actor);
    expect(collect($menu)->pluck('label')->all())->not->toContain('sales');
});

test('canonical fixed asset breadcrumbs follow the business domain hierarchy', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    app()->setLocale('en');

    $breadcrumbs = app(BreadcrumbService::class)->forMenuRoute('admin.fixed-assets.assets.index');

    expect(collect($breadcrumbs)->pluck('label')->all())->toBe([
        'Dashboard',
        'Fixed Assets',
        'Fixed Assets Register',
    ])->and($breadcrumbs[array_key_last($breadcrumbs)]['active'])->toBeTrue()
        ->and($breadcrumbs[array_key_last($breadcrumbs)]['url'])->toBeNull();
});

test('navigation styling provides readable interactive nested menus in both directions', function (): void {
    $dropdownTemplate = file_get_contents(resource_path('views/layouts/partials/menu/top-dropdown-items.blade.php'));
    $topTemplate = file_get_contents(resource_path('views/layouts/partials/menu/top-items.blade.php'));
    $topNavbarTemplate = file_get_contents(resource_path('views/layouts/partials/navbar-top.blade.php'));
    $navigationStyles = file_get_contents(public_path('assets/css/user.css'));
    $navigationScript = file_get_contents(public_path('assets/js/modules/Core/layout.js'));

    expect($dropdownTemplate)->not->toContain('link-600')
        ->and($dropdownTemplate)->toContain(
            'erp-top-nav-branch',
            'erp-top-nav-item',
            'erp-top-nav-submenu',
            'data-erp-menu-toggle',
            'aria-controls=',
            'aria-expanded="false"',
            'aria-current="page"',
        )
        ->and($dropdownTemplate)->not->toContain('data-bs-toggle="dropdown"')
        ->and($topTemplate)->toContain(
            'erp-top-nav-menu',
            'erp-top-nav-panel',
            'data-erp-menu-toggle',
            'aria-controls=',
            'aria-expanded="false"',
        )
        ->and($topTemplate)->not->toContain('data-bs-toggle="dropdown"')
        ->and($topNavbarTemplate)->toContain('data-erp-top-navigation')
        ->and($topNavbarTemplate)->not->toContain('data-top-nav-dropdowns')
        ->and($navigationStyles)->toContain(
            '--erp-navigation-link-color: var(--falcon-gray-700)',
            '--erp-navigation-submenu-color: var(--falcon-gray-800)',
            'color: var(--erp-navigation-submenu-color)',
            '--erp-navigation-submenu-active-bg: rgba(var(--falcon-primary-rgb), .12)',
            '.erp-top-nav-item:focus-visible',
            '.erp-top-nav-item[aria-disabled="true"]',
            'inset-inline-start: calc(100% - .125rem)',
            'inset-inline-end: calc(100% - .125rem)',
            'margin-inline: 0',
            'max-height: var(--erp-menu-available-height, calc(100dvh - 1.5rem))',
            'overflow-y: auto',
            'overscroll-behavior-y: contain',
            'scrollbar-width: thin',
            '.navbar-collapse:has(> [data-erp-top-navigation])',
            'position: fixed',
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
            "owner.dataset.erpMenuState = 'closed'",
            "openOwnerPath(owner, 'hover')",
            "openOwnerPath(owner, 'pinned')",
            '}, 150)',
            '}, 350)',
            "event.key === 'Enter'",
            "event.key !== 'Escape' || !hasOpenMenus()",
            "document.addEventListener('click'",
            "event.pointerType === 'touch'",
            "navigation.dataset.erpTopNavigationInitialized === 'true'",
            "toggle.setAttribute('aria-expanded', 'true')",
            "toggle.setAttribute('aria-expanded', 'false')",
            'window.visualViewport',
            "menu.style.setProperty('--erp-menu-available-height'",
            'submenu.scrollHeight',
            'scheduleOpenMenuPositioning',
            "navigation.addEventListener('scroll'",
            "window.visualViewport.addEventListener('resize'",
            "navigation.addEventListener('focusin'",
            "window.addEventListener('resize'",
        );
});

test('former placeholder aliases remain technically resolvable without fixed count contracts', function (): void {
    $registry = app(ErpUiScreenRegistry::class);
    $aliases = collect($registry->legacyPlaceholderAliases());

    expect($aliases)->not->toBeEmpty()
        ->and($aliases->pluck('key')->unique()->count())->toBe($aliases->count());

    $aliases->each(function (array $alias): void {
        expect(Route::has($alias['route']))->toBeTrue()
            ->and($alias['target'])->not->toBeNull()
            ->and($alias['target']->get('tabs'))->not->toBeEmpty();
    });
});

test('legacy placeholder permissions do not expose retired shells in navigation', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $registry = app(ErpUiScreenRegistry::class);
    $aliases = collect($registry->legacyPlaceholderAliases());
    $permissions = $aliases->pluck('permission')->unique()->values()->all();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $visibleLabels = collect(app(MenuService::class)->getMenu($actor))
        ->flatMap(fn (array $domain): array => collect($domain['children'] ?? [])
            ->flatMap(fn (array $group): array => collect($group['children'] ?? [])->pluck('label')->all())
            ->all());

    expect($visibleLabels->intersect($aliases->pluck('key')))->toBeEmpty();
});
