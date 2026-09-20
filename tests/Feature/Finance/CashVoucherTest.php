<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\FinanceReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

function cashVoucherActor(array $permissions): User
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
function cashVoucherSeedFoundation(): array
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

function cashVoucherLinkedCashAccount(Company $company, string $accountCode = '111101'): Account
{
    $parent = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', '1111')
        ->firstOrFail();

    return Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => 'Test Cashbox Account',
        'name_en' => 'Test Cashbox Account',
        'parent_id' => $parent->getKey(),
        'level' => ((int) $parent->level) + 1,
        'account_classification_id' => $parent->account_classification_id,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'is_system' => false,
        'status' => 'active',
    ]);
}

function cashVoucherPostableAccount(Company $company, string $accountCode): Account
{
    return Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $accountCode)
        ->where('is_postable', true)
        ->where('is_group', false)
        ->where('status', 'active')
        ->firstOrFail();
}

/**
 * @param  list<Currency>  $currencies
 */
function cashVoucherCashbox(Company $company, Branch $branch, array $currencies, string $name = 'Main Cashbox'): Cashbox
{
    static $accountSequence = 10;

    $accountSequence++;

    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => $name,
        'branch_id' => $branch->getKey(),
        'account_id' => cashVoucherLinkedCashAccount($company, '1111'.str_pad((string) $accountSequence, 2, '0', STR_PAD_LEFT))->getKey(),
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

function cashVoucherUsd(Company $company): Currency
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

function cashVoucherPayload(Cashbox $cashbox, Currency $currency, Account $lineAccount, array $overrides = []): array
{
    return [
        'voucher_date' => '2026-06-18',
        'cashbox_doc_num' => $cashbox->doc_num,
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => $currency->is_main ? 1 : 30.5,
        'amount' => 100,
        'person_name' => 'Feature Test Person',
        'person_national_id' => '29901011234567',
        'person_phone' => '+201001112223',
        'reason' => 'Test voucher',
        'description' => 'Created by feature test',
        'lines' => [
            [
                'account_doc_num' => $lineAccount->doc_num,
                'amount' => 100,
                'description' => 'Distribution',
                'notes' => 'Line note',
            ],
        ],
        ...$overrides,
    ];
}

function cashVoucherAuthorizationImage(Company $company, int $documentNumber, string $docNum, string $fileName): ArchiveFile
{
    $path = 'tests/cash-voucher-authorization/'.$fileName;
    Storage::disk('public')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAKAAAAAyCAIAAABUA0cyAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABR0lEQVR4nO3bUY6CMBgA4XWz91hvocfYPSnX4BgcxYcmTfNTaolFzTjfk8GChBGoJJ5+L39f4vp+9Q7oWAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAxnYDgDwxkYzsBwBob76Rm0zFN1+fn6H8aUS6rrpgF3N1gOqC6sbrBnfz5NV+CkcbDyoV/mqXGUl3lKA0KzsOVyYV6luhvrdxUMuETnHuHsXMfrKRHWap/xYcuNj/5Yjwbe2+O4g16e8dbNdlyiq/fF56ve1PNr6wZj7sEv8W778552BG64e48caGvypaoxv4PTDKucHm8Z9VXonHzpwAcd6wY9PfrnwzbuMeYSvSXNevbOzsJaXocfcfLPZ2w+i4YzMJyB4QwMZ2A4A8MZGM7AcAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAx3A4Npkgj1aQnLAAAAAElFTkSuQmCC'));

    return ArchiveFile::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => $docNum,
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'core',
        'record_type' => 'company_authorization',
        'hidden_from_picker' => false,
        'original_name' => $fileName,
        'stored_name' => $fileName,
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 13,
    ]);
}

test('CashVoucher permissions are discovered for receipt and payment vouchers', function (): void {
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

    foreach (['cash_receipt_vouchers', 'cash_payment_vouchers'] as $prefix) {
        foreach ($actions as $action) {
            expect(Permission::query()->where('name', "{$prefix}.{$action}")->exists())->toBeTrue()
                ->and($admin->hasPermissionTo("{$prefix}.{$action}"))->toBeTrue();
        }
    }
});

test('CashVoucher receipt draft distribution approval lock and cancellation rules work', function (): void {
    ['company' => $company, 'branch' => $branch, 'period' => $period, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.view',
        'cash_receipt_vouchers.create',
        'cash_receipt_vouchers.edit',
        'cash_receipt_vouchers.delete',
        'cash_receipt_vouchers.restore',
        'cash_receipt_vouchers.approve',
        'cash_receipt_vouchers.cancel',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Receipt Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount, [
            'amount' => 100,
            'lines' => [
                ['account_doc_num' => $lineAccount->doc_num, 'amount' => 60],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'CRV-00001');

    $voucher = CashVoucher::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($voucher->voucher_type)->toBe(CashVoucher::TypeReceipt)
        ->and($voucher->status)->toBe(CashVoucher::StatusDraft)
        ->and($voucher->person_name)->toBe('Feature Test Person')
        ->and($voucher->person_national_id)->toBe('29901011234567')
        ->and($voucher->person_phone)->toBe('+201001112223');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cash-receipt-vouchers.update', $voucher->doc_num), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $voucher->refresh();

    expect($voucher->status)->toBe(CashVoucher::StatusApproved)
        ->and($voucher->approved_by)->toBe($actor->getKey())
        ->and($voucher->approved_at)->not->toBeNull();

    $journal = JournalEntry::query()
        ->where('source_type', CashVoucherService::SourceReceipt)
        ->where('source_id', $voucher->getKey())
        ->with('lines')
        ->sole();
    expect($journal->company_id)->toBe($company->getKey())
        ->and($journal->financial_period_id)->toBe($period->getKey())
        ->and($journal->branch_id)->toBe($branch->getKey())
        ->and($journal->currency_id)->toBe($egp->getKey())
        ->and($journal->exchange_rate)->toBe('1.000000')
        ->and($journal->posted_by)->toBe($actor->getKey())
        ->and($journal->source_doc_num)->toBe($voucher->doc_num)
        ->and($journal->lines)->toHaveCount(2)
        ->and($journal->lines->firstWhere('account_id', $cashbox->account_id)?->debit_amount)->toBe('100.0000')
        ->and($journal->lines->firstWhere('account_id', $cashbox->account_id)?->credit_amount)->toBe('0.0000')
        ->and($journal->lines->firstWhere('account_id', $lineAccount->getKey())?->credit_amount)->toBe('100.0000');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertOk();
    expect(JournalEntry::query()->where('source_type', CashVoucherService::SourceReceipt)->where('source_id', $voucher->getKey())->count())->toBe(1);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cash-receipt-vouchers.update', $voucher->doc_num), cashVoucherPayload($cashbox, $egp, $lineAccount, ['reason' => 'Changed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'Wrong receipt'])
        ->assertOk()
        ->assertJsonPath('success', true);

    $voucher->refresh();

    expect($voucher->status)->toBe(CashVoucher::StatusCancelled)
        ->and($voucher->cancelled_by)->toBe($actor->getKey())
        ->and($voucher->cancel_reason)->toBe('Wrong receipt');

    $reversal = JournalEntry::query()
        ->where('source_type', CashVoucherService::SourceReceiptReversal)
        ->where('source_id', $voucher->getKey())
        ->with('lines')
        ->sole();
    expect($journal->refresh()->reversed_entry_id)->toBe($reversal->getKey())
        ->and($reversal->lines->firstWhere('account_id', $cashbox->account_id)?->credit_amount)->toBe('100.0000')
        ->and(JournalEntry::query()->whereIn('source_type', [CashVoucherService::SourceReceipt, CashVoucherService::SourceReceiptReversal])->where('source_id', $voucher->getKey())->count())->toBe(2);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'Repeated'])
        ->assertUnprocessable();
    expect(JournalEntry::query()->where('source_type', CashVoucherService::SourceReceiptReversal)->where('source_id', $voucher->getKey())->count())->toBe(1);
});

test('CashVoucher payment draft distribution approval lock and cancellation rules work', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_payment_vouchers.view',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.edit',
        'cash_payment_vouchers.delete',
        'cash_payment_vouchers.restore',
        'cash_payment_vouchers.approve',
        'cash_payment_vouchers.cancel',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Payment Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '521');

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'CPV-00001');

    $voucher = CashVoucher::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($voucher->voucher_type)->toBe(CashVoucher::TypePayment)
        ->and($voucher->amount)->toBe('100.0000')
        ->and($voucher->person_name)->toBe('Feature Test Person')
        ->and((float) $voucher->lines()->sum('amount'))->toBe(100.0);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.approve', $voucher->doc_num))
        ->assertOk();

    $journal = JournalEntry::query()
        ->where('source_type', CashVoucherService::SourcePayment)
        ->where('source_id', $voucher->getKey())
        ->with('lines')
        ->sole();
    expect($journal->lines)->toHaveCount(2)
        ->and($journal->lines->firstWhere('account_id', $lineAccount->getKey())?->debit_amount)->toBe('100.0000')
        ->and($journal->lines->firstWhere('account_id', $cashbox->account_id)?->credit_amount)->toBe('100.0000');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.approve', $voucher->doc_num))
        ->assertOk();
    expect(JournalEntry::query()->where('source_type', CashVoucherService::SourcePayment)->where('source_id', $voucher->getKey())->count())->toBe(1);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cash-payment-vouchers.update', $voucher->doc_num), cashVoucherPayload($cashbox, $egp, $lineAccount, ['amount' => 120]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'Payment voided'])
        ->assertOk();

    $voucher->refresh();
    $reversal = JournalEntry::query()
        ->where('source_type', CashVoucherService::SourcePaymentReversal)
        ->where('source_id', $voucher->getKey())
        ->with('lines')
        ->sole();
    expect($voucher->status)->toBe(CashVoucher::StatusCancelled)
        ->and($journal->refresh()->reversed_entry_id)->toBe($reversal->getKey())
        ->and($reversal->lines->firstWhere('account_id', $cashbox->account_id)?->debit_amount)->toBe('100.0000');
});

test('direct cash voucher actions enforce branch original period and cancellation posting period scopes', function (): void {
    ['company' => $company, 'branch' => $branch, 'period' => $period, 'currency' => $egp] = cashVoucherSeedFoundation();
    $permissions = [
        'cash_payment_vouchers.view',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.edit',
        'cash_payment_vouchers.approve',
        'cash_payment_vouchers.cancel',
        'accounts.view',
    ];
    $owner = cashVoucherActor($permissions);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Scoped Payment Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '521');
    $docNum = $this->actingAs($owner)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $otherBranch = Branch::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('branches', Branch::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => 'Forbidden Voucher Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $outsidePeriod = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('financial_periods', FinancialPeriod::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => 'Voucher Cancellation 2027',
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);

    $wrongBranch = cashVoucherActor($permissions);
    $wrongBranchRole = Role::query()->create([
        'name' => 'Wrong Voucher Branch '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $wrongBranchRole->companyAccessCompanies()->sync([$company->getKey()]);
    $wrongBranchRole->branchAccessBranches()->sync([$otherBranch->getKey()]);
    $wrongBranch->assignRole($wrongBranchRole);
    $this->actingAs($wrongBranch)->get(route('admin.finance.cash-payment-vouchers.show', $docNum))->assertNotFound();
    $this->get(route('admin.finance.cash-payment-vouchers.edit', $docNum))->assertNotFound();
    $this->postJson(route('admin.finance.cash-payment-vouchers.approve', $docNum))->assertNotFound();

    $wrongPeriod = cashVoucherActor($permissions);
    $wrongPeriodRole = Role::query()->create([
        'name' => 'Wrong Voucher Period '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $wrongPeriodRole->companyAccessCompanies()->sync([$company->getKey()]);
    $wrongPeriodRole->branchAccessBranches()->sync([$branch->getKey()]);
    $wrongPeriodRole->financialPeriodAccessPeriods()->sync([$outsidePeriod->getKey()]);
    $wrongPeriod->assignRole($wrongPeriodRole);
    $this->actingAs($wrongPeriod)->get(route('admin.finance.cash-payment-vouchers.show', $docNum))->assertNotFound();
    $this->get(route('admin.finance.cash-payment-vouchers.edit', $docNum))->assertNotFound();
    $this->postJson(route('admin.finance.cash-payment-vouchers.approve', $docNum))->assertNotFound();

    $this->actingAs($owner)->postJson(route('admin.finance.cash-payment-vouchers.approve', $docNum))->assertOk();
    $cancellationDocNum = $this->actingAs($owner)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount, [
            'voucher_date' => '2027-06-15',
        ]))
        ->assertOk()
        ->json('data.doc_num');
    $this->actingAs($owner)->postJson(route('admin.finance.cash-payment-vouchers.approve', $cancellationDocNum))->assertOk();

    $voucherPeriodOnly = cashVoucherActor($permissions);
    $voucherPeriodRole = Role::query()->create([
        'name' => 'Original Voucher Period '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => true,
    ]);
    $voucherPeriodRole->companyAccessCompanies()->sync([$company->getKey()]);
    $voucherPeriodRole->branchAccessBranches()->sync([$branch->getKey()]);
    $voucherPeriodRole->financialPeriodAccessPeriods()->sync([$outsidePeriod->getKey()]);
    $voucherPeriodOnly->assignRole($voucherPeriodRole);
    $this->actingAs($voucherPeriodOnly)
        ->withSession([
            OperatingContextService::CompanyIdKey => $company->getKey(),
            OperatingContextService::CompanyDocNumKey => $company->doc_num,
            OperatingContextService::BranchIdKey => $branch->getKey(),
            OperatingContextService::BranchDocNumKey => $branch->doc_num,
            OperatingContextService::FinancialPeriodIdKey => $outsidePeriod->getKey(),
            OperatingContextService::FinancialPeriodDocNumKey => $outsidePeriod->doc_num,
        ])
        ->postJson(route('admin.finance.cash-payment-vouchers.cancel', $cancellationDocNum), ['cancel_reason' => 'Forbidden cancellation period'])
        ->assertNotFound();
    expect(CashVoucher::query()->where('doc_num', $cancellationDocNum)->value('status'))->toBe(CashVoucher::StatusApproved);
});

test('CashVoucher approval cannot race a closed financial period', function (): void {
    ['company' => $company, 'branch' => $branch, 'period' => $period, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Period Lock Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '521');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');

    $period->forceFill(['is_closed' => true])->save();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.approve', $docNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect(CashVoucher::query()->where('doc_num', $docNum)->firstOrFail()->status)->toBe(CashVoucher::StatusDraft);
});

test('generic approval replay remains idempotent after period close and rejects legacy approved vouchers without journals', function (): void {
    ['company' => $company, 'branch' => $branch, 'period' => $period, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Replay Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $postedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $postedDocNum))
        ->assertOk();
    $posted = CashVoucher::query()->where('doc_num', $postedDocNum)->firstOrFail();

    $period->forceFill(['is_closed' => true])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $postedDocNum))
        ->assertOk();
    expect(JournalEntry::query()->where('source_type', CashVoucherService::SourceReceipt)->where('source_id', $posted->getKey())->count())->toBe(1);

    $period->forceFill(['is_closed' => false])->save();
    $legacyDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount, ['reason' => 'Legacy approved without journal']))
        ->assertOk()
        ->json('data.doc_num');
    $legacy = CashVoucher::query()->where('doc_num', $legacyDocNum)->firstOrFail();
    $legacy->forceFill([
        'status' => CashVoucher::StatusApproved,
        'approved_by' => $actor->getKey(),
        'approved_at' => now(),
    ])->save();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $legacyDocNum))
        ->assertUnprocessable();
    expect($legacy->refresh()->status)->toBe(CashVoucher::StatusApproved)
        ->and(JournalEntry::query()->where('source_type', CashVoucherService::SourceReceipt)->where('source_id', $legacy->getKey())->count())->toBe(0);
});

test('generic replay and cashbox report do not require the pending payroll table', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Schema Guard Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $docNum))
        ->assertOk();

    Schema::shouldReceive('hasTable')->with('hr_payroll_payments')->twice()->andReturnFalse();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $docNum))
        ->assertOk();
    $statement = app(FinanceReportService::class)->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'cashbox_doc_num' => $cashbox->doc_num,
    ]);
    expect($statement['rows'])->toHaveCount(1)
        ->and($statement['rows']->first()['document'])->toBe($docNum);
});

test('cashbox statement retains canonical branch history and represents prior-only closing balance', function (): void {
    ['company' => $company, 'branch' => $branchA, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $branchB = Branch::query()->create([
        'doc_number' => 9982,
        'doc_num' => 'BR-9982',
        'company_id' => $company->getKey(),
        'name' => 'Moved Cashbox Branch',
        'type' => 'branch',
        'status' => 'active',
    ]);
    $cashbox = cashVoucherCashbox($company, $branchA, [$egp], 'Historical Branch Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount, [
            'voucher_date' => '2026-06-30',
            'amount' => '75.0000',
            'lines' => [['account_doc_num' => $lineAccount->doc_num, 'amount' => '75.0000']],
        ]))
        ->assertOk()
        ->json('data.doc_num');
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $docNum))
        ->assertOk();

    $cashbox->forceFill(['branch_id' => $branchB->getKey()])->save();
    $service = app(FinanceReportService::class);
    $historical = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-06-30',
        'to_date' => '2026-06-30',
        'cashbox_doc_num' => $cashbox->doc_num,
        'branch_id' => $branchA->getKey(),
    ]);
    $movedBranch = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-06-30',
        'to_date' => '2026-06-30',
        'cashbox_doc_num' => $cashbox->doc_num,
        'branch_id' => $branchB->getKey(),
    ]);
    expect($historical['rows'])->toHaveCount(1)
        ->and($historical['rows']->first()['document'])->toBe($docNum)
        ->and($historical['rows']->first()['branch'])->toBe($branchA->name)
        ->and($historical['rows']->first()['balance'])->toBe('75.0000')
        ->and($movedBranch['rows'])->toBeEmpty();

    $priorOnly = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-31',
        'cashbox_doc_num' => $cashbox->doc_num,
        'branch_id' => $branchA->getKey(),
    ]);
    $balanceRow = $priorOnly['rows']->sole();
    expect($balanceRow['_is_balance_row'])->toBeTrue()
        ->and($balanceRow['document'])->toBe('')
        ->and($balanceRow['receipt'])->toBe('0.0000')
        ->and($balanceRow['payment'])->toBe('0.0000')
        ->and($balanceRow['opening_balance'])->toBe('75.0000')
        ->and($balanceRow['balance'])->toBe('75.0000')
        ->and($balanceRow['branch'])->toBe($branchA->name);

    $glBalance = DB::table('journal_entry_lines as lines')
        ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
        ->where('entries.company_id', $company->getKey())
        ->where('lines.account_id', $cashbox->account_id)
        ->selectRaw('COALESCE(SUM(lines.debit_amount - lines.credit_amount), 0) as balance')
        ->value('balance');
    expect(number_format((float) $glBalance, 4, '.', ''))->toBe($balanceRow['balance']);
});

test('generic cash vouchers reconcile statement opening movements closing and canonical GL without double counting', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.create',
        'cash_receipt_vouchers.approve',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.approve',
        'cash_payment_vouchers.cancel',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Reconciliation Cashbox');
    $unrelatedCashbox = cashVoucherCashbox($company, $branch, [$egp], 'Unrelated Cashbox');
    $receiptAccount = cashVoucherPostableAccount($company, '411');
    $paymentAccount = cashVoucherPostableAccount($company, '521');

    $createAndApprove = function (string $type, Cashbox $selectedCashbox, Account $account, string $date, string $amount) use ($actor, $egp): CashVoucher {
        $routePrefix = $type === CashVoucher::TypeReceipt ? 'cash-receipt-vouchers' : 'cash-payment-vouchers';
        $docNum = $this->actingAs($actor)
            ->postJson(route("admin.finance.{$routePrefix}.store"), cashVoucherPayload($selectedCashbox, $egp, $account, [
                'voucher_date' => $date,
                'amount' => $amount,
                'lines' => [['account_doc_num' => $account->doc_num, 'amount' => $amount]],
            ]))
            ->assertOk()
            ->json('data.doc_num');
        $this->actingAs($actor)
            ->postJson(route("admin.finance.{$routePrefix}.approve", $docNum))
            ->assertOk();

        return CashVoucher::query()->where('doc_num', $docNum)->firstOrFail();
    };

    $openingReceipt = $createAndApprove(CashVoucher::TypeReceipt, $cashbox, $receiptAccount, '2026-06-30', '25.0000');
    $periodReceipt = $createAndApprove(CashVoucher::TypeReceipt, $cashbox, $receiptAccount, '2026-07-01', '100.0000');
    $periodPayment = $createAndApprove(CashVoucher::TypePayment, $cashbox, $paymentAccount, '2026-07-02', '40.0000');
    $unrelatedReceipt = $createAndApprove(CashVoucher::TypeReceipt, $unrelatedCashbox, $receiptAccount, '2026-07-03', '700.0000');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.cancel', $periodPayment->doc_num), ['cancel_reason' => 'Reconcile reversal'])
        ->assertOk();

    $statement = app(FinanceReportService::class)->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-07-01',
        'to_date' => '2026-12-31',
        'cashbox_doc_num' => $cashbox->doc_num,
        'currency_doc_num' => $egp->doc_num,
        'branch_id' => $branch->getKey(),
    ]);
    $rows = $statement['rows'];
    expect($rows)->toHaveCount(3)
        ->and($rows->first()['opening_balance'])->toBe('25.0000')
        ->and($rows->last()['balance'])->toBe('125.0000')
        ->and($rows->where('document', $openingReceipt->doc_num))->toHaveCount(0)
        ->and($rows->where('document', $periodReceipt->doc_num))->toHaveCount(1)
        ->and($rows->where('document', $periodPayment->doc_num))->toHaveCount(2)
        ->and($rows->where('document', $unrelatedReceipt->doc_num))->toHaveCount(0);

    $glDebit = (string) DB::table('journal_entry_lines as lines')
        ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
        ->where('entries.company_id', $company->getKey())
        ->where('entries.status', JournalEntry::StatusPosted)
        ->where('entries.is_posted', true)
        ->whereDate('entries.entry_date', '<=', '2026-12-31')
        ->where('lines.account_id', $cashbox->account_id)
        ->sum('lines.debit_amount');
    $glCredit = (string) DB::table('journal_entry_lines as lines')
        ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
        ->where('entries.company_id', $company->getKey())
        ->where('entries.status', JournalEntry::StatusPosted)
        ->where('entries.is_posted', true)
        ->whereDate('entries.entry_date', '<=', '2026-12-31')
        ->where('lines.account_id', $cashbox->account_id)
        ->sum('lines.credit_amount');
    expect(bcsub($glDebit, $glCredit, 4))->toBe('125.0000');

    $noActivity = app(FinanceReportService::class)->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-01-01',
        'to_date' => '2026-01-31',
        'cashbox_doc_num' => $cashbox->doc_num,
    ]);
    expect($noActivity['rows'])->toBeEmpty();
});

test('generic cash voucher posting and reversal failures roll back voucher state atomically', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'cash_receipt_vouchers.cancel', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Atomic Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $voucher = CashVoucher::query()->where('doc_num', $docNum)->firstOrFail();

    $postingFailure = Mockery::mock(JournalEntryService::class);
    $postingFailure->shouldReceive('createPostedFromSource')->once()->andThrow(new RuntimeException('Injected posting failure'));
    $this->app->instance(JournalEntryService::class, $postingFailure);

    expect(fn () => app(CashVoucherService::class)->approveGeneric(CashVoucher::TypeReceipt, $voucher))->toThrow(RuntimeException::class, 'Injected posting failure');
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusDraft)
        ->and(JournalEntry::query()->where('source_id', $voucher->getKey())->count())->toBe(0);

    $this->app->forgetInstance(JournalEntryService::class);
    $voucher = app(CashVoucherService::class)->approveGeneric(CashVoucher::TypeReceipt, $voucher);
    $original = JournalEntry::query()->where('source_type', CashVoucherService::SourceReceipt)->where('source_id', $voucher->getKey())->sole();

    $reversalFailure = Mockery::mock(JournalEntryService::class);
    $reversalFailure->shouldReceive('createPostedReversalFromSource')->once()->andThrow(new RuntimeException('Injected reversal failure'));
    $this->app->instance(JournalEntryService::class, $reversalFailure);

    expect(fn () => app(CashVoucherService::class)->cancelGeneric(CashVoucher::TypeReceipt, $voucher, 'Injected failure'))->toThrow(RuntimeException::class, 'Injected reversal failure');
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusApproved)
        ->and($original->refresh()->reversed_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('source_type', CashVoucherService::SourceReceiptReversal)->where('source_id', $voucher->getKey())->count())->toBe(0);
});

test('specialized voucher entry points do not create generic journals and direct mutations require permissions', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $authorized = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'cash_receipt_vouchers.cancel', 'accounts.view']);
    $this->actingAs($authorized);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Owned Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $service = app(CashVoucherService::class);
    $voucher = $service->create(CashVoucher::TypeReceipt, cashVoucherPayload($cashbox, $egp, $lineAccount), $company->getKey())['record'];

    $service->approve(CashVoucher::TypeReceipt, $voucher, $company->getKey());
    expect(JournalEntry::query()->whereIn('source_type', [CashVoucherService::SourceReceipt, CashVoucherService::SourcePayment])->where('source_id', $voucher->getKey())->count())->toBe(0);

    $unauthorized = cashVoucherActor([]);
    $this->actingAs($unauthorized)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertForbidden();
    $this->actingAs($unauthorized)
        ->postJson(route('admin.finance.cash-receipt-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'Unauthorized'])
        ->assertForbidden();
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusApproved)
        ->and($voucher->cancelled_by)->toBeNull()
        ->and($voucher->approved_by)->toBe($authorized->getKey());
});

test('generic voucher approval rejects cross company cashbox branch and counter account drift', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Scope Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $voucher = CashVoucher::query()->where('doc_num', $docNum)->firstOrFail();
    $otherCompany = Company::query()->create([
        'doc_number' => 9981,
        'doc_num' => 'COMP-9981',
        'name' => 'Other Scope Company',
        'status' => 'active',
        'is_main' => false,
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 9981,
        'doc_num' => 'BR-9981',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Scope Branch',
        'type' => 'branch',
        'status' => 'active',
    ]);

    $cashbox->forceFill(['company_id' => $otherCompany->getKey()])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertUnprocessable();
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusDraft);

    $cashbox->forceFill(['company_id' => $company->getKey(), 'branch_id' => $otherBranch->getKey()])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertUnprocessable();
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusDraft);

    $cashbox->forceFill(['branch_id' => $branch->getKey()])->save();
    $lineAccount->forceFill(['company_id' => $otherCompany->getKey()])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertUnprocessable();
    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusDraft)
        ->and(JournalEntry::query()->where('source_id', $voucher->getKey())->count())->toBe(0);
});

test('CashVoucher receipt and payment prints use localized company authorization identity', function (): void {
    Storage::fake('public');

    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.create',
        'cash_receipt_vouchers.print',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.print',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Print Cashbox');
    $receiptAccount = cashVoucherPostableAccount($company, '411');
    $paymentAccount = cashVoucherPostableAccount($company, '521');
    $stamp = cashVoucherAuthorizationImage($company, 991, 'ARCH-PRINT-STAMP', 'stamp.png');
    $signature = cashVoucherAuthorizationImage($company, 992, 'ARCH-PRINT-SIGN', 'signature.png');

    $company->forceFill([
        'legal_name' => 'Printable Legal Company',
        'show_company_identity_on_prints' => true,
        'authorized_signatory_name' => 'Mona Ali',
        'authorized_signatory_title' => 'Authorized Director',
        'company_stamp_archive_file_id' => $stamp->getKey(),
        'authorized_signatory_signature_archive_file_id' => $signature->getKey(),
    ])->save();

    $receiptDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $receiptAccount))
        ->assertOk()
        ->json('data.doc_num');
    $paymentDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $paymentAccount))
        ->assertOk()
        ->json('data.doc_num');

    $actor->forceFill(['locale' => 'en'])->save();

    foreach (['en', 'ar'] as $locale) {
        $actor->forceFill(['locale' => $locale])->save();
        foreach ([true, false] as $showIdentity) {
            $company->forceFill(['show_company_identity_on_prints' => $showIdentity])->save();
            foreach ([
                ['route' => 'admin.finance.cash-receipt-vouchers.print', 'doc_num' => $receiptDocNum],
                ['route' => 'admin.finance.cash-payment-vouchers.print', 'doc_num' => $paymentDocNum],
            ] as $print) {
                $response = $this->actingAs($actor)->get(route($print['route'], $print['doc_num']))
                    ->assertOk()->assertHeader('content-type', 'application/pdf');
                $path = tempnam(sys_get_temp_dir(), 'voucher-print-');
                file_put_contents($path, $response->getContent());
                try {
                    $process = new Process(['pdftotext', '-layout', $path, '-']);
                    $process->mustRun();
                    $text = $process->getOutput();
                    expect($text)->toContain($print['doc_num']);
                    if ($showIdentity) {
                        expect($text)->toContain('Printable Legal Company')->toContain('Mona Ali')->toContain('Authorized Director');
                    } else {
                        expect($text)->not->toContain('Printable Legal Company')->not->toContain('Mona Ali');
                    }
                } finally {
                    unlink($path);
                }
            }
        }
    }
});

test('CashVoucher currency rules enforce base rate non base positivity and cashbox restrictions', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $usd = cashVoucherUsd($company);
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $egpOnlyCashbox = cashVoucherCashbox($company, $branch, [$egp], 'EGP Cashbox');
    $usdCashbox = cashVoucherCashbox($company, $branch, [$usd], 'USD Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($egpOnlyCashbox, $egp, $lineAccount, ['person_name' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['person_name']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($egpOnlyCashbox, $usd, $lineAccount))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['currency_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($egpOnlyCashbox, $egp, $lineAccount, ['exchange_rate' => 2]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($usdCashbox, $usd, $lineAccount, ['exchange_rate' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($usdCashbox, $usd, $lineAccount, ['exchange_rate' => 30.5]))
        ->assertOk();

    $voucher = CashVoucher::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($voucher->exchange_rate)->toBe('30.500000')
        ->and($voucher->amount_base)->toBe('3050.0000');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $voucher->doc_num))
        ->assertOk();
    $journal = JournalEntry::query()->where('source_type', CashVoucherService::SourceReceipt)->where('source_id', $voucher->getKey())->sole();
    expect($journal->currency_id)->toBe($usd->getKey())
        ->and($journal->exchange_rate)->toBe('30.500000')
        ->and($journal->lines()->sum('debit_amount'))->toEqual(100)
        ->and($journal->lines()->sum('credit_amount'))->toEqual(100);

    $service = app(FinanceReportService::class);
    $usdStatement = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'cashbox_doc_num' => $usdCashbox->doc_num,
        'currency_doc_num' => $usd->doc_num,
    ]);
    $egpStatement = $service->report([
        'type' => FinanceReportService::CashboxStatement,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'cashbox_doc_num' => $usdCashbox->doc_num,
        'currency_doc_num' => $egp->doc_num,
    ]);
    expect($usdStatement['rows'])->toHaveCount(1)
        ->and($usdStatement['rows']->first()['receipt'])->toBe('100.0000')
        ->and($egpStatement['rows'])->toBeEmpty();
});

test('CashVoucher datatables expose expected receipt and payment columns without internal ids', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.view',
        'cash_receipt_vouchers.create',
        'cash_payment_vouchers.view',
        'cash_payment_vouchers.create',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Data Cashbox');
    $receiptAccount = cashVoucherPostableAccount($company, '411');
    $paymentAccount = cashVoucherPostableAccount($company, '521');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $receiptAccount))
        ->assertOk();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $paymentAccount))
        ->assertOk();

    foreach ([
        route('admin.finance.cash-receipt-vouchers.data'),
        route('admin.finance.cash-payment-vouchers.data'),
    ] as $url) {
        $row = $this->actingAs($actor)
            ->getJson($url)
            ->assertOk()
            ->json('data.0');

        expect($row)->toHaveKeys([
            'checkbox',
            'doc_num',
            'voucher_date',
            'cashbox',
            'person_name',
            'currency',
            'exchange_rate',
            'amount',
            'distributed_amount',
            'remaining_amount',
            'status',
            'reason',
            'created_by',
            'updated_by',
            'approved_by',
            'approved_at',
            'actions',
        ])->not->toHaveKeys(['id', 'company_id', 'cashbox_id', 'currency_id']);

        expect($row['cashbox'])
            ->toContain('<span class="dt-ellipsis-content"')
            ->not->toContain('&lt;span')
            ->and($row['person_name'])
            ->toContain('<span class="dt-ellipsis-content"')
            ->toContain('Feature Test Person')
            ->not->toContain('&lt;span')
            ->and($row['currency'])
            ->toContain('<span class="dt-ellipsis-content"')
            ->not->toContain('&lt;span')
            ->and($row['reason'])
            ->toContain('<span class="dt-ellipsis-content"')
            ->not->toContain('&lt;span');
    }
});

test('CashVoucher UI uses localized headers shared select2 centered dates and standard save actions', function (): void {
    cashVoucherSeedFoundation();
    app()->setLocale('ar');
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.view',
        'cash_receipt_vouchers.create',
        'cash_receipt_vouchers.edit',
        'cash_receipt_vouchers.clone',
        'cash_payment_vouchers.view',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.edit',
        'cash_payment_vouchers.clone',
        'accounts.view',
    ]);

    foreach ([
        ['index' => 'admin.finance.cash-receipt-vouchers.index', 'create' => 'admin.finance.cash-receipt-vouchers.create'],
        ['index' => 'admin.finance.cash-payment-vouchers.index', 'create' => 'admin.finance.cash-payment-vouchers.create'],
    ] as $routes) {
        $indexHtml = $this->actingAs($actor)
            ->get(route($routes['index']))
            ->assertOk()
            ->getContent();

        expect($indexHtml)
            ->toContain('الخزنة')
            ->toContain('تاريخ السند')
            ->toContain('اعتمد بواسطة')
            ->toContain('اسم الشخص')
            ->not->toContain('finance.columns.');

        $formHtml = $this->actingAs($actor)
            ->get(route($routes['create']))
            ->assertOk()
            ->getContent();

        expect($formHtml)
            ->toContain('form-select js-select2-ajax js-cash-voucher-cashbox')
            ->toContain('form-select js-select2-ajax js-cash-voucher-currency')
            ->toContain('form-select js-select2-ajax js-cash-voucher-account')
            ->toContain('data-dependent-param="cashbox"')
            ->toContain('data-extra-params="{&quot;exclude&quot;:&quot;#cashbox_account_doc_num_filter&quot;}"')
            ->toContain('form-control text-center js-date-picker')
            ->toContain('name="person_name"')
            ->toContain('اسم الشخص')
            ->toContain('الرقم القومي')
            ->toContain('رقم الهاتف')
            ->toContain('for="description">ملاحظات</label>')
            ->toContain('name="description" rows="3"')
            ->toContain('dropdown-toggle dropdown-toggle-split')
            ->toContain('data-submit-action="save_view"')
            ->toContain('data-submit-action="save_back"')
            ->toContain('data-submit-action="save_clone"')
            ->toContain(__('common.actions.save_and_new'))
            ->not->toContain('Searching...')
            ->not->toContain('cash_receipt_vouchers.')
            ->not->toContain('cash_payment_vouchers.');
    }
});

test('CashVoucher soft delete restore works for drafts and approved delete is blocked', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor([
        'cash_receipt_vouchers.view',
        'cash_receipt_vouchers.create',
        'cash_receipt_vouchers.delete',
        'cash_receipt_vouchers.restore',
        'cash_payment_vouchers.view',
        'cash_payment_vouchers.create',
        'cash_payment_vouchers.delete',
        'cash_payment_vouchers.approve',
        'accounts.view',
    ]);
    $cashbox = cashVoucherCashbox($company, $branch, [$egp], 'Delete Cashbox');
    $receiptAccount = cashVoucherPostableAccount($company, '411');
    $paymentAccount = cashVoucherPostableAccount($company, '521');

    $receiptDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $egp, $receiptAccount))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.cash-receipt-vouchers.destroy', $receiptDocNum))
        ->assertOk();

    $receipt = CashVoucher::withTrashed()->where('doc_num', $receiptDocNum)->firstOrFail();

    expect($receipt->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.finance.cash-receipt-vouchers.restore', $receiptDocNum))
        ->assertOk();

    expect($receipt->refresh()->trashed())->toBeFalse()
        ->and($receipt->restored_by)->toBe($actor->getKey())
        ->and($receipt->restored_at)->not->toBeNull();

    $paymentDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.store'), cashVoucherPayload($cashbox, $egp, $paymentAccount))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.approve', $paymentDocNum))
        ->assertOk();

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.cash-payment-vouchers.destroy', $paymentDocNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect(CashVoucher::query()->where('doc_num', $paymentDocNum)->firstOrFail()->trashed())->toBeFalse();
});

test('cash voucher lines retain soft deleted historical accounts', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $currency] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$currency], 'Historical Relation Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $currency, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');

    $lineAccount->delete();
    $line = CashVoucher::query()->where('doc_num', $docNum)->firstOrFail()->lines()->firstOrFail();

    expect($line->account)->toBeInstanceOf(Account::class)
        ->and($line->account?->trashed())->toBeTrue();
});

test('active cashbox cannot approve with inactive or deleted linked ledger account', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $currency] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'cash_receipt_vouchers.approve', 'accounts.view']);
    $cashbox = cashVoucherCashbox($company, $branch, [$currency], 'Stale Ledger Cashbox');
    $lineAccount = cashVoucherPostableAccount($company, '411');
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.store'), cashVoucherPayload($cashbox, $currency, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');
    $linkedAccount = $cashbox->account()->firstOrFail();

    $linkedAccount->forceFill(['status' => 'inactive'])->save();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $docNum))
        ->assertUnprocessable();
    expect(CashVoucher::query()->where('doc_num', $docNum)->value('status'))->toBe(CashVoucher::StatusDraft);

    $linkedAccount->forceFill(['status' => 'active'])->save();
    $linkedAccount->delete();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-receipt-vouchers.approve', $docNum))
        ->assertUnprocessable();
    expect(CashVoucher::query()->where('doc_num', $docNum)->value('status'))->toBe(CashVoucher::StatusDraft);
});
