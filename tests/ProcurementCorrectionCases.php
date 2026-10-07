<?php

use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementCorrectionPlanService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerReceipt;

require_once __DIR__.'/ProcurementCorrectionSupport.php';

test('purchase correction refuses a mismatched canonical journal before any financial or stock mutation', function (string $kind): void {
    $fixture = procurementCorrectionFixture();
    $invoices = app(PurchaseInvoiceService::class);
    $receiving = app(ProcurementReceivingService::class);
    $settlements = app(ProcurementSettlementService::class);
    if (in_array($kind, ['invoice', 'return'], true)) {
        $fixture['invoice'] = $invoices->approve($invoices->create($fixture['invoice_data'])['record']);
    }
    if (in_array($kind, ['return', 'grni_return'], true)) {
        procurementUseBranch($fixture, $fixture['branch']);
        $return = $settlements->approvePurchaseReturn($settlements->createPurchaseReturn([
            'purchase_order_doc_num' => $fixture['order']->doc_num,
            'purchase_invoice_doc_num' => $fixture['invoice']->doc_num ?? null,
            'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC source journal integrity',
            'lines' => [['receipt_line_public_id' => $fixture['receipts']->first()->lines->sole()->public_id, 'quantity' => '1', 'from_quarantine' => false]],
        ]));
    }
    $record = match ($kind) {
        'invoice' => $fixture['invoice'], 'receipt' => $fixture['receipts']->first(), default => $return,
    };
    $journalId = match ($kind) {
        'invoice', 'return' => $record->journal_entry_id,
        'receipt' => $record->lines->sole()->grni_journal_entry_id,
        default => $record->grni_reversal_journal_entry_id,
    };
    $journal = JournalEntry::findOrFail($journalId);
    $otherPeriod = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC wrong source period',
        'from_date' => '2027-01-01', 'to_date' => '2027-12-31', 'is_closed' => false,
    ]);
    $stock = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get()->map->getRawOriginal()->all();
    $journalCount = JournalEntry::query()->where('company_id', $fixture['company']->id)->count();
    $state = $record->getRawOriginal();
    $reverse = match ($kind) {
        'invoice' => fn () => $invoices->reverse($record->fresh(), 'SYNTHETIC source rejection'),
        'receipt' => fn () => $receiving->reverseReceipt($record->fresh(), 'SYNTHETIC source rejection'),
        default => fn () => $settlements->reversePurchaseReturn($record->fresh(), 'SYNTHETIC source rejection'),
    };
    procurementUseBranch($fixture, $kind === 'invoice' ? $fixture['admin'] : $fixture['branch']);
    foreach (['source_id' => $journal->source_id + 1000000, 'source_type' => 'SYNTHETIC_wrong_source',
        'financial_period_id' => $otherPeriod->id, 'branch_id' => $journal->branch_id === $fixture['admin']->id ? $fixture['branch']->id : $fixture['admin']->id,
        'entry_date' => '2020-01-01', 'exchange_rate' => '2', 'is_posted' => false, 'status' => JournalEntry::StatusDraft] as $field => $value) {
        $before = $journal->getRawOriginal($field);
        $journal->forceFill([$field => $value])->save();
        expect($reverse)->toThrow(DomainException::class)
            ->and($record->fresh()->getRawOriginal())->toBe($state)
            ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get()->map->getRawOriginal()->all())->toBe($stock)
            ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->count())->toBe($journalCount)
            ->and($journal->fresh()->reversed_entry_id)->toBeNull();
        $journal->forceFill([$field => $before])->save();
    }
})->with(['invoice', 'receipt', 'return', 'grni_return']);

test('historical purchase correction selection rejects foreign source periods stale approval tokens and a closed posting target', function (): void {
    $fixture = procurementCorrectionFixture();
    $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
    $foreign = Company::query()->create([
        ...app(DocumentNumberService::class)->next('companies', Company::class),
        'name' => 'SYNTHETIC foreign period owner', 'status' => 'active',
    ]);
    $foreignPeriod = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $foreign->id, 'name' => 'SYNTHETIC foreign source period',
        'from_date' => now()->startOfYear()->toDateString(), 'to_date' => now()->endOfYear()->toDateString(), 'is_closed' => true,
    ]);
    $data = ['document_type' => OpenDocumentsService::PurchaseInvoices,
        'from_number' => $fixture['invoice']->doc_number, 'to_number' => $fixture['invoice']->doc_number,
        'source_period_doc_num' => $foreignPeriod->doc_num];
    $this->postJson(route('admin.tools.open-documents.preview'), $data)->assertUnprocessable()->assertJsonValidationErrors('source_period_doc_num');
    $data['source_period_doc_num'] = $fixture['period']->doc_num;
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $data)->assertOk();
    $fixture['period']->update(['name' => 'SYNTHETIC changed source approval context']);
    $this->postJson(route('admin.tools.open-documents.store'), $data + ['reason' => 'SYNTHETIC stale period approval',
        'preview_token' => $preview->json('preview_token')])->assertUnprocessable();
    $fixture['period']->update(['is_closed' => true]);
    $closedPreview = $this->postJson(route('admin.tools.open-documents.preview'), $data)->assertOk()->assertJsonPath('period_open', false);
    $this->postJson(route('admin.tools.open-documents.store'), $data + ['reason' => 'SYNTHETIC closed posting target',
        'preview_token' => $closedPreview->json('preview_token')])->assertUnprocessable();
    expect($fixture['invoice']->fresh()->reversal_journal_entry_id)->toBeNull()
        ->and($fixture['receipts']->first()->fresh()->posting_status)->toBe('posted');
});

test('legacy scheduled purchase payment has a real authorized current-period correction route and exact journal history', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 29));
    $fixture = procurementLegacyScheduledPaymentFixture();
    $cash = app(CashVoucherService::class);
    $original = $fixture['voucher_journal'];
    $originalLines = $original->lines->map->getRawOriginal()->all();
    $step = app(ProcurementCorrectionPlanService::class)->forInvoice($fixture['invoice'], request())[0];
    expect($step['kind'])->toBe('voucher')->and($step['doc_num'])->toBe($fixture['voucher']->doc_num)->and($step['permitted'])->toBeTrue();
    expect(fn () => $cash->cancelGeneric(CashVoucher::TypePayment, $fixture['voucher'], 'SYNTHETIC ordinary cancellation'))->toThrow(DomainException::class);
    $fixture['source_period'] = $fixture['period'];
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC legacy cash October correction',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 3));
    procurementUseBranch($fixture, $fixture['admin']);
    $this->get(route('admin.finance.cash-payment-vouchers.show', $fixture['voucher']->doc_num))->assertOk()
        ->assertSee(route('admin.finance.cash-payment-vouchers.correct-purchase-payment', $fixture['voucher']->doc_num), false);
    $route = route('admin.finance.cash-payment-vouchers.correct-purchase-payment', $fixture['voucher']->doc_num);
    $this->postJson($route, ['cancel_reason' => ['SYNTHETIC invalid array']])->assertUnprocessable()->assertJsonValidationErrors('cancel_reason');
    $fixture['user']->revokePermissionTo('purchase_invoices.reverse');
    $this->postJson($route, ['cancel_reason' => 'SYNTHETIC missing permission'])->assertForbidden();
    $fixture['user']->givePermissionTo('purchase_invoices.reverse');
    $this->withoutExceptionHandling();
    $this->postJson($route, ['cancel_reason' => 'SYNTHETIC confirmed cash recovery'])->assertOk();
    $inverse = $original->fresh()->reversedEntry;
    expect($inverse->financial_period_id)->toBe($fixture['period']->id)->and($inverse->entry_date->toDateString())->toBe('2026-10-03')
        ->and($original->fresh()->financial_period_id)->toBe($fixture['source_period']->id)
        ->and($original->fresh()->lines->map->getRawOriginal()->all())->toBe($originalLines)
        ->and($fixture['voucher']->fresh()->status)->toBe(CashVoucher::StatusCancelled)
        ->and($fixture['schedule']->fresh()->status)->toBe(PurchaseInvoicePaymentSchedule::StatusCancelled)
        ->and($fixture['source_period']->fresh()->is_closed)->toBeTrue();
    foreach ([$fixture['cashGl']->id, $fixture['payable']->id] as $accountId) {
        $lines = JournalEntryLine::query()->where('journal_entry_id', $original->id)->orWhere('journal_entry_id', $inverse->id)->get()->where('account_id', $accountId);
        expect($lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
    $fixture['period']->update(['is_closed' => true]);
    $this->postJson($route, ['cancel_reason' => 'SYNTHETIC replay'])->assertOk();
    expect(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', CashVoucherService::SourcePaymentReversal)->count())->toBe(1);
    $this->travelBack();
});

test('legacy scheduled purchase correction refuses wrong source period and substituted balanced accounts atomically', function (): void {
    $fixture = procurementLegacyScheduledPaymentFixture();
    $cash = app(CashVoucherService::class);
    $journal = $fixture['voucher_journal'];
    $other = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC unrelated payment period',
        'from_date' => '2027-01-01', 'to_date' => '2027-12-31', 'is_closed' => false,
    ]);
    foreach (['period', 'account'] as $kind) {
        $line = $journal->lines->firstWhere('account_id', $fixture['payable']->id);
        if ($kind === 'period') {
            $journal->update(['financial_period_id' => $other->id]);
        } else {
            $journal->update(['financial_period_id' => $fixture['period']->id]);
            $line->update(['account_id' => $fixture['bankGl']->id]);
        }
        expect(fn () => $cash->correctScheduledPurchasePayment($fixture['voucher']->fresh(), 'SYNTHETIC incorrect original'))->toThrow(DomainException::class)
            ->and($fixture['voucher']->fresh()->isApproved())->toBeTrue()
            ->and($fixture['schedule']->fresh()->status)->toBe(PurchaseInvoicePaymentSchedule::StatusPaid)
            ->and($journal->fresh()->reversed_entry_id)->toBeNull()
            ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', CashVoucherService::SourcePaymentReversal)->exists())->toBeFalse();
    }
});

test('legacy purchase payment correction detects a soft deleted competing posting owner and leaves every effect unchanged', function (string $ownerType): void {
    $fixture = procurementLegacyScheduledPaymentFixture();
    $common = ['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['admin']->id, 'cash_voucher_id' => $fixture['voucher']->id,
        'currency_id' => $fixture['currency']->id, 'amount' => '5', 'exchange_rate' => '1'];
    if ($ownerType === 'customer_receipt') {
        $customer = Customer::query()->create([
            ...app(DocumentNumberService::class)->next('customers', Customer::class),
            'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC competing customer', 'status' => 'active',
        ]);
        $owner = CustomerReceipt::query()->create($common + [
            'doc_number' => 9900, 'doc_num' => 'SYNTHETIC-COMPETING-RECEIPT', 'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(), 'receipt_type' => 'collection', 'payment_method' => 'cash',
        ]);
    } else {
        $owner = ProductionExpenseRequest::query()->create($common + [
            'doc_number' => 9900, 'doc_num' => 'SYNTHETIC-COMPETING-EXPENSE', 'request_date' => now()->toDateString(),
            'expense_account_id' => $fixture['payable']->id, 'reason' => 'SYNTHETIC competing production source',
        ]);
    }
    $owner->delete();
    $voucher = $fixture['voucher']->fresh()->getRawOriginal();
    $schedule = $fixture['schedule']->fresh()->getRawOriginal();
    $audits = DB::table(config('activitylog.table_name', 'activity_log'))->where('log_name', 'purchases')->count();
    expect(fn () => app(CashVoucherService::class)->correctScheduledPurchasePayment($fixture['voucher'], 'SYNTHETIC ambiguous owner rejection'))
        ->toThrow(DomainException::class)->and($fixture['voucher']->fresh()->getRawOriginal())->toBe($voucher)
        ->and($fixture['schedule']->fresh()->getRawOriginal())->toBe($schedule)
        ->and($fixture['voucher_journal']->fresh()->reversed_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', CashVoucherService::SourcePaymentReversal)->exists())->toBeFalse()
        ->and(DB::table(config('activitylog.table_name', 'activity_log'))->where('log_name', 'purchases')->count())->toBe($audits);
})->with(['customer_receipt', 'production_expense']);

test('reversed receipt cannot acquire a new supplier invoice header or lines after its row lock', function (): void {
    $fixture = procurementCorrectionFixture();
    procurementUseBranch($fixture, $fixture['branch']);
    app(ProcurementReceivingService::class)->reverseReceipt($fixture['receipts']->first(), 'SYNTHETIC incorrect receipt');
    procurementUseBranch($fixture, $fixture['admin']);
    $before = PurchaseInvoice::query()->where('company_id', $fixture['company']->id)->count();
    expect(fn () => app(PurchaseInvoiceService::class)->create($fixture['invoice_data']))->toThrow(DomainException::class)
        ->and(PurchaseInvoice::query()->where('company_id', $fixture['company']->id)->count())->toBe($before);
});

test('partial purchase correction follows each reviewed return payment invoice and receipt step with valid intermediate balances', function (): void {
    $fixture = procurementCorrectionFixture();
    $invoices = app(PurchaseInvoiceService::class);
    $settlements = app(ProcurementSettlementService::class);
    $receiving = app(ProcurementReceivingService::class);
    $fixture['invoice'] = $invoices->approve($invoices->create($fixture['invoice_data'])['record']);
    $payments = collect();
    foreach (['5', '7'] as $amount) {
        $payments->push($settlements->approveSupplierPayment($settlements->createSupplierPayment(procurementCorrectionPaymentData($fixture, $amount))));
    }
    procurementUseBranch($fixture, $fixture['branch']);
    $return = $settlements->approvePurchaseReturn($settlements->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num, 'purchase_invoice_doc_num' => $fixture['invoice']->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC correction',
        'lines' => [['receipt_line_public_id' => $fixture['receipts']->first()->lines->sole()->public_id,
            'quantity' => '1', 'from_quarantine' => false]],
    ]));
    $steps = app(ProcurementCorrectionPlanService::class)->forReceipt($fixture['receipts']->first(), request());
    expect(array_column($steps, 'kind'))->toBe(['return', 'payment', 'payment', 'invoice', 'receipt'])
        ->and(array_column($steps, 'permitted'))->toBe([true, true, true, true, true]);
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), ['document_type' => OpenDocumentsService::PurchaseReceipts,
        'from_number' => $fixture['receipts']->first()->doc_number, 'to_number' => $fixture['receipts']->first()->doc_number])->assertOk();
    $preview->assertJsonPath('documents.0.decision', 'blocked')->assertJsonCount(5, 'documents.0.correction_steps');
    $settlements->reversePurchaseReturn($return, 'SYNTHETIC return evidence corrected');
    procurementUseBranch($fixture, $fixture['admin']);
    foreach ($payments as $payment) {
        $settlements->cancelSupplierPayment($payment, 'SYNTHETIC confirmed payment recovery');
        $settlements->cancelSupplierPayment($payment->fresh(), 'SYNTHETIC replay recovery');
    }
    expect($fixture['invoice']->fresh()->remaining_amount)->toBe('20.0000');
    $closed = $invoices->close($fixture['invoice']->fresh());
    expect(fn () => $invoices->cancel($closed, 'SYNTHETIC forbidden ordinary cancellation'))->toThrow(DomainException::class)
        ->and($invoices->reversalPlan($closed)['can_reverse'])->toBeTrue();
    $invoices->reverse($closed, 'SYNTHETIC separate reviewed invoice reversal');
    $invoices->reverse($closed->fresh(), 'SYNTHETIC replay invoice reversal');
    procurementUseBranch($fixture, $fixture['branch']);
    $receiving->reverseReceipt($fixture['receipts']->first(), 'SYNTHETIC first partial receipt correction');
    $rows = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get();
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))->toBe('6.00000000');
    $receiving->reverseReceipt($fixture['receipts']->last(), 'SYNTHETIC second partial receipt correction');
    expect($fixture['receipts']->first()->fresh()->lines->sole()->accepted_quantity)->toBe('4.00000000')
        ->and($fixture['receipts']->last()->fresh()->lines->sole()->accepted_quantity)->toBe('6.00000000');
    foreach ([$fixture['payable']->id, $fixture['bankGl']->id] as $accountId) {
        $lines = JournalEntryLine::query()->where('account_id', $accountId)->get();
        expect($lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
    foreach (JournalEntry::query()->where('company_id', $fixture['company']->id)->with('lines')->get() as $journal) {
        expect($journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
    expect(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get()
        ->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))->toBe('0.00000000');
});

test('draft return correction cancels the retained owner document and clears the invoice blocker', function (): void {
    $fixture = procurementCorrectionFixture();
    $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
    procurementUseBranch($fixture, $fixture['branch']);
    $return = app(ProcurementSettlementService::class)->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num, 'purchase_invoice_doc_num' => $fixture['invoice']->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC abandoned return draft',
        'lines' => [['receipt_line_public_id' => $fixture['receipts']->first()->lines->sole()->public_id,
            'quantity' => '1', 'from_quarantine' => false]],
    ]);
    $fixture['user']->revokePermissionTo('purchases.purchase_returns.reverse');
    $step = app(ProcurementCorrectionPlanService::class)->forReceipt($fixture['receipts']->first(), request())[0];
    expect($step['action_route'])->toBe('admin.purchases.purchase-returns.cancel')->and($step['permitted'])->toBeTrue();
    $lines = $return->lines->map->getRawOriginal()->all();
    $stock = InventoryTransaction::query()->get()->map->getRawOriginal()->all();
    $journals = JournalEntry::query()->get()->map->getRawOriginal()->all();
    $payload = ['cancel_reason' => 'SYNTHETIC abandoned return retained', '_submission_token' => (string) Str::uuid()];
    $this->postJson(route($step['action_route'], $return->doc_num), $payload)->assertOk();
    $this->postJson(route($step['action_route'], $return->doc_num), $payload)->assertOk();
    expect($return->fresh()->status)->toBe(PurchaseReturn::StatusCancelled)
        ->and($return->fresh()->trashed())->toBeFalse()->and($return->fresh()->cancel_reason)->toBe($payload['cancel_reason'])
        ->and($return->fresh()->lines->map->getRawOriginal()->all())->toBe($lines)
        ->and(InventoryTransaction::query()->get()->map->getRawOriginal()->all())->toBe($stock)
        ->and(JournalEntry::query()->get()->map->getRawOriginal()->all())->toBe($journals);
    procurementUseBranch($fixture, $fixture['admin']);
    expect(app(PurchaseInvoiceService::class)->reversalPlan($fixture['invoice']->fresh())['can_reverse'])->toBeTrue();
});

test('cash payment correction refuses mismatched canonical history and rolls back both voucher and payment', function (string $field, string $value): void {
    $fixture = procurementCorrectionFixture();
    $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
    $data = procurementCorrectionPaymentData($fixture, '5');
    $data['payment_method'] = 'cash';
    $data['cashbox_doc_num'] = $fixture['cashbox']->doc_num;
    $settlements = app(ProcurementSettlementService::class);
    $payment = $settlements->approveSupplierPayment($settlements->createSupplierPayment($data));
    if ($field === 'journal_branch') {
        $payment->journalEntry->update(['branch_id' => $fixture['branch']->id]);
    } else {
        $payment->cashVoucher->update([$field => $value]);
    }
    expect(fn () => $settlements->cancelSupplierPayment($payment->fresh(), 'SYNTHETIC mismatched journal correction'))->toThrow(DomainException::class)
        ->and($payment->fresh()->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and($payment->fresh()->cashVoucher->isApproved())->toBeTrue()
        ->and($fixture['invoice']->fresh()->paid_amount)->toBe('5.0000')
        ->and(JournalEntry::query()->where('company_id', $fixture['company']->id)->where('source_type', 'supplier_payment_reversal')->exists())->toBeFalse();
})->with([
    'journal branch' => ['journal_branch', ''],
    'voucher date' => ['voucher_date', '2026-01-01'],
    'voucher exchange' => ['exchange_rate', '2'],
    'voucher base amount' => ['amount_base', '50'],
]);

test('approved supplier payment recovery works from the current open period through the authorized route with historical source preserved', function (string $method): void {
    $this->travelTo(now()->setDate(2026, 9, 29));
    $fixture = procurementCorrectionFixture();
    $invoices = app(PurchaseInvoiceService::class);
    $settlements = app(ProcurementSettlementService::class);
    $fixture['invoice'] = $invoices->approve($invoices->create($fixture['invoice_data'])['record']);
    $paymentData = procurementCorrectionPaymentData($fixture, '5');
    $paymentData['payment_method'] = $method;
    $paymentData['cashbox_doc_num'] = $fixture['cashbox']->doc_num;
    if ($method === 'cheque') {
        $paymentData['cheque_number'] = 'SYNTHETIC-CORRECTION-'.$fixture['company']->id;
        $paymentData['cheque_date'] = now()->toDateString();
        $paymentData['cheque_due_date'] = now()->addDay()->toDateString();
    }
    $payment = $settlements->approveSupplierPayment($settlements->createSupplierPayment($paymentData));
    $fixture['invoice'] = $invoices->close($fixture['invoice']->fresh());
    if ($method === 'cash') {
        expect(fn () => app(CashVoucherService::class)->cancelGeneric(
            CashVoucher::TypePayment, $payment->cashVoucher, 'SYNTHETIC ordinary cancellation forbidden'))
            ->toThrow(DomainException::class);
    }
    $originalJournal = $payment->journalEntry->getAttributes();
    $fixture['source_period'] = $fixture['period'];
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC October correction',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 3));
    procurementUseBranch($fixture, $fixture['admin']);
    $url = route('admin.purchases.supplier-payments.cancel', $payment->doc_num);
    $this->postJson($url, ['cancel_reason' => 'SYNTHETIC confirmed bank recovery'])->assertOk();
    $this->postJson($url, ['cancel_reason' => 'SYNTHETIC replay bank recovery'])->assertOk();
    expect($payment->fresh()->status)->toBe(SupplierPaymentContext::StatusCancelled)
        ->and($payment->fresh()->financial_period_id)->toBe($fixture['source_period']->id)
        ->and($fixture['source_period']->fresh()->is_closed)->toBeTrue()
        ->and($fixture['invoice']->fresh()->paid_amount)->toBe('0.0000')
        ->and($fixture['invoice']->fresh()->remaining_amount)->toBe('20.0000');
    $reversal = JournalEntry::query()->where('company_id', $fixture['company']->id)
        ->where('source_type', 'supplier_payment_reversal')->where('source_id', $payment->id)->sole();
    expect($reversal->financial_period_id)->toBe($fixture['period']->id)
        ->and($reversal->entry_date->toDateString())->toBe('2026-10-03');
    $after = $payment->fresh()->journalEntry->getAttributes();
    unset($after['reversed_entry_id'], $after['updated_at'], $originalJournal['reversed_entry_id'], $originalJournal['updated_at']);
    expect($after)->toBe($originalJournal);
    $fixture['period']->update(['is_closed' => true]);
    $this->postJson($url, ['cancel_reason' => 'SYNTHETIC replay after period closed'])->assertOk();
    expect(JournalEntry::query()->where('company_id', $fixture['company']->id)
        ->where('source_type', 'supplier_payment_reversal')->where('source_id', $payment->id)->count())->toBe(1);
    $this->travelBack();
})->with(['bank', 'cash', 'cheque']);

test('closed historical purchase chain corrects through signed source-period previews and current-period stock and GL without reopening history', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 29));
    $fixture = procurementCorrectionFixture();
    $invoices = app(PurchaseInvoiceService::class);
    $settlements = app(ProcurementSettlementService::class);
    $fixture['invoice'] = $invoices->close($invoices->approve($invoices->create($fixture['invoice_data'])['record']));
    $payment = $settlements->approveSupplierPayment($settlements->createSupplierPayment(procurementCorrectionPaymentData($fixture, '5')));
    procurementUseBranch($fixture, $fixture['branch']);
    $return = $settlements->approvePurchaseReturn($settlements->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num, 'purchase_invoice_doc_num' => $fixture['invoice']->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'SYNTHETIC historical correction',
        'lines' => [['receipt_line_public_id' => $fixture['receipts']->first()->lines->sole()->public_id,
            'quantity' => '1', 'from_quarantine' => false]],
    ]));
    $originals = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get()->keyBy('id')
        ->map(fn ($record): array => $record->getRawOriginal());
    $journals = JournalEntry::query()->where('company_id', $fixture['company']->id)->get();
    $fixture['source_period'] = $fixture['period'];
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC October historical purchase correction',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 3));
    procurementUseBranch($fixture, $fixture['branch']);
    $settlements->reversePurchaseReturn($return, 'SYNTHETIC historical return recovery');
    procurementUseBranch($fixture, $fixture['admin']);
    $settlements->cancelSupplierPayment($payment, 'SYNTHETIC historical confirmed bank recovery');
    $invoiceData = ['document_type' => OpenDocumentsService::PurchaseInvoices,
        'from_number' => $fixture['invoice']->doc_number, 'to_number' => $fixture['invoice']->doc_number,
        'source_period_doc_num' => $fixture['source_period']->doc_num];
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $invoiceData)->assertOk()->assertJsonPath('documents.0.decision', 'ready_reverse');
    $this->postJson(route('admin.tools.open-documents.store'), $invoiceData + ['reason' => 'SYNTHETIC later-period invoice correction',
        'preview_token' => $preview->json('preview_token')])->assertOk()->assertJsonPath('summary.opened', 1);
    procurementUseBranch($fixture, $fixture['branch']);
    foreach ($fixture['receipts'] as $receipt) {
        $data = ['document_type' => OpenDocumentsService::PurchaseReceipts, 'from_number' => $receipt->doc_number,
            'to_number' => $receipt->doc_number, 'source_period_doc_num' => $fixture['source_period']->doc_num];
        $preview = $this->postJson(route('admin.tools.open-documents.preview'), $data)->assertOk()->assertJsonPath('documents.0.decision', 'ready_reverse');
        $this->postJson(route('admin.tools.open-documents.store'), $data + ['reason' => 'SYNTHETIC later-period receipt correction',
            'preview_token' => $preview->json('preview_token')])->assertOk()->assertJsonPath('summary.opened', 1);
    }
    expect($fixture['source_period']->fresh()->is_closed)->toBeTrue()
        ->and($fixture['invoice']->fresh()->financial_period_id)->toBe($fixture['source_period']->id);
    foreach ($originals as $id => $snapshot) {
        expect(InventoryTransaction::findOrFail($id)->getRawOriginal())->toBe($snapshot);
    }
    foreach ($journals as $journal) {
        expect($journal->fresh()->financial_period_id)->toBe($fixture['source_period']->id);
    }
    $rows = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get();
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))->toBe('0.00000000')
        ->and($rows->reduce(fn (string $sum, $row): string => bcadd($sum, $row->signedValue(), 8), '0'))->toBe('0.00000000');
    foreach ($rows->whereNotIn('id', $originals->keys()->all()) as $row) {
        expect($row->financial_period_id)->toBe($fixture['period']->id)->and($row->transaction_date->toDateString())->toBe('2026-10-03');
    }
    foreach (JournalEntry::query()->where('company_id', $fixture['company']->id)->whereNotIn('id', $journals->modelKeys())->get() as $journal) {
        expect($journal->financial_period_id)->toBe($fixture['period']->id)->and($journal->entry_date->toDateString())->toBe('2026-10-03');
    }
    foreach (JournalEntryLine::query()->whereHas('journalEntry', fn ($query) => $query->where('company_id', $fixture['company']->id))->get()->groupBy('account_id') as $lines) {
        expect($lines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0'))->toBe('0.0000');
    }
    $this->travelBack();
});
