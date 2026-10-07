<?php

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProcurementSupport.php';

/** @return array<string, mixed> */
function procurementCorrectionFixture(): array
{
    $fixture = procurementFixture(true);
    $fixture['company']->update(['name' => 'SYNTHETIC purchase correction '.$fixture['company']->id]);
    $fixture['admin'] = procurementAdministrativeBranch($fixture);
    foreach (['purchases.prices.view', 'purchase_invoices.view', 'purchase_invoices.reverse', 'supplier_payments.view',
        'supplier_payments.cancel', 'supplier_payments.approve', 'purchases.purchase_returns.view', 'purchases.purchase_returns.delete', 'purchases.purchase_returns.cancel',
        'purchases.purchase_returns.reverse', 'purchases.goods_receipt_notes.view', 'purchases.goods_receipt_notes.reverse',
        'tools.open_documents.view', 'tools.open_documents.open'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    test()->actingAs($fixture['user']);
    $fixture['payable'] = procurementPostingAccount($fixture['company'], '2111', '2111098', 'SYNTHETIC correction payable');
    $fixture['bankGl'] = procurementPostingAccount($fixture['company'], '1112', '1112098', 'SYNTHETIC correction bank');
    $fixture['firstSupplier']->update(['account_id' => $fixture['payable']->id]);
    $fixture['bank'] = BankAccount::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 9900, 'doc_num' => 'SYNTHETIC-BANK-CORRECTION-'.$fixture['company']->id,
        'bank_id' => Account::query()->where('company_id', $fixture['company']->id)->where('account_code', '1112')->value('id'),
        'account_id' => $fixture['bankGl']->id, 'currency_id' => $fixture['currency']->id,
        'account_name' => 'SYNTHETIC acceptance bank', 'account_number' => 'SYNTHETIC-NOT-A-REAL-ACCOUNT', 'status' => 'active']);
    $fixture['cashGl'] = procurementPostingAccount($fixture['company'], '1111', '1111098', 'SYNTHETIC correction cash');
    $fixture['cashbox'] = Cashbox::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 9900, 'doc_num' => 'SYNTHETIC-CASH-CORRECTION-'.$fixture['company']->id,
        'branch_id' => $fixture['admin']->id, 'account_id' => $fixture['cashGl']->id,
        'name' => 'SYNTHETIC acceptance cashbox', 'status' => 'active']);
    CashboxCurrency::query()->create(['cashbox_id' => $fixture['cashbox']->id, 'currency_id' => $fixture['currency']->id,
        'is_default' => true, 'status' => 'active']);
    procurementUseBranch($fixture, $fixture['admin']);
    $fixture['order'] = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '10', 'unit_price' => '2']],
    ])['record']);
    procurementUseBranch($fixture, $fixture['branch']);
    $fixture['receipts'] = collect();
    $receiving = app(ProcurementReceivingService::class);
    foreach (['4', '6'] as $quantity) {
        $receipt = $receiving->createReceipt($fixture['order'], ['document_date' => now()->toDateString(),
            'lines' => [['purchase_order_line_public_id' => $fixture['order']->lines->sole()->public_id, 'delivered_quantity' => $quantity]]]);
        if ($receipt->qc_status === 'pending_inspection') {
            $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id,
                'accepted_quantity' => $quantity, 'rejected_quantity' => '0']]]);
        }
        $fixture['receipts']->push($receiving->postReceipt($receipt->fresh())->load('lines'));
    }
    procurementUseBranch($fixture, $fixture['admin']);
    $fixture['invoice_data'] = ['purchase_order_doc_num' => $fixture['order']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => '1', 'invoice_date' => now()->toDateString(), 'payment_type' => 'credit',
        'lines' => $fixture['receipts']->map(fn ($receipt): array => [
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $fixture['order']->lines->sole()->public_id,
            'receipt_line_public_id' => $receipt->lines->sole()->public_id,
            'quantity' => $receipt->lines->sole()->accepted_quantity, 'unit_price' => '2',
        ])->all()];

    return $fixture;
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function procurementCorrectionPaymentData(array $fixture, string $amount): array
{
    return ['supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'payment_date' => now()->toDateString(), 'payment_method' => 'bank', 'bank_account_doc_num' => $fixture['bank']->doc_num,
        'amount' => $amount, 'reason' => 'SYNTHETIC correction settlement',
        'allocations' => [['purchase_invoice_doc_num' => $fixture['invoice']->doc_num, 'amount' => $amount]]];
}

/** @return array<string, mixed> */
function procurementLegacyScheduledPaymentFixture(): array
{
    $fixture = procurementCorrectionFixture();
    foreach (['cash_payment_vouchers.view', 'cash_payment_vouchers.cancel'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $fixture['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($fixture['invoice_data'])['record']);
    $cash = app(CashVoucherService::class);
    $fixture['voucher'] = $cash->approveGeneric(CashVoucher::TypePayment,
        $cash->create(CashVoucher::TypePayment, [
            'voucher_date' => now()->toDateString(), 'cashbox_doc_num' => $fixture['cashbox']->doc_num,
            'currency_doc_num' => $fixture['currency']->doc_num, 'amount' => '5', 'person_name' => 'SYNTHETIC legacy supplier',
            'reason' => 'SYNTHETIC historical generic scheduled payment',
            'lines' => [['account_doc_num' => $fixture['payable']->doc_num, 'amount' => '5']],
        ])['record']);
    $fixture['voucher_journal'] = JournalEntry::query()->with('lines')
        ->where('company_id', $fixture['company']->id)->where('source_type', CashVoucherService::SourcePayment)
        ->where('source_id', $fixture['voucher']->id)->sole();
    $fixture['schedule'] = PurchaseInvoicePaymentSchedule::query()->create([
        'purchase_invoice_id' => $fixture['invoice']->id, 'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'line_number' => 1, 'due_date' => now()->toDateString(), 'payment_date' => now()->toDateString(), 'amount' => '5', 'paid_amount' => '5',
        'cashbox_id' => $fixture['cashbox']->id, 'cash_voucher_id' => $fixture['voucher']->id,
        'payment_source_type' => PurchaseInvoice::SourceCashbox,
        'status' => PurchaseInvoicePaymentSchedule::StatusPaid,
    ]);
    $fixture['invoice']->refresh();
    app(PurchaseInvoiceService::class)->close($fixture['invoice']);

    return $fixture;
}
