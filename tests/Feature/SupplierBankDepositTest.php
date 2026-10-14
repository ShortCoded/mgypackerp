<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\FinanceReportService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementSettlementService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../ProcurementSupport.php';

test('native outgoing supplier bank deposit persists its reference and uses one reversible canonical settlement', function (string $method): void {
    $f = procurementFixture();
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    foreach (['supplier_payments.create', 'supplier_payments.view', 'supplier_payments.print', 'supplier_payments.approve', 'supplier_payments.cancel', 'purchases.prices.view'] as $name) {
        Permission::findOrCreate($name, 'web');
        $f['user']->givePermissionTo($name);
    }
    $this->actingAs($f['user']);
    $payable = procurementPostingAccount($f['company'], '2111', '2111991', 'SYNTHETIC supplier payable');
    $bankGl = procurementPostingAccount($f['company'], '1112', '1112991', 'SYNTHETIC outgoing bank');
    $f['firstSupplier']->update(['account_id' => $payable->id]);
    $bank = BankAccount::query()->create(['company_id' => $f['company']->id, 'doc_num' => 'BANK-DEPOSIT-SYNTHETIC', 'doc_number' => 9991,
        'bank_id' => Account::query()->where('company_id', $f['company']->id)->where('account_code', '1112')->sole()->id,
        'account_id' => $bankGl->id, 'currency_id' => $f['currency']->id, 'account_name' => 'SYNTHETIC deposit source', 'account_number' => 'SYNTHETIC-001', 'status' => 'active']);
    $context = app(OperatingContextService::class)->snapshot(request());
    $invoice = PurchaseInvoice::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $context['branch_id'],
        'doc_num' => 'PINV-BANK-DEPOSIT-SYNTHETIC', 'doc_number' => 9991, 'supplier_id' => $f['firstSupplier']->id,
        'currency_id' => $f['currency']->id, 'invoice_date' => now()->toDateString(), 'total_amount' => '10', 'remaining_amount' => '10', 'status' => PurchaseInvoice::StatusApproved]);
    $payload = ['supplier_doc_num' => $f['firstSupplier']->doc_num, 'payment_method' => $method,
        'payment_date' => now()->toDateString(), 'currency_doc_num' => $f['currency']->doc_num, 'bank_account_doc_num' => $bank->doc_num,
        'reference_no' => ' SYNTHETIC-DEP-123 ', 'amount' => '4', 'exchange_rate' => '1',
        'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => '4']]];
    foreach (['ar', 'en'] as $locale) {
        $this->withSession(['locale' => $locale])->get(route('admin.purchases.supplier-payments.create'))->assertOk()->assertSee('value="bank_deposit"', false)->assertSee('name="reference_no"', false);
    }
    $this->postJson(route('admin.purchases.supplier-payments.store'), [...$payload, 'bank_account_doc_num' => null])->assertUnprocessable()->assertJsonValidationErrors('bank_account_doc_num');
    $this->postJson(route('admin.purchases.supplier-payments.store'), [...$payload, 'reference_no' => str_repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('reference_no');
    expect(SupplierPaymentContext::query()->count())->toBe(0);
    $response = $this->postJson(route('admin.purchases.supplier-payments.store'), $payload)->assertOk();
    $payment = SupplierPaymentContext::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($payment->payment_method)->toBe($method)->and($payment->reference_no)->toBe('SYNTHETIC-DEP-123')->and($payment->bank_account_id)->toBe($bank->id)
        ->and($payment->cash_voucher_id)->toBeNull()->and($payment->cheque_id)->toBeNull()->and(DB::table('journal_entries')->count())->toBe(0);
    $settlement = app(ProcurementSettlementService::class);
    $settlement->approveSupplierPayment($payment);
    $settlement->approveSupplierPayment($payment);
    $entry = JournalEntry::query()->with('lines')->where('source_type', 'supplier_payment')->where('source_id', $payment->id)->sole();
    expect($entry->lines->firstWhere('account_id', $payable->id)->debit_amount)->toBe('4.0000')
        ->and($entry->lines->firstWhere('account_id', $bankGl->id)->credit_amount)->toBe('4.0000')
        ->and($entry->lines->firstWhere('account_id', $bankGl->id)->bank_account_id)->toBe($bank->id)
        ->and($invoice->fresh()->paid_amount)->toBe('4.0000')->and($invoice->fresh()->remaining_amount)->toBe('6.0000');
    $this->get(route('admin.purchases.supplier-payments.show', $payment))->assertOk()->assertSee('SYNTHETIC-DEP-123');
    $pdf = $this->get(route('admin.purchases.procurement.print', ['supplier-payment', $payment->doc_num]))->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
    $file = tempnam(sys_get_temp_dir(), 'supplier-deposit-pdf-');
    file_put_contents($file, $pdf);
    try {
        $text = new Process(['pdftotext', '-layout', $file, '-']);
        $text->mustRun();
        expect($text->getOutput())->toContain('SYNTHETIC-DEP-123');
    } finally {
        unlink($file);
    }
    $filters = ['type' => FinanceReportService::BankAccountStatement, 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(), 'bank_account_doc_num' => $bank->doc_num];
    $statement = app(FinanceReportService::class)->report($filters);
    expect($statement['rows'])->toHaveCount(1)->and($statement['rows']->first()['payment'])->toBe('4.0000')->and($statement['rows']->first()['receipt'])->toBe('0.0000');
    $settlement->cancelSupplierPayment($payment->fresh(), 'SYNTHETIC returned outgoing deposit');
    $settlement->cancelSupplierPayment($payment->fresh(), 'SYNTHETIC repeated cancellation');
    $reversal = JournalEntry::query()->with('lines')->where('source_type', 'supplier_payment_reversal')->where('source_id', $payment->id)->sole();
    expect($reversal->lines->firstWhere('account_id', $bankGl->id)->debit_amount)->toBe('4.0000')
        ->and($invoice->fresh()->paid_amount)->toBe('0.0000')->and($invoice->fresh()->remaining_amount)->toBe('10.0000')
        ->and($payment->fresh()->reference_no)->toBe('SYNTHETIC-DEP-123');
})->with([SupplierPaymentContext::MethodBankDeposit, SupplierPaymentContext::MethodBankTransfer]);
