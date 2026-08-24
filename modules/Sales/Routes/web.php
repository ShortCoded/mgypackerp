<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CustomerController;
use Modules\Sales\Http\Controllers\CustomerDataReportController;
use Modules\Sales\Http\Controllers\ProjectStructureController;
use Modules\Sales\Http\Controllers\ProjectStructureModelController;
use Modules\Sales\Http\Controllers\QuotationController;
use Modules\Sales\Http\Controllers\SalesCycleController;
use Modules\Sales\Http\Controllers\SalesCycleReportController;
use Modules\Sales\Services\SalesSelect2Service;

Route::middleware('auth')
    ->prefix('admin/sales')
    ->as('admin.sales.')
    ->group(function (): void {
        Route::controller(SalesCycleController::class)->group(function (): void {
            Route::get('/sales-orders', 'orders')->middleware('can:sales_orders.view')->name('sales-orders.index');
            Route::get('/sales-orders/create', 'createOrder')->middleware('can:sales_orders.create')->name('sales-orders.create');
            Route::post('/sales-orders', 'storeOrder')->name('sales-orders.store');
            Route::get('/sales-orders/{salesOrder}/edit', 'editOrder')->middleware('can:sales_orders.edit')->name('sales-orders.edit');
            Route::put('/sales-orders/{salesOrder}', 'updateOrder')->middleware('can:sales_orders.edit')->name('sales-orders.update');
            Route::get('/sales-orders/{salesOrder}', 'showOrder')->middleware('can:sales_orders.view')->name('sales-orders.show');
            Route::get('/sales-orders/{salesOrder}/print', 'printOrder')->middleware('can:sales_orders.print')->name('sales-orders.print');
            Route::post('/sales-orders/{salesOrder}/submit', 'submitOrder')->middleware('can:sales_orders.edit')->name('sales-orders.submit');
            Route::post('/sales-orders/{salesOrder}/approve', 'approveOrder')->middleware('can:sales_orders.approve')->name('sales-orders.approve');
            Route::post('/sales-orders/{salesOrder}/reject', 'rejectOrder')->middleware('can:sales_orders.reject')->name('sales-orders.reject');
            Route::post('/sales-orders/{salesOrder}/credit-override', 'overrideOrder')->middleware('can:sales_orders.credit_override')->name('sales-orders.credit-override');
            Route::post('/sales-orders/{salesOrder}/reopen', 'reopenOrder')->middleware('can:sales_orders.reopen')->name('sales-orders.reopen');
            Route::post('/sales-orders/{salesOrder}/cancel', 'cancelOrder')->middleware('can:sales_orders.cancel')->name('sales-orders.cancel');
            Route::post('/sales-orders/{salesOrder}/deliveries', 'deliverOrder')->middleware('can:sales_orders.deliver')->name('sales-orders.deliveries.store');
            Route::post('/sales-orders/{salesOrder}/reservations', 'reserveOrder')->name('sales-orders.reservations.store');
            Route::post('/sales-orders/{salesOrder}/reservations/release', 'releaseReservation')->middleware('can:sales_orders.reserve')->name('sales-orders.reservations.release');
            Route::post('/sales-orders/{salesOrder}/production-requests', 'produceOrder')->name('sales-orders.production-requests.store');
            Route::post('/sales-orders/{salesOrder}/invoices', 'invoiceOrder')->middleware('can:sales_orders.invoice')->name('sales-orders.invoices.store');

            Route::get('/sales-invoices', 'invoices')->middleware('can:customer_invoices.view')->name('sales-invoices.index');
            Route::get('/sales-invoices/{customerInvoice}', 'showInvoice')->middleware('can:customer_invoices.view')->name('sales-invoices.show');
            Route::get('/sales-invoices/{customerInvoice}/edit', 'editInvoice')->middleware('can:customer_invoices.edit')->name('sales-invoices.edit');
            Route::put('/sales-invoices/{customerInvoice}', 'updateInvoice')->middleware('can:customer_invoices.edit')->name('sales-invoices.update');
            Route::get('/sales-invoices/{customerInvoice}/print', 'printInvoice')->middleware('can:customer_invoices.print')->name('sales-invoices.print');
            Route::post('/sales-invoices/{customerInvoice}/post', 'postInvoice')->middleware('can:customer_invoices.post')->name('sales-invoices.post');
            Route::post('/sales-invoices/{customerInvoice}/reopen', 'reopenInvoice')->middleware('can:customer_invoices.reopen')->name('sales-invoices.reopen');
            Route::get('/sales-invoices/{customerInvoice}/payment-schedule/print', 'printPaymentSchedule')->middleware('can:customer_invoices.print')->name('sales-invoices.payment-schedule.print');

            Route::get('/customer-invoices', 'invoices')->middleware('can:customer_invoices.view')->name('customer-invoices.index');
            Route::get('/customer-invoices/{customerInvoice}', 'showInvoice')->middleware('can:customer_invoices.view')->name('customer-invoices.show');

            Route::get('/customer-receipts', 'receipts')->middleware('can:customer_receipts.view')->name('customer-receipts.index');
            Route::get('/customer-receipts/create', 'createReceipt')->middleware('can:customer_receipts.create')->name('customer-receipts.create');
            Route::post('/customer-receipts', 'storeReceipt')->name('customer-receipts.store');
            Route::get('/customer-receipts/{customerReceipt}', 'showReceipt')->middleware('can:customer_receipts.view')->name('customer-receipts.show');
            Route::get('/customer-receipts/{customerReceipt}/print', 'printReceipt')->middleware('can:customer_receipts.print')->name('customer-receipts.print');

            Route::get('/sales-returns', 'returns')->middleware('can:sales_returns.view')->name('sales-returns.index');
            Route::post('/sales-invoices/{customerInvoice}/returns', 'storeReturn')->name('sales-returns.store');
            Route::get('/sales-returns/{salesReturn}', 'showReturn')->middleware('can:sales_returns.view')->name('sales-returns.show');
            Route::get('/sales-returns/{salesReturn}/print', 'printReturn')->middleware('can:sales_returns.print')->name('sales-returns.print');
            Route::get('/sales-returns/{salesReturn}/quality-disposition/print', 'printQualityDisposition')->middleware('can:sales_returns.print')->name('sales-returns.quality-disposition.print');
            Route::post('/sales-returns/{salesReturn}/authorize', 'authorizeReturn')->middleware('can:sales_returns.authorize')->name('sales-returns.authorize');
            Route::post('/sales-returns/{salesReturn}/receive', 'receiveReturn')->middleware('can:sales_returns.receive')->name('sales-returns.receive');
            Route::post('/sales-returns/{salesReturn}/inspect', 'inspectReturn')->middleware('can:sales_returns.inspect')->name('sales-returns.inspect');
            Route::post('/sales-returns/{salesReturn}/close', 'closeReturn')->middleware('can:sales_returns.close')->name('sales-returns.close');

            Route::get('/delivery-notes', 'deliveries')->middleware('can:sales_deliveries.view')->name('delivery-notes.index');
            Route::get('/delivery-notes/{inventoryDocument}', 'showDelivery')->middleware('can:sales_deliveries.view')->name('delivery-notes.show');
            Route::get('/delivery-notes/{inventoryDocument}/print', 'printDelivery')->middleware('can:sales_deliveries.print')->name('delivery-notes.print');
            Route::get('/sales-deliveries/{inventoryDocument}', 'showDelivery')->middleware('can:sales_deliveries.view')->name('sales-deliveries.show');
            Route::get('/production-requests/{productionOrder}', 'showProduction')->middleware('can:sales_orders.production')->name('production-requests.show');
            Route::get('/production-requests/{productionOrder}/print', 'printProduction')->middleware('can:sales_orders.production')->name('production-requests.print');
            Route::post('/production-requests/{productionOrder}/complete', 'completeProduction')->middleware('can:sales_orders.production')->name('production-requests.complete');
        });

        Route::get('/select2/customer-groups', function (Request $request, SalesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('customers.view')
                || (bool) $request->user()?->can('customers.create')
                || (bool) $request->user()?->can('customers.edit'),
                403
            );

            return response()->json($select2->customerGroups($request));
        })->name('select2.customer-groups');
        Route::get('/select2/customers', [QuotationController::class, 'customers'])
            ->name('select2.customers');
        Route::get('/select2/quotation-products', [QuotationController::class, 'products'])
            ->name('select2.quotation-products');
        Route::get('/select2/project-structures', [ProjectStructureController::class, 'select2'])
            ->name('select2.project-structures');

        Route::prefix('customers')->name('customers.')->controller(CustomerController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:customers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:customers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:customers.create')->name('create');
            Route::post('/account-groups', 'storeAccountGroup')->middleware('can:accounts.create')->name('account-groups.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:customers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:customers.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{customer}/restore', 'restore')->middleware('can:customers.restore')->name('restore');
            Route::get('/{customer}/clone', 'clone')->middleware('can:customers.clone')->name('clone');
            Route::get('/{customer}', 'show')->withTrashed()->middleware('can:customers.view')->name('show');
            Route::get('/{customer}/edit', 'edit')->middleware('can:customers.edit')->name('edit');
            Route::put('/{customer}', 'update')->middleware('can:customers.edit')->name('update');
            Route::delete('/{customer}', 'destroy')->middleware('can:customers.delete')->name('destroy');
        });

        Route::prefix('project-structures')->name('project-structures.')->controller(ProjectStructureController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:project_structures.view')->name('index');
            Route::get('/data', 'data')->middleware('can:project_structures.view')->name('data');
            Route::get('/select2', 'select2')->name('select2');
            Route::get('/tree', 'tree')->middleware('can:project_structures.tree.view')->name('tree');
            Route::get('/create', 'create')->middleware('can:project_structures.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:project_structures.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:project_structures.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{projectStructure}/restore', 'restore')->middleware('can:project_structures.restore')->name('restore');
            Route::get('/{projectStructure}/clone', 'clone')->middleware('can:project_structures.clone')->name('clone');
            Route::get('/{projectStructure}', 'show')->middleware('can:project_structures.view')->name('show');
            Route::get('/{projectStructure}/edit', 'edit')->middleware('can:project_structures.edit')->name('edit');
            Route::put('/{projectStructure}', 'update')->middleware('can:project_structures.edit')->name('update');
            Route::delete('/{projectStructure}', 'destroy')->middleware('can:project_structures.delete')->name('destroy');
        });

        Route::prefix('project-structure-models')->name('project-structure-models.')->controller(ProjectStructureModelController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:project_structure_models.view')->name('index');
            Route::get('/data', 'data')->middleware('can:project_structure_models.view')->name('data');
            Route::get('/create', 'create')->middleware('can:project_structure_models.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:project_structure_models.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:project_structure_models.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{projectStructureModel}/restore', 'restore')->middleware('can:project_structure_models.restore')->name('restore');
            Route::get('/{projectStructureModel}/clone', 'clone')->middleware('can:project_structure_models.clone')->name('clone');
            Route::get('/{projectStructureModel}', 'show')->middleware('can:project_structure_models.view')->name('show');
            Route::get('/{projectStructureModel}/edit', 'edit')->middleware('can:project_structure_models.edit')->name('edit');
            Route::put('/{projectStructureModel}', 'update')->middleware('can:project_structure_models.edit')->name('update');
            Route::delete('/{projectStructureModel}', 'destroy')->middleware('can:project_structure_models.delete')->name('destroy');
        });

        Route::prefix('quotations')->name('quotations.')->controller(QuotationController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:quotations.view')->name('index');
            Route::get('/data', 'data')->middleware('can:quotations.view')->name('data');
            Route::get('/create', 'create')->middleware('can:quotations.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:quotations.delete')->name('bulk-delete');
            Route::patch('/bulk-restore', 'bulkRestore')->middleware('can:quotations.restore')->name('bulk-restore');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:quotations.document_number_settings.update')->name('document-number-settings.update');
            Route::delete('/attachments/{attachment:public_uuid}', 'destroyAttachment')->middleware('can:quotations.attachments.manage')->name('attachments.destroy');
            Route::patch('/{quotation}/restore', 'restore')->middleware('can:quotations.restore')->name('restore');
            Route::get('/{quotation}/clone', 'clone')->middleware('can:quotations.clone')->name('clone');
            Route::post('/{quotation}/revisions', 'createRevision')->middleware('can:quotations.revisions.create')->name('revisions.create');
            Route::get('/{quotation}/revisions/{revision:revision_code}/print', 'printRevision')->middleware('can:quotations.print')->name('revisions.print');
            Route::get('/{quotation}/revisions/{revision:revision_code}', 'showRevision')->middleware('can:quotations.revisions.view')->name('revisions.show');
            Route::post('/{quotation}/mark-sent', 'markSent')->middleware('can:quotations.mark_sent')->name('mark-sent');
            Route::post('/{quotation}/accept', 'accept')->middleware('can:quotations.accept')->name('accept');
            Route::post('/{quotation}/reject', 'reject')->middleware('can:quotations.reject')->name('reject');
            Route::post('/{quotation}/cancel', 'cancel')->middleware('can:quotations.cancel')->name('cancel');
            Route::post('/{quotation}/convert', 'convert')->middleware('can:sales_orders.create')->name('convert');
            Route::get('/{quotation}/print', 'print')->middleware('can:quotations.print')->name('print');
            Route::get('/{quotation}', 'show')->withTrashed()->middleware('can:quotations.view')->name('show');
            Route::get('/{quotation}/edit', 'edit')->middleware('can:quotations.edit')->name('edit');
            Route::put('/{quotation}', 'update')->middleware('can:quotations.edit')->name('update');
            Route::delete('/{quotation}', 'destroy')->middleware('can:quotations.delete')->name('destroy');
        });
    });

Route::middleware('auth')
    ->prefix('admin/reports/sales')
    ->as('admin.reports.sales.')
    ->controller(SalesCycleReportController::class)
    ->group(function (): void {
        Route::get('/sales-orders', 'index')->middleware('can:reports.sales.sales_orders.view')->name('sales-orders.index');
        Route::get('/sales-orders/print', 'print')->middleware('can:reports.sales.sales_orders.print')->name('sales-orders.print');
        Route::get('/sales-orders/export', 'export')->middleware('can:reports.sales.sales_orders.export')->name('sales-orders.export');
    });

Route::middleware('auth')
    ->prefix('admin/reports/customers')
    ->as('admin.reports.customers.')
    ->controller(CustomerDataReportController::class)
    ->group(function (): void {
        Route::get('/', 'index')->middleware('can:reports.customers.view')->name('index');
        Route::get('/data', 'data')->middleware('can:reports.customers.view')->name('data');
        Route::get('/filter-options/accounts', 'filterAccounts')->middleware('can:reports.customers.view')->name('filter-options.accounts');
        Route::get('/filter-options/account-groups', 'filterAccountGroups')->middleware('can:reports.customers.view')->name('filter-options.account-groups');
        Route::get('/export/excel', 'exportExcel')->middleware('can:reports.customers.export')->name('export.excel');
        Route::get('/export/csv', 'exportCsv')->middleware('can:reports.customers.export')->name('export.csv');
        Route::get('/export/pdf', 'exportPdf')->middleware('can:reports.customers.pdf')->name('export.pdf');
    });
