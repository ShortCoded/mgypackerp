<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Purchases\Http\Controllers\PurchaseInvoiceController;
use Modules\Purchases\Http\Controllers\PurchaseOrderController;
use Modules\Purchases\Http\Controllers\SupplierController;
use Modules\Purchases\Services\PurchasesSelect2Service;

Route::middleware('auth')
    ->prefix('admin/purchases')
    ->as('admin.purchases.')
    ->group(function (): void {
        Route::get('/select2/supplier-groups', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('suppliers.view')
                || (bool) $request->user()?->can('suppliers.create')
                || (bool) $request->user()?->can('suppliers.edit'),
                403
            );

            return response()->json($select2->supplierGroups($request));
        })->name('select2.supplier-groups');

        Route::get('/select2/suppliers', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit')
                || (bool) $request->user()?->can('suppliers.view'),
                403
            );

            return response()->json($select2->suppliers($request));
        })->name('select2.suppliers');

        Route::get('/select2/products', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit')
                || (bool) $request->user()?->can('products.view'),
                403
            );

            return response()->json($select2->products($request));
        })->name('select2.products');

        Route::get('/select2/currencies', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit')
                || (bool) $request->user()?->can('currencies.view'),
                403
            );

            return response()->json($select2->currencies($request));
        })->name('select2.currencies');

        Route::get('/select2/branch-stores', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit'),
                403
            );

            return response()->json($select2->branchStores($request));
        })->middleware('erp.expanded')->name('select2.branch-stores');

        Route::get('/select2/cashboxes', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('cashboxes.view'),
                403
            );

            return response()->json($select2->cashboxes($request));
        })->name('select2.cashboxes');

        Route::get('/select2/bank-accounts', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('bank_accounts.view'),
                403
            );

            return response()->json($select2->bankAccounts($request));
        })->name('select2.bank-accounts');

        Route::prefix('suppliers')->name('suppliers.')->controller(SupplierController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:suppliers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:suppliers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:suppliers.create')->name('create');
            Route::post('/account-groups', 'storeAccountGroup')->middleware('can:accounts.create')->name('account-groups.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:suppliers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:suppliers.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{supplier}/restore', 'restore')->middleware('can:suppliers.restore')->name('restore');
            Route::get('/{supplier}/clone', 'clone')->middleware('can:suppliers.clone')->name('clone');
            Route::get('/{supplier}', 'show')->withTrashed()->middleware('can:suppliers.view')->name('show');
            Route::get('/{supplier}/edit', 'edit')->middleware('can:suppliers.edit')->name('edit');
            Route::put('/{supplier}', 'update')->middleware('can:suppliers.edit')->name('update');
            Route::delete('/{supplier}', 'destroy')->middleware('can:suppliers.delete')->name('destroy');
        });

        Route::prefix('purchase-invoices')->name('purchase-invoices.')->controller(PurchaseInvoiceController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:purchase_invoices.view')->name('index');
            Route::get('/data', 'data')->middleware('can:purchase_invoices.view')->name('data');
            Route::get('/create', 'create')->middleware('can:purchase_invoices.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:purchase_invoices.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:purchase_invoices.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{purchaseInvoice}/approve', 'approve')->middleware('can:purchase_invoices.approve')->name('approve');
            Route::post('/{purchaseInvoice}/close', 'close')->middleware('can:purchase_invoices.close')->name('close');
            Route::post('/{purchaseInvoice}/cancel', 'cancel')->middleware('can:purchase_invoices.cancel')->name('cancel');
            Route::get('/{purchaseInvoice}/print', 'print')->middleware('can:purchase_invoices.print')->name('print');
            Route::patch('/{purchaseInvoice}/restore', 'restore')->middleware('can:purchase_invoices.restore')->name('restore');
            Route::get('/{purchaseInvoice}/clone', 'clone')->middleware('can:purchase_invoices.clone')->name('clone');
            Route::get('/{purchaseInvoice}', 'show')->withTrashed()->middleware('can:purchase_invoices.view')->name('show');
            Route::get('/{purchaseInvoice}/edit', 'edit')->middleware('can:purchase_invoices.edit')->name('edit');
            Route::put('/{purchaseInvoice}', 'update')->middleware('can:purchase_invoices.edit')->name('update');
            Route::delete('/{purchaseInvoice}', 'destroy')->middleware('can:purchase_invoices.delete')->name('destroy');
        });

        Route::prefix('purchase-orders')->name('purchase-orders.')->middleware('erp.expanded')->controller(PurchaseOrderController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:purchase_orders.view')->name('index');
            Route::get('/data', 'data')->middleware('can:purchase_orders.view')->name('data');
            Route::get('/create', 'create')->middleware('can:purchase_orders.create')->name('create');
            Route::post('/', 'store')->middleware('can:purchase_orders.create')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:purchase_orders.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:purchase_orders.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{purchaseOrder}/approve', 'approve')->middleware('can:purchase_orders.approve')->name('approve');
            Route::post('/{purchaseOrder}/close', 'close')->middleware('can:purchase_orders.close')->name('close');
            Route::post('/{purchaseOrder}/cancel', 'cancel')->middleware('can:purchase_orders.cancel')->name('cancel');
            Route::get('/{purchaseOrder}/print', 'print')->middleware('can:purchase_orders.print')->name('print');
            Route::patch('/{purchaseOrder}/restore', 'restore')->middleware('can:purchase_orders.restore')->name('restore');
            Route::get('/{purchaseOrder}', 'show')->withTrashed()->middleware('can:purchase_orders.view')->name('show');
            Route::get('/{purchaseOrder}/edit', 'edit')->middleware('can:purchase_orders.edit')->name('edit');
            Route::put('/{purchaseOrder}', 'update')->middleware('can:purchase_orders.edit')->name('update');
            Route::delete('/{purchaseOrder}', 'destroy')->middleware('can:purchase_orders.delete')->name('destroy');
        });
    });
