<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\MenuService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<array<string, mixed>>
 */
function businessDomainMenuLeaves(array $items, int $depth = 0): array
{
    $leaves = [];

    foreach ($items as $item) {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];

        if ($children !== []) {
            $leaves = [
                ...$leaves,
                ...businessDomainMenuLeaves($children, $depth + 1),
            ];

            continue;
        }

        if (is_string($item['route'] ?? null) && $item['route'] !== '') {
            $item['depth'] = $depth;
            $leaves[] = $item;
        }
    }

    return $leaves;
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function businessDomainDestinations(array $items): array
{
    $destinations = array_map(
        fn (array $item): string => $item['route'].'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR),
        businessDomainMenuLeaves($items),
    );

    sort($destinations);

    return $destinations;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return list<string>
 */
function businessDomainPermissionNames(array $nodes): array
{
    $permissions = [];

    foreach ($nodes as $node) {
        $permissions = [
            ...$permissions,
            ...array_column($node['permissions'] ?? [], 'name'),
            ...businessDomainPermissionNames($node['children'] ?? []),
        ];
    }

    return array_values(array_unique($permissions));
}

function businessDomainActor(string $permission): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate($permission, 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo($permission);

    return $actor;
}

function businessDomainRequest(string $routeName): void
{
    $request = Request::create('/_business-domain-menu');
    $request->setRouteResolver(fn (): RoutingRoute => new RoutingRoute(
        ['GET'],
        '/_business-domain-menu',
        ['as' => $routeName],
    ));

    app()->instance('request', $request);
}

test('configured and expanded menu destinations are preserved exactly once in the domain hierarchy', function (string $phaseMode, bool $includeExpanded): void {
    config()->set('erp.phase_mode', $phaseMode);

    $sourceItems = [];

    foreach (app(MenuConfigFileOrder::class)->files() as $file) {
        $items = require $file;

        if (is_array($items)) {
            $sourceItems = [...$sourceItems, ...$items];
        }
    }

    if ($includeExpanded) {
        $sourceItems = [
            ...$sourceItems,
            ...app(ErpUiScreenRegistry::class)->menuItems(),
        ];
    }

    $sourceDestinations = businessDomainDestinations($sourceItems);
    $organized = app(MenuService::class)->structure();
    $organizedDestinations = businessDomainDestinations($organized);
    $organizedLeaves = businessDomainMenuLeaves($organized);

    expect($organizedDestinations)
        ->toBe($sourceDestinations)
        ->toHaveCount($includeExpanded ? 604 : 80)
        ->and(array_unique($organizedDestinations))->toHaveCount(count($organizedDestinations))
        ->and(max(array_column($organizedLeaves, 'depth')))->toBe(1);
})->with([
    'legacy configuration' => ['legacy', false],
    'expanded configuration' => ['expanded', true],
]);

test('menu uses the required business domain order and maps representative screens correctly', function (string $phaseMode): void {
    config()->set('erp.phase_mode', $phaseMode);

    $menu = app(MenuService::class)->structure();
    $domains = collect($menu)->keyBy('label');

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
    ])->and($domains['dashboard']['route'])->toBe('dashboard')
        ->and($domains['dashboard']['children'])->toBe([]);

    $labelsFor = fn (string $domain): array => collect($domains[$domain]['children'])->pluck('label')->all();

    expect($labelsFor('basic_data'))
        ->toContain('companies', 'branches', 'financial_periods', 'users', 'roles', 'item_units', 'item_sizes', 'item_categories', 'item_colors')
        ->not->toContain('customers', 'suppliers', 'products', 'file_manager', 'calendar', 'chat')
        ->and($labelsFor('sales'))->toContain('customers', 'quotations', 'customers_report')
        ->and($labelsFor('purchases'))->toContain('suppliers', 'purchase_orders', 'purchase_invoices', 'suppliers_report')
        ->and($labelsFor('inventory'))->toContain('products', 'raw_materials', 'packaging_materials', 'opening_stocks', 'unpriced_inventory_receipts', 'opening_stock_pricings', 'products_data_report')
        ->and($labelsFor('production'))->toContain('production_identifier_types', 'production_identifiers')
        ->and($labelsFor('accounting_costing'))->toContain('chart_of_accounts', 'cost_centers', 'currencies', 'bank_accounts', 'cashboxes', 'opening_balances', 'fixed_assets_register')
        ->and($labelsFor('human_resources'))->toContain('hr_employees')
        ->and($labelsFor('tools'))->toContain('open_documents', 'file_manager', 'calendar', 'my_board', 'task_boards', 'team_board', 'chat', 'pwa_settings', 'activity_logs', 'auth_logs', 'auth_sessions');

    expect($labelsFor('tools'))->not->toContain('sales', 'purchases', 'inventory', 'production', 'human_resources')
        ->and(collect($menu)->pluck('label')->all())->not->toContain(
            'administration',
            'item_data',
            'general_ledger',
            'finance',
            'fixed_assets',
            'costing',
            'reports',
            'planning_production',
            'quality',
            'maintenance',
        );

    if ($phaseMode === 'expanded') {
        expect($labelsFor('basic_data'))->toContain(
            'product_data_product_types',
            'product_data_packaging_material_types',
            'product_data_product_families',
            'product_data_product_specifications',
            'product_data_product_units',
        )->and($labelsFor('sales'))->toContain('sales_sales_orders', 'sales_sales_invoices', 'sales_sales_returns', 'reports_sales_sales_orders')
            ->and($labelsFor('purchases'))->toContain('purchases_purchase_returns', 'reports_purchases_purchase_orders')
            ->and($labelsFor('inventory'))->toContain('inventory_stock_receipts', 'inventory_stock_adjustments', 'core_warehouse_policies', 'reports_inventory_inventory_balances')
            ->and($labelsFor('inventory'))->not->toContain('product_data_product_types', 'product_data_product_bom', 'inventory_finished_goods_receipt')
            ->and($labelsFor('production'))->toContain(
                'production_work_orders',
                'product_data_product_bom',
                'product_data_product_routing',
                'product_data_mold_product_relationships',
                'inventory_finished_goods_receipt',
                'inventory_production_material_issue',
                'quality_incoming_material_inspection',
                'maintenance_maintenance_work_orders',
                'reports_production_production_plan',
            )
            ->and($labelsFor('accounting_costing'))->toContain('costing_costing_settings', 'fixed_assets_asset_categories', 'reports_finance_cashbox_balances', 'reports_costing_product_cost');
    }
})->with(['legacy', 'expanded']);

test('phase-gated purchase orders keep their route and visibility behavior', function (): void {
    $actor = businessDomainActor('purchase_orders.view');

    config()->set('erp.phase_mode', 'legacy');
    $legacyMenu = app(MenuService::class)->getMenu($actor);

    expect(collect($legacyMenu)->firstWhere('label', 'purchases'))->toBeNull();

    config()->set('erp.phase_mode', 'expanded');
    $expandedPurchases = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'purchases');
    $purchaseOrders = collect($expandedPurchases['children'])->firstWhere('label', 'purchase_orders');

    expect($expandedPurchases)->not->toBeNull()
        ->and($purchaseOrders['route'])->toBe('admin.purchases.purchase-orders.index')
        ->and($purchaseOrders['phase_modes'])->toBe(['expanded']);
});

test('legacy phase-gated permissions and route aliases keep their target visible and active', function (): void {
    config()->set('erp.phase_mode', 'expanded');

    $legacyPermission = 'costing.estimated_cost_sheets.view';
    $actor = businessDomainActor($legacyPermission);
    businessDomainRequest('admin.costing.estimated-cost-sheets.index');

    $menu = app(MenuService::class)->getMenu($actor);
    $accounting = collect($menu)->firstWhere('label', 'accounting_costing');
    $target = collect($accounting['children'])->firstWhere('label', 'costing_work_order_estimated_cost');

    expect($accounting)->not->toBeNull()
        ->and($accounting['active'])->toBeTrue()
        ->and($accounting['open'])->toBeTrue()
        ->and($target)->not->toBeNull()
        ->and($target['active'])->toBeTrue()
        ->and($target['route'])->toBe('admin.costing.work-order-estimated-cost.index')
        ->and($target['permission'])->toContain($legacyPermission);

    $groups = app(PermissionRegistryService::class)->groupedForForm([$legacyPermission]);
    $accountingPermissions = businessDomainPermissionNames(
        collect($groups)->where('key', 'accounting_costing')->values()->all(),
    );

    expect($accountingPermissions)->toContain($legacyPermission);
});

test('only the owning business domain opens for child index create show and edit routes', function (string $routeName, string $permission, string $expectedDomain, string $phaseMode): void {
    config()->set('erp.phase_mode', $phaseMode);

    $actor = businessDomainActor($permission);
    businessDomainRequest($routeName);

    $menu = app(MenuService::class)->getMenu($actor);
    $openDomains = collect($menu)->where('open', true)->pluck('label')->all();
    $activeDomains = collect($menu)
        ->where('label', '!=', 'dashboard')
        ->where('active', true)
        ->pluck('label')
        ->all();

    expect($openDomains)->toBe([$expectedDomain])
        ->and($activeDomains)->toBe([$expectedDomain]);
})->with([
    'basic data create' => ['admin.item-units.create', 'item_units.view', 'basic_data', 'legacy'],
    'sales show' => ['admin.sales.customers.show', 'customers.view', 'sales', 'legacy'],
    'purchases edit' => ['admin.purchases.suppliers.edit', 'suppliers.view', 'purchases', 'legacy'],
    'inventory edit' => ['admin.products.edit', 'products.view', 'inventory', 'legacy'],
    'production show' => ['admin.production.identifiers.show', 'production.identifiers.view', 'production', 'legacy'],
    'accounting edit' => ['admin.finance.bank-accounts.edit', 'bank_accounts.view', 'accounting_costing', 'legacy'],
    'tools index' => ['admin.file-manager.index', 'file_manager.view', 'tools', 'legacy'],
    'expanded sales edit' => ['admin.sales.sales-orders.edit', 'sales_orders.view', 'sales', 'expanded'],
]);

test('permission filtering hides unauthorized children and empty business domains', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    $actor = businessDomainActor('customers.view');
    $menu = app(MenuService::class)->getMenu($actor);
    $sales = collect($menu)->firstWhere('label', 'sales');

    expect(collect($menu)->pluck('label')->all())->toBe(['dashboard', 'sales'])
        ->and($sales)->not->toBeNull()
        ->and(collect($sales['children'])->pluck('label')->all())->toBe(['customers']);
});

test('permission form uses the same recursive business domain hierarchy', function (): void {
    app()->setLocale('en');

    $groups = app(PermissionRegistryService::class)->groupedForForm([
        'dashboard.view',
        'roles.view',
        'item_units.view',
        'customers.view',
        'reports.customers.view',
        'suppliers.view',
        'products.view',
        'production.identifiers.view',
        'quality.incoming_material_inspection.view',
        'accounts.view',
        'fixed_assets.view',
        'costing.costing_settings.view',
        'hr.employees.view',
        'file_manager.view',
    ]);

    expect(array_column($groups, 'key'))->toBe([
        'general',
        'basic_data',
        'sales',
        'purchases',
        'inventory',
        'production',
        'accounting_costing',
        'human_resources',
        'tools',
    ])->and(array_column($groups, 'label'))->toBe([
        __('common.groups.general'),
        __('menu.basic_data'),
        __('menu.sales'),
        __('menu.purchases'),
        __('menu.inventory'),
        __('menu.production'),
        __('menu.accounting_costing'),
        __('menu.human_resources'),
        __('menu.tools'),
    ]);

    $permissionsByDomain = collect($groups)->mapWithKeys(
        fn (array $group): array => [$group['key'] => businessDomainPermissionNames([$group])],
    );

    expect($permissionsByDomain['basic_data'])->toContain('roles.view', 'item_units.view')
        ->and($permissionsByDomain['sales'])->toContain('customers.view', 'reports.customers.view')
        ->and($permissionsByDomain['purchases'])->toContain('suppliers.view')
        ->and($permissionsByDomain['inventory'])->toContain('products.view')
        ->and($permissionsByDomain['production'])->toContain('production.identifiers.view', 'quality.incoming_material_inspection.view')
        ->and($permissionsByDomain['accounting_costing'])->toContain('accounts.view', 'fixed_assets.view', 'costing.costing_settings.view')
        ->and($permissionsByDomain['human_resources'])->toContain('hr.employees.view')
        ->and($permissionsByDomain['tools'])->toContain('file_manager.view');
});

test('domain labels are localized exactly and navigation renderers keep unique collapse ids and scrolling', function (): void {
    config()->set('erp.phase_mode', 'legacy');

    app()->setLocale('en');
    $english = collect(app(MenuService::class)->structure())->pluck('text', 'label');

    expect($english['basic_data'])->toBe('Basic Data')
        ->and($english['sales'])->toBe('Sales')
        ->and($english['purchases'])->toBe('Purchases')
        ->and($english['inventory'])->toBe('Inventory')
        ->and($english['production'])->toBe('Production')
        ->and($english['accounting_costing'])->toBe('Accounting & Costing')
        ->and($english['human_resources'])->toBe('Human Resources')
        ->and($english['tools'])->toBe('Tools');

    app()->setLocale('ar');
    $arabicMenu = app(MenuService::class)->structure();
    $arabic = collect($arabicMenu)->pluck('text', 'label');

    expect($arabic['basic_data'])->toBe('البيانات الأساسية')
        ->and($arabic['sales'])->toBe('المبيعات')
        ->and($arabic['purchases'])->toBe('المشتريات')
        ->and($arabic['inventory'])->toBe('المخزون')
        ->and($arabic['production'])->toBe('الإنتاج')
        ->and($arabic['accounting_costing'])->toBe('الحسابات والتكاليف')
        ->and($arabic['human_resources'])->toBe('الموارد البشرية')
        ->and($arabic['tools'])->toBe('الأدوات');

    $verticalHtml = view('layouts.partials.menu.vertical-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();
    preg_match_all('/\\sid="(vertical-menu-[^"]+)"/', $verticalHtml, $collapseIds);
    preg_match_all('/\\saria-controls="(vertical-menu-[^"]+)"/', $verticalHtml, $controlledIds);

    $topHtml = view('layouts.partials.menu.top-items', [
        'items' => $arabicMenu,
        'menuPath' => [],
    ])->render();
    preg_match_all('/\\sid="(top-menu-[^"]+)"/', $topHtml, $topIds);

    expect($collapseIds[1])->toHaveCount(8)
        ->and(array_unique($collapseIds[1]))->toHaveCount(8)
        ->and($controlledIds[1])->toBe($collapseIds[1])
        ->and($topIds[1])->toHaveCount(8)
        ->and(array_unique($topIds[1]))->toHaveCount(8)
        ->and(file_get_contents(resource_path('views/layouts/partials/navbar-vertical.blade.php')))
        ->toContain('navbar-vertical-content scrollbar')
        ->and(file_get_contents(resource_path('views/layouts/app.blade.php')))
        ->toContain("dir=\"{{ config('languages.available.' . app()->getLocale() . '.dir', 'ltr') }}\"");

    app()->setLocale('en');
});
