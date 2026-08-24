<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ItemLookupController;
use Modules\Core\Http\Controllers\Select2\ItemLookupSelect2Controller;
use Modules\Production\Http\Controllers\ProductionIdentifierController;
use Modules\Production\Http\Controllers\ProductionOrderController;
use Modules\Production\Http\Controllers\ProductionReportController;
use Modules\Production\Http\Controllers\ProductionResourceController;
use Modules\Production\Http\Controllers\ProductionRunController;

Route::middleware('auth')
    ->prefix('admin/production')
    ->as('admin.production.')
    ->group(function (): void {
        Route::get('/resources', [ProductionResourceController::class, 'index'])
            ->middleware('can:production.resources.view')
            ->name('resources.index');
        Route::post('/resources/machines', [ProductionResourceController::class, 'storeMachine'])
            ->middleware('can:production.resources.manage')
            ->name('resources.machines.store');
        Route::post('/resources/molds', [ProductionResourceController::class, 'storeMold'])
            ->middleware('can:production.resources.manage')
            ->name('resources.molds.store');
        Route::post('/resources/shifts', [ProductionResourceController::class, 'storeShift'])
            ->middleware('can:production.resources.manage')
            ->name('resources.shifts.store');
        Route::get('/select2/identifier-types', ItemLookupSelect2Controller::class)
            ->defaults('lookup', 'production-identifier-types')
            ->name('select2.identifier-types');
        Route::get('/select2/identifiers', [ProductionIdentifierController::class, 'select2Identifiers'])
            ->middleware('can:production.identifiers.view')
            ->name('select2.identifiers');

        Route::prefix('work-orders')->name('work-orders.')->controller(ProductionOrderController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.orders.view')->name('index');
            Route::get('/{productionOrder}', 'show')->middleware('can:production.orders.view')->name('show');
            Route::get('/{productionOrder}/print', 'print')->middleware('can:production.orders.print')->name('print');
            Route::get('/{productionOrder}/requirement/print', 'printRequirement')->middleware('can:production.orders.print')->name('requirement.print');
        });

        Route::post('/work-orders/{productionOrder}/release', [ProductionRunController::class, 'releaseOrder'])
            ->middleware('can:production.orders.release')
            ->name('work-orders.release');
        Route::post('/work-orders/make-to-stock', [ProductionRunController::class, 'storeMakeToStockOrder'])
            ->middleware('can:production.orders.plan')
            ->name('work-orders.make-to-stock.store');
        Route::post('/work-orders/{productionOrder}/short-close', [ProductionRunController::class, 'shortClose'])
            ->middleware('can:production.orders.short_close')
            ->name('work-orders.short-close');

        Route::prefix('runs')->name('runs.')->controller(ProductionRunController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:production.runs.view')->name('index');
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
            Route::post('/{productionRun}/inspect', 'inspect')->middleware('can:production.runs.qc')->name('inspect');
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

        Route::prefix('identifier-types')
            ->name('identifier-types.')
            ->controller(ItemLookupController::class)
            ->group(function (): void {
                Route::get('/', 'index')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.view')
                    ->name('index');
                Route::get('/data', 'data')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.view')
                    ->name('data');
                Route::get('/create', 'create')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.create')
                    ->name('create');
                Route::post('/', 'store')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.delete')
                    ->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.document_number_settings.update')
                    ->name('document-number-settings.update');
                Route::patch('/{record}/restore', 'restore')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.restore')
                    ->name('restore');
                Route::get('/{record}/clone', 'clone')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.clone')
                    ->name('clone');
                Route::get('/{record}', 'show')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.view')
                    ->name('show');
                Route::get('/{record}/edit', 'edit')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.edit')
                    ->name('edit');
                Route::put('/{record}', 'update')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.edit')
                    ->name('update');
                Route::delete('/{record}', 'destroy')
                    ->defaults('itemLookup', 'production_identifier_types')
                    ->middleware('can:production.identifier_types.delete')
                    ->name('destroy');
            });

        Route::prefix('identifiers')
            ->name('identifiers.')
            ->controller(ProductionIdentifierController::class)
            ->group(function (): void {
                Route::get('/', 'index')->middleware('can:production.identifiers.view')->name('index');
                Route::get('/data', 'data')->middleware('can:production.identifiers.view')->name('data');
                Route::get('/select2', 'select2Identifiers')->middleware('can:production.identifiers.view')->name('select2');
                Route::get('/tree', 'tree')->middleware('can:production.identifiers.view')->name('tree');
                Route::get('/create', 'create')->middleware('can:production.identifiers.create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:production.identifiers.delete')->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:production.identifiers.document_number_settings.update')->name('document-number-settings.update');
                Route::patch('/{identifier}/restore', 'restore')->middleware('can:production.identifiers.restore')->name('restore');
                Route::get('/{identifier}/clone', 'clone')->middleware('can:production.identifiers.clone')->name('clone');
                Route::get('/{identifier}', 'show')->withTrashed()->middleware('can:production.identifiers.view')->name('show');
                Route::get('/{identifier}/edit', 'edit')->middleware('can:production.identifiers.edit')->name('edit');
                Route::put('/{identifier}', 'update')->middleware('can:production.identifiers.edit')->name('update');
                Route::delete('/{identifier}', 'destroy')->middleware('can:production.identifiers.delete')->name('destroy');
            });
    });
