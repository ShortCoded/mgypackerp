<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Services\CashVoucherService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/ProcurementCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('prepare ordinary actors and synthetic purchase correction chains for actual browser acceptance', function (): void {
    if (getenv('MGYPACK_PROCUREMENT_CORRECTION_BROWSER_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture only.');
    }
    $path = '/tmp/mgypack-procurement-correction-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['plan']['company_id'])->name)->toContain('SYNTHETIC');

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = procurementCorrectionFixture();
        $fixture['user']->update(['username' => 'synthetic-procurement-admin-'.$fixture['company']->id]);
        $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
        $payments = collect();
        foreach (['5', '7'] as $amount) {
            $payments->push(app(ProcurementSettlementService::class)->approveSupplierPayment(app(ProcurementSettlementService::class)
                ->createSupplierPayment(procurementCorrectionPaymentData($fixture, $amount))));
        }
        app(DefaultLoginContextService::class)->update($fixture['user'], ['company_doc_num' => $fixture['company']->doc_num,
            'branch_doc_num' => $fixture['admin']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        procurementUseBranch($fixture, $fixture['branch']);
        $return = app(ProcurementSettlementService::class)->approvePurchaseReturn(app(ProcurementSettlementService::class)->createPurchaseReturn([
            'purchase_order_doc_num' => $fixture['order']->doc_num, 'purchase_invoice_doc_num' => $fixture['invoice']->doc_num,
            'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC browser correction return',
            'lines' => [['receipt_line_public_id' => $fixture['receipts']->first()->lines->sole()->public_id,
                'quantity' => '1', 'from_quarantine' => false]],
        ]));
        $factoryActor = User::factory()->create([...app(DocumentNumberService::class)->next('users', User::class),
            'username' => 'synthetic-procurement-factory-'.$fixture['company']->id]);
        $factoryActor->givePermissionTo($fixture['user']->getAllPermissions());
        app(DefaultLoginContextService::class)->update($factoryActor, ['company_doc_num' => $fixture['company']->doc_num,
            'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        $plan = ['company_id' => $fixture['company']->id, 'factory_actor' => $factoryActor->username,
            'admin_actor' => $fixture['user']->username, 'receipt_id' => $fixture['receipts']->first()->id,
            'receipt_number' => $fixture['receipts']->first()->doc_number, 'receipt_doc_num' => $fixture['receipts']->first()->doc_num,
            'invoice_id' => $fixture['invoice']->id, 'invoice_number' => $fixture['invoice']->doc_number,
            'invoice_doc_num' => $fixture['invoice']->doc_num, 'return_doc_num' => $return->doc_num,
            'payment_ids' => $payments->pluck('id')->all(), 'payment_doc_nums' => $payments->pluck('doc_num')->all()];

        test()->travelTo(now()->setDate(2026, 9, 29));
        $historical = procurementCorrectionFixture();
        $historical['user']->update(['username' => 'synthetic-procurement-recovery-'.$historical['company']->id]);
        $historical['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($historical['invoice_data'])['record']);
        $data = procurementCorrectionPaymentData($historical, '5');
        $data['payment_method'] = 'cash';
        $data['cashbox_doc_num'] = $historical['cashbox']->doc_num;
        $payment = app(ProcurementSettlementService::class)->approveSupplierPayment(app(ProcurementSettlementService::class)->createSupplierPayment($data));
        app(PurchaseInvoiceService::class)->close($historical['invoice']);
        $historical['source_period'] = $historical['period'];
        $historical['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
        $historical['period'] = FinancialPeriod::query()->create([
            ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
            'company_id' => $historical['company']->id, 'name' => 'SYNTHETIC October supplier payment recovery',
            'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
        ]);
        test()->travelBack();
        app(DefaultLoginContextService::class)->update($historical['user'], ['company_doc_num' => $historical['company']->doc_num,
            'branch_doc_num' => $historical['admin']->doc_num, 'financial_period_doc_num' => $historical['period']->doc_num]);

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'plan' => $plan,
            'recovery' => ['company_id' => $historical['company']->id, 'actor' => $historical['user']->username,
                'payment_id' => $payment->id, 'payment_doc_num' => $payment->doc_num, 'voucher_id' => $payment->cash_voucher_id,
                'invoice_id' => $historical['invoice']->id, 'source_period_id' => $historical['source_period']->id,
                'posting_period_id' => $historical['period']->id]];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('actual browser supplier payment recovery preserves closed source and reconciles original payable cash and journals', function (): void {
    if (getenv('MGYPACK_PROCUREMENT_CORRECTION_BROWSER_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit persisted browser result verification only.');
    }
    $path = '/tmp/mgypack-procurement-correction-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue();
    $source = $manifest['recovery'];
    expect(Company::findOrFail($source['company_id'])->name)->toContain('SYNTHETIC');
    $payment = SupplierPaymentContext::query()->with('cashVoucher', 'journalEntry')->findOrFail($source['payment_id']);
    $reversal = JournalEntry::query()->where('source_type', 'supplier_payment_reversal')->where('source_id', $payment->id)->sole();
    expect($payment->status)->toBe('cancelled')->and($payment->cashVoucher->status)->toBe('cancelled')
        ->and($payment->financial_period_id)->toBe($source['source_period_id'])
        ->and(FinancialPeriod::findOrFail($source['source_period_id'])->is_closed)->toBeTrue()
        ->and($reversal->financial_period_id)->toBe($source['posting_period_id'])
        ->and($payment->cancelled_by)->toBe(User::query()->where('username', $source['actor'])->value('id'))
        ->and($payment->cancel_reason)->toContain('SYNTHETIC browser')
        ->and(PurchaseInvoice::findOrFail($source['invoice_id'])->paid_amount)->toBe('0.0000')
        ->and(PurchaseInvoice::findOrFail($source['invoice_id'])->remaining_amount)->toBe('20.0000');
    foreach ($payment->journalEntry->lines as $originalLine) {
        $counterpart = $reversal->lines->firstWhere('account_id', $originalLine->account_id);
        expect($counterpart->debit_amount)->toBe($originalLine->credit_amount)
            ->and($counterpart->credit_amount)->toBe($originalLine->debit_amount);
    }
    $manifest['browser_recovery_verified'] = true;
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('actual browser ordered purchase correction reconciles every intermediate document and final stock', function (): void {
    $phase = getenv('MGYPACK_PROCUREMENT_BROWSER_PHASE');
    if (! in_array($phase, ['return', 'payment_one', 'payments', 'invoice', 'finished'], true)) {
        $this->markTestSkipped('Explicit persisted browser phase verification only.');
    }
    $path = '/tmp/mgypack-procurement-correction-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $source = $manifest['plan'];
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($source['company_id'])->name)->toContain('SYNTHETIC');
    $invoice = PurchaseInvoice::findOrFail($source['invoice_id']);
    $return = PurchaseReturn::query()->where('company_id', $source['company_id'])->where('doc_num', $source['return_doc_num'])->sole();
    expect($return->status)->toBe('reversed')->and($return->reversal_reason)->toContain('SYNTHETIC browser');
    $payments = SupplierPaymentContext::query()->whereIn('id', $source['payment_ids'])->orderBy('id')->get();
    $paid = match ($phase) {
        'return' => '12.0000', 'payment_one' => '7.0000', default => '0.0000'
    };
    expect($invoice->paid_amount)->toBe($paid)->and($invoice->credited_amount)->toBe('0.0000');
    if ($phase !== 'return') {
        expect($payments->first()->status)->toBe('cancelled');
    }
    if (in_array($phase, ['payments', 'invoice', 'finished'], true)) {
        expect($payments->last()->status)->toBe('cancelled');
    }
    if (in_array($phase, ['invoice', 'finished'], true)) {
        expect($invoice->status)->toBe('cancelled')->and($invoice->reversal_journal_entry_id)->not->toBeNull();
    }
    $rows = InventoryTransaction::query()->where('company_id', $source['company_id'])->get();
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))
        ->toBe($phase === 'finished' ? '0.00000000' : '10.00000000');
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, $row->signedValue(), 8), '0'))
        ->toBe($phase === 'finished' ? '0.00000000' : '20.00000000');
    foreach (JournalEntry::query()->where('company_id', $source['company_id'])->with('lines')->get() as $journal) {
        expect($journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
    if ($phase === 'finished') {
        foreach (UnpricedInventoryReceipt::query()->where('company_id', $source['company_id'])->with('lines')->get() as $receipt) {
            expect($receipt->posting_status)->toBe('reversed')->and($receipt->lines->sole()->accepted_quantity)->toBe($receipt->doc_number === 1 ? '4.00000000' : '6.00000000');
        }
        foreach (JournalEntryLine::query()->whereHas('journalEntry', fn ($query) => $query->where('company_id', $source['company_id']))->get()->groupBy('account_id') as $lines) {
            expect($lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
        }
    }
    $manifest['browser_plan_phases'][$phase] = true;
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('ordered PostgreSQL purchase correction races observe real blocking and preserve the partial chain', function (string $scenario): void {
    if (getenv('MGYPACK_PROCUREMENT_CORRECTION_RACE') !== '1') {
        $this->markTestSkipped('Explicit isolated committed SYNTHETIC two-connection acceptance only.');
    }
    $fixture = DB::transaction(function () use ($scenario): array {
        $fixture = procurementCorrectionFixture();
        if ($scenario !== 'receipt-create') {
            $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
            $fixture['payment'] = app(ProcurementSettlementService::class)->createSupplierPayment(procurementCorrectionPaymentData($fixture, '5'));
            if ($scenario === 'payment-cancel') {
                $fixture['payment'] = app(ProcurementSettlementService::class)->approveSupplierPayment($fixture['payment']);
            }
        }

        return $fixture;
    });
    $common = ['user' => $fixture['user']->id];
    if ($scenario === 'receipt-create') {
        procurementUseBranch($fixture, $fixture['branch']);
        $first = $common + ['operation' => 'purchase-receipt-reverse', 'receipt' => $fixture['receipts']->first()->id, 'context' => session()->all()];
        procurementUseBranch($fixture, $fixture['admin']);
        $second = $common + ['operation' => 'purchase-invoice-create', 'data' => $fixture['invoice_data'], 'context' => session()->all()];
    } else {
        procurementUseBranch($fixture, $fixture['admin']);
        $first = $common + ['operation' => $scenario === 'payment-approve' ? 'supplier-payment-approve' : 'supplier-payment-cancel',
            'payment' => $fixture['payment']->id];
        $second = $common + ['operation' => 'purchase-invoice-reverse', 'invoice' => $fixture['invoice']->id];
    }
    $results = closurePostgresRace([$first, $second], orderedCompanyLock: true);
    expect(array_column($results, 'root_transaction_attempts'))->toBe([1, 1])
        ->and(array_column($results, 'result'))->toBe($scenario === 'payment-cancel' ? ['applied', 'applied'] : ['applied', 'blocked']);
    if ($scenario === 'receipt-create') {
        expect(PurchaseInvoice::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
            ->and($fixture['receipts']->first()->fresh()->posting_status)->toBe('reversed')
            ->and($fixture['receipts']->last()->fresh()->posting_status)->toBe('posted');
    } elseif ($scenario === 'payment-approve') {
        expect($fixture['invoice']->fresh()->status)->toBe(PurchaseInvoice::StatusApproved)
            ->and($fixture['invoice']->fresh()->paid_amount)->toBe('5.0000')
            ->and($fixture['invoice']->fresh()->remaining_amount)->toBe('15.0000')
            ->and($fixture['invoice']->fresh()->reversal_journal_entry_id)->toBeNull();
    } else {
        expect($fixture['payment']->fresh()->status)->toBe('cancelled')
            ->and($fixture['invoice']->fresh()->paid_amount)->toBe('0.0000')
            ->and($fixture['invoice']->fresh()->status)->toBe(PurchaseInvoice::StatusCancelled)
            ->and($fixture['invoice']->fresh()->reversal_journal_entry_id)->not->toBeNull();
    }
    foreach (JournalEntry::query()->where('company_id', $fixture['company']->id)->with('lines')->get() as $journal) {
        expect($journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
})->with(['receipt-create', 'payment-approve', 'payment-cancel']);

test('concurrent legacy purchase payment corrections keep one exact inverse and return the same canonical voucher', function (): void {
    if (getenv('MGYPACK_PROCUREMENT_CORRECTION_RACE') !== '1') {
        $this->markTestSkipped('Explicit isolated committed SYNTHETIC two-connection acceptance only.');
    }
    $fixture = DB::transaction(fn (): array => procurementLegacyScheduledPaymentFixture());
    $operation = ['operation' => 'purchase-scheduled-payment-correct', 'user' => $fixture['user']->id, 'voucher' => $fixture['voucher']->id];
    $results = closurePostgresRace([$operation, $operation], orderedCompanyLock: true);
    expect(array_column($results, 'root_transaction_attempts'))->toBe([1, 1])->and(array_column($results, 'result'))->toBe(['applied', 'applied'])
        ->and(array_column($results, 'id'))->toBe([$fixture['voucher']->id, $fixture['voucher']->id]);
    $inverse = JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', CashVoucherService::SourcePaymentReversal)->sole();
    expect($fixture['voucher_journal']->fresh()->reversed_entry_id)->toBe($inverse->id)->and($fixture['voucher']->fresh()->status)->toBe('cancelled');
    foreach ($fixture['voucher_journal']->lines as $line) {
        $counterpart = $inverse->lines->firstWhere('account_id', $line->account_id);
        expect($counterpart->debit_amount)->toBe($line->credit_amount)->and($counterpart->credit_amount)->toBe($line->debit_amount);
    }
});
