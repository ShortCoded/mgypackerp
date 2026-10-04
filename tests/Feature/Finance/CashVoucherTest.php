<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\FinanceReportService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/CashVoucherSupport.php';

test('cash vouchers keep automatic numbers beyond deleted history and resolve an active legacy duplicate', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $currency] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_payment_vouchers.view', 'cash_payment_vouchers.create', 'cash_payment_vouchers.delete']);
    $this->actingAs($actor);
    request()->setLaravelSession(app('session.store'));
    request()->setUserResolver(fn () => $actor);
    $cashbox = cashVoucherCashbox($company, $branch, [$currency], 'SYNTHETIC retained-number cashbox');
    $account = cashVoucherPostableAccount($company, '411');
    $payload = cashVoucherPayload($cashbox, $currency, $account);
    $service = app(CashVoucherService::class);
    $old = $service->create(CashVoucher::TypePayment, $payload, $company->id)['record'];
    $service->delete(CashVoucher::TypePayment, $old);
    $next = $service->create(CashVoucher::TypePayment, $payload, $company->id)['record'];
    expect($next->doc_number)->toBe($old->doc_number + 1)->and($next->doc_num)->not->toBe($old->doc_num);
    $legacy = $service->create(CashVoucher::TypePayment, $payload + ['doc_number' => $old->doc_number], $company->id)['record'];
    $this->get(route('admin.finance.cash-payment-vouchers.show', $legacy->doc_num))->assertOk()
        ->assertViewHas('record', fn ($record): bool => $record->id === $legacy->id);
    expect($old->fresh()->trashed())->toBeTrue();
});

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

test('cash voucher cost centers use linked scoped AJAX choices and remain attributed through approval print and reversal', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $currency] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_payment_vouchers.view', 'cash_payment_vouchers.create', 'cash_payment_vouchers.edit',
        'cash_payment_vouchers.approve', 'cash_payment_vouchers.cancel', 'cash_payment_vouchers.print']);
    $cashbox = cashVoucherCashbox($company, $branch, [$currency], 'SYNTHETIC attributed cashbox');
    $account = cashVoucherPostableAccount($company, '523');
    $makeCenter = function (int $number, array $overrides = []) use ($company, $account): CostCenter {
        $center = CostCenter::query()->create(['company_id' => $company->id,
            'doc_number' => $number, 'doc_num' => 'SYNTHETIC-CASH-CENTER-'.$number,
            'cost_center_code' => 'SYNTHETIC-'.$number, 'name' => 'مركز مصروفات صناعية تجريبي '.$number,
            'name_en' => 'SYNTHETIC factory expense center '.$number, 'is_group' => false, 'status' => 'active', ...$overrides]);
        $center->accounts()->sync([$account->id]);

        return $center;
    };
    $center = $makeCenter(18001);
    $inactive = $makeCenter(18002, ['status' => 'inactive']);
    $unlinked = $makeCenter(18003);
    $unlinked->accounts()->sync([]);
    $foreignCompany = Company::factory()->create(['doc_number' => 18002, 'doc_num' => 'SYNTHETIC-CASH-FOREIGN']);
    $foreign = $makeCenter(18004, ['company_id' => $foreignCompany->id]);
    foreach (range(18005, 18016) as $number) {
        $makeCenter($number);
    }
    config()->set('select2.pagination.per_page', 10);
    $this->actingAs($actor);
    $this->getJson(route('admin.finance.select2.accounts', ['q' => '523']))->assertForbidden();
    $this->getJson(route('admin.finance.select2.cash-voucher-accounts', ['q' => '523', 'exclude' => $cashbox->account->doc_num]))
        ->assertOk()->assertJsonPath('results.0.id', $account->doc_num)->assertJsonPath('pagination.more', false);
    $lookup = route('admin.finance.select2.cash-voucher-cost-centers', ['account' => $account->doc_num]);
    $firstPage = $this->getJson($lookup)->assertOk()->assertJsonPath('pagination.more', true);
    expect($firstPage->json('results'))->toHaveCount(10)
        ->and(collect($firstPage->json('results'))->pluck('id')->all())->not->toContain($inactive->doc_num, $unlinked->doc_num, $foreign->doc_num);
    $this->getJson($lookup.'&page=2')->assertOk()->assertJsonCount(3, 'results')->assertJsonPath('pagination.more', false);
    $this->getJson(route('admin.finance.select2.cash-voucher-cost-centers', ['account' => $cashbox->account->doc_num]))
        ->assertOk()->assertJsonPath('results', []);
    $payload = cashVoucherPayload($cashbox, $currency, $account);
    foreach ([$inactive, $unlinked, $foreign] as $invalid) {
        $payload['lines'][0]['cost_center_doc_num'] = $invalid->doc_num;
        $this->postJson(route('admin.finance.cash-payment-vouchers.store'), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.cost_center_doc_num');
    }
    expect(CashVoucher::query()->count())->toBe(0);
    $payload['lines'][0]['cost_center_doc_num'] = $center->doc_num;
    $response = $this->postJson(route('admin.finance.cash-payment-vouchers.store'), $payload)->assertOk();
    $voucher = CashVoucher::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($voucher->lines->sole()->cost_center_id)->toBe($center->id);
    $center->accounts()->sync([]);
    $this->postJson(route('admin.finance.cash-payment-vouchers.approve', $voucher->doc_num))->assertUnprocessable();
    expect($voucher->fresh()->status)->toBe(CashVoucher::StatusDraft)->and(JournalEntry::query()->where('source_type', CashVoucherService::SourcePayment)->exists())->toBeFalse();
    $center->accounts()->sync([$account->id]);
    $this->postJson(route('admin.finance.cash-payment-vouchers.approve', $voucher->doc_num))->assertOk();
    $journal = JournalEntry::query()->where('source_type', CashVoucherService::SourcePayment)->where('source_id', $voucher->id)->sole();
    expect($journal->lines->firstWhere('account_id', $account->id)->cost_center_id)->toBe($center->id);
    $center->delete();
    $this->get(route('admin.finance.cash-payment-vouchers.show', $voucher->doc_num))->assertOk()->assertSee($center->cost_center_code);
    foreach (['ar', 'en'] as $locale) {
        $actor->update(['locale' => $locale]);
        $pdf = $this->get(route('admin.finance.cash-payment-vouchers.print', $voucher->doc_num))->assertOk()->assertHeader('content-type', 'application/pdf');
        $path = '/tmp/mgypack-cash-cost-center-'.$locale.'.pdf';
        file_put_contents($path, $pdf->getContent());
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->mustRun();
        expect($process->getOutput())->toContain($center->cost_center_code)->and(strlen($pdf->getContent()))->toBeGreaterThan(1000);
    }
    $this->postJson(route('admin.finance.cash-payment-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'SYNTHETIC historic-center reversal'])->assertOk();
    $reversal = JournalEntry::query()->where('source_type', CashVoucherService::SourcePaymentReversal)->where('source_id', $voucher->id)->sole();
    expect($reversal->lines->firstWhere('account_id', $account->id)->cost_center_id)->toBe($center->id)
        ->and($reversal->lines->firstWhere('account_id', $account->id)->credit_amount)->toBe('100.0000');
});

test('cash center migration owns only its added column and refuses to discard attributed lines', function (): void {
    ['company' => $company, 'branch' => $branch, 'currency' => $currency] = cashVoucherSeedFoundation();
    $actor = cashVoucherActor(['cash_payment_vouchers.create']);
    $cashbox = cashVoucherCashbox($company, $branch, [$currency], 'SYNTHETIC migration cashbox');
    $account = cashVoucherPostableAccount($company, '523');
    $center = CostCenter::query()->create(['company_id' => $company->id,
        'doc_number' => 18001, 'doc_num' => 'SYNTHETIC-MIGRATION-CC', 'cost_center_code' => 'SYNTHETIC-MIGRATION',
        'name' => 'SYNTHETIC migration center', 'status' => 'active', 'is_group' => false]);
    $center->accounts()->sync([$account->id]);
    $payload = cashVoucherPayload($cashbox, $currency, $account);
    $payload['lines'][0]['cost_center_doc_num'] = $center->doc_num;
    $response = $this->actingAs($actor)->postJson(route('admin.finance.cash-payment-vouchers.store'), $payload)->assertOk();
    $voucher = CashVoucher::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    $migration = require base_path('modules/Finance/Database/Migrations/2026_10_03_120300_add_cost_center_to_cash_voucher_lines.php');
    $migration->up();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and($voucher->fresh()->lines->sole()->cost_center_id)->toBe($center->id);
    $voucher->lines()->update(['cost_center_id' => null]);
    $migration->down();
    expect(Schema::hasColumn('cash_voucher_lines', 'cost_center_id'))->toBeFalse();
    Schema::table('cash_voucher_lines', fn (Blueprint $table) => $table->unsignedBigInteger('cost_center_id')->nullable());
    $migration->up();
    expect((bool) DB::table('cash_voucher_cost_center_column_ownership')->where('column_name', 'cost_center_id')->value('created_by_migration'))->toBeFalse();
    $voucher->lines()->update(['cost_center_id' => $center->id]);
    $migration->down();
    expect(Schema::hasColumn('cash_voucher_lines', 'cost_center_id'))->toBeTrue()
        ->and($voucher->fresh()->lines->sole()->cost_center_id)->toBe($center->id);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $migration->up();
});
