<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ExcelImportController;
use Modules\FixedAssets\Http\Controllers\FixedAssetController;
use Modules\FixedAssets\Services\FixedAssetsSelect2Service;

Route::middleware('auth')
    ->prefix('admin/fixed-assets')
    ->as('admin.fixed-assets.')
    ->group(function (): void {
        Route::get('/select2/asset-categories', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('fixed_assets.view')
                || (bool) $request->user()?->can('fixed_assets.create')
                || (bool) $request->user()?->can('fixed_assets.edit'),
                403
            );

            return response()->json($select2->assetCategories($request));
        })->name('select2.asset-categories');

        Route::get('/select2/credit-accounts', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->creditAccounts($request));
        })->name('select2.credit-accounts');

        Route::get('/select2/cost-centers', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->costCenters($request));
        })->name('select2.cost-centers');

        Route::get('/select2/branches', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->branches($request));
        })->name('select2.branches');

        Route::get('/select2/branch-halls', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->branchHalls($request));
        })->name('select2.branch-halls');

        Route::get('/select2/currencies', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->currencies($request));
        })->name('select2.currencies');

        Route::prefix('assets/import')
            ->name('assets.import.')
            ->controller(ExcelImportController::class)
            ->group(function (): void {
                Route::get('/', 'index')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('index');
                Route::get('/template', 'template')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('template');
                Route::post('/upload', 'store')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('store');
                Route::get('/{batch:public_uuid}', 'show')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('show');
                Route::get('/{batch:public_uuid}/errors', 'errorWorkbook')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('errors');
                Route::post('/{batch:public_uuid}/replace', 'replace')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('replace');
                Route::post('/{batch:public_uuid}/confirm', 'confirm')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('confirm');
                Route::post('/{batch:public_uuid}/cancel', 'cancel')->defaults('excelImportModule', 'fixed_assets')->middleware('can:fixed_assets.import')->name('cancel');
            });

        Route::prefix('assets')->name('assets.')->controller(FixedAssetController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:fixed_assets.view')->name('index');
            Route::get('/data', 'data')->middleware('can:fixed_assets.view')->name('data');
            Route::get('/create', 'create')->middleware('can:fixed_assets.create')->name('create');
            Route::post('/asset-categories', 'storeAssetCategory')->middleware('can:accounts.create')->name('asset-categories.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:fixed_assets.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:fixed_assets.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{fixedAsset}/restore', 'restore')->middleware('can:fixed_assets.restore')->name('restore');
            Route::get('/{fixedAsset}/image', 'image')->withTrashed()->middleware('can:fixed_assets.view')->name('image');
            Route::get('/{fixedAsset}/clone', 'clone')->middleware('can:fixed_assets.clone')->name('clone');
            Route::get('/{fixedAsset}', 'show')->withTrashed()->middleware('can:fixed_assets.view')->name('show');
            Route::get('/{fixedAsset}/edit', 'edit')->middleware('can:fixed_assets.edit')->name('edit');
            Route::put('/{fixedAsset}', 'update')->middleware('can:fixed_assets.edit')->name('update');
            Route::delete('/{fixedAsset}', 'destroy')->middleware('can:fixed_assets.delete')->name('destroy');
        });
    });
