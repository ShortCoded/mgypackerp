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

            // Production work orders use the canonical production.orders permission.
            ['production.orders.view'],

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

    public function test_retired_procurement_redirects_use_the_destination_permission(): void
    {
        $destinations = [
            'admin.purchases.purchase-requisition-lines.index' => 'purchases.purchase_requisitions.view',
            'admin.purchases.purchase-requisition-approvals.index' => 'purchases.purchase_requisitions.view',
            'admin.purchases.supplier-quotation-lines.index' => 'purchases.supplier_quotation_entry.view',
            'admin.purchases.purchase-order-lines.index' => 'purchase_orders.view',
            'admin.purchases.purchase-order-approvals.index' => 'purchase_orders.view',
            'admin.purchases.goods-receipt-lines.index' => 'purchases.goods_receipt_notes.view',
            'admin.purchases.purchase-invoice-lines.index' => 'purchase_invoices.view',
            'admin.purchases.purchase-invoice-payments.index' => 'purchase_invoices.view',
            'admin.purchases.purchase-invoice-allocations.index' => 'purchase_invoices.view',
            'admin.purchases.purchase-return-lines.index' => 'purchases.purchase_returns.view',
            'admin.purchases.supplier-payment-allocations.index' => 'supplier_payments.view',
            'admin.purchases.supplier-debit-notes.index' => 'purchases.purchase_returns.view',
        ];
        $permissions = app(PermissionRegistryService::class)->all();

        foreach ($destinations as $routeName => $destinationPermission) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, $routeName);
            $this->assertContains('can:'.$destinationPermission, $route->gatherMiddleware(), $routeName);
        }

        foreach ([
            'purchases.purchase_requisition_lines.view',
            'purchases.purchase_requisition_approvals.view',
            'purchases.supplier_quotation_lines.view',
            'purchases.purchase_order_lines.view',
            'purchases.purchase_order_approvals.view',
            'purchases.goods_receipt_lines.view',
            'purchases.purchase_invoice_lines.view',
            'purchases.purchase_invoice_payments.view',
            'purchases.purchase_invoice_allocations.view',
            'purchases.purchase_return_lines.view',
            'purchases.supplier_payment_allocations.view',
            'purchases.supplier_debit_notes.view',
        ] as $retiredPermission) {
            $this->assertNotContains($retiredPermission, $permissions);
        }
    }

    public function test_supplier_payment_allocation_action_is_grouped_with_supplier_payments(): void
    {
        app()->setLocale('ar');
        $groups = app(PermissionRegistryService::class)->groupedForForm(app(PermissionRegistryService::class)->all());
        $matchingNodes = [];
        $visit = function (array $nodes) use (&$visit, &$matchingNodes): void {
            foreach ($nodes as $node) {
                if (in_array('purchases.supplier_payment_allocations.create', array_column($node['permissions'] ?? [], 'name'), true)) {
                    $matchingNodes[] = $node['label'];
                }

                $visit($node['children'] ?? []);
            }
        };
        $visit($groups);

        $this->assertSame(['مدفوعات الموردين'], $matchingNodes);
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
        $this->assertNotContains('التقارير', $groupLabels);
        $this->assertSame($registry->all(), collect($names)->sort()->values()->all());
        $this->assertCount(count($names), array_unique($names));
        $this->assertSame('مقارنة تقييم المخزون', $valuationGroup);

        $accountingGroup = collect($groups)->firstWhere('key', 'accounting_costing');
        $accountingPermissions = [];
        $collectAccountingPermissions = function (array $nodes) use (&$collectAccountingPermissions, &$accountingPermissions): void {
            foreach ($nodes as $node) {
                array_push($accountingPermissions, ...array_column($node['permissions'] ?? [], 'name'));
                $collectAccountingPermissions($node['children'] ?? []);
            }
        };
        $collectAccountingPermissions([$accountingGroup]);

        $this->assertContains('reports.costing.product_cost.view', $accountingPermissions);
        $this->assertContains('reports.costing.product_cost.print', $accountingPermissions);
        $this->assertContains('reports.costing.product_cost.export', $accountingPermissions);
        $this->assertContains('reports.finance.cashbox_balances.view', $accountingPermissions);

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
