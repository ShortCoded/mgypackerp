<?php

use App\Models\User;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Services\FinanceReportService;
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
