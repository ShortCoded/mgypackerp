<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\BankAccountController;
use Modules\Finance\Http\Controllers\CashboxController;
use Modules\Finance\Http\Controllers\CashPaymentVoucherController;
use Modules\Finance\Http\Controllers\CashReceiptVoucherController;
use Modules\Finance\Http\Controllers\ChequeController;
use Modules\Finance\Http\Controllers\FinanceReportController;
use Modules\Finance\Http\Controllers\FundTransferController;
use Modules\Finance\Http\Controllers\OpeningBalanceController;
use Modules\Finance\Services\FinanceReportService;
use Modules\Finance\Services\FinanceSelect2Service;

Route::middleware('auth')
    ->prefix('admin/finance')
    ->as('admin.finance.')
    ->group(function (): void {
        Route::get('/select2/accounts', fn (Request $request, FinanceSelect2Service $select2) => response()->json($select2->accounts($request)))
            ->middleware('can:accounts.view')
            ->name('select2.accounts');

        Route::get('/select2/cash-voucher-cashboxes', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cash_receipt_vouchers.view')
                || (bool) $request->user()?->can('cash_receipt_vouchers.create')
                || (bool) $request->user()?->can('cash_receipt_vouchers.edit')
                || (bool) $request->user()?->can('cash_payment_vouchers.view')
                || (bool) $request->user()?->can('cash_payment_vouchers.create')
                || (bool) $request->user()?->can('cash_payment_vouchers.edit'),
                403
            );

            return response()->json($select2->cashboxes($request));
        })->name('select2.cash-voucher-cashboxes');

        Route::get('/select2/cashboxes', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('fund_transfers.view')
                || (bool) $request->user()?->can('fund_transfers.create')
                || (bool) $request->user()?->can('fund_transfers.edit'),
                403
            );

            return response()->json($select2->cashboxes($request));
        })->name('select2.cashboxes');

        Route::get('/select2/bank-accounts', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cheques.view')
                || (bool) $request->user()?->can('cheques.create')
                || (bool) $request->user()?->can('cheques.edit')
                || (bool) $request->user()?->can('fund_transfers.view')
                || (bool) $request->user()?->can('fund_transfers.create')
                || (bool) $request->user()?->can('fund_transfers.edit'),
                403
            );

            return response()->json($select2->bankAccounts($request));
        })->name('select2.bank-accounts');

        Route::get('/select2/customers', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cheques.view')
                || (bool) $request->user()?->can('cheques.create')
                || (bool) $request->user()?->can('cheques.edit'),
                403
            );

            return response()->json($select2->customers($request));
        })->name('select2.customers');

        Route::get('/select2/suppliers', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cheques.view')
                || (bool) $request->user()?->can('cheques.create')
                || (bool) $request->user()?->can('cheques.edit'),
                403
            );

            return response()->json($select2->suppliers($request));
        })->name('select2.suppliers');

        Route::get('/select2/holder-currencies', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cheques.view')
                || (bool) $request->user()?->can('cheques.create')
                || (bool) $request->user()?->can('cheques.edit')
                || (bool) $request->user()?->can('fund_transfers.view')
                || (bool) $request->user()?->can('fund_transfers.create')
                || (bool) $request->user()?->can('fund_transfers.edit'),
                403
            );

            return response()->json($select2->holderCurrencies($request));
        })->name('select2.holder-currencies');

        Route::get('/select2/cash-voucher-currencies', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cash_receipt_vouchers.view')
                || (bool) $request->user()?->can('cash_receipt_vouchers.create')
                || (bool) $request->user()?->can('cash_receipt_vouchers.edit')
                || (bool) $request->user()?->can('cash_payment_vouchers.view')
                || (bool) $request->user()?->can('cash_payment_vouchers.create')
                || (bool) $request->user()?->can('cash_payment_vouchers.edit'),
                403
            );

            return response()->json($select2->cashboxCurrencies($request));
        })->name('select2.cash-voucher-currencies');

        Route::get('/select2/branches', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cashboxes.view')
                || (bool) $request->user()?->can('cashboxes.create')
                || (bool) $request->user()?->can('cashboxes.edit'),
                403
            );

            return response()->json($select2->cashboxBranches($request));
        })->name('select2.branches');

        Route::get('/select2/cashbox-parent-accounts', function (Request $request, FinanceSelect2Service $select2) {
            abort_unless(
                (bool) $request->user()?->can('cashboxes.view')
                || (bool) $request->user()?->can('cashboxes.create')
                || (bool) $request->user()?->can('cashboxes.edit'),
                403
            );

            return response()->json($select2->cashboxParentAccounts($request));
        })->name('select2.cashbox-parent-accounts');

        Route::prefix('bank-accounts')->name('bank-accounts.')->controller(BankAccountController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:bank_accounts.view')->name('index');
            Route::get('/data', 'data')->middleware('can:bank_accounts.view')->name('data');
            Route::get('/create', 'create')->middleware('can:bank_accounts.create')->name('create');
            Route::post('/bank-groups', 'storeBankGroup')->middleware('can:accounts.create')->name('bank-groups.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:bank_accounts.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:bank_accounts.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{bankAccount}/restore', 'restore')->middleware('can:bank_accounts.restore')->name('restore');
            Route::get('/{bankAccount}/clone', 'clone')->middleware('can:bank_accounts.clone')->name('clone');
            Route::get('/{bankAccount}', 'show')->withTrashed()->middleware('can:bank_accounts.view')->name('show');
            Route::get('/{bankAccount}/edit', 'edit')->middleware('can:bank_accounts.edit')->name('edit');
            Route::put('/{bankAccount}', 'update')->middleware('can:bank_accounts.edit')->name('update');
            Route::delete('/{bankAccount}', 'destroy')->middleware('can:bank_accounts.delete')->name('destroy');
        });

        Route::prefix('cashboxes')->name('cashboxes.')->controller(CashboxController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:cashboxes.view')->name('index');
            Route::get('/data', 'data')->middleware('can:cashboxes.view')->name('data');
            Route::get('/create', 'create')->middleware('can:cashboxes.create')->name('create');
            Route::post('/account-groups', 'storeAccountGroup')->middleware('can:accounts.create')->name('account-groups.store');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:cashboxes.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:cashboxes.document_number_settings.update')->name('document-number-settings.update');
            Route::patch('/{cashbox}/restore', 'restore')->middleware('can:cashboxes.restore')->name('restore');
            Route::get('/{cashbox}/clone', 'clone')->middleware('can:cashboxes.clone')->name('clone');
            Route::get('/{cashbox}', 'show')->withTrashed()->middleware('can:cashboxes.view')->name('show');
            Route::get('/{cashbox}/edit', 'edit')->middleware('can:cashboxes.edit')->name('edit');
            Route::put('/{cashbox}', 'update')->middleware('can:cashboxes.edit')->name('update');
            Route::delete('/{cashbox}', 'destroy')->middleware('can:cashboxes.delete')->name('destroy');
        });

        Route::prefix('cash-receipt-vouchers')->name('cash-receipt-vouchers.')->controller(CashReceiptVoucherController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:cash_receipt_vouchers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:cash_receipt_vouchers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:cash_receipt_vouchers.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:cash_receipt_vouchers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:cash_receipt_vouchers.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{cashVoucher}/approve', 'approve')->middleware('can:cash_receipt_vouchers.approve')->name('approve');
            Route::post('/{cashVoucher}/cancel', 'cancel')->middleware('can:cash_receipt_vouchers.cancel')->name('cancel');
            Route::patch('/{cashVoucher}/restore', 'restore')->middleware('can:cash_receipt_vouchers.restore')->name('restore');
            Route::get('/{cashVoucher}/clone', 'clone')->middleware('can:cash_receipt_vouchers.clone')->name('clone');
            Route::get('/{cashVoucher}/print', 'print')->middleware('can:cash_receipt_vouchers.print')->name('print');
            Route::get('/{cashVoucher}', 'show')->middleware('can:cash_receipt_vouchers.view')->name('show');
            Route::get('/{cashVoucher}/edit', 'edit')->middleware('can:cash_receipt_vouchers.edit')->name('edit');
            Route::put('/{cashVoucher}', 'update')->middleware('can:cash_receipt_vouchers.edit')->name('update');
            Route::delete('/{cashVoucher}', 'destroy')->middleware('can:cash_receipt_vouchers.delete')->name('destroy');
        });

        Route::prefix('cash-payment-vouchers')->name('cash-payment-vouchers.')->controller(CashPaymentVoucherController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:cash_payment_vouchers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:cash_payment_vouchers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:cash_payment_vouchers.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:cash_payment_vouchers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:cash_payment_vouchers.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{cashVoucher}/approve', 'approve')->middleware('can:cash_payment_vouchers.approve')->name('approve');
            Route::post('/{cashVoucher}/cancel', 'cancel')->middleware('can:cash_payment_vouchers.cancel')->name('cancel');
            Route::patch('/{cashVoucher}/restore', 'restore')->middleware('can:cash_payment_vouchers.restore')->name('restore');
            Route::get('/{cashVoucher}/clone', 'clone')->middleware('can:cash_payment_vouchers.clone')->name('clone');
            Route::get('/{cashVoucher}/print', 'print')->middleware('can:cash_payment_vouchers.print')->name('print');
            Route::get('/{cashVoucher}', 'show')->middleware('can:cash_payment_vouchers.view')->name('show');
            Route::get('/{cashVoucher}/edit', 'edit')->middleware('can:cash_payment_vouchers.edit')->name('edit');
            Route::put('/{cashVoucher}', 'update')->middleware('can:cash_payment_vouchers.edit')->name('update');
            Route::delete('/{cashVoucher}', 'destroy')->middleware('can:cash_payment_vouchers.delete')->name('destroy');
        });

        Route::prefix('cheques')->name('cheques.')->controller(ChequeController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:cheques.view')->name('index');
            Route::get('/data', 'data')->middleware('can:cheques.view')->name('data');
            Route::get('/create', 'create')->middleware('can:cheques.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:cheques.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:cheques.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{cheque}/mark-deposited', 'markDeposited')->middleware('can:cheques.mark_deposited')->name('mark-deposited');
            Route::post('/{cheque}/mark-collected', 'markCollected')->middleware('can:cheques.mark_collected')->name('mark-collected');
            Route::post('/{cheque}/mark-returned', 'markReturned')->middleware('can:cheques.mark_returned')->name('mark-returned');
            Route::post('/{cheque}/mark-issued', 'markIssued')->middleware('can:cheques.mark_issued')->name('mark-issued');
            Route::post('/{cheque}/mark-delivered', 'markDelivered')->middleware('can:cheques.mark_delivered')->name('mark-delivered');
            Route::post('/{cheque}/mark-cleared', 'markCleared')->middleware('can:cheques.mark_cleared')->name('mark-cleared');
            Route::post('/{cheque}/reverse-clearing', 'reverseClearing')->middleware('can:cheques.mark_cleared')->name('reverse-clearing');
            Route::post('/{cheque}/represent', 'represent')->middleware('can:cheques.mark_issued')->name('represent');
            Route::post('/{cheque}/cancel', 'cancel')->middleware('can:cheques.cancel')->name('cancel');
            Route::get('/{cheque}/print', 'print')->middleware('can:cheques.print')->name('print');
            Route::patch('/{cheque}/restore', 'restore')->middleware('can:cheques.restore')->name('restore');
            Route::get('/{cheque}/clone', 'clone')->middleware('can:cheques.clone')->name('clone');
            Route::get('/{cheque}', 'show')->middleware('can:cheques.view')->name('show');
            Route::get('/{cheque}/edit', 'edit')->middleware('can:cheques.edit')->name('edit');
            Route::put('/{cheque}', 'update')->middleware('can:cheques.edit')->name('update');
            Route::delete('/{cheque}', 'destroy')->middleware('can:cheques.delete')->name('destroy');
        });

        Route::prefix('fund-transfers')->name('fund-transfers.')->controller(FundTransferController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:fund_transfers.view')->name('index');
            Route::get('/data', 'data')->middleware('can:fund_transfers.view')->name('data');
            Route::get('/create', 'create')->middleware('can:fund_transfers.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:fund_transfers.delete')->name('bulk-delete');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:fund_transfers.document_number_settings.update')->name('document-number-settings.update');
            Route::post('/{fundTransfer}/approve', 'approve')->middleware('can:fund_transfers.approve')->name('approve');
            Route::post('/{fundTransfer}/cancel', 'cancel')->middleware('can:fund_transfers.cancel')->name('cancel');
            Route::get('/{fundTransfer}/print', 'print')->middleware('can:fund_transfers.print')->name('print');
            Route::patch('/{fundTransfer}/restore', 'restore')->middleware('can:fund_transfers.restore')->name('restore');
            Route::get('/{fundTransfer}/clone', 'clone')->middleware('can:fund_transfers.clone')->name('clone');
            Route::get('/{fundTransfer}', 'show')->middleware('can:fund_transfers.view')->name('show');
            Route::get('/{fundTransfer}/edit', 'edit')->middleware('can:fund_transfers.edit')->name('edit');
            Route::put('/{fundTransfer}', 'update')->middleware('can:fund_transfers.edit')->name('update');
            Route::delete('/{fundTransfer}', 'destroy')->middleware('can:fund_transfers.delete')->name('destroy');
        });

        Route::prefix('opening-balances')->name('opening-balances.')->controller(OpeningBalanceController::class)->group(function (): void {
            Route::get('/', 'index')->middleware('can:opening_balances.view')->name('index');
            Route::get('/data', 'data')->middleware('can:opening_balances.view')->name('data');
            Route::get('/create', 'create')->middleware('can:opening_balances.create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::delete('/bulk-delete', 'bulkDelete')->middleware('can:opening_balances.delete')->name('bulk-delete');
            Route::post('/bulk-approve', 'bulkApprove')->middleware('can:opening_balances.approve')->name('bulk-approve');
            Route::put('/document-number-settings', 'updateDocumentNumberSettings')->middleware('can:opening_balances.document_number_settings.update')->name('document-number-settings.update');
            Route::get('/trashed/{openingBalance}', 'showTrashed')->middleware(['can:opening_balances.view', 'can:opening_balances.view_trashed'])->name('trashed.show');
            Route::post('/{openingBalance}/approve', 'approve')->middleware('can:opening_balances.approve')->name('approve');
            Route::post('/{openingBalance}/cancel', 'cancel')->middleware('can:opening_balances.cancel')->name('cancel');
            Route::patch('/{openingBalance}/restore', 'restore')->middleware('can:opening_balances.restore')->name('restore');
            Route::get('/{openingBalance}/clone', 'clone')->middleware('can:opening_balances.clone')->name('clone');
            Route::get('/{openingBalance}', 'show')->middleware('can:opening_balances.view')->name('show');
            Route::get('/{openingBalance}/edit', 'edit')->middleware('can:opening_balances.edit')->name('edit');
            Route::put('/{openingBalance}', 'update')->middleware('can:opening_balances.edit')->name('update');
            Route::delete('/{openingBalance}', 'destroy')->middleware('can:opening_balances.delete')->name('destroy');
        });
    });

Route::middleware('auth')
    ->prefix('admin/reports/finance')
    ->as('admin.reports.finance.')
    ->controller(FinanceReportController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/export/excel', 'excel')->name('export.excel');
        Route::get('/export/csv', 'csv')->name('export.csv');
        Route::get('/export/pdf', 'pdf')->name('export.pdf');

        foreach ([
            'cashbox-balances' => FinanceReportService::CashboxBalances,
            'cashbox-statement' => FinanceReportService::CashboxStatement,
            'bank-account-balances' => FinanceReportService::BankAccountBalances,
            'bank-account-statement' => FinanceReportService::BankAccountStatement,
            'cheque-transit' => FinanceReportService::DueCheques,
            'treasury-transfers' => FinanceReportService::FundTransfers,
            'customer-aging' => FinanceReportService::CustomerAging,
            'supplier-aging' => FinanceReportService::SupplierAging,
        ] as $slug => $type) {
            Route::get("/{$slug}", 'index')
                ->defaults('finance_report_type', $type)
                ->name("{$slug}.index");
        }
    });
