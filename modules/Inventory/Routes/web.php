<?php

use App\Http\Middleware\IdempotentDocumentSubmission;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryDocumentController;
use Modules\Inventory\Http\Controllers\InventoryReportController;
use Modules\Inventory\Http\Controllers\OpeningStockController;
use Modules\Inventory\Http\Controllers\OpeningStockPricingController;
use Modules\Inventory\Http\Controllers\StockCountController;
use Modules\Inventory\Http\Controllers\UnpricedInventoryReceiptController;

Route::middleware('auth')
    ->prefix('admin/inventory')
    ->as('admin.inventory.')
    ->group(function (): void {
        Route::prefix('documents')->name('documents.')->controller(InventoryDocumentController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.documents.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.documents.view')->name('data');
            Route::get('/select2/stores', 'stores')->middleware('can:inventory.documents.view')->name('select2.stores');
            Route::get('/select2/products', 'products')->middleware('can:inventory.documents.view')->name('select2.products');
            Route::get('/create', 'create')->middleware('can:inventory.documents.create')->name('create');
            Route::post('/', 'store')->middleware(['can:inventory.documents.create', IdempotentDocumentSubmission::class])->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:inventory.documents.delete')->name('bulk-delete');
            Route::patch('/{inventoryDocument}/restore', 'restore')->middleware('can:inventory.documents.restore')->name('restore');
            Route::get('/{inventoryDocument}/clone', 'clone')->middleware('can:inventory.documents.clone')->name('clone');
            Route::get('/{inventoryDocument}/edit', 'edit')->middleware('can:inventory.documents.edit')->name('edit');
            Route::put('/{inventoryDocument}', 'update')->middleware('can:inventory.documents.edit')->name('update');
            Route::delete('/{inventoryDocument}', 'destroy')->middleware('can:inventory.documents.delete')->name('destroy');
            Route::post('/{inventoryDocument}/post', 'post')->middleware('can:inventory.documents.post')->name('post');
            Route::get('/{inventoryDocument}/print', 'print')->middleware('can:inventory.documents.print')->name('print');
            Route::post('/{inventoryDocument}/reverse', 'reverse')->middleware('can:inventory.documents.reverse')->name('reverse');
            Route::get('/{inventoryDocument}', 'show')->middleware('can:inventory.documents.view')->name('show');
        });
        Route::get('/reports/operations', [InventoryReportController::class, 'index'])
            ->middleware('can:inventory.reports.operational')
            ->name('reports.index');
        Route::get('/reports/operations/export.xlsx', [InventoryReportController::class, 'export'])
            ->middleware(['can:inventory.reports.operational', 'can:inventory.reports.export'])
            ->name('reports.export');
        Route::get('/reports/operations/print', [InventoryReportController::class, 'print'])
            ->middleware(['can:inventory.reports.operational', 'can:inventory.reports.export'])
            ->name('reports.print');
        Route::get('/stock-balances', [InventoryReportController::class, 'stockBalances'])
            ->middleware('can:inventory.reports.operational')
            ->name('stock-balances.index');
        Route::get('/stock-balances/export.xlsx', [InventoryReportController::class, 'stockBalancesExport'])
            ->middleware(['can:inventory.reports.operational', 'can:inventory.reports.export'])
            ->name('stock-balances.export');
        Route::get('/stock-balances/print', [InventoryReportController::class, 'stockBalancesPrint'])
            ->middleware(['can:inventory.reports.operational', 'can:inventory.reports.export'])
            ->name('stock-balances.print');
        Route::prefix('stock-counts')->name('stock-counts.')->controller(StockCountController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.stock_counts.view')->name('index');
            Route::post('/', 'store')->middleware('can:inventory.stock_counts.create')->name('store');
            Route::get('/{stockCount}/print', 'print')->middleware('can:inventory.stock_counts.print')->name('print');
            Route::post('/{stockCount}/record', 'record')->middleware('can:inventory.stock_counts.record')->name('record');
            Route::post('/{stockCount}/approve', 'approve')->middleware('can:inventory.stock_counts.approve')->name('approve');
            Route::get('/{stockCount}', 'show')->middleware('can:inventory.stock_counts.view')->name('show');
        });

        Route::get('/select2/opening-stock-products', [OpeningStockController::class, 'products'])
            ->name('select2.opening-stock-products');
        Route::get('/select2/opening-stock-pricing-branches', [OpeningStockPricingController::class, 'branches'])
            ->name('select2.opening-stock-pricing-branches');
        Route::get('/select2/opening-stock-pricing-branch-halls', [OpeningStockPricingController::class, 'branchHalls'])
            ->name('select2.opening-stock-pricing-branch-halls');
        Route::get('/select2/opening-stock-pricing-currencies', [OpeningStockPricingController::class, 'currencies'])
            ->name('select2.opening-stock-pricing-currencies');
        Route::get('/select2/opening-stock-pricing-documents', [OpeningStockPricingController::class, 'openingStocks'])
            ->name('select2.opening-stock-pricing-documents');
        Route::get('/select2/opening-stock-pricing-lines', [OpeningStockPricingController::class, 'lines'])
            ->name('select2.opening-stock-pricing-lines');
        Route::get('/select2/unpriced-inventory-receipt-branches', [UnpricedInventoryReceiptController::class, 'branches'])
            ->name('select2.unpriced-inventory-receipt-branches');
        Route::get('/select2/unpriced-inventory-receipt-branch-halls', [UnpricedInventoryReceiptController::class, 'branchHalls'])
            ->name('select2.unpriced-inventory-receipt-branch-halls');
        Route::get('/select2/unpriced-inventory-receipt-branch-stores', [UnpricedInventoryReceiptController::class, 'branchStores'])
            ->name('select2.unpriced-inventory-receipt-branch-stores');
        Route::get('/select2/unpriced-inventory-receipt-suppliers', [UnpricedInventoryReceiptController::class, 'suppliers'])
            ->name('select2.unpriced-inventory-receipt-suppliers');
        Route::get('/select2/unpriced-inventory-receipt-products', [UnpricedInventoryReceiptController::class, 'products'])
            ->name('select2.unpriced-inventory-receipt-products');
        Route::get('/select2/branch-halls', [OpeningStockController::class, 'branchHalls'])
            ->name('select2.branch-halls');
        Route::get('/select2/branch-stores', [OpeningStockController::class, 'branchStores'])
            ->name('select2.branch-stores');
        Route::get('/products/{product}/details', [OpeningStockController::class, 'productDetails'])
            ->name('products.details');

        Route::prefix('unpriced-inventory-receipts')->name('unpriced-inventory-receipts.')->controller(UnpricedInventoryReceiptController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.unpriced_inventory_receipts.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.unpriced_inventory_receipts.view')->name('data');
            Route::get('/create', 'create')->middleware('can:inventory.unpriced_inventory_receipts.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:inventory.unpriced_inventory_receipts.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{unpricedInventoryReceipt}/approve', 'approve')->middleware('can:inventory.unpriced_inventory_receipts.approve')->name('approve');
            Route::post('/{unpricedInventoryReceipt}/close', 'close')->middleware('can:inventory.unpriced_inventory_receipts.close')->name('close');
            Route::post('/{unpricedInventoryReceipt}/cancel', 'cancel')->middleware('can:inventory.unpriced_inventory_receipts.cancel')->name('cancel');
            Route::patch('/{unpricedInventoryReceipt}/restore', 'restore')->middleware('can:inventory.unpriced_inventory_receipts.restore')->name('restore');
            Route::get('/products/{product}/details', 'productDetails')->name('products.details');
            Route::get('/{unpricedInventoryReceipt}', 'show')->withTrashed()->middleware('can:inventory.unpriced_inventory_receipts.view')->name('show');
            Route::get('/{unpricedInventoryReceipt}/edit', 'edit')->middleware('can:inventory.unpriced_inventory_receipts.edit')->name('edit');
            Route::put('/{unpricedInventoryReceipt}', 'update')->middleware('can:inventory.unpriced_inventory_receipts.edit')->name('update');
            Route::delete('/{unpricedInventoryReceipt}', 'destroy')->middleware('can:inventory.unpriced_inventory_receipts.delete')->name('destroy');
        });

        Route::prefix('opening-stock-pricings')->name('opening-stock-pricings.')->controller(OpeningStockPricingController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.opening_stock_pricings.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.opening_stock_pricings.view')->name('data');
            Route::get('/create', 'create')->middleware('can:inventory.opening_stock_pricings.create')->name('create');
            Route::get('/remaining-lines', 'remainingLines')->middleware('can:inventory.opening_stock_pricings.view')->name('remaining-lines');
            Route::post('/', 'store')->name('store');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:inventory.opening_stock_pricings.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{openingStockPricing}/restore', 'restore')->middleware('can:inventory.opening_stock_pricings.restore')->name('restore');
            Route::get('/{openingStockPricing}/clone', 'clone')->middleware('can:inventory.opening_stock_pricings.clone')->name('clone');
            Route::get('/{openingStockPricing}', 'show')->withTrashed()->middleware('can:inventory.opening_stock_pricings.view')->name('show');
            Route::get('/{openingStockPricing}/edit', 'edit')->middleware('can:inventory.opening_stock_pricings.edit')->name('edit');
            Route::put('/{openingStockPricing}', 'update')->middleware('can:inventory.opening_stock_pricings.edit')->name('update');
            Route::delete('/{openingStockPricing}', 'destroy')->middleware('can:inventory.opening_stock_pricings.delete')->name('destroy');
        });

        Route::prefix('opening-stocks')->name('opening-stocks.')->controller(OpeningStockController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.opening_stocks.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.opening_stocks.view')->name('data');
            Route::get('/create', 'create')->middleware('can:inventory.opening_stocks.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:inventory.opening_stocks.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{openingStock}/approve', 'approve')->middleware('can:inventory.opening_stocks.approve')->name('approve');
            Route::patch('/{openingStock}/restore', 'restore')->middleware('can:inventory.opening_stocks.restore')->name('restore');
            Route::get('/{openingStock}/clone', 'clone')->middleware('can:inventory.opening_stocks.clone')->name('clone');
            Route::get('/{openingStock}/print', 'print')->middleware('can:inventory.opening_stocks.view')->name('print');
            Route::get('/{openingStock}', 'show')->withTrashed()->middleware('can:inventory.opening_stocks.view')->name('show');
            Route::get('/{openingStock}/edit', 'edit')->middleware('can:inventory.opening_stocks.edit')->name('edit');
            Route::put('/{openingStock}', 'update')->middleware('can:inventory.opening_stocks.edit')->name('update');
            Route::delete('/{openingStock}', 'destroy')->middleware('can:inventory.opening_stocks.delete')->name('destroy');
        });
    });
