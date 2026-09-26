<?php

use App\Http\Middleware\IdempotentDocumentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Purchases\Http\Controllers\ProcurementWorkflowController;
use Modules\Purchases\Http\Controllers\PurchaseInvoiceController;
use Modules\Purchases\Http\Controllers\PurchaseOrderController;
use Modules\Purchases\Http\Controllers\SupplierController;
use Modules\Purchases\Http\Controllers\SupplierDataReportController;
use Modules\Purchases\Services\PurchasesSelect2Service;

Route::middleware('auth')
    ->prefix('admin/purchases')
    ->as('admin.purchases.')
    ->group(function (): void {
        foreach (['currency-rate' => ['purchase_orders.create', 'purchase_orders.edit'], 'employees' => ['purchases.purchase_requisitions.create', 'purchases.purchase_requisitions.edit'],
            'rfqs' => ['purchases.supplier_quotation_entry.create'], 'requisitions' => ['purchase_orders.create', 'purchase_orders.edit', 'purchases.request_for_quotations.create', 'purchases.supplier_quotation_entry.create'],
            'purchase-orders' => ['purchases.goods_receipt_inspection.create', 'purchases.supply_orders.create', 'purchases.supplier_quotation_entry.create', 'purchase_invoices.create', 'purchase_invoices.edit'],
            'supply-orders' => ['purchases.goods_receipt_inspection.create'],
            'inspections' => ['purchases.goods_receipt_notes.create'],
            'invoices' => ['purchases.purchase_returns.create', 'purchases.purchase_returns.edit', 'purchases.supply_orders.create'],
            'receipts' => ['purchases.purchase_returns.create', 'purchases.purchase_returns.edit', 'purchase_invoices.create', 'purchase_invoices.edit']] as $lookup => $permissions) {
            Route::get('/select2/'.$lookup, function (Request $request, PurchasesSelect2Service $select2) use ($lookup, $permissions) {
                abort_unless(collect($permissions)->contains(fn ($permission) => $request->user()?->can($permission)), 403);
                $method = match ($lookup) {
                    'purchase-orders' => 'purchaseOrders', 'supply-orders' => 'supplyOrders', 'currency-rate' => 'currencyRate', default => $lookup
                };

                return response()->json($select2->{$method}($request));
            })->name('select2.'.$lookup);
        }

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
                (bool) $request->user()?->can('purchases.goods_receipt_notes.view')
                || (bool) $request->user()?->can('purchases.purchase_returns.view')
                || (bool) $request->user()?->can('purchases.supplier_quotation_entry.view')
                || (bool) $request->user()?->can('purchases.purchase_requisitions.create')
                || (bool) $request->user()?->can('purchases.purchase_requisitions.edit')
                || (bool) $request->user()?->can('purchases.request_for_quotations.create')
                || (bool) $request->user()?->can('purchases.supplier_quotation_entry.create')
                || (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit')
                || (bool) $request->user()?->can('supplier_payments.create')
                || (bool) $request->user()?->can('purchases.supply_orders.create')
                || (bool) $request->user()?->can('suppliers.view')
                || (bool) $request->user()?->canAny(app(PermissionRegistryService::class)->reportViewPermissions('reports.purchases')),
                403
            );

            return response()->json($select2->suppliers($request));
        })->name('select2.suppliers');

        Route::get('/select2/supplier-payment-purchase-orders', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless((bool) $request->user()?->can('supplier_payments.create'), 403);

            return response()->json($select2->supplierPaymentPurchaseOrders($request));
        })->name('select2.supplier-payment-purchase-orders');

        Route::get('/select2/products', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchases.purchase_requisitions.create')
                || (bool) $request->user()?->can('purchases.purchase_requisitions.edit')
                || (bool) $request->user()?->can('purchases.request_for_quotations.create')
                || (bool) $request->user()?->can('purchases.supplier_quotation_entry.create')
                || (bool) $request->user()?->can('purchase_invoices.view')
                || (bool) $request->user()?->can('purchase_invoices.create')
                || (bool) $request->user()?->can('purchase_invoices.edit')
                || (bool) $request->user()?->can('purchases.supply_orders.create')
                || (bool) $request->user()?->can('purchase_orders.view')
                || (bool) $request->user()?->can('purchase_orders.create')
                || (bool) $request->user()?->can('purchase_orders.edit')
                || (bool) $request->user()?->can('products.view')
                || (bool) $request->user()?->canAny(app(PermissionRegistryService::class)->reportViewPermissions('reports.purchases')),
                403
            );

            return response()->json($select2->products($request));
        })->name('select2.products');

        Route::get('/select2/currencies', function (Request $request, PurchasesSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('purchases.purchase_requisitions.create')
                || (bool) $request->user()?->can('purchases.purchase_requisitions.edit')
                || (bool) $request->user()?->can('purchases.request_for_quotations.create')
                || (bool) $request->user()?->can('purchases.supplier_quotation_entry.create')
                || (bool) $request->user()?->can('purchase_invoices.view')
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
                (bool) $request->user()?->can('purchases.purchase_requisitions.create')
                || (bool) $request->user()?->can('purchases.purchase_requisitions.edit')
                || (bool) $request->user()?->can('purchase_orders.view')
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

        Route::prefix('purchase-invoices')->name('purchase-invoices.')->middleware('can:purchases.prices.view')->controller(PurchaseInvoiceController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:purchase_invoices.view')->name('index');
            Route::get('/data', 'data')->middleware('can:purchase_invoices.view')->name('data');
            Route::get('/create', 'create')->middleware('can:purchase_invoices.create')->name('create');
            Route::post('/', 'store')->middleware(IdempotentDocumentSubmission::class)->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:purchase_invoices.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:purchase_invoices.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{purchaseInvoice}/approve', 'approve')->middleware('can:purchase_invoices.approve')->name('approve');
            Route::post('/{purchaseInvoice}/close', 'close')->middleware('can:purchase_invoices.close')->name('close');
            Route::post('/{purchaseInvoice}/cancel', 'cancel')->middleware('can:purchase_invoices.cancel')->name('cancel');
            Route::post('/{purchaseInvoice}/reverse', 'reverse')->middleware('can:purchase_invoices.reverse')->name('reverse');
            Route::post('/{purchaseInvoice}/asset-treatment', 'updateAssetTreatment')->middleware('can:purchase_invoices.edit')->name('asset-treatment');
            Route::get('/{purchaseInvoice}/print', 'print')->middleware('can:purchase_invoices.print')->name('print');
            Route::patch('/{purchaseInvoice}/restore', 'restore')->middleware('can:purchase_invoices.restore')->name('restore');
            Route::get('/{purchaseInvoice}/clone', 'clone')->middleware('can:purchase_invoices.clone')->name('clone');
            Route::get('/{purchaseInvoice}', 'show')->withTrashed()->middleware('can:purchase_invoices.view')->name('show');
            Route::get('/{purchaseInvoice}/edit', 'edit')->middleware('can:purchase_invoices.edit')->name('edit');
            Route::put('/{purchaseInvoice}', 'update')->middleware('can:purchase_invoices.edit')->name('update');
            Route::delete('/{purchaseInvoice}', 'destroy')->middleware('can:purchase_invoices.delete')->name('destroy');
        });

        Route::prefix('purchase-orders')->name('purchase-orders.')->middleware(['erp.expanded', 'can:purchases.prices.view'])->controller(PurchaseOrderController::class)->group(function (): void {
            Route::post('/{purchaseOrder}/sent', 'markSent')->middleware('can:purchase_orders.send')->name('sent');
            Route::get('/', 'index')->middleware('can:purchase_orders.view')->name('index');
            Route::get('/data', 'data')->middleware('can:purchase_orders.view')->name('data');
            Route::get('/create', 'create')->middleware('can:purchase_orders.create')->name('create');
            Route::post('/', 'store')->middleware('can:purchase_orders.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:purchase_orders.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:purchase_orders.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{purchaseOrder}/submit', 'submit')->middleware('can:purchase_orders.submit')->name('submit');
            Route::post('/{purchaseOrder}/reject', 'reject')->middleware('can:purchase_orders.reject')->name('reject');
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

        Route::middleware('erp.expanded')->controller(ProcurementWorkflowController::class)->group(function (): void {
            Route::get('/procurement/data/{screen}', 'documentData')->name('procurement.data');
            Route::patch('/procurement/{screen}/{document}/restore', 'restoreDocument')->name('procurement.restore');
            Route::prefix('purchase-requisitions')->name('purchase-requisitions.')->group(function (): void {
                Route::get('/', 'requisitionsIndex')->middleware('can:purchases.purchase_requisitions.view')->name('index');
                Route::get('/create', 'createRequisition')->middleware('can:purchases.purchase_requisitions.create')->name('create');
                Route::post('/', 'storeRequisition')->middleware('can:purchases.purchase_requisitions.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/availability', 'requisitionAvailability')->middleware('can:purchases.purchase_requisitions.view')->name('availability');
                Route::get('/{purchaseRequisition}', 'showRequisition')->middleware('can:purchases.purchase_requisitions.view')->name('show');
                Route::post('/{purchaseRequisition}/submit', 'submitRequisition')->middleware('can:purchases.purchase_requisitions.submit')->name('submit');
                Route::post('/{purchaseRequisition}/approve', 'approveRequisition')->middleware('can:purchases.purchase_requisition_approvals.approve')->name('approve');
                Route::get('/{purchaseRequisition}/edit', 'editRequisition')->middleware('can:purchases.purchase_requisitions.edit')->name('edit');
                Route::put('/{purchaseRequisition}', 'updateRequisition')->middleware('can:purchases.purchase_requisitions.edit')->name('update');
                Route::delete('/{purchaseRequisition}', 'destroyRequisition')->middleware('can:purchases.purchase_requisitions.delete')->name('destroy');
                Route::post('/{purchaseRequisition}/reject', 'rejectRequisition')->middleware('can:purchases.purchase_requisition_approvals.reject')->name('reject');
                Route::post('/{purchaseRequisition}/cancel', 'cancelRequisition')->middleware('can:purchases.purchase_requisitions.cancel')->name('cancel');
                Route::post('/{purchaseRequisition}/close', 'closeRequisition')->middleware('can:purchases.purchase_requisitions.close')->name('close');
            });
            Route::get('purchase-requisition-lines', 'requisitionLinesIndex')->middleware('can:purchases.purchase_requisitions.view')->name('purchase-requisition-lines.index');
            Route::get('purchase-requisition-approvals', 'requisitionApprovalsIndex')->middleware('can:purchases.purchase_requisitions.view')->name('purchase-requisition-approvals.index');

            Route::prefix('request-for-quotations')->name('request-for-quotations.')->group(function (): void {
                Route::get('/{record}/edit', 'editRfq')->middleware('can:purchases.request_for_quotations.edit')->name('edit');
                Route::put('/{record}', 'updateRfq')->middleware('can:purchases.request_for_quotations.edit')->name('update');
                Route::delete('/{record}', 'destroyRfq')->middleware('can:purchases.request_for_quotations.delete')->name('destroy');
                Route::get('/create', 'chooseSource')->defaults('screen', 'request_for_quotations')->middleware('can:purchases.request_for_quotations.create')->name('choose-source');
                Route::get('/', 'rfqsIndex')->middleware('can:purchases.request_for_quotations.view')->name('index');
                Route::get('/create/{purchaseRequisition}', 'createRfq')->middleware('can:purchases.request_for_quotations.create')->name('create');
                Route::post('/from/{purchaseRequisition}', 'storeRfq')->middleware('can:purchases.request_for_quotations.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{requestForQuotation}', 'showRfq')->middleware('can:purchases.request_for_quotations.view')->name('show');
                Route::post('/{requestForQuotation}/issue', 'issueRfq')->middleware('can:purchases.request_for_quotations.approve')->name('issue');
            });

            Route::prefix('supplier-quotation-entry')->name('supplier-quotation-entry.')->group(function (): void {
                Route::get('/{record}/edit', 'editQuotation')->middleware('can:purchases.supplier_quotation_entry.edit')->name('edit');
                Route::put('/{record}', 'updateQuotation')->middleware('can:purchases.supplier_quotation_entry.edit')->name('update');
                Route::delete('/{record}', 'destroyQuotation')->middleware('can:purchases.supplier_quotation_entry.delete')->name('destroy');
                Route::get('/create', 'chooseQuotationSource')->middleware('can:purchases.supplier_quotation_entry.create')->name('choose-source');
                Route::get('/', 'quotationsIndex')->middleware('can:purchases.supplier_quotation_entry.view')->name('index');
                Route::get('/create/{sourceType}/{sourceDocument}', 'createQuotationFromSource')->middleware(['can:purchases.supplier_quotation_entry.create', 'can:purchases.prices.view'])->name('create-source');
                Route::post('/from/{sourceType}/{sourceDocument}', 'storeQuotationFromSource')->middleware(['can:purchases.supplier_quotation_entry.create', 'can:purchases.prices.view'])->middleware(IdempotentDocumentSubmission::class)->name('store-source');
                Route::get('/create/{requestForQuotation}', 'createQuotation')->middleware(['can:purchases.supplier_quotation_entry.create', 'can:purchases.prices.view'])->name('create');
                Route::post('/from/{requestForQuotation}', 'storeQuotation')->middleware(['can:purchases.supplier_quotation_entry.create', 'can:purchases.prices.view'])->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{supplierQuotation}', 'showQuotation')->middleware('can:purchases.supplier_quotation_entry.view')->name('show');
                Route::post('/{supplierQuotation}/submit', 'submitQuotation')->middleware('can:purchases.supplier_quotation_entry.edit')->name('submit');
            });
            Route::get('supplier-quotation-lines', 'quotationLinesIndex')->middleware('can:purchases.supplier_quotation_entry.view')->name('supplier-quotation-lines.index');
            Route::get('supplier-quotation-comparison', 'comparisonIndex')->middleware('can:purchases.supplier_quotation_comparison.view')->name('supplier-quotation-comparison.index');
            Route::get('supplier-quotation-comparison/{requestForQuotation}', 'compare')->middleware('can:purchases.supplier_quotation_comparison.view')->name('supplier-quotation-comparison.show');

            Route::prefix('supplier-selection')->name('supplier-selection.')->group(function (): void {
                Route::get('/', 'selectionsIndex')->middleware('can:purchases.supplier_selection.view')->name('index');
                Route::get('/create/{requestForQuotation}', 'createSelection')->middleware(['can:purchases.supplier_selection.create', 'can:purchases.prices.view'])->name('create');
                Route::post('/from/{requestForQuotation}', 'storeSelection')->middleware(['can:purchases.supplier_selection.create', 'can:purchases.prices.view'])->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{supplierSelection}', 'showSelection')->middleware('can:purchases.supplier_selection.view')->name('show');
                Route::post('/{supplierSelection}/approve', 'approveSelection')->middleware(['can:purchases.supplier_selection.approve', 'can:purchases.prices.view'])->name('approve');
            });

            Route::get('purchase-order-lines', 'inquiry')->defaults('procurement_screen', 'purchase_order_lines')->middleware('can:purchase_orders.view')->name('purchase-order-lines.index');
            Route::get('purchase-order-approvals', 'inquiry')->defaults('procurement_screen', 'purchase_order_approvals')->middleware('can:purchase_orders.view')->name('purchase-order-approvals.index');
            Route::prefix('purchase-order-change-requests')->name('purchase-order-change-requests.')->group(function (): void {
                Route::get('/', 'changeRequestsIndex')->middleware('can:purchases.purchase_order_change_requests.view')->name('index');
                Route::get('/create/{purchaseOrder}', 'createChangeRequest')->middleware(['can:purchases.purchase_order_change_requests.create', 'can:purchases.prices.view'])->name('create');
                Route::post('/from/{purchaseOrder}', 'storeChangeRequest')->middleware(['can:purchases.purchase_order_change_requests.create', 'can:purchases.prices.view'])->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{purchaseOrderChangeRequest}', 'showChangeRequest')->middleware('can:purchases.purchase_order_change_requests.view')->name('show');
                Route::post('/{purchaseOrderChangeRequest}/approve', 'approveChangeRequest')->middleware(['can:purchases.purchase_order_change_requests.approve', 'can:purchases.prices.view'])->name('approve');
            });

            Route::prefix('purchase-order-delivery-schedule')->name('purchase-order-delivery-schedule.')->group(function (): void {
                Route::get('/', 'deliverySchedulesIndex')->middleware('can:purchases.purchase_order_delivery_schedule.view')->name('index');
                Route::get('/create/{purchaseOrder}', 'createDeliverySchedule')->middleware('can:purchases.purchase_order_delivery_schedule.create')->name('create');
                Route::post('/from/{purchaseOrder}', 'storeDeliverySchedule')->middleware('can:purchases.purchase_order_delivery_schedule.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
            });

            Route::prefix('supply-orders')->name('supply-orders.')->group(function (): void {
                Route::get('/', 'supplyOrdersIndex')->middleware('can:purchases.supply_orders.view')->name('index');
                Route::get('/create', 'createSupplyOrder')->middleware('can:purchases.supply_orders.create')->name('create');
                Route::post('/', 'storeSupplyOrder')->middleware('can:purchases.supply_orders.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{supplyOrder}/edit', 'editSupplyOrder')->middleware('can:purchases.supply_orders.edit')->name('edit');
                Route::put('/{supplyOrder}', 'updateSupplyOrder')->middleware('can:purchases.supply_orders.edit')->name('update');
                Route::delete('/{supplyOrder}', 'destroySupplyOrder')->middleware('can:purchases.supply_orders.delete')->name('destroy');
                Route::post('/{supplyOrder}/issue', 'issueSupplyOrder')->middleware('can:purchases.supply_orders.issue')->name('issue');
                Route::post('/{supplyOrder}/cancel', 'cancelSupplyOrder')->middleware('can:purchases.supply_orders.cancel')->name('cancel');
                Route::get('/{supplyOrder}', 'showSupplyOrder')->middleware('can:purchases.supply_orders.view')->name('show');
            });

            Route::prefix('goods-receipt-notes')->name('goods-receipt-notes.')->group(function (): void {
                Route::get('/create', 'chooseSource')->defaults('screen', 'goods_receipts')->middleware('can:purchases.goods_receipt_notes.create')->name('choose-source');
                Route::get('/', 'receiptsIndex')->middleware('can:purchases.goods_receipt_notes.view')->name('index');
                Route::get('/create/{sourceDocument}', 'createReceipt')->middleware('can:purchases.goods_receipt_notes.create')->name('create');
                Route::post('/from/{sourceDocument}', 'storeReceipt')->middleware('can:purchases.goods_receipt_notes.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{goodsReceiptNote}/edit', 'editReceipt')->middleware('can:purchases.goods_receipt_notes.edit')->name('edit');
                Route::put('/{goodsReceiptNote}', 'updateReceipt')->middleware('can:purchases.goods_receipt_notes.edit')->name('update');
                Route::delete('/{goodsReceiptNote}', 'destroyReceipt')->middleware('can:purchases.goods_receipt_notes.delete')->name('destroy');
                Route::get('/{goodsReceiptNote}', 'showReceipt')->middleware('can:purchases.goods_receipt_notes.view')->name('show');
                Route::post('/{goodsReceiptNote}/post', 'postReceipt')->middleware('can:purchases.goods_receipt_notes.post')->name('post');
                Route::post('/{goodsReceiptNote}/reverse', 'reverseReceipt')->middleware('can:purchases.goods_receipt_notes.reverse')->name('reverse');
                Route::post('/{goodsReceiptNote}/cancel', 'cancelReceipt')->middleware('can:purchases.goods_receipt_notes.edit')->name('cancel');
            });
            Route::get('goods-receipt-lines', 'receiptLinesIndex')->middleware('can:purchases.goods_receipt_notes.view')->name('goods-receipt-lines.index');
            Route::prefix('goods-receipt-inspection')->name('goods-receipt-inspection.')->group(function (): void {
                Route::get('/', 'inspectionsIndex')->middleware('can:purchases.goods_receipt_inspection.view')->name('index');
                Route::get('/create', 'chooseSource')->defaults('screen', 'goods_receipt_inspections')->middleware('can:purchases.goods_receipt_inspection.create')->name('choose-source');
                Route::get('/create/{sourceDocument}', 'createInspection')->middleware('can:purchases.goods_receipt_inspection.create')->name('create');
                Route::post('/from/{sourceDocument}', 'storeInspection')->middleware('can:purchases.goods_receipt_inspection.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{goodsReceiptInspection}', 'showInspection')->middleware('can:purchases.goods_receipt_inspection.view')->name('show');
            });

            Route::get('purchase-invoice-lines', 'inquiry')->defaults('procurement_screen', 'purchase_invoice_lines')->middleware('can:purchase_invoices.view')->name('purchase-invoice-lines.index');
            Route::get('purchase-invoice-payments', 'inquiry')->defaults('procurement_screen', 'purchase_invoice_payments')->middleware('can:purchase_invoices.view')->name('purchase-invoice-payments.index');
            Route::get('purchase-invoice-allocations', 'inquiry')->defaults('procurement_screen', 'purchase_invoice_allocations')->middleware('can:purchase_invoices.view')->name('purchase-invoice-allocations.index');

            Route::prefix('purchase-returns')->name('purchase-returns.')->group(function (): void {
                Route::get('/', 'returnsIndex')->middleware('can:purchases.purchase_returns.view')->name('index');
                Route::get('/create', 'createReturn')->middleware('can:purchases.purchase_returns.create')->name('create');
                Route::post('/', 'storeReturn')->middleware('can:purchases.purchase_returns.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{purchaseReturn}/edit', 'editReturn')->middleware('can:purchases.purchase_returns.edit')->name('edit');
                Route::put('/{purchaseReturn}', 'updateReturn')->middleware('can:purchases.purchase_returns.edit')->name('update');
                Route::delete('/{purchaseReturn}', 'destroyReturn')->middleware('can:purchases.purchase_returns.delete')->name('destroy');
                Route::get('/{purchaseReturn}', 'showReturn')->middleware('can:purchases.purchase_returns.view')->name('show');
                Route::post('/{purchaseReturn}/approve', 'approveReturn')->middleware('can:purchases.purchase_returns.post')->name('approve');
                Route::post('/{purchaseReturn}/reverse', 'reverseReturn')->middleware('can:purchases.purchase_returns.reverse')->name('reverse');
            });
            Route::get('purchase-return-lines', 'returnLinesIndex')->middleware('can:purchases.purchase_returns.view')->name('purchase-return-lines.index');

            Route::get('supplier-advances', 'supplierAdvancesIndex')->middleware('can:purchases.supplier_advances.view')->name('supplier-advances.index');
            Route::prefix('supplier-payments')->name('supplier-payments.')->middleware('can:purchases.prices.view')->group(function (): void {
                Route::get('/', 'supplierPaymentsIndex')->middleware('can:supplier_payments.view')->name('index');
                Route::get('/create', 'createSupplierPayment')->middleware('can:supplier_payments.create')->name('create');
                Route::post('/', 'storeSupplierPayment')->middleware('can:supplier_payments.create')->middleware(IdempotentDocumentSubmission::class)->name('store');
                Route::get('/{supplierPayment}', 'showSupplierPayment')->middleware('can:supplier_payments.view')->name('show');
                Route::post('/{supplierPayment}/approve', 'approveSupplierPayment')->middleware('can:supplier_payments.approve')->name('approve');
                Route::post('/{supplierPayment}/cancel', 'cancelSupplierPayment')->middleware('can:supplier_payments.cancel')->name('cancel');
            });
            Route::get('supplier-payment-allocations', 'inquiry')->defaults('procurement_screen', 'supplier_payment_allocations')->middleware('can:supplier_payments.view')->name('supplier-payment-allocations.index');
            Route::post('supplier-payment-allocations/{supplierPayment}', 'allocateSupplierPayment')->middleware(['can:purchases.supplier_payment_allocations.create', 'can:purchases.prices.view'])->name('supplier-payment-allocations.store');
            Route::get('supplier-debit-notes', 'inquiry')->defaults('procurement_screen', 'supplier_debit_notes')->middleware('can:purchases.purchase_returns.view')->name('supplier-debit-notes.index');

            Route::prefix('procurement-cycle-report')->name('procurement-cycle-report.')->group(function (): void {
                Route::get('/', 'report')->name('index');
                Route::get('/export/excel', 'exportReportExcel')->name('export.excel');
                Route::get('/print', 'printReport')->name('print');
            });
            Route::get('procurement-print/{type}/{docNum}', 'printDocument')->name('procurement.print');
        });
    });

Route::middleware('auth')
    ->prefix('admin/reports/suppliers')
    ->as('admin.reports.suppliers.')
    ->controller(SupplierDataReportController::class)
    ->group(function (): void {
        Route::get('/', 'index')->middleware('can:reports.suppliers.view')->name('index');
        Route::get('/data', 'data')->middleware('can:reports.suppliers.view')->name('data');
        Route::get('/filter-options/accounts', 'filterAccounts')->middleware('can:reports.suppliers.view')->name('filter-options.accounts');
        Route::get('/filter-options/account-groups', 'filterAccountGroups')->middleware('can:reports.suppliers.view')->name('filter-options.account-groups');
        Route::get('/export/excel', 'exportExcel')->middleware('can:reports.suppliers.export')->name('export.excel');
        Route::get('/export/csv', 'exportCsv')->middleware('can:reports.suppliers.export')->name('export.csv');
        Route::get('/export/pdf', 'exportPdf')->middleware('can:reports.suppliers.pdf')->name('export.pdf');
    });
