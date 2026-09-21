<?php

use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\CostCenterController;
use Modules\Accounting\Http\Controllers\CostingReportController;
use Modules\Accounting\Http\Controllers\FinancialAnalyticsReportController;
use Modules\Accounting\Http\Controllers\FinancialStatementReportController;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Http\Controllers\LedgerReportController;
use Modules\Accounting\Http\Controllers\OverheadAllocationController;
use Modules\Accounting\Http\Controllers\ReconciliationCenterController;
use Modules\Accounting\Http\Controllers\TrialBalanceReportController;

Route::middleware(['auth', 'erp.expanded'])
    ->prefix('admin/costing')
    ->as('admin.costing.')
    ->controller(OverheadAllocationController::class)
    ->group(function (): void {
        Route::get('/overhead-allocation-rules', 'rules')
            ->middleware('can:costing.overhead_allocation_rules.view')
            ->name('overhead-allocation-rules.index');
        Route::get('/overhead-allocation-rules/select2/cost-centers', 'costCenters')
            ->middleware('can:costing.overhead_allocation_rules.create')
            ->name('overhead-allocation-rules.select2.cost-centers');
        Route::get('/overhead-allocation-rules/select2/source-accounts', 'sourceAccounts')
            ->middleware('can:costing.overhead_allocation_rules.create')
            ->name('overhead-allocation-rules.select2.source-accounts');
        Route::post('/overhead-allocation-rules', 'storeRule')
            ->middleware('can:costing.overhead_allocation_rules.create')
            ->name('overhead-allocation-rules.store');
        Route::get('/overhead-allocation-run', 'runs')
            ->middleware('can:costing.overhead_allocation_run.view')
            ->name('overhead-allocation-run.index');
        Route::post('/overhead-allocation-run/preview', 'preview')
            ->middleware('can:costing.overhead_allocation_run.create')
            ->name('overhead-allocation-run.preview');
        Route::post('/overhead-allocation-run/{allocationRun}/approve', 'approve')
            ->middleware('can:costing.overhead_allocation_run.approve')
            ->name('overhead-allocation-run.approve');
        Route::post('/overhead-allocation-run/{allocationRun}/reverse', 'reverse')
            ->middleware('can:costing.overhead_allocation_run.reverse')
            ->name('overhead-allocation-run.reverse');
    });

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

        Route::prefix('journal-entries/select2')
            ->as('journal-entries.select2.')
            ->controller(JournalEntryController::class)
            ->group(function (): void {
                Route::get('/accounts', 'accounts')->name('accounts');
                Route::get('/cost-centers', 'costCenters')->name('cost-centers');
                Route::get('/customers', 'customers')->name('customers');
                Route::get('/suppliers', 'suppliers')->name('suppliers');
                Route::get('/employees', 'employees')->name('employees');
            });

        Route::prefix('journal-entries')
            ->as('journal-entries.')
            ->controller(JournalEntryController::class)
            ->group(function (): void {
                Route::get('/', 'index')->middleware('can:journal_entries.view')->name('index');
                Route::get('/data', 'data')->middleware('can:journal_entries.view')->name('data');
                Route::get('/create', 'create')->middleware('can:journal_entries.create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/trashed/{journalEntry}', 'showTrashed')->middleware(['can:journal_entries.view', 'can:journal_entries.view_trashed'])->name('trashed.show');
                Route::patch('/{journalEntry}/restore', 'restore')->middleware('can:journal_entries.restore')->name('restore');
                Route::post('/{journalEntry}/post', 'post')->middleware('can:journal_entries.post')->name('post');
                Route::get('/{journalEntry}', 'show')->middleware('can:journal_entries.view')->name('show');
                Route::get('/{journalEntry}/edit', 'edit')->middleware('can:journal_entries.edit')->name('edit');
                Route::put('/{journalEntry}', 'update')->middleware('can:journal_entries.edit')->name('update');
                Route::delete('/{journalEntry}', 'destroy')->middleware('can:journal_entries.delete')->name('destroy');
            });

        Route::prefix('reports')
            ->as('reports.')
            ->controller(LedgerReportController::class)
            ->group(function (): void {
                foreach ([
                    'general-journal' => 'general_journal',
                    'account-ledger' => 'account_ledger',
                    'customer-statement' => 'customer_statement',
                    'supplier-statement' => 'supplier_statement',
                ] as $slug => $type) {
                    foreach (['excel', 'csv', 'pdf'] as $format) {
                        $permissionType = $type === 'general_journal' ? 'account_ledger' : $type;
                        Route::get("/{$slug}/export/{$format}", 'export')
                            ->defaults('ledger_report_type', $type)
                            ->defaults('ledger_export_format', $format)
                            ->middleware("can:reports.{$permissionType}.export")
                            ->name("{$slug}.export.{$format}");
                    }
                }

                Route::get('/general-journal', 'generalJournal')->middleware('can:reports.account_ledger.view')->name('general-journal');
                Route::get('/account-ledger', 'accountLedger')->middleware('can:reports.account_ledger.view')->name('account-ledger');
                Route::get('/customer-statement', 'customerStatement')->middleware('can:reports.customer_statement.view')->name('customer-statement');
                Route::get('/supplier-statement', 'supplierStatement')->middleware('can:reports.supplier_statement.view')->name('supplier-statement');
            });

        foreach (['excel', 'csv', 'pdf'] as $format) {
            Route::get("/reports/trial-balance/export/{$format}", [TrialBalanceReportController::class, 'export'])
                ->defaults('trial_balance_export_format', $format)
                ->middleware('can:reports.trial_balance.export')
                ->name("reports.trial-balance.export.{$format}");
        }

        Route::get('/reports/trial-balance', [TrialBalanceReportController::class, 'index'])
            ->middleware('can:reports.trial_balance.view')
            ->name('reports.trial-balance');

        foreach (['excel', 'csv', 'pdf'] as $format) {
            Route::get("/reports/reconciliation-center/export/{$format}", [ReconciliationCenterController::class, 'export'])
                ->defaults('reconciliation_export_format', $format)
                ->middleware('can:reports.account_ledger.export')
                ->name("reports.reconciliation-center.export.{$format}");
        }

        Route::get('/reports/reconciliation-center', [ReconciliationCenterController::class, 'index'])
            ->middleware('can:reports.account_ledger.view')
            ->name('reports.reconciliation-center');

        foreach (['excel', 'csv', 'pdf'] as $format) {
            Route::get("/reports/financial-statements/export/{$format}", [FinancialStatementReportController::class, 'export'])
                ->defaults('financial_statement_export_format', $format)
                ->middleware('can:reports.financial_statements.export')
                ->name("reports.financial-statements.export.{$format}");
        }

        Route::get('/reports/financial-statements', [FinancialStatementReportController::class, 'index'])
            ->middleware('can:reports.financial_statements.view')
            ->name('reports.financial-statements');

        foreach ([
            'expense-analysis' => 'expense_analysis',
            'financial-ratios' => 'financial_ratios',
        ] as $slug => $type) {
            Route::get("/reports/financial-analytics/{$slug}", [FinancialAnalyticsReportController::class, 'index'])
                ->defaults('financial_analytics_type', $type)
                ->middleware("can:reports.financial_analytics.{$type}.view")
                ->name("reports.financial-analytics.{$slug}.index");
            Route::get("/reports/financial-analytics/{$slug}/export/{financial_analytics_format}", [FinancialAnalyticsReportController::class, 'export'])
                ->defaults('financial_analytics_type', $type)
                ->whereIn('financial_analytics_format', ['excel', 'csv', 'pdf'])
                ->middleware("can:reports.financial_analytics.{$type}.export")
                ->name("reports.financial-analytics.{$slug}.export");
        }

        Route::prefix('reports/costing/export')
            ->as('reports.costing.export.')
            ->controller(CostingReportController::class)
            ->group(function (): void {
                Route::get('/excel', 'excel')->name('excel');
                Route::get('/csv', 'csv')->name('csv');
                Route::get('/pdf', 'pdf')->name('pdf');
            });

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
