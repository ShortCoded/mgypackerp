<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ExcelImportController;
use Modules\FixedAssets\Http\Controllers\FixedAssetController;
use Modules\FixedAssets\Http\Controllers\FixedAssetDepreciationController;
use Modules\FixedAssets\Http\Controllers\FixedAssetLifecycleController;
use Modules\FixedAssets\Http\Controllers\FixedAssetReportController;
use Modules\FixedAssets\Services\FixedAssetsSelect2Service;

Route::middleware('auth')
    ->prefix('admin/fixed-assets')
    ->as('admin.fixed-assets.')
    ->group(function (): void {
        Route::get('/select2/assets', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('fixed_assets.view')
                || (bool) $request->user()?->can('fixed_assets.depreciation.preview')
                || (bool) $request->user()?->can('fixed_assets.reports'),
                403
            );

            return response()->json($select2->assets($request));
        })->name('select2.assets');

        Route::get('/select2/asset-categories', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('fixed_assets.view')
                || (bool) $request->user()?->can('fixed_assets.create')
                || (bool) $request->user()?->can('fixed_assets.edit')
                || (bool) $request->user()?->can('fixed_assets.depreciation.preview')
                || (bool) $request->user()?->can('fixed_assets.reports')
                || (bool) $request->user()?->can('fixed_assets.accounting.configure'),
                403
            );

            return response()->json($select2->assetCategories($request));
        })->name('select2.asset-categories');

        Route::get('/select2/credit-accounts', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit') || (bool) $request->user()?->can('fixed_assets.accounting.configure'), 403);

            return response()->json($select2->creditAccounts($request));
        })->name('select2.credit-accounts');

        Route::get('/select2/cost-centers', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit') || (bool) $request->user()?->can('fixed_assets.transfer') || (bool) $request->user()?->can('fixed_assets.depreciation.preview') || (bool) $request->user()?->can('fixed_assets.reports'), 403);

            return response()->json($select2->costCenters($request));
        })->name('select2.cost-centers');

        Route::get('/select2/branches', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit') || (bool) $request->user()?->can('fixed_assets.transfer') || (bool) $request->user()?->can('fixed_assets.depreciation.preview') || (bool) $request->user()?->can('fixed_assets.reports'), 403);

            return response()->json($select2->branches($request));
        })->name('select2.branches');

        Route::get('/select2/branch-halls', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit') || (bool) $request->user()?->can('fixed_assets.transfer') || (bool) $request->user()?->can('fixed_assets.reports'), 403);

            return response()->json($select2->branchHalls($request));
        })->name('select2.branch-halls');

        Route::get('/select2/currencies', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.create') || (bool) $request->user()?->can('fixed_assets.edit'), 403);

            return response()->json($select2->currencies($request));
        })->name('select2.currencies');

        Route::get('/select2/customers', function (Request $request, FixedAssetsSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('fixed_assets.dispose'), 403);

            return response()->json($select2->customers($request));
        })->name('select2.customers');

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

        Route::controller(FixedAssetLifecycleController::class)->group(function (): void {
            Route::get('/assets/{fixedAsset}/card', 'show')->middleware('can:fixed_assets.view')->name('lifecycle.show');
            Route::post('/assets/{fixedAsset}/activate', 'activate')->name('lifecycle.activate');
            Route::post('/assets/{fixedAsset}/transfers', 'transfer')->name('lifecycle.transfer');
            Route::post('/assets/{fixedAsset}/disposals', 'dispose')->name('lifecycle.dispose');
            Route::post('/disposals/{disposal}/reverse', 'reverseDisposal')->name('disposal.reverse');
            Route::get('/accounting-mappings', 'accounting')->middleware('can:fixed_assets.accounting.configure')->name('accounting.index');
            Route::post('/accounting-mappings', 'configureAccounting')->name('accounting.store');
            Route::get('/assets/{fixedAsset}/print', 'printAsset')->middleware('can:fixed_assets.print')->name('prints.asset');
            Route::get('/movements/{movement}/print', 'printMovement')->middleware('can:fixed_assets.print')->name('prints.movement');
            Route::get('/disposals/{disposal}/print', 'printDisposal')->middleware('can:fixed_assets.print')->name('prints.disposal');
        });

        Route::prefix('depreciation')->name('depreciation.')->controller(FixedAssetDepreciationController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:fixed_assets.depreciation.preview')->name('index');
            Route::post('/preview', 'preview')->name('preview');
            Route::post('/post', 'post')->name('post');
            Route::get('/runs/{run}', 'show')->middleware('can:fixed_assets.depreciation.preview')->name('show');
            Route::post('/runs/{run}/reverse', 'reverse')->name('reverse');
            Route::get('/runs/{run}/print', 'print')->middleware('can:fixed_assets.print')->name('print');
        });

        Route::prefix('reports')->name('reports.')->controller(FixedAssetReportController::class)->middleware('can:fixed_assets.reports')->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/print', 'print')->middleware('can:fixed_assets.print')->name('print');
            Route::get('/excel', 'excel')->middleware('can:fixed_assets.export')->name('excel');
            Route::get('/pdf', 'pdf')->middleware('can:fixed_assets.print')->name('pdf');
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
