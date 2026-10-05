<?php

use App\Http\Middleware\IdempotentDocumentSubmission;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryCostPolicyController;
use Modules\Inventory\Http\Controllers\InventoryCostPolicyTransitionController;
use Modules\Inventory\Http\Controllers\InventoryDocumentController;
use Modules\Inventory\Http\Controllers\InventoryMovementCorrectionController;
use Modules\Inventory\Http\Controllers\InventoryPeriodicCostCloseController;
use Modules\Inventory\Http\Controllers\InventoryReceiptCostCompletionController;
use Modules\Inventory\Http\Controllers\InventoryReportController;
use Modules\Inventory\Http\Controllers\InventoryStandardCostController;
use Modules\Inventory\Http\Controllers\OpeningStockController;
use Modules\Inventory\Http\Controllers\OpeningStockCostCorrectionController;
use Modules\Inventory\Http\Controllers\OpeningStockPricingController;
use Modules\Inventory\Http\Controllers\OpeningStockQuantityCorrectionController;
use Modules\Inventory\Http\Controllers\SalesIssueController;
use Modules\Inventory\Http\Controllers\StockCountController;
use Modules\Inventory\Http\Controllers\UnpricedInventoryReceiptController;

Route::middleware('auth')
    ->prefix('admin/inventory')
    ->as('admin.inventory.')
    ->group(function (): void {
        Route::prefix('opening-stock-quantity-corrections')->name('opening-stock-quantity-corrections.')
            ->controller(OpeningStockQuantityCorrectionController::class)->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/{openingStock}/select2/layers/{line}', 'layers')->name('select2.layers');
                Route::post('/{openingStock}', 'prepare')->middleware('can:inventory.opening_stock_quantity_corrections.prepare')->name('prepare');
                Route::post('/{openingStock}/{quantityCorrection}/approve', 'approve')->middleware('can:inventory.opening_stock_quantity_corrections.approve')->name('approve');
                Route::post('/{openingStock}/{quantityCorrection}/reject', 'reject')->middleware('can:inventory.opening_stock_quantity_corrections.approve')->name('reject');
                Route::get('/{openingStock}', 'show')->name('show');
            });
        Route::prefix('opening-stock-cost-corrections')->name('opening-stock-cost-corrections.')
            ->controller(OpeningStockCostCorrectionController::class)->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/select2/accounts', 'accounts')->name('select2.accounts');
                Route::post('/{openingStock}', 'prepare')->middleware('can:inventory.opening_stock_cost_corrections.prepare')->name('prepare');
                Route::post('/{openingStock}/{correction}/approve', 'approve')->middleware('can:inventory.opening_stock_cost_corrections.approve')->name('approve');
                Route::post('/{openingStock}/{correction}/reject', 'reject')->middleware('can:inventory.opening_stock_cost_corrections.approve')->name('reject');
                Route::get('/{openingStock}', 'show')->name('show');
            });
        Route::prefix('standard-costs')->name('standard-costs.')->controller(InventoryStandardCostController::class)
            ->middleware('can:inventory.cost_policies.standard.view')->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/select2/{kind}', 'lookup')->whereIn('kind', ['products', 'runs', 'variance-accounts', 'clearing-accounts'])->name('lookup');
                Route::post('/versions', 'prepare')->middleware('can:inventory.cost_policies.standard.prepare')->name('prepare');
                Route::post('/settlements', 'settle')->middleware('can:inventory.cost_policies.standard.settle')->name('settle');
                Route::get('/{kind}/{uuid}/output/{format}', 'output')->whereIn('kind', ['versions', 'settlements'])->whereUuid('uuid')->whereIn('format', ['xlsx', 'csv', 'pdf'])->name('output');
                Route::post('/{kind}/{uuid}/{decision}', 'decision')->whereIn('kind', ['versions', 'settlements'])->whereUuid('uuid')->whereIn('decision', ['approve', 'reject'])
                    ->middleware('can:inventory.cost_policies.standard.approve')->name('decision');
                Route::get('/{kind}/{uuid}', 'show')->whereIn('kind', ['versions', 'settlements'])->whereUuid('uuid')->name('show');
            });
        Route::prefix('periodic-cost-closes')->name('periodic-cost-closes.')->controller(InventoryPeriodicCostCloseController::class)
            ->middleware('can:inventory.cost_policies.periodic.view')->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/select2/accounts', 'accounts')->name('select2.accounts');
                Route::get('/select2/branches', [InventoryCostPolicyController::class, 'branches'])->name('select2.branches');
                Route::get('/select2/stores', [InventoryCostPolicyController::class, 'stores'])->name('select2.stores');
                Route::post('/', 'prepare')->middleware('can:inventory.cost_policies.periodic.prepare')->name('prepare');
                Route::post('/{close}/approve', 'approve')->middleware('can:inventory.cost_policies.periodic.approve')->name('approve');
                Route::post('/{close}/reject', 'reject')->middleware('can:inventory.cost_policies.periodic.approve')->name('reject');
                Route::get('/{close}/export/{format}', 'export')->middleware('can:inventory.cost_policies.periodic.export')->whereIn('format', ['xlsx', 'csv'])->name('export');
                Route::get('/{close}/print', 'print')->middleware('can:inventory.cost_policies.periodic.print')->name('print');
                Route::get('/{close}', 'show')->name('show');
            });
        Route::prefix('cost-completions')->name('cost-completions.')->controller(InventoryReceiptCostCompletionController::class)
            ->middleware('can:inventory.documents.view')
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/select2/accounts', 'accounts')->name('select2.accounts');
                Route::get('/{inventoryDocument}/receipt-cost-template', 'template')->middleware('can:inventory.documents.propose_receipt_cost')->name('receipt-cost-template');
                Route::post('/{inventoryDocument}/price-receipt', 'prepare')->middleware('can:inventory.documents.propose_receipt_cost')->name('price-receipt');
                Route::get('/{inventoryDocument}/proposals/{proposal}/source', 'source')->middleware('can:inventory.documents.approve_receipt_cost')->name('receipt-cost-source');
                Route::post('/{inventoryDocument}/proposals/{proposal}/approve', 'approve')->middleware('can:inventory.documents.approve_receipt_cost')->name('receipt-cost-approve');
                Route::post('/{inventoryDocument}/proposals/{proposal}/reject', 'reject')->middleware('can:inventory.documents.approve_receipt_cost')->name('receipt-cost-reject');
                Route::get('/{inventoryDocument}', 'show')->name('show');
            });
        Route::prefix('cost-policies')->name('cost-policies.')->controller(InventoryCostPolicyController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.cost_policies.view')->name('index');
            Route::get('/select2/branches', 'branches')->middleware('can:inventory.cost_policies.view')->name('select2.branches');
            Route::get('/select2/stores', 'stores')->middleware('can:inventory.cost_policies.view')->name('select2.stores');
            Route::post('/', 'store')->middleware('can:inventory.cost_policies.manage')->name('store');
            Route::post('/transitions', [InventoryCostPolicyTransitionController::class, 'prepare'])
                ->middleware('can:inventory.cost_policies.transition.prepare')->name('transitions.prepare');
            Route::post('/transitions/{transition}/approve', [InventoryCostPolicyTransitionController::class, 'approve'])
                ->middleware('can:inventory.cost_policies.transition.approve')->name('transitions.approve');
            Route::post('/transitions/{transition}/activate', [InventoryCostPolicyTransitionController::class, 'activate'])
                ->middleware('can:inventory.cost_policies.transition.activate')->name('transitions.activate');
            Route::post('/transitions/{transition}/cancel', [InventoryCostPolicyTransitionController::class, 'cancel'])
                ->middleware('can:inventory.cost_policies.transition.cancel')->name('transitions.cancel');
        });
        Route::prefix('documents')->name('documents.')->controller(InventoryDocumentController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.documents.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.documents.view')->name('data');
            Route::get('/select2/stores', 'stores')->middleware('can:inventory.documents.view')->name('select2.stores');
            Route::get('/select2/sales-issue-stores', 'stores')->middleware(['can:inventory.documents.create', 'can:inventory.documents.issue'])->name('select2.sales-issue-stores');
            Route::get('/select2/products', 'products')->middleware('can:inventory.documents.view')->name('select2.products');
            Route::get('/select2/receipt-layers', 'receiptLayers')->name('select2.receipt-layers');
            Route::get('/select2/production-run-batches', 'productionRunBatches')->middleware('can:inventory.documents.create')->name('select2.production-run-batches');
            Route::get('/select2/production-material-requests', 'productionMaterialIssueRequests')->middleware('can:inventory.documents.view')->name('select2.production-material-requests');
            Route::get('/production-material-issue', 'productionMaterialIssue')->middleware('can:inventory.documents.view')->name('production-material-issue.create');
            Route::get('/select2/sales-issue-orders', [SalesIssueController::class, 'orders'])->middleware(['can:inventory.documents.create', 'can:inventory.documents.issue'])->name('select2.sales-issue-orders');
            Route::get('/sales-issue-orders/{salesIssueOrder}/details', [SalesIssueController::class, 'details'])->middleware(['can:inventory.documents.create', 'can:inventory.documents.issue'])->name('sales-issue-orders.details');
            Route::get('/sales-issue/create', [SalesIssueController::class, 'create'])->middleware(['can:inventory.documents.create', 'can:inventory.documents.issue'])->name('sales-issue.create');
            Route::post('/sales-issue', [SalesIssueController::class, 'store'])->middleware(['can:inventory.documents.create', 'can:inventory.documents.issue', IdempotentDocumentSubmission::class])->name('sales-issue.store');
            Route::get('/production-run-batches/{publicId}/details', 'productionRunBatchDetails')->middleware('can:inventory.documents.create')->name('production-batches.details');
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
            Route::get('/{inventoryDocument}/reversal-preview', 'reversalPreview')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.reverse'])->name('reversal-preview');
            Route::get('/{inventoryDocument}/corrections', [InventoryMovementCorrectionController::class, 'index'])
                ->middleware('can:inventory.documents.view')->name('corrections.index');
            Route::post('/{inventoryDocument}/corrections', [InventoryMovementCorrectionController::class, 'store'])
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.correct_prepare'])->name('corrections.store');
            Route::post('/{inventoryDocument}/corrections/{correction}/approve', [InventoryMovementCorrectionController::class, 'approve'])
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.correct_approve'])->name('corrections.approve');
            Route::post('/{inventoryDocument}/corrections/{correction}/reject', [InventoryMovementCorrectionController::class, 'reject'])
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.correct_approve'])->name('corrections.reject');
            Route::post('/{inventoryDocument}/reverse', 'reverse')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.reverse'])->name('reverse');
            Route::post('/{inventoryDocument}/price-receipt', 'priceReceipt')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.propose_receipt_cost'])
                ->name('price-receipt');
            Route::get('/{inventoryDocument}/receipt-cost-template', 'receiptCostTemplate')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.propose_receipt_cost'])
                ->name('receipt-cost-template');
            Route::get('/{inventoryDocument}/receipt-cost-proposals/{proposal}/source', 'receiptCostSource')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.approve_receipt_cost'])
                ->name('receipt-cost-source');
            Route::post('/{inventoryDocument}/receipt-cost-proposals/{proposal}/approve', 'approveReceiptCost')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.approve_receipt_cost'])
                ->name('receipt-cost-approve');
            Route::post('/{inventoryDocument}/receipt-cost-proposals/{proposal}/reject', 'rejectReceiptCost')
                ->middleware(['can:inventory.documents.view', 'can:inventory.documents.approve_receipt_cost'])
                ->name('receipt-cost-reject');
            Route::get('/{inventoryDocument}', 'show')->middleware('can:inventory.documents.view')->name('show');
        });
        Route::get('/reports/stock-card', [InventoryReportController::class, 'stockCard'])
            ->middleware('can:inventory.reports.operations.view')->name('reports.stock-card');
        Route::get('/reports/stock-card/export.xlsx', [InventoryReportController::class, 'stockCardExport'])
            ->middleware(['can:inventory.reports.operations.view', 'can:inventory.reports.operations.export'])->name('reports.stock-card.export');
        Route::get('/reports/stock-card/print', [InventoryReportController::class, 'stockCardPrint'])
            ->middleware(['can:inventory.reports.operations.view', 'can:inventory.reports.operations.print'])->name('reports.stock-card.print');
        Route::get('/reports/operations', [InventoryReportController::class, 'index'])
            ->middleware('can:inventory.reports.operations.view')
            ->name('reports.index');
        Route::get('/reports/operations/export.xlsx', [InventoryReportController::class, 'export'])
            ->middleware(['can:inventory.reports.operations.view', 'can:inventory.reports.operations.export'])
            ->name('reports.export');
        Route::get('/reports/operations/export.csv', [InventoryReportController::class, 'exportCsv'])
            ->middleware(['can:inventory.reports.operations.view', 'can:inventory.reports.operations.export'])
            ->name('reports.export.csv');
        Route::get('/reports/operations/print', [InventoryReportController::class, 'print'])
            ->middleware(['can:inventory.reports.operations.view', 'can:inventory.reports.operations.print'])
            ->name('reports.print');
        Route::get('/reports/valuation', [InventoryReportController::class, 'valuation'])
            ->middleware('can:inventory.reports.valuation.view')
            ->name('reports.valuation');
        foreach (['excel', 'csv', 'pdf'] as $format) {
            Route::get("/reports/valuation/export/{$format}", [InventoryReportController::class, 'valuationExport'])
                ->defaults('valuation_export_format', $format)
                ->middleware(['can:inventory.reports.valuation.view', 'can:inventory.reports.valuation.export'])
                ->name("reports.valuation.export.{$format}");
        }
        Route::get('/stock-balances', [InventoryReportController::class, 'stockBalances'])
            ->middleware('can:inventory.reports.stock_balances.view')
            ->name('stock-balances.index');
        Route::get('/stock-balances/export.xlsx', [InventoryReportController::class, 'stockBalancesExport'])
            ->middleware(['can:inventory.reports.stock_balances.view', 'can:inventory.reports.stock_balances.export'])
            ->name('stock-balances.export');
        Route::get('/stock-balances/print', [InventoryReportController::class, 'stockBalancesPrint'])
            ->middleware(['can:inventory.reports.stock_balances.view', 'can:inventory.reports.stock_balances.print'])
            ->name('stock-balances.print');
        Route::get('/sales-valuation', [InventoryReportController::class, 'salesValuation'])
            ->middleware('can:inventory.reports.sales_valuation.view')
            ->name('sales-valuation');
        Route::get('/sales-valuation/export/{format?}', [InventoryReportController::class, 'salesValuationExport'])
            ->middleware(['can:inventory.reports.sales_valuation.view', 'can:inventory.reports.sales_valuation.export'])
            ->name('sales-valuation.export');
        Route::get('/sales-valuation/print', [InventoryReportController::class, 'salesValuationPrint'])
            ->middleware(['can:inventory.reports.sales_valuation.view', 'can:inventory.reports.sales_valuation.print'])
            ->name('sales-valuation.print');
        Route::prefix('stock-counts')->name('stock-counts.')->controller(StockCountController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:inventory.stock_counts.view')->name('index');
            Route::get('/data', 'data')->middleware('can:inventory.stock_counts.view')->name('data');
            Route::get('/create', 'create')->middleware('can:inventory.stock_counts.create')->name('create');
            Route::get('/select2/products', 'products')->name('select2.products');
            Route::get('/products/{product}/details', 'productDetails')->name('products.details');
            Route::get('/balance', 'balance')->name('balance');
            Route::post('/', 'store')->name('store');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:inventory.stock_counts.document_number_settings.update')->name('document-number-settings.update');
            Route::get('/{stockCount}/print', 'print')->middleware('can:inventory.stock_counts.print')->name('print');
            Route::get('/{stockCount}/export.xlsx', 'export')->middleware('can:inventory.stock_counts.export')->name('export');
            Route::post('/{stockCount}/approve', 'approve')->middleware('can:inventory.stock_counts.approve')->name('approve');
            Route::patch('/{stockCount}/restore', 'restore')->withTrashed()->middleware('can:inventory.stock_counts.restore')->name('restore');
            Route::get('/{stockCount}/clone', 'clone')->middleware('can:inventory.stock_counts.clone')->name('clone');
            Route::get('/{stockCount}', 'show')->withTrashed()->middleware('can:inventory.stock_counts.view')->name('show');
            Route::get('/{stockCount}/edit', 'edit')->middleware('can:inventory.stock_counts.edit')->name('edit');
            Route::put('/{stockCount}', 'update')->middleware('can:inventory.stock_counts.edit')->name('update');
            Route::delete('/{stockCount}', 'destroy')->middleware('can:inventory.stock_counts.delete')->name('destroy');
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
            Route::get('/import-estimate', 'importEstimateForm')->middleware(['can:inventory.opening_stock_pricings.import_estimate', 'can:inventory.opening_stock_pricings.view'])->name('import-estimate.form');
            Route::get('/import-estimate/template', 'estimateTemplate')->middleware(['can:inventory.opening_stock_pricings.import_estimate', 'can:inventory.opening_stock_pricings.view'])->name('import-estimate.template');
            Route::post('/import-estimate', 'importEstimate')->middleware(['can:inventory.opening_stock_pricings.import_estimate', 'can:inventory.opening_stock_pricings.view'])->name('import-estimate.store');
            Route::get('/remaining-lines', 'remainingLines')->name('remaining-lines');
            Route::post('/', 'store')->name('store');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:inventory.opening_stock_pricings.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{openingStockPricing}/approve-estimate', 'approveEstimate')->middleware('can:inventory.opening_stock_pricings.approve_estimate')->name('approve-estimate');
            Route::patch('/{openingStockPricing}/restore', 'restore')->middleware('can:inventory.opening_stock_pricings.restore')->name('restore');
            Route::get('/{openingStockPricing}/clone', 'clone')->middleware('can:inventory.opening_stock_pricings.clone')->name('clone');
            Route::get('/{openingStockPricing}/source-file', 'sourceFile')->middleware('can:inventory.opening_stock_pricings.view')->name('source-file');
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
