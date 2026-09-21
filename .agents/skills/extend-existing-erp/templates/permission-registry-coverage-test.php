<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Tests\TestCase;

class PermissionRegistryCoverageTest extends TestCase
{
    /**
     * Known permission references discovered by scanning the application code.
     * Update this list when new permission references are added to the codebase.
     * When a permission in this list is missing from the canonical registry,
     * this test fails with the exact list of missing permissions.
     */
    private array $knownPermissionReferences = [
        // Dashboard summaries
        'dashboard.summaries.sales.view',
        'dashboard.summaries.purchases.view',

        // Quick Tasks (module: Core)
        'quick_tasks.view',
        'quick_tasks.create',
        'quick_tasks.update',
        'quick_tasks.delete',
        'quick_tasks.restore',
        'quick_tasks.change_status',
        'quick_tasks.bulk_delete',

        // User Tasks (module: Core)
        'tasks.view',
        'tasks.create',
        'tasks.edit',
        'tasks.update',
        'tasks.delete',
        'tasks.clone',
        'tasks.bulk_delete',
        'tasks.restore',
        'tasks.view_trashed',
        'tasks.document_number.control',
        'tasks.document_number_settings.update',

        // Reports
        'reports.finance.view',

        // Purchases
        'purchase_orders.clone',

        // Production / Work Orders
        'production.work_orders.view',
    ];

    public function testEveryKnownPermissionReferenceExistsInCanonicalRegistry(): void
    {
        $registry = app(PermissionRegistryService::class);
        $canonical = $registry->all();

        $missing = [];
        foreach ($this->knownPermissionReferences as $permission) {
            if (! in_array($permission, $canonical, true)) {
                $missing[] = $permission;
            }
        }

        $this->assertEmpty(
            $missing,
            'The following permission(s) referenced by application code are missing '
            . 'from the canonical permission registry (PermissionRegistryService::all()). '
            . 'Add them to the appropriate config source (config/menu/*.php or '
            . 'config/erp_ui_screens/*.php) and run "php artisan erp:permissions:sync": '
            . implode(", ", $missing)
        );
    }

    public function testCanonicalRegistryIsNotEmpty(): void
    {
        $registry = app(PermissionRegistryService::class);
        $all = $registry->all();

        $this->assertGreaterThan(100, count($all), 'Canonical permission registry should contain many permissions');
    }
}
