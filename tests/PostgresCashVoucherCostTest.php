<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Accounting\Services\OverheadAllocationService;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/PayrollFinancialSupport.php';
require_once __DIR__.'/CashVoucherSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @return array<string, mixed> */
function cashCostFixture(): array
{
    $fixture = costTransitionFixture('-SYNTHETIC-CASH-COST-'.Str::random(10), isolatedCompany: true);
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    $account = Account::query()->forCompany($fixture['company']->id)->where('account_code', '523')->firstOrFail();
    $currency = Currency::query()->forCompany($fixture['company']->id)->where('is_main', true)->firstOrFail();
    $cashbox = DB::transaction(fn () => cashVoucherCashbox($fixture['company'], $fixture['branch'], [$currency], 'SYNTHETIC overhead cashbox'));
    $centers = collect(range(1, 2))->map(function (int $sequence) use ($fixture, $account): CostCenter {
        $number = (int) CostCenter::withTrashed()->max('doc_number') + 1;
        $center = CostCenter::query()->create(['company_id' => $fixture['company']->id,
            'doc_number' => $number, 'doc_num' => 'SYNTHETIC-CASH-CC-'.$number,
            'cost_center_code' => 'CASH-'.$sequence, 'name' => 'SYNTHETIC cash center '.$sequence,
            'name_en' => 'SYNTHETIC cash center '.$sequence, 'is_group' => false, 'status' => 'active']);
        $center->accounts()->sync([$account->id]);

        return $center;
    });
    $fixture['cost_center'] = $centers->first();
    $run = payrollManufacturingRun($fixture, $fixture['product'], $fixture['unit'], (int) ProductionRun::max('id') + 1, []);
    foreach (['cash_payment_vouchers.view', 'cash_payment_vouchers.create', 'cash_payment_vouchers.edit',
        'cash_payment_vouchers.approve', 'cash_payment_vouchers.cancel', 'cash_payment_vouchers.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['preparer']->givePermissionTo($permission);
    }
    $payload = cashVoucherPayload($cashbox, $currency, $account, ['voucher_date' => '2026-09-30', 'amount' => '300',
        'person_name' => 'SYNTHETIC cash cost recipient', 'person_national_id' => null,
        'reason' => 'SYNTHETIC overhead for allocation', 'lines' => [['account_doc_num' => $account->doc_num,
            'cost_center_doc_num' => $centers->first()->doc_num, 'amount' => '300', 'description' => 'SYNTHETIC cash overhead']]]);
    test()->actingAs($fixture['preparer'])->withSession(costTransitionSession($fixture));

    return [...$fixture, ...compact('account', 'currency', 'cashbox', 'centers', 'run', 'payload')];
}

test('cash overhead keeps line center through HTTP and races allocation with canonical voucher cancellation without retry', function (): void {
    $fixture = cashCostFixture();
    $payload = $fixture['payload'];
    $center = $fixture['centers']->first();
    $response = $this->postJson(route('admin.finance.cash-payment-vouchers.store'), $payload)->assertOk();
    $voucher = CashVoucher::query()->forCompany($fixture['company']->id)->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($voucher->lines->sole()->cost_center_id)->toBe($center->id);
    $second = $fixture['centers']->last();
    $payload['lines'][0]['cost_center_doc_num'] = $second->doc_num;
    $this->putJson(route('admin.finance.cash-payment-vouchers.update', $voucher->doc_num), $payload)->assertOk();
    expect($voucher->fresh()->lines->sole()->cost_center_id)->toBe($second->id);
    $payload['lines'][0]['cost_center_doc_num'] = $center->doc_num;
    $this->putJson(route('admin.finance.cash-payment-vouchers.update', $voucher->doc_num), $payload)->assertOk();
    $this->get(route('admin.finance.cash-payment-vouchers.show', $voucher->doc_num))->assertOk()->assertSee($center->codeNameLabel());
    $this->postJson(route('admin.finance.cash-payment-vouchers.approve', $voucher->doc_num))->assertOk();
    $source = JournalEntry::query()->where('company_id', $fixture['company']->id)
        ->where('source_type', CashVoucherService::SourcePayment)->where('source_id', $voucher->id)->sole();
    expect($source->lines->firstWhere('account_id', $fixture['account']->id)->cost_center_id)->toBe($center->id)
        ->and($source->lines->sum('debit_amount'))->toEqual(300)->and($source->lines->sum('credit_amount'))->toEqual(300);
    $service = app(OverheadAllocationService::class);
    $rule = $service->createRule(['name' => 'SYNTHETIC cash overhead allocation', 'source_cost_center_id' => $center->id,
        'source_account_ids' => [$fixture['account']->id], 'target_cost_center_ids' => [$center->id],
        'basis' => 'machine_hours', 'cost_behavior' => 'variable', 'effective_from' => $fixture['period']->from_date->toDateString()],
        $fixture['company']->id, $fixture['branch']->id);
    $allocation = $service->preview($rule, $fixture['period'], $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(), $fixture['period']->to_date->toDateString());
    expect($allocation->sources->sole()->journalEntryLine->journal_entry_id)->toBe($source->id)
        ->and($allocation->allocated_cost)->toBe('300.0000');
    $results = closurePostgresRace([
        ['operation' => 'cost-allocation-approve', 'allocation' => $allocation->id, 'user' => $fixture['preparer']->id],
        ['operation' => 'cash-voucher-cancel', 'voucher' => $voucher->id, 'user' => $fixture['preparer']->id],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1]);
    $allocation->refresh();
    if ($allocation->status === OverheadAllocationRun::StatusPosted) {
        expect($voucher->fresh()->status)->toBe(CashVoucher::StatusApproved)
            ->and(app(ProductionCostService::class)->runPosition($fixture['run'])['allocated_overhead'])->toBe('300.00000000');
        $service->reverse($allocation, 'SYNTHETIC release allocated voucher');
        $this->postJson(route('admin.finance.cash-payment-vouchers.cancel', $voucher->doc_num), ['cancel_reason' => 'SYNTHETIC released overhead'])->assertOk();
    }
    expect($voucher->fresh()->status)->toBe(CashVoucher::StatusCancelled)
        ->and($source->fresh()->reversed_entry_id)->not->toBeNull()
        ->and(app(ProductionCostService::class)->runPosition($fixture['run'])['allocated_overhead'])->toBe('0.00000000');
    expect(fn () => $service->preview($rule, $fixture['period'], $fixture['branch']->id,
        $fixture['period']->from_date->toDateString(), $fixture['period']->to_date->toDateString()))->toThrow(DomainException::class);
});

test('approval and draft center amendment serialize to matching voucher and journal centers on PostgreSQL', function (): void {
    $fixture = cashCostFixture();
    $response = $this->postJson(route('admin.finance.cash-payment-vouchers.store'), $fixture['payload'])->assertOk();
    $voucher = CashVoucher::query()->forCompany($fixture['company']->id)->where('doc_num', $response->json('data.doc_num'))->sole();
    $changed = $fixture['payload'];
    $changed['lines'][0]['cost_center_doc_num'] = $fixture['centers']->last()->doc_num;
    $results = closurePostgresRace([
        ['operation' => 'cash-voucher-approve', 'voucher' => $voucher->id, 'user' => $fixture['preparer']->id],
        ['operation' => 'cash-voucher-update', 'voucher' => $voucher->id, 'data' => $changed, 'user' => $fixture['preparer']->id],
    ]);
    expect(collect($results)->firstWhere('operation', 'cash-voucher-approve')['result'])->toBe('applied')
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1]);
    $journal = JournalEntry::query()->where('company_id', $fixture['company']->id)
        ->where('source_type', CashVoucherService::SourcePayment)->where('source_id', $voucher->id)->sole();
    expect($voucher->fresh()->status)->toBe(CashVoucher::StatusApproved)
        ->and($journal->lines->firstWhere('account_id', $fixture['account']->id)->cost_center_id)->toBe($voucher->fresh()->lines->sole()->cost_center_id);
    $stale = $voucher;
    expect(fn () => app(CashVoucherService::class)->update(CashVoucher::TypePayment, $stale, $changed))->toThrow(DomainException::class);
});

test('prepare synthetic cash voucher center browser fixture', function (): void {
    if (getenv('MGYPACK_CASH_COST_RUNTIME_FIXTURE') !== '1') {
        $this->markTestSkipped('Opt-in explicitly synthetic runtime preparation only.');
    }
    $fixture = cashCostFixture();
    app(DefaultLoginContextService::class)->update($fixture['preparer'], [
        'company_doc_num' => $fixture['company']->doc_num, 'branch_doc_num' => $fixture['branch']->doc_num,
        'financial_period_doc_num' => $fixture['period']->doc_num,
    ]);
    file_put_contents('/tmp/mgypack-cash-cost-runtime-20261003.json', json_encode([
        'synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003',
        'user' => $fixture['preparer']->id, 'username' => $fixture['preparer']->username,
        'company' => $fixture['company']->id, 'branch' => $fixture['branch']->id, 'period' => $fixture['period']->id,
        'cashbox' => $fixture['cashbox']->doc_num, 'currency' => $fixture['currency']->doc_num,
        'account' => $fixture['account']->doc_num, 'account_label' => $fixture['account']->codeNameLabel(),
        'centers' => $fixture['centers']->map(fn ($center) => ['doc_num' => $center->doc_num, 'label' => $center->codeNameLabel()])->all(),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    expect($fixture['company']->name)->toContain('SYNTHETIC');
});

test('verify saved synthetic browser cash voucher center and canonical posted journal', function (): void {
    if (getenv('MGYPACK_CASH_COST_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Opt-in verification of the saved synthetic browser workflow.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-cash-cost-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and($manifest['database'])->toBe(DB::getDatabaseName());
    $voucher = CashVoucher::query()->forCompany($manifest['company'])
        ->where('reason', 'SYNTHETIC runtime cash center attribution')->sole();
    $center = CostCenter::query()->forCompany($manifest['company'])->where('doc_num', $manifest['centers'][1]['doc_num'])->sole();
    $account = Account::query()->forCompany($manifest['company'])->where('doc_num', $manifest['account'])->sole();
    expect($voucher->company->name)->toContain('SYNTHETIC')
        ->and($voucher->created_by)->toBe($manifest['user'])->and($voucher->status)->toBe(CashVoucher::StatusApproved)
        ->and($voucher->amount)->toBe('300.0000')->and($voucher->lines)->toHaveCount(1)
        ->and($voucher->lines->sole()->cost_center_id)->toBe($center->id)
        ->and($voucher->lines->sole()->account_id)->toBe($account->id);
    $journal = JournalEntry::query()->where('company_id', $manifest['company'])
        ->where('source_type', CashVoucherService::SourcePayment)->where('source_id', $voucher->id)->sole();
    expect($journal->status)->toBe(JournalEntry::StatusPosted)
        ->and($journal->lines->firstWhere('account_id', $account->id)->cost_center_id)->toBe($center->id)
        ->and($journal->lines->firstWhere('account_id', $account->id)->debit_amount)->toBe('300.0000');
});
