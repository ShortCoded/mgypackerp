<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Production\Exports\ProductionReportExport;

uses(RefreshDatabase::class);

test('production execution and maintenance foundation is migrated', function (): void {
    expect(Schema::hasColumns('production_stages', [
        'company_id', 'code', 'name', 'description', 'output_type', 'standard_duration_value', 'standard_duration_unit', 'display_order', 'status', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('product_production_stages', ['product_id', 'production_stage_id', 'sequence', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('product_components', ['production_stage_id']))->toBeTrue()
        ->and(Schema::hasColumns('production_order_stage_snapshots', ['production_order_line_id', 'stage_code', 'stage_name', 'sequence', 'status']))->toBeTrue()
        ->and(Schema::hasColumns('production_order_lines', ['customer_invoice_line_id']))->toBeTrue()
        ->and(Schema::hasColumns('production_runs', ['production_order_stage_snapshot_id', 'fixed_asset_id', 'work_description', 'planned_labor_count', 'actual_labor_count', 'labor_details', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('production_material_requests', ['production_run_id', 'branch_store_id', 'request_type', 'purchase_requisition_id', 'status', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('production_expense_requests', ['production_run_id', 'payment_channel', 'cash_voucher_id', 'bank_account_id', 'status', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('quality_inspections', [
            'parent_inspection_id', 'root_inspection_id', 'reinspection_number', 'disposition',
            'requested_by', 'requested_at', 'received_by', 'received_at', 'started_by', 'started_at',
            'reviewed_by', 'reviewed_at', 'released_by', 'released_at', 'closed_by', 'closed_at', 'close_notes',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('maintenance_requests', ['fixed_asset_id', 'request_type', 'symptoms', 'status', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('maintenance_work_orders', ['fixed_asset_id', 'maintenance_type', 'service_mode', 'external_provider_name', 'diagnosis', 'work_performed', 'status', 'deleted_at']))->toBeTrue();
});

test('canonical execution routes are registered', function (): void {
    expect(Route::has('admin.production.stages.index'))->toBeTrue()
        ->and(Route::has('admin.production.product-stages.index'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.index'))->toBeTrue()
        ->and(Route::has('admin.production.runs.index'))->toBeTrue()
        ->and(Route::has('admin.production.runs.select2.order-lines'))->toBeTrue()
        ->and(Route::has('admin.production.runs.select2.stages'))->toBeTrue()
        ->and(Route::has('admin.production.runs.select2.assets'))->toBeTrue()
        ->and(Route::has('admin.production.runs.select2.workers'))->toBeTrue()
        ->and(Route::has('admin.production.runs.labor'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.index'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.create'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.edit'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.clone'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.destroy'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.restore'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.print'))->toBeTrue()
        ->and(Route::has('admin.production.material-requests.allocate-shortage'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.index'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.create'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.edit'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.clone'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.destroy'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.restore'))->toBeTrue()
        ->and(Route::has('admin.production.expenses.print'))->toBeTrue()
        ->and(Route::has('admin.production.reports.orders'))->toBeTrue()
        ->and(Route::has('admin.production.reports.runs'))->toBeTrue()
        ->and(Route::has('admin.production.reports.materials'))->toBeTrue()
        ->and(Route::has('admin.production.reports.quality'))->toBeTrue()
        ->and(Route::has('admin.production.reports.receipts'))->toBeTrue()
        ->and(Route::has('admin.production.quality.index'))->toBeTrue()
        ->and(Route::has('admin.production.quality.create'))->toBeTrue()
        ->and(Route::has('admin.production.quality.select2'))->toBeTrue()
        ->and(Route::has('admin.production.quality.store'))->toBeTrue()
        ->and(Route::has('admin.production.quality.show'))->toBeTrue()
        ->and(Route::has('admin.production.quality.evidence'))->toBeTrue()
        ->and(Route::has('admin.production.quality.receive'))->toBeTrue()
        ->and(Route::has('admin.production.quality.start'))->toBeTrue()
        ->and(Route::has('admin.production.quality.submit'))->toBeTrue()
        ->and(Route::has('admin.production.quality.approve'))->toBeTrue()
        ->and(Route::has('admin.production.quality.reject'))->toBeTrue()
        ->and(Route::has('admin.production.quality.close'))->toBeTrue()
        ->and(Route::has('admin.production.quality.reinspect'))->toBeTrue()
        ->and(Route::has('admin.production.quality.export'))->toBeTrue()
        ->and(Route::has('admin.production.quality.print'))->toBeTrue()
        ->and(Route::has('admin.maintenance.requests.index'))->toBeTrue()
        ->and(Route::has('admin.maintenance.orders.index'))->toBeTrue()
        ->and(Route::has('admin.maintenance.select2'))->toBeTrue()
        ->and(Route::has('admin.maintenance.orders.show'))->toBeTrue()
        ->and(Route::has('admin.maintenance.orders.export'))->toBeTrue()
        ->and(Route::has('admin.maintenance.orders.print'))->toBeTrue()
        ->and(Route::has('admin.maintenance.reports.index'))->toBeTrue()
        ->and(Route::has('admin.maintenance.reports.export'))->toBeTrue()
        ->and(Route::has('admin.maintenance.reports.print'))->toBeTrue();
});

test('manufacturing warehouse quality and maintenance UI shells are absent from the runtime registry', function (): void {
    $domains = ['inventory', 'production', 'quality', 'maintenance'];
    $screens = collect(app(ErpUiScreenRegistry::class)->screens())
        ->filter(fn ($screen): bool => in_array($screen->module(), $domains, true));
    $menuLabels = collect(app(ErpUiScreenRegistry::class)->menuItems())
        ->flatMap(fn (array $module): array => collect($module['children'] ?? [])->flatMap(fn (array $group): array => collect($group['children'] ?? [])->pluck('label')->all())->all());

    expect($screens)->toBeEmpty()
        ->and($menuLabels)->not->toContain('production_resources', 'inventory_warehouse_locations')
        ->and(Route::has('admin.inventory.warehouse-locations.index'))->toBeFalse()
        ->and(Route::has('admin.production.resources.index'))->toBeFalse()
        ->and(Route::has('admin.production.identifier-types.index'))->toBeFalse()
        ->and(Route::has('admin.production.identifiers.index'))->toBeFalse();

    foreach (['inventory', 'production', 'quality', 'maintenance'] as $retiredReportDomain) {
        expect(collect(app(ErpUiScreenRegistry::class)->screens())
            ->filter(fn ($screen): bool => str_starts_with($screen->key(), "reports_{$retiredReportDomain}_")))
            ->toBeEmpty();
    }

    foreach ([
        config_path('erp_ui_screens/inventory.php'),
        config_path('erp_ui_screens/maintenance.php'),
        config_path('erp_ui_screens/production.php'),
        config_path('erp_ui_screens/quality.php'),
        base_path('modules/Inventory/Http/Controllers/WarehouseLocationController.php'),
        resource_path('views/modules/inventory/warehouse-locations/index.blade.php'),
        base_path('modules/Production/Http/Controllers/ProductionResourceController.php'),
        resource_path('views/modules/production/resources/index.blade.php'),
        base_path('modules/Production/Http/Controllers/ProductionIdentifierController.php'),
        resource_path('views/modules/production/identifiers/index.blade.php'),
        public_path('assets/js/modules/Production/identifiers.js'),
    ] as $retiredShellPath) {
        expect($retiredShellPath)->not->toBeFile();
    }

    foreach (['preventive_maintenance_plans', 'maintenance_execution', 'maintenance_history', 'maintenance_reports', 'inventory_reports', 'production_reports', 'quality_domain_reports', 'maintenance_domain_reports'] as $key) {
        expect(collect(config('erp_expanded_screens.screens'))->firstWhere('key', $key)['status'])->toBe('retired');
    }
});

test('production orders expose optional sales sources and standalone entry points', function (): void {
    expect(file_get_contents(resource_path('views/modules/production/work-orders/form.blade.php')))
        ->toContain('make_to_stock')
        ->toContain('sales_order')
        ->toContain('customer_invoice')
        ->toContain('source_line_reference')
        ->toContain('data-extra-params')
        ->toContain('data-duplicate-production-line')
        ->toContain('modules.finance.partials.form-actions')
        ->toContain('arrow-step="1"');

    expect(Route::has('admin.production.work-orders.create'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.store'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.edit'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.update'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.destroy'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.restore'))->toBeTrue()
        ->and(Route::has('admin.production.work-orders.clone'))->toBeTrue()
        ->and(Route::has('admin.production.quality.inspection.print'))->toBeTrue();
    expect(Route::has('admin.production.resources.machines.store'))->toBeFalse();
    expect(Route::has('admin.production.resources.index'))->toBeFalse()
        ->and(Route::has('admin.production.runs.inspect'))->toBeFalse();
});

test('production quality capture is mobile friendly', function (): void {
    $view = file_get_contents(resource_path('views/modules/production/runs/show.blade.php'));
    $css = file_get_contents(public_path('assets/css/modules/Production/execution.css'));
    $javascript = file_get_contents(public_path('assets/js/modules/Production/execution.js'));
    $qualityView = file_get_contents(resource_path('views/modules/production/quality/show.blade.php'));

    expect($view)->toContain('production-mobile-workflow');

    expect($qualityView)
        ->toContain('production-mobile-workflow')
        ->toContain('capture="environment"')
        ->toContain('name="evidence_files[]"')
        ->toContain('quality-capture-actions')
        ->toContain('multiple data-quality-evidence')
        ->toContain('aria-live="polite"')
        ->toContain('production_execution.fields.sampled_at')
        ->and($css)
        ->toContain('@media screen and (max-width: 767.98px)')
        ->not->toContain('min-block-size: 2.75rem')
        ->toContain('-webkit-overflow-scrolling: touch')
        ->and($javascript)
        ->toContain("file.type.startsWith('image/')")
        ->toContain('URL.createObjectURL(file)')
        ->toContain('preview.replaceChildren()');

    expect($view)
        ->toContain('data-production-labor-planning')
        ->toContain("route('admin.production.runs.labor'")
        ->toContain('actualDurationHours()')
        ->and($javascript)
        ->toContain('[data-add-labor-row]')
        ->toContain('reindexLaborRows');

    expect($javascript)
        ->toContain('dblclick.productionRowNavigation')
        ->toContain('[data-row-primary-link]')
        ->toContain('[data-client-report-tables]')
        ->and($css)
        ->toContain('z-index: 1085');

    expect(file_get_contents(resource_path('views/modules/production/stages/form.blade.php')))
        ->toContain('save_and_new')
        ->toContain('save_and_edit')
        ->toContain('save_and_back');

    expect($qualityView)
        ->toContain('quality-evidence-gallery')
        ->toContain('quality-review-actions')
        ->toContain('quality-lifecycle')
        ->toContain('$numbers->format($record->affected_base_quantity)')
        ->toContain('arrow-step="1"')
        ->not->toContain('name="affected_base_quantity" type="number"');

    expect(file_get_contents(resource_path('views/modules/inventory/reports/index.blade.php')))
        ->toContain('$numbers->format($row->on_hand)')
        ->toContain('$numbers->format($reportTotals[\'quantity_in\'])')
        ->not->toContain('One ledger-backed dataset powers this screen');

    expect(file_get_contents(base_path('modules/Inventory/Http/Controllers/StockCountController.php')))
        ->toContain("'system_quantity' => \$this->numbers->format(\$line->system_quantity)");
    expect(file_get_contents(resource_path('views/modules/inventory/stock-counts/form.blade.php')))
        ->toContain("\$line['system_quantity'] ?? 0")
        ->toContain('arrow-step="1"')
        ->not->toContain('type="number" step="0.00000001"');
    expect(file_get_contents(resource_path('views/modules/production/product-stages/index.blade.php')))
        ->toContain('js-select2-ajax')
        ->toContain('admin.production.product-stages.select2.products')
        ->not->toContain('@foreach($products as $product)');
    expect(trans('roles.permission_labels.labor', [], 'ar'))->toBe('تسجيل العمالة والساعات')
        ->and(trans('roles.permission_labels.labor', [], 'en'))->toBe('Record labor and hours')
        ->and(trans('roles.permission_labels.account_materials', [], 'ar'))->toBe('تسوية الخامات')
        ->and(trans('roles.permission_labels.reinspect', [], 'ar'))->toBe('إعادة فحص');
    expect(file_get_contents(base_path('modules/Production/Http/Controllers/ProductionQualityController.php')))
        ->toContain("route('admin.production.quality.evidence'");

    foreach ([
        'production/stages/form.blade.php',
        'production/stages/index.blade.php',
        'production/product-stages/form.blade.php',
        'production/product-stages/index.blade.php',
        'production/work-orders/index.blade.php',
        'production/work-orders/show.blade.php',
        'production/runs/index.blade.php',
        'production/material-requests/index.blade.php',
        'production/expenses/index.blade.php',
        'maintenance/requests/index.blade.php',
        'maintenance/orders/index.blade.php',
        'maintenance/orders/form.blade.php',
        'maintenance/orders/show.blade.php',
        'maintenance/orders/complete.blade.php',
        'maintenance/reports/index.blade.php',
    ] as $relativePath) {
        expect(file_get_contents(resource_path('views/modules/'.$relativePath)))
            ->toContain('production-mobile-workflow')
            ->toContain('assets/css/modules/Production/execution.css');
    }

    foreach (['inventory/reports/index.blade.php', 'production/reports/index.blade.php', 'maintenance/reports/index.blade.php'] as $reportPath) {
        expect(file_get_contents(resource_path('views/modules/'.$reportPath)))
            ->toContain('data-client-report-tables')
            ->toContain('assets/js/modules/Production/execution.js');
    }
});

test('inventory and production report exports localize headings and protect financial columns', function (): void {
    app()->setLocale('ar');

    $empty = new Collection;
    $inventorySheets = (new InventoryReportExport([
        'balances' => $empty,
        'reportTotals' => [
            'on_hand' => 0,
            'inventory_value' => 0,
            'unvalued_receipt_quantity' => 0,
            'quantity_in' => 0,
            'quantity_out' => 0,
        ],
        'reservations' => $empty,
        'movements' => $empty,
        'qualityBalances' => $empty,
        'damageAndScrap' => $empty,
        'stockCountVariances' => $empty,
        'reorder' => $empty,
        'agingLayers' => $empty,
        'expiryLayers' => $empty,
        'glReconciliation' => null,
        'glReconciliationUnavailableReason' => null,
    ]))->sheets();
    $productionOrderSheets = (new ProductionReportExport([
        'runs' => $empty,
        'kpis' => ['planned_base_quantity' => 0, 'good_base_quantity' => 0, 'loss_base_quantity' => 0],
        'orders' => $empty,
        'qualityInspections' => $empty,
        'materials' => $empty,
        'finishedGoodsReceipts' => $empty,
        'runCosts' => $empty,
    ], 'orders'))->sheets();
    $productionReceiptSheets = (new ProductionReportExport([
        'runs' => $empty,
        'kpis' => ['planned_base_quantity' => 0, 'good_base_quantity' => 0, 'loss_base_quantity' => 0],
        'orders' => $empty,
        'qualityInspections' => $empty,
        'materials' => $empty,
        'finishedGoodsReceipts' => $empty,
        'runCosts' => $empty,
    ], 'receipts'))->sheets();

    expect($inventorySheets[0]->title())->toBe('رصيد المخزون')
        ->and($inventorySheets[2]->title())->toBe('الحركات وكارت الصنف')
        ->and($inventorySheets[2]->headings())->not->toContain('تكلفة الوحدة', 'إجمالي التكلفة')
        ->and($productionOrderSheets)->toHaveCount(1)
        ->and($productionOrderSheets[0]->title())->toBe('موقف أوامر الإنتاج')
        ->and($productionReceiptSheets)->toHaveCount(1)
        ->and($productionReceiptSheets[0]->title())->toBe('استلامات المنتج التام')
        ->and($productionReceiptSheets[0]->headings())->not->toContain('القيمة', 'القيد');
});
