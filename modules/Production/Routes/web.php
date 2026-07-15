<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ItemLookupController;
use Modules\Core\Http\Controllers\Select2\ItemLookupSelect2Controller;
use Modules\Production\Http\Controllers\ProductionIdentifierController;

Route::middleware('auth')
    ->prefix('admin/production')
    ->as('admin.production.')
    ->group(function (): void {
        Route::get('/select2/identifier-types', ItemLookupSelect2Controller::class)
            ->defaults('lookup', 'production-identifier-types')
            ->name('select2.identifier-types');
        Route::get('/select2/identifiers', [ProductionIdentifierController::class, 'select2Identifiers'])
            ->middleware('can:production.identifiers.view')
            ->name('select2.identifiers');

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
