<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\FinanceReportService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function financeReportFixture(object $test): array
{
    $company = Company::query()->create([
        'doc_number' => 9701, 'doc_num' => 'COMP-9701', 'name' => 'Finance Reports Company', 'status' => 'active', 'is_main' => true,
    ]);
    $branch = Branch::query()->create([
        'doc_number' => 9701, 'doc_num' => 'BR-9701', 'company_id' => $company->getKey(), 'name' => 'Main Branch', 'type' => 'main', 'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => 9701, 'doc_num' => 'FY-9701', 'company_id' => $company->getKey(), 'name' => 'FY 2026',
        'from_date' => '2026-01-01', 'to_date' => '2026-12-31', 'is_closed' => false, 'status' => 'active',
    ]);
    $currency = Currency::query()->create([
        'doc_number' => 9701, 'doc_num' => 'CUR-9701', 'company_id' => $company->getKey(), 'name' => 'Egyptian Pound',
        'name_en' => 'Egyptian Pound', 'code' => 'EGP', 'minor_unit_name' => 'Piastre', 'minor_unit_factor' => 100, 'is_main' => true, 'status' => 'active',
    ]);
    $accountOne = financeReportAccount($company, 9701, '111101', 'Main Cashbox Account');
    $accountTwo = financeReportAccount($company, 9702, '111102', 'Petty Cashbox Account');
    $cashboxOne = Cashbox::query()->create([
        'doc_number' => 9701, 'doc_num' => 'CASH-9701', 'company_id' => $company->getKey(), 'name' => 'Main Cashbox',
        'branch_id' => $branch->getKey(), 'account_id' => $accountOne->getKey(), 'status' => 'active',
    ]);
    $cashboxTwo = Cashbox::query()->create([
        'doc_number' => 9702, 'doc_num' => 'CASH-9702', 'company_id' => $company->getKey(), 'name' => 'Petty Cashbox',
        'branch_id' => $branch->getKey(), 'account_id' => $accountTwo->getKey(), 'status' => 'active',
    ]);

    CashVoucher::query()->create([
        'doc_number' => 9701, 'doc_num' => 'CRV-9701', 'company_id' => $company->getKey(), 'voucher_type' => CashVoucher::TypeReceipt,
        'voucher_date' => '2026-09-01', 'cashbox_id' => $cashboxOne->getKey(), 'currency_id' => $currency->getKey(),
        'exchange_rate' => 1, 'amount' => 100, 'amount_base' => 100, 'reason' => 'Approved receipt', 'status' => CashVoucher::StatusApproved,
    ]);
    CashVoucher::query()->create([
        'doc_number' => 9702, 'doc_num' => 'CRV-9702-DRAFT', 'company_id' => $company->getKey(), 'voucher_type' => CashVoucher::TypeReceipt,
        'voucher_date' => '2026-09-01', 'cashbox_id' => $cashboxOne->getKey(), 'currency_id' => $currency->getKey(),
        'exchange_rate' => 1, 'amount' => 999, 'amount_base' => 999, 'reason' => 'Draft receipt', 'status' => CashVoucher::StatusDraft,
    ]);
    FundTransfer::query()->create([
        'doc_number' => 9701, 'doc_num' => 'TRF-9701', 'company_id' => $company->getKey(), 'transfer_date' => '2026-09-02',
        'source_type' => FundTransfer::HolderCashbox, 'source_cashbox_id' => $cashboxOne->getKey(),
        'target_type' => FundTransfer::HolderCashbox, 'target_cashbox_id' => $cashboxTwo->getKey(),
        'source_currency_id' => $currency->getKey(), 'target_currency_id' => $currency->getKey(), 'source_amount' => 20,
        'exchange_rate' => 1, 'target_amount' => 20, 'source_amount_base' => 20, 'target_amount_base' => 20,
        'reason' => 'Cash replenishment', 'status' => FundTransfer::StatusApproved,
    ]);

    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(), OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(), OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(), OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    $test->withSession($session);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put($session);

    return compact('company', 'branch', 'period', 'currency', 'cashboxOne', 'cashboxTwo');
}

function financeReportAccount(Company $company, int $number, string $code, string $name): Account
{
    return Account::query()->create([
        'doc_number' => $number, 'doc_num' => 'ACC-'.$number, 'company_id' => $company->getKey(), 'account_code' => $code,
        'name' => $name, 'name_en' => $name, 'level' => 1, 'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit,
        'is_group' => false, 'is_postable' => true, 'status' => 'active',
    ]);
}

function financeReportActor(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permission = Permission::findOrCreate('reports.finance.view', 'web');
    $user = User::factory()->create(['locale' => 'en']);
    $user->givePermissionTo($permission);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

function financeDashboardCount(string $html, string $key): int
{
    preg_match('/data-operational-card="'.preg_quote($key, '/').'".*?data-operational-card-value[^>]*>\s*([0-9,]+)\s*</s', $html, $matches);

    return (int) str_replace(',', '', $matches[1] ?? '0');
}

function financeReportCount(string $html, string $key): int
{
    preg_match('/data-report-count="'.preg_quote($key, '/').'"[^>]*>\s*([0-9,]+)\s*</', $html, $matches);

    return (int) str_replace(',', '', $matches[1] ?? '0');
}

test('finance cashbox reports reconcile approved vouchers and both transfer legs without counting drafts', function (): void {
    $fixture = financeReportFixture($this);
    $actor = financeReportActor();
    $this->actingAs($actor);

    $service = app(FinanceReportService::class);
    $report = $service->report([
        'type' => FinanceReportService::CashboxBalances,
        'as_of_date' => '2026-09-30',
    ]);

    $main = $report['rows']->first(fn (array $row): bool => str_contains($row['cashbox'], $fixture['cashboxOne']->doc_num));
    $petty = $report['rows']->first(fn (array $row): bool => str_contains($row['cashbox'], $fixture['cashboxTwo']->doc_num));

    expect($main['receipts'])->toBe('100.0000')
        ->and($main['payments'])->toBe('20.0000')
        ->and($main['balance'])->toBe('80.0000')
        ->and($petty['receipts'])->toBe('20.0000')
        ->and($petty['payments'])->toBe('0.0000')
        ->and($petty['balance'])->toBe('20.0000');

    $statement = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-09-01',
        'to_date' => '2026-09-30',
        'as_of_date' => '2026-09-30',
    ]);

    expect($statement['rows']->pluck('document')->all())
        ->toContain('CRV-9701', 'TRF-9701')
        ->not->toContain('CRV-9702-DRAFT');
});

test('legacy finance report route renders the actual unified report and exposes evidence based unsupported states', function (): void {
    financeReportFixture($this);
    $actor = financeReportActor();

    $this->actingAs($actor)
        ->get(route('admin.reports.finance.index', ['type' => FinanceReportService::CashboxStatement, 'from_date' => '2026-09-01', 'to_date' => '2026-09-30']))
        ->assertOk()
        ->assertSee(__('finance_reports.types.cashbox_statement.title'))
        ->assertSee('CRV-9701')
        ->assertDontSee('CRV-9702-DRAFT')
        ->assertSee(route('admin.reports.finance.export.excel'), false);

    $this->actingAs($actor)
        ->get(route('admin.reports.finance.index', ['type' => FinanceReportService::GuaranteeCheques]))
        ->assertOk()
        ->assertSee(__('finance_reports.notices.guarantee_cheques_unsupported'));

    $this->actingAs($actor)
        ->getJson(route('admin.reports.finance.cashbox-balances.data', ['as_of_date' => '2026-09-30']))
        ->assertOk()
        ->assertJsonPath('recordsTotal', 2)
        ->assertJsonFragment(['balance' => '80.0000']);
});

test('financial exception cards reuse scoped aging and cheque reports without terminal overlap', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 9, 17, 10));
    $fixture = financeReportFixture($this);
    $actor = financeReportActor();
    $customer = Customer::query()->create([
        'doc_number' => 9710, 'doc_num' => 'CUS-9710', 'company_id' => $fixture['company']->getKey(),
        'name' => 'Dashboard Customer', 'status' => 'active',
    ]);
    $supplier = Supplier::query()->create([
        'doc_number' => 9710, 'doc_num' => 'SUP-9710', 'company_id' => $fixture['company']->getKey(),
        'name' => 'Dashboard Supplier', 'status' => 'active',
    ]);

    $overdueReceivable = CustomerInvoice::query()->create([
        'doc_number' => 9710, 'doc_num' => 'SINV-OVERDUE', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $customer->getKey(), 'invoice_date' => '2026-08-01', 'due_date' => '2026-09-01',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 100, 'remaining_amount' => 60,
        'status' => CustomerInvoice::StatusPosted, 'posting_status' => CustomerInvoice::StatusPosted,
        'document_type' => CustomerInvoice::TypeInvoice,
    ]);
    $overdueReceivable->paymentSchedules()->create([
        'sequence' => 1, 'due_date' => '2026-09-01', 'amount' => 100, 'collected_amount' => 40, 'credited_amount' => 0,
    ]);
    $futureReceivable = CustomerInvoice::query()->create([
        'doc_number' => 9711, 'doc_num' => 'SINV-FUTURE', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $customer->getKey(), 'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 100, 'remaining_amount' => 100,
        'status' => CustomerInvoice::StatusPosted, 'posting_status' => CustomerInvoice::StatusPosted,
        'document_type' => CustomerInvoice::TypeInvoice,
    ]);
    $futureReceivable->paymentSchedules()->create([
        'sequence' => 1, 'due_date' => '2026-10-01', 'amount' => 100, 'collected_amount' => 0, 'credited_amount' => 0,
    ]);
    $dueReceivable = CustomerInvoice::query()->create([
        'doc_number' => 9712, 'doc_num' => 'SINV-DUE-TODAY', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $customer->getKey(), 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-17',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 30, 'remaining_amount' => 30,
        'status' => CustomerInvoice::StatusPosted, 'posting_status' => CustomerInvoice::StatusPosted,
        'document_type' => CustomerInvoice::TypeInvoice,
    ]);
    $dueReceivable->paymentSchedules()->create([
        'sequence' => 1, 'due_date' => '2026-09-17', 'amount' => 30, 'collected_amount' => 0, 'credited_amount' => 0,
    ]);
    $overduePayable = PurchaseInvoice::query()->create([
        'doc_number' => 9710, 'doc_num' => 'PINV-OVERDUE', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $supplier->getKey(), 'invoice_date' => '2026-08-01',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 80, 'remaining_amount' => 50,
        'status' => PurchaseInvoice::StatusApproved, 'payment_status' => 'partially_paid',
    ]);
    $overduePayable->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'due_date' => '2026-09-01', 'amount' => 80, 'paid_amount' => 30,
        'credited_amount' => 0, 'status' => PurchaseInvoicePaymentSchedule::StatusPartiallyPaid,
    ]);
    $futurePayable = PurchaseInvoice::query()->create([
        'doc_number' => 9711, 'doc_num' => 'PINV-FUTURE', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $supplier->getKey(), 'invoice_date' => '2026-09-01',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 80, 'remaining_amount' => 80,
        'status' => PurchaseInvoice::StatusApproved, 'payment_status' => 'unpaid',
    ]);
    $futurePayable->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'due_date' => '2026-10-01', 'amount' => 80, 'paid_amount' => 0,
        'credited_amount' => 0, 'status' => PurchaseInvoicePaymentSchedule::StatusScheduled,
    ]);
    $duePayable = PurchaseInvoice::query()->create([
        'doc_number' => 9712, 'doc_num' => 'PINV-DUE-TODAY', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $supplier->getKey(), 'invoice_date' => '2026-09-01',
        'currency_id' => $fixture['currency']->getKey(), 'total_amount' => 20, 'remaining_amount' => 20,
        'status' => PurchaseInvoice::StatusApproved, 'payment_status' => 'unpaid',
    ]);
    $duePayable->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'due_date' => '2026-09-17', 'amount' => 20, 'paid_amount' => 0,
        'credited_amount' => 0, 'status' => PurchaseInvoicePaymentSchedule::StatusScheduled,
    ]);

    foreach ([
        ['number' => 9710, 'document' => 'CHQ-DUE', 'status' => Cheque::StatusReceived, 'due' => '2026-09-17'],
        ['number' => 9711, 'document' => 'CHQ-RETURNED', 'status' => Cheque::StatusReturned, 'due' => '2026-09-10'],
        ['number' => 9712, 'document' => 'CHQ-CLEARED', 'status' => Cheque::StatusCleared, 'due' => '2026-09-10'],
    ] as $cheque) {
        Cheque::query()->create([
            'doc_number' => $cheque['number'], 'doc_num' => $cheque['document'], 'company_id' => $fixture['company']->getKey(),
            'cheque_type' => Cheque::TypeReceived, 'cheque_number' => (string) $cheque['number'],
            'cheque_date' => '2026-08-01', 'due_date' => $cheque['due'], 'currency_id' => $fixture['currency']->getKey(),
            'amount' => 25, 'amount_base' => 25, 'reason' => 'Dashboard exception fixture', 'status' => $cheque['status'],
        ]);
    }
    $otherCompany = Company::query()->create([
        'doc_number' => 9799, 'doc_num' => 'COMP-9799', 'name' => 'Excluded Finance Company', 'status' => 'active', 'is_main' => false,
    ]);
    $otherCurrency = Currency::query()->create([
        'doc_number' => 9799, 'doc_num' => 'CUR-9799', 'company_id' => $otherCompany->getKey(), 'name' => 'Excluded Currency',
        'name_en' => 'Excluded Currency', 'code' => 'XFC', 'minor_unit_name' => 'Unit', 'minor_unit_factor' => 100, 'is_main' => true, 'status' => 'active',
    ]);
    Cheque::query()->create([
        'doc_number' => 9799, 'doc_num' => 'CHQ-OTHER-COMPANY', 'company_id' => $otherCompany->getKey(),
        'cheque_type' => Cheque::TypeReceived, 'cheque_number' => '9799', 'cheque_date' => '2026-08-01',
        'due_date' => '2026-09-17', 'currency_id' => $otherCurrency->getKey(), 'amount' => 25, 'amount_base' => 25,
        'reason' => 'Wrong-company exclusion fixture', 'status' => Cheque::StatusReceived,
    ]);

    $base = [
        'as_of_date' => '2026-09-17', 'branch_id' => $fixture['branch']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
    ];
    $service = app(FinanceReportService::class);
    $receivables = $service->report([...$base, 'type' => FinanceReportService::CustomerAging, 'due_state' => 'due_or_overdue']);
    $payables = $service->report([...$base, 'type' => FinanceReportService::SupplierAging, 'due_state' => 'due_or_overdue']);
    $dueCheques = $service->report([...$base, 'type' => FinanceReportService::DueCheques, 'to_date' => '2026-09-17']);
    $returnedCheques = $service->report([...$base, 'type' => FinanceReportService::ReturnedCheques]);
    $pendingApprovals = $service->report([...$base, 'type' => FinanceReportService::UnapprovedDocuments]);

    expect($receivables['rows']->pluck('document')->all())->toBe(['SINV-OVERDUE', 'SINV-DUE-TODAY'])
        ->and($payables['rows']->pluck('document')->all())->toBe(['PINV-OVERDUE', 'PINV-DUE-TODAY'])
        ->and($service->report([...$base, 'type' => FinanceReportService::CustomerAging, 'due_state' => 'due'])['rows']->pluck('document')->all())->toBe(['SINV-DUE-TODAY'])
        ->and($service->report([...$base, 'type' => FinanceReportService::SupplierAging, 'due_state' => 'due'])['rows']->pluck('document')->all())->toBe(['PINV-DUE-TODAY'])
        ->and($dueCheques['rows']->pluck('document')->all())->toBe(['CHQ-DUE'])
        ->and($returnedCheques['rows']->pluck('document')->all())->toBe(['CHQ-RETURNED'])
        ->and($pendingApprovals['rows']->pluck('document')->all())->toBe(['CRV-9702-DRAFT']);

    $dashboard = $this->actingAs($actor)->get(route('dashboard'))->assertOk()->getContent();
    $receivablesReport = $this->actingAs($actor)->get(route('admin.reports.finance.index', [...$base, 'type' => FinanceReportService::CustomerAging, 'due_state' => 'due_or_overdue']))->assertOk()->getContent();
    $payablesReport = $this->actingAs($actor)->get(route('admin.reports.finance.index', [...$base, 'type' => FinanceReportService::SupplierAging, 'due_state' => 'due_or_overdue']))->assertOk()->getContent();
    $dueChequesReport = $this->actingAs($actor)->get(route('admin.reports.finance.index', [...$base, 'type' => FinanceReportService::DueCheques, 'to_date' => '2026-09-17']))->assertOk()->getContent();
    $returnedChequesReport = $this->actingAs($actor)->get(route('admin.reports.finance.index', [...$base, 'type' => FinanceReportService::ReturnedCheques]))->assertOk()->getContent();
    $pendingApprovalsReport = $this->actingAs($actor)->get(route('admin.reports.finance.index', [...$base, 'type' => FinanceReportService::UnapprovedDocuments]))->assertOk()->getContent();
    expect(financeDashboardCount($dashboard, 'pending_finance_approvals'))->toBe(1)
        ->and(financeDashboardCount($dashboard, 'pending_finance_approvals'))->toBe(financeReportCount($pendingApprovalsReport, 'pending_finance_approvals'))
        ->and(financeDashboardCount($dashboard, 'due_receivables'))->toBe($receivables['rows']->count())
        ->and(financeDashboardCount($dashboard, 'due_receivables'))->toBe(financeReportCount($receivablesReport, 'due_receivables'))
        ->and(financeDashboardCount($dashboard, 'due_payables'))->toBe($payables['rows']->count())
        ->and(financeDashboardCount($dashboard, 'due_payables'))->toBe(financeReportCount($payablesReport, 'due_payables'))
        ->and(financeDashboardCount($dashboard, 'due_cheques'))->toBe($dueCheques['rows']->count())
        ->and(financeDashboardCount($dashboard, 'due_cheques'))->toBe(financeReportCount($dueChequesReport, 'due_cheques'))
        ->and(financeDashboardCount($dashboard, 'returned_cheques'))->toBe($returnedCheques['rows']->count())
        ->and(financeDashboardCount($dashboard, 'returned_cheques'))->toBe(financeReportCount($returnedChequesReport, 'returned_cheques'));

    Carbon::setTestNow();
});
