<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\FundTransfer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function fundTransferFeatureActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency}
 */
function fundTransferFeatureSeedFoundation(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();

    test()->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return compact('company', 'branch', 'period', 'currency');
}

function fundTransferFeatureUsd(Company $company): Currency
{
    return Currency::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('currencies', Currency::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => 'US Dollar',
        'code' => 'USD',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'is_main' => false,
        'status' => 'active',
    ]);
}

function fundTransferFeatureChildAccount(Company $company, string $parentCode, string $accountCode, string $name): Account
{
    $parent = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $parentCode)
        ->firstOrFail();

    return Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => $name,
        'name_en' => $name,
        'parent_id' => $parent->getKey(),
        'level' => ((int) $parent->level) + 1,
        'account_classification_id' => $parent->account_classification_id,
        'account_type' => $parent->account_type,
        'statement_type' => $parent->statement_type,
        'normal_balance' => $parent->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'is_system' => false,
        'status' => 'active',
    ]);
}

/**
 * @param  list<Currency>  $currencies
 */
function fundTransferFeatureCashbox(Company $company, Branch $branch, array $currencies, string $name = 'Transfer Cashbox'): Cashbox
{
    static $sequence = 60;

    $sequence++;

    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => $name.' '.$sequence,
        'branch_id' => $branch->getKey(),
        'account_id' => fundTransferFeatureChildAccount($company, '1111', '1111'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), $name.' Account')->getKey(),
        'status' => 'active',
    ]);

    foreach ($currencies as $currency) {
        CashboxCurrency::query()->create([
            'cashbox_id' => $cashbox->getKey(),
            'currency_id' => $currency->getKey(),
            'status' => 'active',
        ]);
    }

    return $cashbox->refresh();
}

function fundTransferFeatureBankAccount(Company $company, Currency $currency, string $name = 'Transfer Bank'): BankAccount
{
    static $sequence = 70;

    $sequence++;

    $bankGroup = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', '1112')
        ->firstOrFail();
    $linkedAccount = fundTransferFeatureChildAccount($company, '1112', '1112'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), $name.' '.$currency->code);

    $bankAccount = BankAccount::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'bank_id' => $bankGroup->getKey(),
        'account_id' => $linkedAccount->getKey(),
        'currency_id' => $currency->getKey(),
        'account_name' => $name.' '.$currency->code,
        'account_number' => 'FT-BA-'.$sequence,
        'status' => 'active',
    ]);

    $bankAccount->forceFill(['bank_name' => $name])->save();

    return $bankAccount->refresh();
}

function fundTransferFeaturePayload(string $sourceType, mixed $source, Currency $sourceCurrency, string $targetType, mixed $target, Currency $targetCurrency, array $overrides = []): array
{
    $sourceAmount = (float) ($overrides['source_amount'] ?? 100);
    $sameCurrency = (int) $sourceCurrency->getKey() === (int) $targetCurrency->getKey();
    $exchangeRate = $sameCurrency ? 1 : 30;
    $targetAmount = $sameCurrency ? $sourceAmount : $sourceAmount * $exchangeRate;

    return [
        'transfer_date' => '2026-06-18',
        'source_type' => $sourceType,
        'source_cashbox_doc_num' => $sourceType === FundTransfer::HolderCashbox ? $source->doc_num : null,
        'source_bank_account_doc_num' => $sourceType === FundTransfer::HolderBankAccount ? $source->doc_num : null,
        'target_type' => $targetType,
        'target_cashbox_doc_num' => $targetType === FundTransfer::HolderCashbox ? $target->doc_num : null,
        'target_bank_account_doc_num' => $targetType === FundTransfer::HolderBankAccount ? $target->doc_num : null,
        'source_currency_doc_num' => $sourceCurrency->doc_num,
        'target_currency_doc_num' => $targetCurrency->doc_num,
        'source_amount' => $sourceAmount,
        'exchange_rate' => $exchangeRate,
        'target_amount' => $targetAmount,
        'reason' => 'Feature transfer test',
        'description' => 'No balance or posting side effects',
        ...$overrides,
    ];
}

function fundTransferFeaturePermissions(): array
{
    return [
        'fund_transfers.view',
        'fund_transfers.create',
        'fund_transfers.clone',
        'fund_transfers.edit',
        'fund_transfers.delete',
        'fund_transfers.view_trashed',
        'fund_transfers.restore',
        'fund_transfers.document_number.control',
        'fund_transfers.document_number_settings.update',
        'fund_transfers.approve',
        'fund_transfers.cancel',
        'fund_transfers.print',
    ];
}

test('Finance FundTransfer permissions are discovered by the permission registry', function (): void {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $actions = [
        'view',
        'create',
        'clone',
        'edit',
        'delete',
        'view_trashed',
        'restore',
        'document_number.control',
        'document_number_settings.update',
        'approve',
        'cancel',
        'print',
    ];

    foreach ($actions as $action) {
        expect(Permission::query()->where('name', "fund_transfers.{$action}")->exists())->toBeTrue()
            ->and($admin->hasPermissionTo("fund_transfers.{$action}"))->toBeTrue();
    }
});

test('Finance FundTransfer creates all cashbox and bank transfer directions without balances', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $usd = fundTransferFeatureUsd($company);
    $actor = fundTransferFeatureActor(['fund_transfers.create']);
    $egpCashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'EGP Cashbox');
    $usdCashbox = fundTransferFeatureCashbox($company, $branch, [$usd], 'USD Cashbox');
    $egpBankOne = fundTransferFeatureBankAccount($company, $egp, 'EGP Bank One');
    $egpBankTwo = fundTransferFeatureBankAccount($company, $egp, 'EGP Bank Two');
    $usdBank = fundTransferFeatureBankAccount($company, $usd, 'USD Bank');

    $cashboxToBank = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderBankAccount, $egpBankOne, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $bankToCashbox = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderBankAccount, $egpBankOne, $egp, FundTransfer::HolderCashbox, $egpCashbox, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $bankToBank = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderBankAccount, $egpBankTwo, $egp, FundTransfer::HolderBankAccount, $usdBank, $usd))
        ->assertOk()
        ->json('data.doc_num');

    $cashboxToCashbox = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderCashbox, $usdCashbox, $usd))
        ->assertOk()
        ->json('data.doc_num');

    expect($cashboxToBank)->toBe('FTR-00001')
        ->and($bankToCashbox)->toBe('FTR-00002')
        ->and($bankToBank)->toBe('FTR-00003')
        ->and($cashboxToCashbox)->toBe('FTR-00004')
        ->and(FundTransfer::query()->where('doc_num', $bankToBank)->firstOrFail()->target_amount)->toBe('3000.0000');
});

test('Finance FundTransfer same source and target is allowed only when currency differs', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $usd = fundTransferFeatureUsd($company);
    $actor = fundTransferFeatureActor(['fund_transfers.create']);
    $multiCurrencyCashbox = fundTransferFeatureCashbox($company, $branch, [$egp, $usd], 'Multi Currency Cashbox');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $multiCurrencyCashbox, $egp, FundTransfer::HolderCashbox, $multiCurrencyCashbox, $usd))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $multiCurrencyCashbox, $egp, FundTransfer::HolderCashbox, $multiCurrencyCashbox, $egp))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_currency_doc_num']);
});

test('Finance FundTransfer currency amount rules enforce same currency and cross currency math', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $usd = fundTransferFeatureUsd($company);
    $actor = fundTransferFeatureActor(['fund_transfers.create']);
    $egpCashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Rule EGP Cashbox');
    $usdCashbox = fundTransferFeatureCashbox($company, $branch, [$usd], 'Rule USD Cashbox');
    $egpBank = fundTransferFeatureBankAccount($company, $egp, 'Rule EGP Bank');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderBankAccount, $egpBank, $egp, [
            'exchange_rate' => 2,
            'target_amount' => 200,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderBankAccount, $egpBank, $egp, [
            'exchange_rate' => 1,
            'target_amount' => 50,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_amount']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderCashbox, $usdCashbox, $usd, [
            'exchange_rate' => 0,
            'target_amount' => 100,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderCashbox, $usdCashbox, $usd, [
            'exchange_rate' => 30,
            'target_amount' => 2000,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target_amount']);

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $egpCashbox, $egp, FundTransfer::HolderCashbox, $usdCashbox, $usd, [
            'exchange_rate' => 30,
            'target_amount' => 3000,
        ]))
        ->assertOk()
        ->json('data.doc_num');

    expect(FundTransfer::query()->where('doc_num', $docNum)->firstOrFail()->exchange_rate)->toBe('30.000000');
});

test('Finance FundTransfer approve and cancel lock transfers', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $actor = fundTransferFeatureActor(fundTransferFeaturePermissions());
    $cashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Lock Cashbox');
    $bankAccount = fundTransferFeatureBankAccount($company, $egp, 'Lock Bank');

    $approvedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.fund-transfers.approve', $approvedDocNum))->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.fund-transfers.update', $approvedDocNum), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp, ['reason' => 'Changed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $cancelledDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.cancel', $cancelledDocNum), ['cancel_reason' => 'Transfer voided'])
        ->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.fund-transfers.update', $cancelledDocNum), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp, ['reason' => 'Changed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect(FundTransfer::query()->where('doc_num', $approvedDocNum)->firstOrFail()->status)->toBe(FundTransfer::StatusApproved)
        ->and(FundTransfer::query()->where('doc_num', $cancelledDocNum)->firstOrFail()->status)->toBe(FundTransfer::StatusCancelled);
});

test('Finance FundTransfer approval cannot race a closed financial period', function (): void {
    ['company' => $company, 'branch' => $branch, 'period' => $period, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $actor = fundTransferFeatureActor(fundTransferFeaturePermissions());
    $cashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Period Lock Cashbox');
    $bankAccount = fundTransferFeatureBankAccount($company, $egp, 'Period Lock Bank');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $period->forceFill(['is_closed' => true])->save();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.approve', $docNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect(FundTransfer::query()->where('doc_num', $docNum)->firstOrFail()->status)->toBe(FundTransfer::StatusDraft);
});

test('Finance FundTransfer soft delete restore works for drafts and approved delete is blocked', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $actor = fundTransferFeatureActor(fundTransferFeaturePermissions());
    $cashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Delete Cashbox');
    $bankAccount = fundTransferFeatureBankAccount($company, $egp, 'Delete Bank');

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->deleteJson(route('admin.finance.fund-transfers.destroy', $docNum))->assertOk();

    $deleted = FundTransfer::withTrashed()->where('doc_num', $docNum)->firstOrFail();

    expect($deleted->trashed())->toBeTrue();

    $this->actingAs($actor)->patchJson(route('admin.finance.fund-transfers.restore', $docNum))->assertOk();

    expect($deleted->refresh()->trashed())->toBeFalse()
        ->and($deleted->restored_by)->toBe($actor->getKey())
        ->and($deleted->restored_at)->not->toBeNull();

    $approvedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.fund-transfers.approve', $approvedDocNum))->assertOk();

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.fund-transfers.destroy', $approvedDocNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);
});

test('Finance FundTransfer datatable returns expected columns without internal ids', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $actor = fundTransferFeatureActor(fundTransferFeaturePermissions());
    $cashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Data Cashbox');
    $bankAccount = fundTransferFeatureBankAccount($company, $egp, 'Data Bank');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk();

    $row = $this->actingAs($actor)
        ->getJson(route('admin.finance.fund-transfers.data'))
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKeys([
        'checkbox',
        'doc_num',
        'transfer_date',
        'source',
        'source_currency',
        'source_amount',
        'target',
        'target_currency',
        'target_amount',
        'exchange_rate',
        'status',
        'reason',
        'created_by',
        'updated_by',
        'actions',
    ])->not->toHaveKeys([
        'id',
        'company_id',
        'source_cashbox_id',
        'source_bank_account_id',
        'target_cashbox_id',
        'target_bank_account_id',
        'source_currency_id',
        'target_currency_id',
    ]);

    expect($row['checkbox'])->toContain('<input')
        ->and($row['doc_num'])->toContain('<a class="fw-semibold dt-code-value"')
        ->and($row['source'])->toContain('dt-ellipsis-content')
        ->and($row['target'])->toContain('dt-ellipsis-content')
        ->and($row['status'])->toContain('<span')
        ->and($row['reason'])->toContain('dt-ellipsis-content')
        ->and($row['created_by'])->toContain('dt-ellipsis-content')
        ->and($row['updated_by'])->toContain('dt-ellipsis-content')
        ->and($row['actions'])->toContain('dropdown')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;span')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;a')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;div');
});

test('Finance FundTransfer form uses the supported standard save dropdown actions', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = fundTransferFeatureSeedFoundation();
    $actor = fundTransferFeatureActor(fundTransferFeaturePermissions());
    $cashbox = fundTransferFeatureCashbox($company, $branch, [$egp], 'Save Action Cashbox');
    $bankAccount = fundTransferFeatureBankAccount($company, $egp, 'Save Action Bank');

    $createHtml = $this->actingAs($actor)
        ->get(route('admin.finance.fund-transfers.create'))
        ->assertOk()
        ->getContent();

    expect($createHtml)->toContain('class="btn btn-primary js-finance-submit-action" data-submit-action="save_new"')
        ->and($createHtml)->toContain('class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save"')
        ->and($createHtml)->not->toContain('btn-falcon-primary btn-sm js-finance-submit-action');

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.fund-transfers.store'), fundTransferFeaturePayload(FundTransfer::HolderCashbox, $cashbox, $egp, FundTransfer::HolderBankAccount, $bankAccount, $egp))
        ->assertOk()
        ->json('data.doc_num');

    $editHtml = $this->actingAs($actor)
        ->get(route('admin.finance.fund-transfers.edit', $docNum))
        ->assertOk()
        ->getContent();

    expect($editHtml)->toContain('class="btn btn-primary btn-sm js-finance-submit-action" data-submit-action="save"')
        ->and($editHtml)->not->toContain('data-submit-action="save_new"');
});
