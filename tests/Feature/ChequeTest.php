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
use Modules\Finance\Models\Cheque;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function chequeFeatureActor(array $permissions): User
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
function chequeFeatureSeedFoundation(): array
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

function chequeFeatureChildAccount(Company $company, string $parentCode, string $accountCode, string $name): Account
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

function chequeFeatureBankAccount(Company $company, Currency $currency, string $name = 'Cheque Test Bank'): BankAccount
{
    static $sequence = 40;

    $sequence++;

    $bankGroup = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', '1112')
        ->firstOrFail();
    $linkedAccount = chequeFeatureChildAccount($company, '1112', '1112'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), $name.' '.$currency->code);

    $bankAccount = BankAccount::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'bank_id' => $bankGroup->getKey(),
        'account_id' => $linkedAccount->getKey(),
        'currency_id' => $currency->getKey(),
        'account_name' => $name.' '.$currency->code,
        'account_number' => 'BA-'.$sequence,
        'status' => 'active',
    ]);

    $bankAccount->forceFill(['bank_name' => $name])->save();

    return $bankAccount->refresh();
}

function chequeFeatureUsd(Company $company): Currency
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

function chequeFeaturePostableAccount(Company $company, string $accountCode): Account
{
    return Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $accountCode)
        ->where('is_postable', true)
        ->where('is_group', false)
        ->where('status', 'active')
        ->firstOrFail();
}

function chequeFeatureCustomer(Company $company, string $name = 'Selector Customer'): Customer
{
    return Customer::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('customers', Customer::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => $name,
        'status' => 'active',
        'phone' => '+201001112233',
    ]);
}

function chequeFeatureSupplier(Company $company, string $name = 'Selector Supplier'): Supplier
{
    return Supplier::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('suppliers', Supplier::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => $name,
        'status' => 'active',
        'mobile' => '01004445566',
    ]);
}

function chequeFeaturePayload(?BankAccount $bankAccount, Currency $currency, Account $lineAccount, string $type = Cheque::TypeReceived, array $overrides = []): array
{
    return [
        'cheque_type' => $type,
        'cheque_number' => strtoupper($type).'-'.fake()->unique()->numerify('######'),
        'cheque_date' => '2026-06-18',
        'due_date' => '2026-06-30',
        'bank_account_doc_num' => $bankAccount?->doc_num,
        'external_bank_name' => 'External Bank',
        'external_bank_branch' => 'Main Branch',
        'party_type' => $type === Cheque::TypeReceived ? 'customer' : 'supplier',
        'party_name' => $type === Cheque::TypeReceived ? 'Feature Customer' : 'Feature Supplier',
        'currency_doc_num' => $currency->doc_num,
        'exchange_rate' => $currency->is_main ? 1 : 30,
        'amount' => 100,
        'reason' => 'Feature cheque test',
        'description' => 'Safe lifecycle document',
        'lines' => [
            [
                'account_doc_num' => $lineAccount->doc_num,
                'amount' => 100,
                'description' => 'Distribution',
                'notes' => 'Feature line',
            ],
        ],
        ...$overrides,
    ];
}

function chequeFeaturePermissions(): array
{
    return [
        'cheques.view',
        'cheques.create',
        'cheques.clone',
        'cheques.edit',
        'cheques.delete',
        'cheques.view_trashed',
        'cheques.restore',
        'cheques.document_number.control',
        'cheques.document_number_settings.update',
        'cheques.mark_deposited',
        'cheques.mark_collected',
        'cheques.mark_returned',
        'cheques.mark_issued',
        'cheques.mark_delivered',
        'cheques.mark_cleared',
        'cheques.cancel',
        'cheques.print',
        'accounts.view',
    ];
}

test('Finance Cheque permissions are discovered by the permission registry', function (): void {
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
        'mark_deposited',
        'mark_collected',
        'mark_returned',
        'mark_issued',
        'mark_delivered',
        'mark_cleared',
        'cancel',
        'print',
    ];

    foreach ($actions as $action) {
        expect(Permission::query()->where('name', "cheques.{$action}")->exists())->toBeTrue()
            ->and($admin->hasPermissionTo("cheques.{$action}"))->toBeTrue();
    }
});

test('Finance Cheque creates received and issued cheques with separate document sequences', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $receivedLine = chequeFeaturePostableAccount($company, '411');
    $issuedLine = chequeFeaturePostableAccount($company, '521');

    $receivedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine, Cheque::TypeReceived))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'RCH-00001')
        ->json('data.doc_num');

    $issuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->assertJsonPath('data.doc_num', 'ICH-00001')
        ->json('data.doc_num');

    $received = Cheque::query()->where('doc_num', $receivedDocNum)->firstOrFail();
    $issued = Cheque::query()->where('doc_num', $issuedDocNum)->firstOrFail();

    expect($received->cheque_type)->toBe(Cheque::TypeReceived)
        ->and($received->status)->toBe(Cheque::StatusReceived)
        ->and((float) $received->lines()->sum('amount'))->toBe(100.0)
        ->and($issued->cheque_type)->toBe(Cheque::TypeIssued)
        ->and($issued->status)->toBe(Cheque::StatusDraft)
        ->and((float) $issued->lines()->sum('amount'))->toBe(100.0);
});

test('Finance Cheque stores selected customer and supplier parties by public doc number', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $receivedLine = chequeFeaturePostableAccount($company, '411');
    $issuedLine = chequeFeaturePostableAccount($company, '521');
    $customer = chequeFeatureCustomer($company, 'Cheque Customer');
    $supplier = chequeFeatureSupplier($company, 'Cheque Supplier');

    $receivedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine, Cheque::TypeReceived, [
            'party_type' => 'customer',
            'party_doc_num' => $customer->doc_num,
            'party_name' => null,
        ]))
        ->assertOk()
        ->json('data.doc_num');

    $issuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued, [
            'party_type' => 'supplier',
            'party_doc_num' => $supplier->doc_num,
            'party_name' => null,
        ]))
        ->assertOk()
        ->json('data.doc_num');

    $received = Cheque::query()->where('doc_num', $receivedDocNum)->firstOrFail();
    $issued = Cheque::query()->where('doc_num', $issuedDocNum)->firstOrFail();

    expect($received->party_id)->toBe($customer->getKey())
        ->and($received->party_name)->toBe('Cheque Customer')
        ->and($issued->party_id)->toBe($supplier->getKey())
        ->and($issued->party_name)->toBe('Cheque Supplier');
});

test('Finance Cheque party selectors return public doc numbers only', function (): void {
    ['company' => $company] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(['cheques.create']);
    $customer = chequeFeatureCustomer($company, 'Lookup Customer');
    $supplier = chequeFeatureSupplier($company, 'Lookup Supplier');

    $customerOption = $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.customers', ['q' => 'Lookup Customer']))
        ->assertOk()
        ->json('results.0');

    $supplierOption = $this->actingAs($actor)
        ->getJson(route('admin.finance.select2.suppliers', ['q' => 'Lookup Supplier']))
        ->assertOk()
        ->json('results.0');

    expect($customerOption['id'])->toBe($customer->doc_num)
        ->and($customerOption['text'])->toContain('Lookup Customer')
        ->and($customerOption)->not->toHaveKey('internal_id')
        ->and($supplierOption['id'])->toBe($supplier->doc_num)
        ->and($supplierOption['text'])->toContain('Lookup Supplier')
        ->and($supplierOption)->not->toHaveKey('internal_id');
});

test('Finance Cheque distribution cannot exceed the cheque amount', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(['cheques.create', 'accounts.view']);
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $lineAccount = chequeFeaturePostableAccount($company, '411');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $lineAccount, Cheque::TypeReceived, [
            'amount' => 100,
            'lines' => [
                ['account_doc_num' => $lineAccount->doc_num, 'amount' => 70],
                ['account_doc_num' => $lineAccount->doc_num, 'amount' => 40],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);
});

test('Finance Cheque final statuses require full distribution', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $receivedLine = chequeFeaturePostableAccount($company, '411');
    $issuedLine = chequeFeaturePostableAccount($company, '521');

    $receivedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine, Cheque::TypeReceived, [
            'amount' => 100,
            'lines' => [
                ['account_doc_num' => $receivedLine->doc_num, 'amount' => 60],
            ],
        ]))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-deposited', $receivedDocNum))->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.mark-collected', $receivedDocNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cheques.update', $receivedDocNum), chequeFeaturePayload($bankAccount, $egp, $receivedLine, Cheque::TypeReceived))
        ->assertOk();

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-collected', $receivedDocNum))->assertOk();

    expect(Cheque::query()->where('doc_num', $receivedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCollected);

    $partialIssuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued, [
            'amount' => 100,
            'lines' => [
                ['account_doc_num' => $issuedLine->doc_num, 'amount' => 60],
            ],
        ]))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-issued', $partialIssuedDocNum))->assertOk();
    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.mark-cleared', $partialIssuedDocNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $issuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-issued', $issuedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-cleared', $issuedDocNum))->assertOk();

    expect(Cheque::query()->where('doc_num', $issuedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCleared);
});

test('Finance Cheque currency rules enforce base rate non base positivity and bank fixed currency', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $usd = chequeFeatureUsd($company);
    $actor = chequeFeatureActor(['cheques.create', 'accounts.view']);
    $egpBank = chequeFeatureBankAccount($company, $egp, 'EGP Bank');
    $lineAccount = chequeFeaturePostableAccount($company, '411');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($egpBank, $egp, $lineAccount, Cheque::TypeReceived, ['exchange_rate' => 2]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload(null, $usd, $lineAccount, Cheque::TypeReceived, ['exchange_rate' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exchange_rate']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($egpBank, $usd, $lineAccount, Cheque::TypeReceived, ['exchange_rate' => 30]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['currency_doc_num']);

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload(null, $usd, $lineAccount, Cheque::TypeReceived, ['exchange_rate' => 30]))
        ->assertOk()
        ->json('data.doc_num');

    $cheque = Cheque::query()->where('doc_num', $docNum)->firstOrFail();

    expect($cheque->exchange_rate)->toBe('30.000000')
        ->and($cheque->amount_base)->toBe('3000.0000');
});

test('Finance Cheque received and issued status actions work', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $receivedLine = chequeFeaturePostableAccount($company, '411');
    $issuedLine = chequeFeaturePostableAccount($company, '521');

    $collectedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-deposited', $collectedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-collected', $collectedDocNum))->assertOk();

    $returnedReceivedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-deposited', $returnedReceivedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-returned', $returnedReceivedDocNum))->assertOk();

    $cancelledReceivedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.cancel', $cancelledReceivedDocNum), ['cancel_reason' => 'Wrong cheque'])->assertOk();

    $clearedIssuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-issued', $clearedIssuedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-delivered', $clearedIssuedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-cleared', $clearedIssuedDocNum))->assertOk();

    $returnedIssuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-issued', $returnedIssuedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-returned', $returnedIssuedDocNum))->assertOk();

    $cancelledIssuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.cancel', $cancelledIssuedDocNum), ['cancel_reason' => 'Void before delivery'])->assertOk();

    expect(Cheque::query()->where('doc_num', $collectedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCollected)
        ->and(Cheque::query()->where('doc_num', $returnedReceivedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusReturned)
        ->and(Cheque::query()->where('doc_num', $cancelledReceivedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCancelled)
        ->and(Cheque::query()->where('doc_num', $clearedIssuedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCleared)
        ->and(Cheque::query()->where('doc_num', $returnedIssuedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusReturned)
        ->and(Cheque::query()->where('doc_num', $cancelledIssuedDocNum)->firstOrFail()->status)->toBe(Cheque::StatusCancelled);
});

test('Finance Cheque locked statuses cannot be edited directly', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $receivedLine = chequeFeaturePostableAccount($company, '411');
    $issuedLine = chequeFeaturePostableAccount($company, '521');

    $collectedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $receivedLine))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-deposited', $collectedDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-collected', $collectedDocNum))->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cheques.update', $collectedDocNum), chequeFeaturePayload($bankAccount, $egp, $receivedLine, Cheque::TypeReceived, ['reason' => 'Changed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $issuedDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-issued', $issuedDocNum))->assertOk();

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cheques.update', $issuedDocNum), chequeFeaturePayload($bankAccount, $egp, $issuedLine, Cheque::TypeIssued, ['reason' => 'Changed']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);
});

test('Finance Cheque soft delete restore works for allowed statuses and final delete is blocked', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $lineAccount = chequeFeaturePostableAccount($company, '411');

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->deleteJson(route('admin.finance.cheques.destroy', $docNum))->assertOk();

    $deleted = Cheque::withTrashed()->where('doc_num', $docNum)->firstOrFail();

    expect($deleted->trashed())->toBeTrue();

    $this->actingAs($actor)->patchJson(route('admin.finance.cheques.restore', $docNum))->assertOk();

    expect($deleted->refresh()->trashed())->toBeFalse()
        ->and($deleted->restored_by)->toBe($actor->getKey())
        ->and($deleted->restored_at)->not->toBeNull();

    $finalDocNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');

    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-deposited', $finalDocNum))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.finance.cheques.mark-collected', $finalDocNum))->assertOk();

    $this->actingAs($actor)
        ->deleteJson(route('admin.finance.cheques.destroy', $finalDocNum))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);
});

test('Finance Cheque datatable returns expected columns without internal ids', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $lineAccount = chequeFeaturePostableAccount($company, '411');

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $lineAccount))
        ->assertOk();

    $row = $this->actingAs($actor)
        ->getJson(route('admin.finance.cheques.data'))
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKeys([
        'checkbox',
        'doc_num',
        'cheque_type',
        'cheque_number',
        'party',
        'bank_account',
        'external_bank_name',
        'currency',
        'exchange_rate',
        'amount',
        'distributed_amount',
        'remaining_amount',
        'due_date',
        'status',
        'created_by',
        'updated_by',
        'actions',
    ])->not->toHaveKeys(['id', 'company_id', 'bank_account_id', 'party_id', 'currency_id']);

    expect($row['checkbox'])->toContain('<input')
        ->and($row['doc_num'])->toContain('<a class="fw-semibold dt-code-value"')
        ->and($row['cheque_type'])->toContain('<span class="badge')
        ->and($row['party'])->toContain('dt-ellipsis-content')
        ->and($row['bank_account'])->toContain('dt-ellipsis-content')
        ->and($row['external_bank_name'])->toContain('dt-ellipsis-content')
        ->and($row['status'])->toContain('<span')
        ->and($row['created_by'])->toContain('dt-ellipsis-content')
        ->and($row['updated_by'])->toContain('dt-ellipsis-content')
        ->and($row['actions'])->toContain('dropdown')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;span')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;a')
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('&lt;div');
});

test('Finance Cheque form uses the supported standard save dropdown actions', function (): void {
    ['company' => $company, 'currency' => $egp] = chequeFeatureSeedFoundation();
    $actor = chequeFeatureActor(chequeFeaturePermissions());
    $bankAccount = chequeFeatureBankAccount($company, $egp);
    $lineAccount = chequeFeaturePostableAccount($company, '411');

    $createHtml = $this->actingAs($actor)
        ->get(route('admin.finance.cheques.create'))
        ->assertOk()
        ->getContent();

    expect($createHtml)->toContain('class="btn btn-primary js-finance-submit-action" data-submit-action="save_new"')
        ->and($createHtml)->toContain('class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save"')
        ->and($createHtml)->not->toContain('btn-falcon-primary btn-sm js-finance-submit-action');

    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.finance.cheques.store'), chequeFeaturePayload($bankAccount, $egp, $lineAccount))
        ->assertOk()
        ->json('data.doc_num');

    $editHtml = $this->actingAs($actor)
        ->get(route('admin.finance.cheques.edit', $docNum))
        ->assertOk()
        ->getContent();

    expect($editHtml)->toContain('class="btn btn-primary btn-sm js-finance-submit-action" data-submit-action="save"')
        ->and($editHtml)->not->toContain('data-submit-action="save_new"');
});
