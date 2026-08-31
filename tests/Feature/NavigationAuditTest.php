<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Lang;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\MenuConfigFileOrder;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\NavigationSearchService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  list<array<string, mixed>>  $items
 * @param  list<string>  $labelPath
 * @param  list<string>  $textPath
 * @return list<array<string, mixed>>
 */
function navigationAuditRecords(array $items, array $labelPath = [], array $textPath = []): array
{
    $records = [];

    foreach ($items as $item) {
        $currentLabelPath = [...$labelPath, (string) $item['label']];
        $resolvedText = $item['text'] ?? $item['title'] ?? $item['label'];
        $currentTextPath = [...$textPath, is_string($resolvedText) ? $resolvedText : (string) $item['label']];
        $records[] = [
            ...$item,
            'label_path' => $currentLabelPath,
            'text_path' => $currentTextPath,
            'stable_path' => implode(' > ', $currentLabelPath),
        ];
        $records = [
            ...$records,
            ...navigationAuditRecords($item['children'] ?? [], $currentLabelPath, $currentTextPath),
        ];
    }

    return $records;
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<array<string, mixed>>
 */
function navigationAuditRoutedItems(array $items): array
{
    return collect(navigationAuditRecords($items))
        ->filter(fn (array $item): bool => is_string($item['route'] ?? null) && $item['route'] !== '')
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $item
 */
function navigationAuditRouteFingerprint(array $item): string
{
    return $item['route'].'|'.json_encode($item['route_params'] ?? [], JSON_THROW_ON_ERROR);
}

function navigationAuditNormalizedUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    $normalizedPath = is_string($path) && $path !== '' ? '/'.ltrim($path, '/') : '/';

    if ($normalizedPath !== '/') {
        $normalizedPath = rtrim($normalizedPath, '/');
    }

    if (! is_string($query) || $query === '') {
        return $normalizedPath;
    }

    parse_str($query, $parameters);
    ksort($parameters);

    return $normalizedPath.'?'.http_build_query($parameters);
}

function navigationAuditAdmin(): User
{
    app(PermissionSeeder::class)->run();

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail());

    return $admin;
}

function navigationAuditActor(string $permission): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate($permission, 'web');

    $actor = User::factory()->create();
    $actor->givePermissionTo($permission);

    return $actor;
}

function navigationAuditRequest(string $routeName): void
{
    $request = Request::create('/_navigation-audit');
    $request->setRouteResolver(fn (): RoutingRoute => new RoutingRoute(
        ['GET'],
        '/_navigation-audit',
        ['as' => $routeName],
    ));

    app()->instance('request', $request);
}

test('domain cleanup preserves the complete route and normalized URL inventory', function (string $phaseMode, bool $includeExpanded, int $expectedRouteCount): void {
    config()->set('erp.phase_mode', $phaseMode);
    $sourceItems = [];

    foreach (app(MenuConfigFileOrder::class)->files() as $file) {
        $configuredItems = require $file;

        if (is_array($configuredItems)) {
            $sourceItems = [...$sourceItems, ...$configuredItems];
        }
    }

    if ($includeExpanded) {
        $sourceItems = [...$sourceItems, ...app(ErpUiScreenRegistry::class)->menuItems()];
    }

    $sourceRoutes = collect(navigationAuditRoutedItems($sourceItems));
    $sourceFingerprints = $sourceRoutes
        ->map(fn (array $item): string => navigationAuditRouteFingerprint($item))
        ->unique()
        ->sort()
        ->values();
    $sourceUrls = $sourceRoutes
        ->map(fn (array $item): string => navigationAuditNormalizedUrl(route($item['route'], $item['route_params'] ?? [])))
        ->unique()
        ->sort()
        ->values();
    $organizedRoutes = collect(navigationAuditRoutedItems(app(MenuService::class)->structure()));
    $organizedFingerprints = $organizedRoutes
        ->map(fn (array $item): string => navigationAuditRouteFingerprint($item))
        ->sort()
        ->values();
    $organizedUrls = $organizedRoutes
        ->map(fn (array $item): string => navigationAuditNormalizedUrl($item['url']))
        ->sort()
        ->values();

    expect($organizedRoutes)->toHaveCount($expectedRouteCount)
        ->and($organizedFingerprints)->toEqual($sourceFingerprints)
        ->and($organizedUrls)->toEqual($sourceUrls)
        ->and($organizedFingerprints->duplicates())->toBeEmpty()
        ->and($organizedUrls->duplicates())->toBeEmpty();
})->with([
    'legacy navigation' => ['legacy', false, 103],
    'expanded navigation' => ['expanded', true, 591],
]);

test('fully authorized rendered navigation is unique and identical across locales', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $admin = navigationAuditAdmin();
    $localeRoutes = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $records = collect(navigationAuditRecords(app(MenuService::class)->getMenu($admin)));
        $routes = $records->filter(fn (array $item): bool => is_string($item['route'] ?? null));
        $routeFingerprints = $routes->map(fn (array $item): string => navigationAuditRouteFingerprint($item));
        $normalizedUrls = $routes->map(fn (array $item): string => navigationAuditNormalizedUrl($item['url']));

        expect($records)->toHaveCount(648)
            ->and($routes)->toHaveCount(590)
            ->and($routeFingerprints->duplicates())->toBeEmpty()
            ->and($normalizedUrls->duplicates())->toBeEmpty();

        $localeRoutes[$locale] = $routeFingerprints->sort()->values()->all();
    }

    expect($localeRoutes['ar'])->toBe($localeRoutes['en']);
});

test('every visible label has paired menu translations or the approved bilingual registry resolver', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $admin = navigationAuditAdmin();
    $localizedRecords = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $localizedRecords[$locale] = collect(navigationAuditRecords(app(MenuService::class)->getMenu($admin)))
            ->keyBy('stable_path');
    }

    app()->setLocale('en');
    $registryLabels = collect(navigationAuditRecords(app(ErpUiScreenRegistry::class)->menuItems()))
        ->pluck('label')
        ->unique();
    $approvedAcronyms = ['BOM', 'CAPA', 'PWA', 'QC'];

    expect($localizedRecords['ar']->keys()->all())->toBe($localizedRecords['en']->keys()->all());

    foreach ($localizedRecords['en'] as $stablePath => $english) {
        $arabic = $localizedRecords['ar'][$stablePath];
        $translationKey = 'menu.'.$english['label'];
        $hasEnglishKey = Lang::has($translationKey, 'en');
        $hasArabicKey = Lang::has($translationKey, 'ar');

        expect($hasArabicKey)->toBe($hasEnglishKey);

        if (! $hasEnglishKey) {
            expect($registryLabels)->toContain($english['label'])
                ->and($english['text'] !== $arabic['text'] || in_array($english['text'], $approvedAcronyms, true))->toBeTrue();
        }

        expect(preg_match('/[\x{0600}-\x{06FF}]/u', $english['text']))->toBe(0);

        if (! in_array($arabic['text'], $approvedAcronyms, true)) {
            expect(preg_match('/[\x{0600}-\x{06FF}]/u', $arabic['text']))->toBe(1);
        }
    }

    $expectedTranslations = [
        'sales_orders' => ['Sales Orders', 'أوامر المبيعات'],
        'deliveries' => ['Delivery Notes', 'أذون التسليم'],
        'sales_invoices' => ['Sales Invoices', 'فواتير المبيعات'],
        'customer_collections' => ['Customer Collections', 'تحصيلات العملاء'],
        'sales_returns' => ['Sales Returns', 'مرتجعات المبيعات'],
        'procurement_cycle_report' => ['Procurement Cycle Report', 'تقرير دورة المشتريات'],
        'warehouse_locations' => ['Warehouse Locations', 'مواقع المخازن'],
        'inventory_movements' => ['Inventory Movements', 'حركات المخزون'],
        'inventory_operational_reports' => ['Inventory Operational Reports', 'تقارير عمليات المخزون'],
        'inventory_stock_counts' => ['Physical Stock Counts', 'الجرد الفعلي للمخزون'],
        'production_resources' => ['Production Resources', 'موارد الإنتاج'],
        'production_work_orders' => ['Production Work Orders', 'أوامر التشغيل'],
        'production_runs' => ['Production Runs', 'تشغيلات الإنتاج'],
        'production_operational_reports' => ['Production Operational Reports', 'تقارير عمليات الإنتاج'],
        'reports_sales_sales_orders' => ['Sales Orders', 'أوامر المبيعات'],
        'fixed_asset_depreciation' => ['Fixed Asset Depreciation', 'إهلاك الأصول الثابتة'],
        'fixed_asset_reports' => ['Fixed Asset Reports', 'تقارير الأصول الثابتة'],
    ];

    foreach ($expectedTranslations as $label => [$english, $arabic]) {
        expect(Lang::get("menu.{$label}", [], 'en'))->toBe($english)
            ->and(Lang::get("menu.{$label}", [], 'ar'))->toBe($arabic);
    }
});

test('all genuine reports have one canonical location below the Reports menu', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    app()->setLocale('en');
    $admin = navigationAuditAdmin();
    $menu = app(MenuService::class)->getMenu($admin);
    $records = collect(navigationAuditRecords($menu));
    $reportPaths = [
        'admin.reports.sales.sales-orders.index' => ['reports', 'sales_reports', 'reports_sales_sales_orders'],
        'admin.purchases.procurement-cycle-report.index' => ['reports', 'purchase_reports', 'procurement_cycle_report'],
        'admin.inventory.reports.index' => ['reports', 'inventory_reports', 'inventory_operational_reports'],
        'admin.production.reports.index' => ['reports', 'production_reports', 'production_operational_reports'],
        'admin.accounting.reports.account-ledger' => ['reports', 'accounting_costing_reports', 'account_ledger'],
        'admin.accounting.reports.customer-statement' => ['reports', 'accounting_costing_reports', 'customer_statement'],
        'admin.accounting.reports.supplier-statement' => ['reports', 'accounting_costing_reports', 'supplier_statement'],
        'admin.fixed-assets.reports.index' => ['reports', 'asset_reports', 'fixed_asset_reports'],
    ];

    foreach ($reportPaths as $routeName => $expectedPath) {
        $matches = $records->where('route', $routeName)->values();

        expect($matches)->toHaveCount(1)
            ->and($matches->first()['label_path'])->toBe($expectedPath);
    }

    collect(app(ErpUiScreenRegistry::class)->screens())
        ->filter(fn ($screen): bool => $screen->module() === 'reports' && $screen->get('menu_visible', true) !== false)
        ->each(function ($screen) use ($records): void {
            $matches = $records->where('route', $screen->route('index'))->values();

            expect($matches)->toHaveCount(1)
                ->and($matches->first()['label_path'][0])->toBe('reports');
        });

    foreach ([
        'admin.sales.sales-orders.index' => 'sales',
        'admin.sales.delivery-notes.index' => 'sales',
        'admin.sales.sales-invoices.index' => 'sales',
        'admin.sales.customer-receipts.index' => 'sales',
        'admin.sales.sales-returns.index' => 'sales',
        'admin.inventory.warehouse-locations.index' => 'inventory',
        'admin.production.work-orders.index' => 'production',
    ] as $routeName => $expectedModule) {
        $matches = $records->where('route', $routeName)->values();

        expect($matches)->toHaveCount(1)
            ->and($matches->first()['label_path'][0])->toBe($expectedModule);
    }

    $nonConformance = $records->firstWhere('label', 'quality_non_conformance_reports');

    expect($nonConformance['label_path'])->toBe(['production', 'quality_management', 'quality_non_conformance_reports'])
        ->and(collect($menu)->pluck('label'))->not->toContain('fixed_assets', 'maintenance', 'quality');

    foreach ($records as $parent) {
        foreach ($parent['children'] ?? [] as $child) {
            expect($child['text'])->not->toBe($parent['text']);
        }
    }
});

test('report-only permissions retain access, hide empty module parents, and activate the complete Reports chain', function (string $permission, string $routeName, string $subgroup, string $label): void {
    config()->set('erp.phase_mode', 'expanded');
    app()->setLocale('en');
    $actor = navigationAuditActor($permission);
    navigationAuditRequest($routeName);

    $menu = app(MenuService::class)->getMenu($actor);
    $records = collect(navigationAuditRecords($menu));
    $report = collect($menu)->firstWhere('label', 'reports');
    $reportSubgroup = collect($report['children'])->firstWhere('label', $subgroup);
    $leaf = collect($reportSubgroup['children'])->firstWhere('label', $label);

    expect(collect($menu)->pluck('label')->all())->toBe(['dashboard', 'reports'])
        ->and($records->where('route', $routeName))->toHaveCount(1)
        ->and($report['active'])->toBeTrue()
        ->and($report['open'])->toBeTrue()
        ->and($reportSubgroup['active'])->toBeTrue()
        ->and($reportSubgroup['open'])->toBeTrue()
        ->and($leaf['active'])->toBeTrue()
        ->and($leaf['permission'])->toBe($permission);
})->with([
    'sales report' => ['reports.sales.sales_orders.view', 'admin.reports.sales.sales-orders.index', 'sales_reports', 'reports_sales_sales_orders'],
    'purchase report' => ['reports.purchases.view', 'admin.purchases.procurement-cycle-report.index', 'purchase_reports', 'procurement_cycle_report'],
    'inventory report' => ['inventory.reports.operational', 'admin.inventory.reports.index', 'inventory_reports', 'inventory_operational_reports'],
    'production report' => ['production.reports.operational', 'admin.production.reports.index', 'production_reports', 'production_operational_reports'],
    'account ledger' => ['reports.account_ledger.view', 'admin.accounting.reports.account-ledger', 'accounting_costing_reports', 'account_ledger'],
    'customer statement' => ['reports.customer_statement.view', 'admin.accounting.reports.customer-statement', 'accounting_costing_reports', 'customer_statement'],
    'supplier statement' => ['reports.supplier_statement.view', 'admin.accounting.reports.supplier-statement', 'accounting_costing_reports', 'supplier_statement'],
    'fixed asset report' => ['fixed_assets.reports', 'admin.fixed-assets.reports.index', 'asset_reports', 'fixed_asset_reports'],
]);

test('navigation search returns the full permitted destination set once and uses canonical localized report paths', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $admin = navigationAuditAdmin();
    $search = app(NavigationSearchService::class);

    app()->setLocale('en');
    $results = collect([
        ...$search->search($admin, 'admin', 1000)['results'],
        ...$search->search($admin, 'dashboard', 1000)['results'],
    ]);
    $menuRoutes = collect(navigationAuditRoutedItems(app(MenuService::class)->getMenu($admin)))
        ->pluck('route')
        ->sort()
        ->values();

    expect($results)->toHaveCount(590)
        ->and($results->pluck('route_name')->duplicates())->toBeEmpty()
        ->and($results->pluck('url')->map(fn (string $url): string => navigationAuditNormalizedUrl($url))->duplicates())->toBeEmpty()
        ->and($results->pluck('route_name')->sort()->values())->toEqual($menuRoutes);

    $salesReport = collect($search->search($admin, 'sales orders', 100)['results'])
        ->firstWhere('route_name', 'admin.reports.sales.sales-orders.index');

    expect($salesReport['parent_path'])->toBe('Reports / Sales Reports');

    app()->setLocale('ar');
    $inventoryReport = collect($search->search($admin, 'تقارير عمليات المخزون', 100)['results'])
        ->firstWhere('route_name', 'admin.inventory.reports.index');
    $productionResources = collect($search->search($admin, 'موارد الإنتاج', 100)['results'])
        ->firstWhere('route_name', 'admin.production.resources.index');

    expect($inventoryReport['parent_path'])->toBe('التقارير / تقارير المخزون')
        ->and($productionResources['parent_path'])->toBe('التصنيع والإنتاج / إعداد الإنتاج');
});

test('relocated reports keep breadcrumbs and recursive LTR and RTL rendering while prior nesting remains intact', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $admin = navigationAuditAdmin();

    app()->setLocale('en');
    $englishMenu = app(MenuService::class)->getMenu($admin);
    $englishTop = view('layouts.partials.menu.top-items', ['items' => $englishMenu, 'menuPath' => []])->render();
    $englishVertical = view('layouts.partials.menu.vertical-items', ['items' => $englishMenu, 'menuPath' => []])->render();
    $salesReportBreadcrumbs = app(BreadcrumbService::class)->forMenuRoute('admin.reports.sales.sales-orders.index');
    $fixedAssetBreadcrumbs = app(BreadcrumbService::class)->forMenuRoute('admin.fixed-assets.assets.index');

    app()->setLocale('ar');
    $arabicMenu = app(MenuService::class)->getMenu($admin);
    $arabicTop = view('layouts.partials.menu.top-items', ['items' => $arabicMenu, 'menuPath' => []])->render();
    $arabicVertical = view('layouts.partials.menu.vertical-items', ['items' => $arabicMenu, 'menuPath' => []])->render();

    expect(config('languages.available.en.dir'))->toBe('ltr')
        ->and(config('languages.available.ar.dir'))->toBe('rtl')
        ->and($englishTop)->toContain('Accounting &amp; Costing', 'Fixed Assets', 'Maintenance', 'Sales Reports', 'Sales Orders')
        ->and($englishVertical)->toContain('Accounting &amp; Costing', 'Fixed Assets', 'Maintenance', 'Sales Reports', 'Sales Orders')
        ->and($arabicTop)->toContain('الحسابات والتكاليف', 'الأصول الثابتة', 'الصيانة', 'تقارير المبيعات', 'أوامر المبيعات')
        ->and($arabicVertical)->toContain('الحسابات والتكاليف', 'الأصول الثابتة', 'الصيانة', 'تقارير المبيعات', 'أوامر المبيعات')
        ->and(collect($salesReportBreadcrumbs)->pluck('label')->all())->toBe(['Dashboard', 'Reports', 'Sales Reports', 'Sales Orders'])
        ->and(collect($fixedAssetBreadcrumbs)->pluck('label')->all())->toBe(['Dashboard', 'Fixed Assets', 'Asset Data', 'Fixed Assets Register']);
});
