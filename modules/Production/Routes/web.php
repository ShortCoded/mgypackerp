<?php

use App\Http\Middleware\IdempotentDocumentSubmission;
use Illuminate\Support\Facades\Route;
use Modules\Production\Http\Controllers\ProductionExpenseRequestController;
use Modules\Production\Http\Controllers\ProductionMaterialRequestController;
use Modules\Production\Http\Controllers\ProductionOrderController;
use Modules\Production\Http\Controllers\ProductionQualityController;
use Modules\Production\Http\Controllers\ProductionReportController;
use Modules\Production\Http\Controllers\ProductionRunController;
use Modules\Production\Http\Controllers\ProductionStageController;
use Modules\Production\Http\Controllers\ProductProductionStageController;

Route::middleware('auth')
    ->prefix('admin/production')
    ->as('admin.production.')
    ->group(function (): void {
        Route::prefix('stages')->name('stages.')->controller(ProductionStageController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.stages.view')->name('index');
            Route::get('/data', 'data')->middleware('can:production.stages.view')->name('data');
            Route::get('/create', 'create')->middleware('can:production.stages.create')->name('create');
            Route::post('/', 'store')->middleware(IdempotentDocumentSubmission::class)->name('store');
            Route::patch('/{productionStage}/restore', 'restore')->middleware('can:production.stages.restore')->name('restore');
            Route::get('/{productionStage}', 'show')->middleware('can:production.stages.view')->name('show');
            Route::get('/{productionStage}/edit', 'edit')->middleware('can:production.stages.edit')->name('edit');
            Route::put('/{productionStage}', 'update')->name('update');
            Route::delete('/{productionStage}', 'destroy')->middleware('can:production.stages.delete')->name('destroy');
        });
        Route::get('/product-stages', [ProductProductionStageController::class, 'index'])->middleware('can:production.product_stages.view')->name('product-stages.index');
        Route::get('/product-stages/select2/products', [ProductProductionStageController::class, 'products'])->middleware('can:production.product_stages.view')->name('product-stages.select2.products');
        Route::get('/product-stages/{product}/edit', [ProductProductionStageController::class, 'edit'])->middleware('can:production.product_stages.manage')->name('product-stages.edit');
        Route::put('/product-stages/{product}', [ProductProductionStageController::class, 'update'])->name('product-stages.update');

        Route::get('/material-requests', [ProductionMaterialRequestController::class, 'index'])->middleware('can:production.material_requests.view')->name('material-requests.index');
        Route::get('/material-requests/create', [ProductionMaterialRequestController::class, 'create'])->middleware('can:production.material_requests.create')->name('material-requests.create');
        Route::post('/material-requests', [ProductionMaterialRequestController::class, 'store'])->middleware(IdempotentDocumentSubmission::class.':required')->name('material-requests.store');
        Route::delete('/material-requests/bulk-delete', [ProductionMaterialRequestController::class, 'bulkDelete'])->middleware('can:production.material_requests.delete')->name('material-requests.bulk-delete');
        Route::patch('/material-requests/{productionMaterialRequest}/restore', [ProductionMaterialRequestController::class, 'restore'])->middleware('can:production.material_requests.restore')->name('material-requests.restore');
        Route::get('/material-requests/{productionMaterialRequest}/clone', [ProductionMaterialRequestController::class, 'clone'])->middleware('can:production.material_requests.clone')->name('material-requests.clone');
        Route::get('/material-requests/{productionMaterialRequest}/edit', [ProductionMaterialRequestController::class, 'edit'])->middleware('can:production.material_requests.edit')->name('material-requests.edit');
        Route::put('/material-requests/{productionMaterialRequest}', [ProductionMaterialRequestController::class, 'update'])->name('material-requests.update');
        Route::delete('/material-requests/{productionMaterialRequest}', [ProductionMaterialRequestController::class, 'destroy'])->middleware('can:production.material_requests.delete')->name('material-requests.destroy');
        Route::get('/material-requests/{productionMaterialRequest}/print', [ProductionMaterialRequestController::class, 'print'])->middleware('can:production.material_requests.print')->name('material-requests.print');
        Route::post('/material-requests/{productionMaterialRequest}/approve', [ProductionMaterialRequestController::class, 'approve'])->middleware(['can:production.material_requests.approve', IdempotentDocumentSubmission::class])->name('material-requests.approve');
        Route::post('/material-requests/{productionMaterialRequest}/allocate-shortage', [ProductionMaterialRequestController::class, 'allocateShortage'])->middleware(['can:production.material_requests.approve', IdempotentDocumentSubmission::class.':required'])->name('material-requests.allocate-shortage');
        Route::post('/material-requests/{productionMaterialRequest}/issue', [ProductionMaterialRequestController::class, 'issue'])->middleware(['can:production.material_requests.issue', IdempotentDocumentSubmission::class.':required'])->name('material-requests.issue');
        Route::get('/material-requests/{productionMaterialRequest}', [ProductionMaterialRequestController::class, 'show'])->middleware('can:production.material_requests.view')->name('material-requests.show');

        Route::get('/expenses', [ProductionExpenseRequestController::class, 'index'])->middleware('can:production.expenses.view')->name('expenses.index');
        Route::get('/expenses/create', [ProductionExpenseRequestController::class, 'create'])->middleware('can:production.expenses.create')->name('expenses.create');
        Route::post('/expenses', [ProductionExpenseRequestController::class, 'store'])->middleware(IdempotentDocumentSubmission::class.':required')->name('expenses.store');
        Route::delete('/expenses/bulk-delete', [ProductionExpenseRequestController::class, 'bulkDelete'])->middleware('can:production.expenses.delete')->name('expenses.bulk-delete');
        Route::patch('/expenses/{productionExpenseRequest}/restore', [ProductionExpenseRequestController::class, 'restore'])->middleware('can:production.expenses.restore')->name('expenses.restore');
        Route::get('/expenses/{productionExpenseRequest}/clone', [ProductionExpenseRequestController::class, 'clone'])->middleware('can:production.expenses.clone')->name('expenses.clone');
        Route::get('/expenses/{productionExpenseRequest}/edit', [ProductionExpenseRequestController::class, 'edit'])->middleware('can:production.expenses.edit')->name('expenses.edit');
        Route::put('/expenses/{productionExpenseRequest}', [ProductionExpenseRequestController::class, 'update'])->name('expenses.update');
        Route::delete('/expenses/{productionExpenseRequest}', [ProductionExpenseRequestController::class, 'destroy'])->middleware('can:production.expenses.delete')->name('expenses.destroy');
        Route::get('/expenses/{productionExpenseRequest}/print', [ProductionExpenseRequestController::class, 'print'])->middleware('can:production.expenses.print')->name('expenses.print');
        Route::post('/expenses/{productionExpenseRequest}/approve', [ProductionExpenseRequestController::class, 'approve'])->middleware('can:production.expenses.approve')->name('expenses.approve');
        Route::post('/expenses/{productionExpenseRequest}/pay', [ProductionExpenseRequestController::class, 'pay'])->middleware('can:production.expenses.pay')->name('expenses.pay');
        Route::post('/expenses/{productionExpenseRequest}/reverse', [ProductionExpenseRequestController::class, 'reverse'])->middleware('can:production.expenses.reverse')->name('expenses.reverse');
        Route::get('/expenses/{productionExpenseRequest}', [ProductionExpenseRequestController::class, 'show'])->middleware('can:production.expenses.view')->name('expenses.show');

        Route::get('/quality', [ProductionQualityController::class, 'index'])->middleware('can:production.quality.view')->name('quality.index');
        Route::get('/quality/data', [ProductionQualityController::class, 'data'])->middleware('can:production.quality.view')->name('quality.data');
        Route::get('/quality/active', [ProductionQualityController::class, 'active'])->middleware('can:production.quality.view')->name('quality.active');
        Route::get('/quality/reports', [ProductionQualityController::class, 'reportsIndex'])->middleware('can:production.quality.view')->name('quality.reports.index');
        Route::get('/quality/reports/data', [ProductionQualityController::class, 'reportsData'])->middleware('can:production.quality.view')->name('quality.reports.data');
        Route::get('/quality/export.xlsx', [ProductionQualityController::class, 'export'])->middleware('can:production.quality.export')->name('quality.export');
        Route::get('/quality/print', [ProductionQualityController::class, 'print'])->middleware('can:production.quality.print')->name('quality.print');
        Route::get('/quality/select2/{lookup}', [ProductionQualityController::class, 'select2'])
            ->middleware('can:production.quality.view')
            ->whereIn('lookup', ['runs', 'products', 'stores', 'inspection-types'])
            ->name('quality.select2');
        Route::get('/quality/stock-balance', [ProductionQualityController::class, 'stockBalance'])->middleware('can:production.quality.view')->name('quality.stock-balance');
        Route::get('/quality/create', [ProductionQualityController::class, 'create'])->middleware('can:production.quality.create')->name('quality.create');
        Route::post('/quality', [ProductionQualityController::class, 'store'])->middleware(['can:production.quality.create', IdempotentDocumentSubmission::class.':required'])->name('quality.store');
        Route::delete('/quality/bulk-delete', [ProductionQualityController::class, 'bulkDelete'])->middleware('can:production.quality.delete')->name('quality.bulk-delete');
        Route::patch('/quality/{inspection}/restore', [ProductionQualityController::class, 'restore'])->middleware('can:production.quality.restore')->whereNumber('inspection')->name('quality.restore');
        Route::get('/quality/{inspection}/edit', [ProductionQualityController::class, 'edit'])->middleware('can:production.quality.edit')->whereNumber('inspection')->name('quality.edit');
        Route::put('/quality/{inspection}', [ProductionQualityController::class, 'update'])->whereNumber('inspection')->name('quality.update');
        Route::delete('/quality/{inspection}', [ProductionQualityController::class, 'destroy'])->middleware('can:production.quality.delete')->whereNumber('inspection')->name('quality.destroy');
        Route::get('/quality/{inspection}/print', [ProductionQualityController::class, 'printInspection'])->middleware('can:production.quality.print')->whereNumber('inspection')->name('quality.inspection.print');
        Route::get('/quality/{inspection}', [ProductionQualityController::class, 'show'])->middleware('can:production.quality.view')->name('quality.show');
        Route::get('/quality/{inspection}/evidence/{evidence}', [ProductionQualityController::class, 'evidence'])->middleware('can:production.quality.view')->whereNumber('evidence')->name('quality.evidence');
        Route::get('/quality/{inspection}/reports/{report}/evidence/{evidence}', [ProductionQualityController::class, 'reportEvidence'])->middleware('can:production.quality.view')->whereNumber(['report', 'evidence'])->name('quality.reports.evidence');
        Route::post('/quality/{inspection}/receive', [ProductionQualityController::class, 'receive'])->middleware(['can:production.quality.receive', IdempotentDocumentSubmission::class])->name('quality.receive');
        Route::post('/quality/{inspection}/start', [ProductionQualityController::class, 'start'])->middleware(['can:production.quality.start', IdempotentDocumentSubmission::class])->name('quality.start');
        Route::post('/quality/{inspection}/maintenance-request', [ProductionQualityController::class, 'createMaintenanceRequest'])->middleware(['can:maintenance.requests.create', IdempotentDocumentSubmission::class])->name('quality.maintenance-request');
        Route::post('/quality/{inspection}/reports', [ProductionQualityController::class, 'addReport'])->middleware(['can:production.quality.report', IdempotentDocumentSubmission::class.':required'])->name('quality.reports.store');
        Route::post('/quality/{inspection}/submit', [ProductionQualityController::class, 'submit'])->middleware(['can:production.quality.submit', IdempotentDocumentSubmission::class])->name('quality.submit');
        Route::post('/quality/{inspection}/approve', [ProductionQualityController::class, 'approve'])->middleware(['can:production.quality.review', IdempotentDocumentSubmission::class])->name('quality.approve');
        Route::post('/quality/{inspection}/reject', [ProductionQualityController::class, 'reject'])->middleware(['can:production.quality.review', IdempotentDocumentSubmission::class])->name('quality.reject');
        Route::post('/quality/{inspection}/close', [ProductionQualityController::class, 'close'])->middleware(['can:production.quality.close', IdempotentDocumentSubmission::class])->name('quality.close');
        Route::post('/quality/{inspection}/reinspect', [ProductionQualityController::class, 'reinspect'])->middleware(['can:production.quality.reinspect', IdempotentDocumentSubmission::class.':required'])->name('quality.reinspect');

        Route::prefix('work-orders')->name('work-orders.')->controller(ProductionOrderController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.orders.view')->name('index');
            Route::get('/data', 'data')->middleware('can:production.orders.view')->name('data');
            Route::get('/select2/products', 'products')->name('select2.products');
            Route::get('/select2/sources', 'sources')->name('select2.sources');
            Route::get('/select2/stages', 'stages')->name('select2.stages');
            Route::get('/select2/order-stages', 'orderStages')->name('select2.order-stages');
            Route::get('/select2/line-details', 'lineDetails')->name('select2.line-details');
            Route::get('/create', 'create')->middleware('can:production.orders.create')->name('create');
            Route::post('/', 'store')->middleware(IdempotentDocumentSubmission::class.':required')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:production.orders.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:production.orders.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{productionOrder}/restore', 'restore')->middleware('can:production.orders.restore')->name('restore');
            Route::get('/{productionOrder}/clone', 'clone')->middleware('can:production.orders.clone')->name('clone');
            Route::get('/{productionOrder}/edit', 'edit')->middleware('can:production.orders.edit')->name('edit');
            Route::put('/{productionOrder}', 'update')->name('update');
            Route::delete('/{productionOrder}', 'destroy')->middleware('can:production.orders.delete')->name('destroy');
            Route::get('/{productionOrder}', 'show')->middleware('can:production.orders.view')->name('show');
            Route::get('/{productionOrder}/print', 'print')->middleware('can:production.orders.print')->name('print');
            Route::get('/{productionOrder}/requirement/print', 'printRequirement')->middleware('can:production.orders.print')->name('requirement.print');
        });

        Route::post('/work-orders/{productionOrder}/release', [ProductionRunController::class, 'releaseOrder'])
            ->middleware(['can:production.orders.release', IdempotentDocumentSubmission::class])
            ->name('work-orders.release');
        Route::post('/work-orders/{productionOrder}/short-close', [ProductionRunController::class, 'shortClose'])
            ->middleware(['can:production.orders.short_close', IdempotentDocumentSubmission::class])
            ->name('work-orders.short-close');

        Route::prefix('runs')->name('runs.')->controller(ProductionRunController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.runs.view')->name('index');
            Route::get('/data', 'data')->middleware('can:production.runs.view')->name('data');
            Route::get('/select2/orders', 'ordersLookup')->name('select2.orders');
            Route::get('/select2/order-lines', 'orderLines')->name('select2.order-lines');
            Route::get('/select2/stages', 'stages')->name('select2.stages');
            Route::get('/select2/assets', 'assets')->name('select2.assets');
            Route::get('/select2/machines', 'machines')->name('select2.machines');
            Route::get('/select2/workers', 'workersLookup')->name('select2.workers');
            Route::get('/orders/{docNum}/lines', 'orderLinesForOrder')->name('orders.lines');
            Route::get('/batches/{productionRunBatch}', 'showBatch')->middleware('can:production.runs.view')->name('batches.show');
            Route::post('/batches/{productionRunBatch}/issue', 'issueBatch')->middleware(['can:production.runs.issue', IdempotentDocumentSubmission::class.':required'])->name('batches.issue');
            Route::get('/create', 'create')->middleware('can:production.runs.plan')->name('create');
            Route::post('/', 'store')->middleware(['can:production.runs.plan', IdempotentDocumentSubmission::class.':required'])->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:production.runs.delete')->name('bulk-delete');
            Route::patch('/{productionRun}/restore', 'restore')->middleware('can:production.runs.restore')->name('restore');
            Route::get('/{productionRun}/clone', 'clone')->middleware('can:production.runs.clone')->name('clone');
            Route::get('/{productionRun}/edit', 'edit')->middleware('can:production.runs.edit')->name('edit');
            Route::put('/{productionRun}', 'update')->middleware('can:production.runs.edit')->name('update');
            Route::delete('/{productionRun}', 'destroy')->middleware('can:production.runs.delete')->name('destroy');
            Route::get('/{productionRun}/print', 'print')->middleware('can:production.runs.print')->name('print');
            Route::get('/{productionRun}/materials/print', 'printMaterials')->middleware('can:production.runs.print')->name('materials.print');
            Route::get('/{productionRun}/quality/print', 'printQuality')->middleware('can:production.runs.print')->name('quality.print');
            Route::get('/{productionRun}/completion/print', 'printCompletion')->middleware('can:production.runs.print')->name('completion.print');
            Route::post('/{productionRun}/reserve', 'reserve')->middleware(['can:production.runs.reserve', IdempotentDocumentSubmission::class])->name('reserve');
            Route::post('/{productionRun}/issue', 'issue')->middleware(['can:production.runs.issue', IdempotentDocumentSubmission::class.':required'])->name('issue');
            Route::post('/{productionRun}/return', 'returnMaterials')->middleware(['can:production.runs.issue', IdempotentDocumentSubmission::class.':required'])->name('return');
            Route::post('/{productionRun}/setup/start', 'startSetup')->middleware(['can:production.runs.setup', IdempotentDocumentSubmission::class])->name('setup.start');
            Route::post('/{productionRun}/setup/complete', 'completeSetup')->middleware(['can:production.runs.setup', IdempotentDocumentSubmission::class])->name('setup.complete');
            Route::post('/{productionRun}/start', 'start')->middleware(['can:production.runs.setup', IdempotentDocumentSubmission::class])->name('start');
            Route::post('/{productionRun}/resume', 'resume')->middleware(['can:production.runs.qc', IdempotentDocumentSubmission::class])->name('resume');
            Route::post('/{productionRun}/cancel', 'cancel')->middleware(['can:production.runs.cancel', IdempotentDocumentSubmission::class])->name('cancel');
            Route::post('/{productionRun}/progress', 'progress')->middleware(['can:production.runs.progress', IdempotentDocumentSubmission::class.':required'])->name('progress');
            Route::post('/{productionRun}/labor', 'labor')->middleware(['can:production.runs.labor', IdempotentDocumentSubmission::class.':required'])->name('labor');
            Route::post('/{productionRun}/account-materials', 'account')->middleware(['can:production.runs.account_materials', IdempotentDocumentSubmission::class])->name('account');
            Route::post('/{productionRun}/receive', 'receive')->middleware(['can:production.runs.receive', IdempotentDocumentSubmission::class.':required'])->name('receive');
            Route::post('/{productionRun}/complete', 'complete')->middleware(['can:production.runs.complete', IdempotentDocumentSubmission::class])->name('complete');
            Route::get('/{productionRun}', 'show')->middleware('can:production.runs.view')->name('show');
        });
        Route::get('/reports/operations', [ProductionReportController::class, 'index'])
            ->middleware('can:production.reports.operational')
            ->name('reports.index');
        Route::get('/reports/operations/orders', [ProductionReportController::class, 'index'])
            ->defaults('section', 'orders')
            ->middleware('can:production.reports.operational')
            ->name('reports.orders');
        Route::get('/reports/operations/runs', [ProductionReportController::class, 'index'])
            ->defaults('section', 'runs')
            ->middleware('can:production.reports.operational')
            ->name('reports.runs');
        Route::get('/reports/operations/materials', [ProductionReportController::class, 'index'])
            ->defaults('section', 'materials')
            ->middleware('can:production.reports.operational')
            ->name('reports.materials');
        Route::get('/reports/operations/quality', [ProductionReportController::class, 'index'])
            ->defaults('section', 'quality')
            ->middleware('can:production.reports.operational')
            ->name('reports.quality');
        Route::get('/reports/operations/receipts', [ProductionReportController::class, 'index'])
            ->defaults('section', 'receipts')
            ->middleware('can:production.reports.operational')
            ->name('reports.receipts');
        Route::get('/reports/operations/export.xlsx', [ProductionReportController::class, 'export'])
            ->middleware(['can:production.reports.operational', 'can:production.reports.export'])
            ->name('reports.export');
        Route::get('/reports/operations/print', [ProductionReportController::class, 'print'])
            ->middleware(['can:production.reports.operational', 'can:production.reports.export'])
            ->name('reports.print');

    });
