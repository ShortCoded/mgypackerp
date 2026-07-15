<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\CostCenterController;

Route::middleware('auth')
    ->prefix('admin/accounting')
    ->as('admin.accounting.')
    ->group(function (): void {
        Route::get('/select2/accounts', [AccountController::class, 'select2Accounts'])
            ->middleware('can:accounts.view')
            ->name('select2.accounts');
        Route::get('/select2/account-classifications', [AccountController::class, 'select2Classifications'])
            ->middleware('can:accounts.view')
            ->name('select2.account-classifications');
        Route::get('/select2/cost-centers', [CostCenterController::class, 'select2CostCenters'])
            ->middleware('can:cost_centers.view')
            ->name('select2.cost-centers');

        Route::prefix('accounts')
            ->name('accounts.')
            ->controller(AccountController::class)
            ->group(function (): void {
                Route::get('/', 'index')->middleware('can:accounts.view')->name('index');
                Route::get('/data', 'data')->middleware('can:accounts.view')->name('data');
                Route::get('/select2', 'select2Accounts')->middleware('can:accounts.view')->name('select2');
                Route::get('/tree', 'tree')->middleware('can:accounts.view')->name('tree');
                Route::get('/export/excel', 'exportExcel')->middleware('can:accounts.export')->name('export.excel');
                Route::get('/export/csv', 'exportCsv')->middleware('can:accounts.export')->name('export.csv');
                Route::get('/export/pdf', 'exportPdf')->middleware('can:accounts.export')->name('export.pdf');
                Route::get('/create', 'create')->middleware('can:accounts.create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:accounts.delete')->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:accounts.document_number_settings.update')->name('document-number-settings.update');
                Route::patch('/{account}/restore', 'restore')->middleware('can:accounts.restore')->name('restore');
                Route::get('/{account}/clone', 'clone')->middleware('can:accounts.clone')->name('clone');
                Route::get('/{account}', 'show')->withTrashed()->middleware('can:accounts.view')->name('show');
                Route::get('/{account}/edit', 'edit')->middleware('can:accounts.edit')->name('edit');
                Route::put('/{account}', 'update')->middleware('can:accounts.edit')->name('update');
                Route::delete('/{account}', 'destroy')->middleware('can:accounts.delete')->name('destroy');
            });

        Route::prefix('cost-centers')
            ->name('cost-centers.')
            ->controller(CostCenterController::class)
            ->group(function (): void {
                Route::get('/', 'index')->middleware('can:cost_centers.view')->name('index');
                Route::get('/data', 'data')->middleware('can:cost_centers.view')->name('data');
                Route::get('/select2', 'select2CostCenters')->middleware('can:cost_centers.view')->name('select2');
                Route::get('/tree', 'tree')->middleware('can:cost_centers.view')->name('tree');
                Route::get('/export/excel', 'exportExcel')->middleware('can:cost_centers.export')->name('export.excel');
                Route::get('/export/csv', 'exportCsv')->middleware('can:cost_centers.export')->name('export.csv');
                Route::get('/export/pdf', 'exportPdf')->middleware('can:cost_centers.export')->name('export.pdf');
                Route::get('/create', 'create')->middleware('can:cost_centers.create')->name('create');
                Route::get('/next-code', 'nextCode')->middleware('can:cost_centers.create')->name('next-code');
                Route::post('/', 'store')->name('store');
                Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:cost_centers.delete')->name('bulk-delete');
                Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:cost_centers.document_number_settings.update')->name('document-number-settings.update');
                Route::patch('/{costCenter}/restore', 'restore')->middleware('can:cost_centers.restore')->name('restore');
                Route::get('/{costCenter}/clone', 'clone')->middleware('can:cost_centers.clone')->name('clone');
                Route::get('/{costCenter}', 'show')->withTrashed()->middleware('can:cost_centers.view')->name('show');
                Route::get('/{costCenter}/edit', 'edit')->middleware('can:cost_centers.edit')->name('edit');
                Route::put('/{costCenter}', 'update')->middleware('can:cost_centers.edit')->name('update');
                Route::delete('/{costCenter}', 'destroy')->middleware('can:cost_centers.delete')->name('destroy');
            });
    });
