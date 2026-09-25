<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Tests\TestCase;

class PermissionRegistryCoverageTest extends TestCase
{
    /**
     * Regression test: fails when any permission referenced by application
     * code is missing from the canonical permission registry.
     *
     * @return array<string, list<string>>
     */
    public function permissionReferences(): array
    {
        return [
            // Dashboard summary permissions (referenced in resources/views/dashboard.blade.php)
            ['dashboard.summaries.sales.view'],
            ['dashboard.summaries.purchases.view'],

            // Quick tasks permissions (referenced in modules/Core/)
            ['quick_tasks.view'],
            ['quick_tasks.create'],
            ['quick_tasks.update'],
            ['quick_tasks.delete'],
            ['quick_tasks.restore'],
            ['quick_tasks.change_status'],
            ['quick_tasks.bulk_delete'],

            // User tasks now live under My Board; the retired tasks.* screen is excluded.
            ['my_board.view'],
            ['my_board.create'],
            ['my_board.edit'],
            ['my_board.delete'],
            ['my_board.view_trashed'],
            ['my_board.restore'],
            ['my_board.tasks.view_all'],
            ['my_board.notes.view_all'],

            // Finance reports overview permission (referenced in modules/Core/Services/PlasticsDashboardService.php)
            ['reports.finance.view'],

            // Purchase order clone permission (referenced in resources/views/modules/purchases/purchase-orders/form.blade.php)
            ['purchase_orders.clone'],

            // Production work orders view permission (referenced in resources/views/modules/sales/cycle/partials/workflow-actions.blade.php)
            ['production.work_orders.view'],

            // Screen data visibility rules permissions (referenced in modules/Core/Services/ScreenDataVisibilityService.php
            //   and resources/views/modules/auth/screen-data-visibility-rules/)
            ['screen_data_visibility_rules.view'],
            ['screen_data_visibility_rules.create'],
            ['screen_data_visibility_rules.clone'],
            ['screen_data_visibility_rules.edit'],
            ['screen_data_visibility_rules.delete'],
            ['screen_data_visibility_rules.restore'],
            ['screen_data_visibility_rules.view_trashed'],
            ['screen_data_visibility_rules.bypass'],
        ];
    }

    /** @test */
    public function every_referenced_permission_is_in_canonical_registry(): void
    {
        $registry = app(PermissionRegistryService::class);

        $this->assertTrue(
            $registry->all() !== [],
            'PermissionRegistryService::all() returned an empty list — discovery failed.'
        );

        $canonical = collect($registry->all())
            ->map(fn (string $permission): string => trim($permission))
            ->filter(fn (string $permission): bool => $permission !== '')
            ->flip();

        $missing = [];

        foreach ($this->permissionReferences() as $references) {
            foreach ($references as $permission) {
                $permission = trim($permission);
                if ($permission === '') {
                    continue;
                }

                if (! $canonical->has($permission)) {
                    $missing[] = $permission;
                }
            }
        }

        $this->assertEmpty(
            $missing,
            'The following permissions are referenced by application code but missing from the canonical permission registry. Run: php artisan erp:permissions:sync (after adding them to config/menu/*.php or config/erp_ui_screens/*.php). Missing: '.implode(', ', $missing)
        );
    }

    /** @test */
    public function canonical_registry_is_not_empty(): void
    {
        $registry = app(PermissionRegistryService::class);
        $permissions = $registry->all();

        $this->assertGreaterThan(
            1000,
            count($permissions),
            'Permission registry should contain thousands of permissions from ERP menu/config definitions. Got: '.count($permissions)
        );
    }

    public function test_role_form_shows_registered_sales_actions_with_arabic_labels(): void
    {
        app()->setLocale('ar');
        $registry = app(PermissionRegistryService::class);
        $shown = [];
        $visit = function (array $nodes) use (&$visit, &$shown): void {
            foreach ($nodes as $node) {
                foreach ($node['permissions'] ?? [] as $row) {
                    $shown[$row['name']] = $row['label'];
                }
                $visit($node['children'] ?? []);
            }
        };
        $visit($registry->groupedForForm($registry->all()));

        foreach (['customer_invoices.view', 'customer_invoices.create', 'customer_invoices.post', 'sales_deliveries.receive'] as $permission) {
            $this->assertArrayHasKey($permission, $shown);
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $shown[$permission]);
        }
    }

    public function test_role_form_lists_every_assignable_permission_once_in_arabic(): void
    {
        app()->setLocale('ar');
        $registry = app(PermissionRegistryService::class);
        $groups = $registry->groupedForForm($registry->all());
        $shown = [];
        $valuationGroup = null;
        $visit = function (array $nodes) use (&$visit, &$shown, &$valuationGroup): void {
            foreach ($nodes as $node) {
                foreach ($node['permissions'] ?? [] as $row) {
                    $shown[] = $row;

                    if ($row['name'] === 'inventory.reports.financial') {
                        $valuationGroup = $node['label'];
                    }
                }

                $visit($node['children'] ?? []);
            }
        };
        $visit($groups);

        $names = array_column($shown, 'name');
        $groupLabels = array_column($groups, 'label');
        $this->assertCount(count($groupLabels), array_unique($groupLabels));
        $this->assertSame($registry->all(), collect($names)->sort()->values()->all());
        $this->assertCount(count($names), array_unique($names));
        $this->assertSame('مقارنة تقييم المخزون', $valuationGroup);

        foreach ($shown as $row) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $row['label'], $row['name']);
        }
    }

    public function test_every_route_permission_can_be_assigned_to_a_role(): void
    {
        $assignable = array_flip(app(PermissionRegistryService::class)->formAssignablePermissions());
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $permission = explode(',', substr($middleware, 4))[0];

                if (! isset($assignable[$permission])) {
                    $missing[] = $route->getName().': '.$permission;
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
