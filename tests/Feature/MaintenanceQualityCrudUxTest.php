<?php

use Illuminate\Support\Facades\Route;

test('maintenance documents expose the standard crud and trash routes', function (): void {
    foreach (['requests', 'orders', 'material-requests', 'expenses'] as $resource) {
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'restore', 'bulk-delete'] as $action) {
            expect(Route::has("admin.maintenance.{$resource}.{$action}"))
                ->toBeTrue("Missing maintenance route: {$resource}.{$action}");
        }
    }

    expect(Route::has('admin.maintenance.reports.index'))->toBeTrue()
        ->and(Route::has('admin.maintenance.reports.export'))->toBeTrue()
        ->and(Route::has('admin.maintenance.reports.print'))->toBeTrue();
});

test('maintenance list and form views follow the shared erp interaction pattern', function (): void {
    foreach (['requests', 'orders', 'material-requests', 'expenses'] as $resource) {
        $index = file_get_contents(resource_path("views/modules/maintenance/{$resource}/index.blade.php"));

        expect($index)
            ->toContain('data-server-table')
            ->toContain('data-record-selection')
            ->toContain('data-trash-filter')
            ->toContain('data-bulk-delete')
            ->toContain("admin.maintenance.{$resource}.create");
    }

    foreach (['requests', 'orders', 'material-requests', 'expenses'] as $resource) {
        $form = file_get_contents(resource_path("views/modules/maintenance/{$resource}/form.blade.php"));

        expect(substr_count($form, 'modules.finance.partials.form-actions'))->toBe(2)
            ->and($form)->toContain('name="submit_action"');
    }

    $materialForm = file_get_contents(resource_path('views/modules/maintenance/material-requests/form.blade.php'));
    expect(substr_count($materialForm, 'data-add-maintenance-material'))->toBe(2)
        ->and($materialForm)->toContain('data-duplicate-maintenance-material')
        ->toContain('data-remove-maintenance-material');

    $requestForm = file_get_contents(resource_path('views/modules/maintenance/requests/form.blade.php'));
    $orderForm = file_get_contents(resource_path('views/modules/maintenance/orders/form.blade.php'));
    expect($requestForm)->toContain('name="maintainable_key"')
        ->and($orderForm)->toContain('name="maintainable_key"');
});

test('quality management exposes scoped crud reports stock validation and camera capture', function (): void {
    foreach (['index', 'active', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'restore', 'bulk-delete', 'stock-balance'] as $action) {
        expect(Route::has("admin.production.quality.{$action}"))
            ->toBeTrue("Missing production quality route: {$action}");
    }

    expect(Route::has('admin.production.quality.reports.index'))->toBeTrue()
        ->and(Route::has('admin.production.quality.reports.data'))->toBeTrue()
        ->and(Route::has('admin.production.quality.export'))->toBeTrue()
        ->and(Route::has('admin.production.quality.print'))->toBeTrue();

    $index = file_get_contents(resource_path('views/modules/production/quality/index.blade.php'));
    $form = file_get_contents(resource_path('views/modules/production/quality/form.blade.php'));
    $show = file_get_contents(resource_path('views/modules/production/quality/show.blade.php'));
    $reports = file_get_contents(resource_path('views/modules/production/quality/reports.blade.php'));
    $javascript = file_get_contents(public_path('assets/js/modules/Production/execution.js'));

    expect($index)
        ->toContain('data-record-selection')
        ->toContain('data-trash-filter')
        ->toContain('data-bulk-delete')
        ->and($form)
        ->toContain('data-quality-balance-url')
        ->toContain('data-quality-required')
        ->not->toContain('name="warehouse_location_id"')
        ->and($show)
        ->toContain('capture="environment"')
        ->toContain('multiple data-quality-evidence')
        ->not->toContain('production_execution.fields.warehouse_location')
        ->and($reports)
        ->toContain('product_id')
        ->toContain('branch_store_id')
        ->toContain('disposition')
        ->toContain('admin.production.quality.export')
        ->toContain('admin.production.quality.print')
        ->and($javascript)
        ->toContain('updateQualityStockBalance')
        ->toContain('sameHeldPosition')
        ->toContain('updateQualitySubjectFields();');
});

test('purchase inspection camera stores photos in the existing attachment workflow', function (): void {
    $lineAttachments = file_get_contents(resource_path('views/modules/purchases/procurement/line-attachments.blade.php'));
    $documentAttachments = file_get_contents(resource_path('views/modules/purchases/procurement/attachments.blade.php'));
    $javascript = file_get_contents(public_path('assets/js/modules/Purchases/procurement-attachments.js'));

    expect($lineAttachments)
        ->toContain('js-procurement-camera-input')
        ->toContain('capture="environment"')
        ->toContain('lines[')
        ->and($documentAttachments)
        ->toContain('js-procurement-camera-input')
        ->toContain('attachment_file_doc_nums[]')
        ->and($javascript)
        ->toContain('FilePickerUploader.upload')
        ->toContain("formData.append('accept', 'image')")
        ->toContain('appendAttachment(input, response?.data?.file)');
});

test('quality report filters target quality inspections without leaking into receipt queries', function (): void {
    $reportService = file_get_contents(base_path('modules/Production/Services/ProductionReportService.php'));
    $finishedGoodsMethod = str($reportService)->between('public function finishedGoodsReceipts', 'public function qualityInspections')->toString();
    $qualityMethod = str($reportService)->after('public function qualityInspections')->before('private function runCosts')->toString();

    expect($finishedGoodsMethod)
        ->not->toContain("filters['subject_type']")
        ->not->toContain("filters['result']")
        ->not->toContain("filters['disposition']")
        ->and($qualityMethod)
        ->toContain("filters['quality_status']")
        ->toContain("filters['subject_type']")
        ->toContain("filters['result']")
        ->toContain("filters['disposition']")
        ->toContain("filters['product_id']")
        ->toContain("filters['branch_store_id']");
});
