<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
