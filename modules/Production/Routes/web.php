<?php

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
            Route::post('/', 'store')->name('store');
            Route::patch('/{productionStage}/restore', 'restore')->middleware('can:production.stages.restore')->name('restore');
            Route::get('/{productionStage}/edit', 'edit')->middleware('can:production.stages.edit')->name('edit');
            Route::put('/{productionStage}', 'update')->name('update');
            Route::delete('/{productionStage}', 'destroy')->middleware('can:production.stages.delete')->name('destroy');
        });
        Route::get('/product-stages', [ProductProductionStageController::class, 'index'])->middleware('can:production.product_stages.view')->name('product-stages.index');
        Route::get('/product-stages/{product}/edit', [ProductProductionStageController::class, 'edit'])->middleware('can:production.product_stages.manage')->name('product-stages.edit');
        Route::put('/product-stages/{product}', [ProductProductionStageController::class, 'update'])->name('product-stages.update');

        Route::get('/material-requests', [ProductionMaterialRequestController::class, 'index'])->middleware('can:production.material_requests.view')->name('material-requests.index');
        Route::post('/material-requests', [ProductionMaterialRequestController::class, 'store'])->name('material-requests.store');
        Route::post('/material-requests/{productionMaterialRequest}/approve', [ProductionMaterialRequestController::class, 'approve'])->middleware('can:production.material_requests.approve')->name('material-requests.approve');
        Route::post('/material-requests/{productionMaterialRequest}/allocate-shortage', [ProductionMaterialRequestController::class, 'allocateShortage'])->middleware('can:production.material_requests.approve')->name('material-requests.allocate-shortage');
        Route::post('/material-requests/{productionMaterialRequest}/issue', [ProductionMaterialRequestController::class, 'issue'])->middleware('can:production.material_requests.issue')->name('material-requests.issue');

        Route::get('/expenses', [ProductionExpenseRequestController::class, 'index'])->middleware('can:production.expenses.view')->name('expenses.index');
        Route::post('/expenses', [ProductionExpenseRequestController::class, 'store'])->name('expenses.store');
        Route::post('/expenses/{productionExpenseRequest}/approve', [ProductionExpenseRequestController::class, 'approve'])->middleware('can:production.expenses.approve')->name('expenses.approve');
        Route::post('/expenses/{productionExpenseRequest}/pay', [ProductionExpenseRequestController::class, 'pay'])->middleware('can:production.expenses.pay')->name('expenses.pay');
        Route::post('/expenses/{productionExpenseRequest}/reverse', [ProductionExpenseRequestController::class, 'reverse'])->middleware('can:production.expenses.reverse')->name('expenses.reverse');

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
        Route::get('/quality/create', [ProductionQualityController::class, 'create'])->middleware('can:production.quality.create')->name('quality.create');
        Route::post('/quality', [ProductionQualityController::class, 'store'])->name('quality.store');
        Route::get('/quality/{inspection}', [ProductionQualityController::class, 'show'])->middleware('can:production.quality.view')->name('quality.show');
        Route::get('/quality/{inspection}/evidence/{evidence}', [ProductionQualityController::class, 'evidence'])->middleware('can:production.quality.view')->whereNumber('evidence')->name('quality.evidence');
        Route::get('/quality/{inspection}/reports/{report}/evidence/{evidence}', [ProductionQualityController::class, 'reportEvidence'])->middleware('can:production.quality.view')->whereNumber(['report', 'evidence'])->name('quality.reports.evidence');
        Route::post('/quality/{inspection}/receive', [ProductionQualityController::class, 'receive'])->middleware('can:production.quality.receive')->name('quality.receive');
        Route::post('/quality/{inspection}/start', [ProductionQualityController::class, 'start'])->middleware('can:production.quality.start')->name('quality.start');
        Route::post('/quality/{inspection}/reports', [ProductionQualityController::class, 'addReport'])->name('quality.reports.store');
        Route::post('/quality/{inspection}/submit', [ProductionQualityController::class, 'submit'])->name('quality.submit');
        Route::post('/quality/{inspection}/approve', [ProductionQualityController::class, 'approve'])->middleware('can:production.quality.review')->name('quality.approve');
        Route::post('/quality/{inspection}/reject', [ProductionQualityController::class, 'reject'])->middleware('can:production.quality.review')->name('quality.reject');
        Route::post('/quality/{inspection}/close', [ProductionQualityController::class, 'close'])->name('quality.close');
        Route::post('/quality/{inspection}/reinspect', [ProductionQualityController::class, 'reinspect'])->middleware('can:production.quality.reinspect')->name('quality.reinspect');

        Route::prefix('work-orders')->name('work-orders.')->controller(ProductionOrderController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.orders.view')->name('index');
            Route::get('/data', 'data')->middleware('can:production.orders.view')->name('data');
            Route::get('/{productionOrder}', 'show')->middleware('can:production.orders.view')->name('show');
            Route::get('/{productionOrder}/print', 'print')->middleware('can:production.orders.print')->name('print');
            Route::get('/{productionOrder}/requirement/print', 'printRequirement')->middleware('can:production.orders.print')->name('requirement.print');
        });

        Route::post('/work-orders/{productionOrder}/release', [ProductionRunController::class, 'releaseOrder'])
            ->middleware('can:production.orders.release')
            ->name('work-orders.release');
        Route::post('/work-orders/{productionOrder}/short-close', [ProductionRunController::class, 'shortClose'])
            ->middleware('can:production.orders.short_close')
            ->name('work-orders.short-close');

        Route::prefix('runs')->name('runs.')->controller(ProductionRunController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.runs.view')->name('index');
            Route::get('/data', 'data')->middleware('can:production.runs.view')->name('data');
            Route::post('/', 'store')->middleware('can:production.runs.plan')->name('store');
            Route::get('/{productionRun}/print', 'print')->middleware('can:production.runs.print')->name('print');
            Route::get('/{productionRun}/materials/print', 'printMaterials')->middleware('can:production.runs.print')->name('materials.print');
            Route::get('/{productionRun}/quality/print', 'printQuality')->middleware('can:production.runs.print')->name('quality.print');
            Route::get('/{productionRun}/completion/print', 'printCompletion')->middleware('can:production.runs.print')->name('completion.print');
            Route::post('/{productionRun}/reserve', 'reserve')->middleware('can:production.runs.reserve')->name('reserve');
            Route::post('/{productionRun}/issue', 'issue')->middleware('can:production.runs.issue')->name('issue');
            Route::post('/{productionRun}/return', 'returnMaterials')->middleware('can:production.runs.issue')->name('return');
            Route::post('/{productionRun}/setup/start', 'startSetup')->middleware('can:production.runs.setup')->name('setup.start');
            Route::post('/{productionRun}/setup/complete', 'completeSetup')->middleware('can:production.runs.setup')->name('setup.complete');
            Route::post('/{productionRun}/start', 'start')->middleware('can:production.runs.setup')->name('start');
            Route::post('/{productionRun}/resume', 'resume')->middleware('can:production.runs.qc')->name('resume');
            Route::post('/{productionRun}/cancel', 'cancel')->middleware('can:production.runs.cancel')->name('cancel');
            Route::post('/{productionRun}/progress', 'progress')->middleware('can:production.runs.progress')->name('progress');
            Route::post('/{productionRun}/labor', 'labor')->middleware('can:production.runs.labor')->name('labor');
            Route::post('/{productionRun}/account-materials', 'account')->middleware('can:production.runs.account_materials')->name('account');
            Route::post('/{productionRun}/receive', 'receive')->middleware('can:production.runs.receive')->name('receive');
            Route::post('/{productionRun}/complete', 'complete')->middleware('can:production.runs.complete')->name('complete');
            Route::get('/{productionRun}', 'show')->middleware('can:production.runs.view')->name('show');
        });
        Route::get('/reports/operations', [ProductionReportController::class, 'index'])
            ->middleware('can:production.reports.operational')
            ->name('reports.index');
        Route::get('/reports/operations/export.xlsx', [ProductionReportController::class, 'export'])
            ->middleware(['can:production.reports.operational', 'can:production.reports.export'])
            ->name('reports.export');
        Route::get('/reports/operations/print', [ProductionReportController::class, 'print'])
            ->middleware(['can:production.reports.operational', 'can:production.reports.export'])
            ->name('reports.print');

    });
