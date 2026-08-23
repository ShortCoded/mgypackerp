<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
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
    Storage::disk('public')->put($path, 'image-content');

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
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
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

    $this->actingAs($actor)
        ->putJson(route('admin.finance.cash-payment-vouchers.update', $voucher->doc_num), cashVoucherPayload($cashbox, $egp, $lineAccount, ['amount' => 120]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->postJson(route('admin.finance.cash-payment-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'Payment voided'])
        ->assertOk();

    expect($voucher->refresh()->status)->toBe(CashVoucher::StatusCancelled);
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

    foreach ([
        ['route' => 'admin.finance.cash-receipt-vouchers.print', 'doc_num' => $receiptDocNum, 'label' => 'Cash Receipt Voucher'],
        ['route' => 'admin.finance.cash-payment-vouchers.print', 'doc_num' => $paymentDocNum, 'label' => 'Cash Payment Voucher'],
    ] as $print) {
        $this->actingAs($actor)
            ->get(route($print['route'], $print['doc_num']))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('erp-document-company-header', false)
            ->assertSee('erp-document-authorization', false)
            ->assertSee('Printable Legal Company')
            ->assertSee('Mona Ali')
            ->assertSee('Authorized Director')
            ->assertSee($print['label'])
            ->assertSee(route('admin.file-manager.files.preview', $stamp->doc_num), false)
            ->assertSee(route('admin.file-manager.files.preview', $signature->doc_num), false);
    }

    $actor->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($actor)
        ->get(route('admin.finance.cash-receipt-vouchers.print', $receiptDocNum))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('المفوض بالتوقيع')
        ->assertSee('ختم الشركة');

    $company->forceFill([
        'company_stamp_archive_file_id' => null,
        'authorized_signatory_name' => null,
        'authorized_signatory_title' => null,
        'authorized_signatory_signature_archive_file_id' => null,
    ])->save();

    $this->actingAs($actor)
        ->get(route('admin.finance.cash-payment-vouchers.print', $paymentDocNum))
        ->assertOk()
        ->assertSee('erp-document-company-header', false)
        ->assertDontSee('erp-document-authorization', false)
        ->assertDontSee(route('admin.file-manager.files.preview', $stamp->doc_num), false)
        ->assertDontSee(route('admin.file-manager.files.preview', $signature->doc_num), false);
});

test('CashVoucher currency rules enforce base rate non base positivity and cashbox restrictions', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $egp] = cashVoucherSeedFoundation();
    $usd = cashVoucherUsd($company);
    $actor = cashVoucherActor(['cash_receipt_vouchers.create', 'accounts.view']);
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
            ->toContain('data-extra-params=\'{"exclude":"#cashbox_account_doc_num_filter"}\'')
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
