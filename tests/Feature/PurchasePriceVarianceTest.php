<?php

use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;

require_once __DIR__.'/../ProcurementSupport.php';

test('PPV is required only for an actual variance and never changes receipt inventory or duplicates posting', function (bool $hasAccount, string $price, string $variance): void {
    expect(DB::getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:')
        ->and(collect(DB::select('PRAGMA database_list'))->firstWhere('name', 'main')->file)->toBe('');
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111099', 'SYNTHETIC PPV Supplier');
    $fixture['firstSupplier']->update(['account_id' => $supplierAccount->id]);
    $accountQuery = Account::query()->forCompany($fixture['company']->id)
        ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::PurchasePriceVariance));
    $varianceAccountId = (clone $accountQuery)->sole()->id;
    if (! $hasAccount) {
        $accountQuery->update(['is_postable' => false]);
    }
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(), 'exchange_rate' => 1,
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC PPV regression',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '100', 'unit_price' => '10', 'tax_rate' => '0']],
    ])['record']);
    $orderLine = $order->lines->sole();
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->receive($order, [
        'document_date' => now()->toDateString(), 'supplier_delivery_note' => 'SYNTHETIC-PPV',
        'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => '100']],
    ]);
    $receiptLine = $receipt->lines->sole();
    $receiving->inspect($receipt, [
        'inspection_at' => now()->toDateString(),
        'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'accepted_quantity' => '100',
            'rejected_quantity' => '0', 'disposition' => 'accepted']],
    ]);
    $receiving->postReceipt($receipt->fresh());
    $receiptLine->refresh();
    $movement = InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receiptLine->id)->sole();
    expect($movement->unit_cost)->toBe('10.00000000')->and($movement->total_cost)->toBe('1000.00000000');

    $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([
        ['quantity' => '100', 'unit_price' => $price, 'tax_rate' => '0'],
    ], null, 0);
    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9851, 'doc_num' => 'SYNTHETIC-PINV-PPV',
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'supplier_id' => $fixture['firstSupplier']->id,
        'purchase_order_id' => $order->id, 'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->id, 'exchange_rate' => 1,
        ...$calculation['invoice'], 'remaining_amount' => $calculation['invoice']['total_amount'],
        'status' => PurchaseInvoice::StatusDraft,
    ]);
    $invoiceLine = $invoice->lines()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'line_number' => 1, 'product_id' => $fixture['raw']->id, 'unit_id' => $fixture['unit']->id,
        'purchase_order_line_id' => $orderLine->id, 'receipt_line_id' => $receiptLine->id,
        ...$calculation['lines'][0],
    ]);
    $before = [$invoice->fresh()->getRawOriginal(), $invoiceLine->fresh()->getRawOriginal(),
        $receiptLine->fresh()->getRawOriginal(), $movement->fresh()->getRawOriginal(),
        JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    $invoices = app(PurchaseInvoiceService::class);
    if (! $hasAccount && bccomp($variance, '0', 4) !== 0) {
        foreach (range(1, 2) as $attempt) {
            expect(fn () => $invoices->approve($invoice))->toThrow(DomainException::class, PostingAccountResolver::PurchasePriceVariance);
            expect([$invoice->fresh()->getRawOriginal(), $invoiceLine->fresh()->getRawOriginal(),
                $receiptLine->fresh()->getRawOriginal(), $movement->fresh()->getRawOriginal(),
                JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($before);
        }

        return;
    }

    $posted = $invoices->approve($invoice);
    $journal = $posted->journalEntry()->with('lines')->firstOrFail();
    $grniAccount = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::GoodsReceivedNotInvoiced, 'Purchase invoice');
    $varianceLines = $journal->lines->where('account_id', $varianceAccountId);
    expect($posted->status)->toBe(PurchaseInvoice::StatusApproved)
        ->and(bcadd((string) $invoiceLine->fresh()->purchase_price_variance, '0', 4))->toBe($variance)
        ->and($receiptLine->fresh()->grni_cleared_quantity)->toBe('100.00000000')
        ->and($receiptLine->fresh()->grni_cleared_value)->toBe('1000.0000')
        ->and(bcadd((string) $journal->lines->where('account_id', $grniAccount->id)->sum('debit_amount'), '0', 4))->toBe('1000.0000')
        ->and(bcadd((string) $journal->lines->where('account_id', $supplierAccount->id)->sum('credit_amount'), '0', 4))->toBe($calculation['invoice']['total_amount'])
        ->and(bcsub((string) $varianceLines->sum('debit_amount'), (string) $varianceLines->sum('credit_amount'), 4))->toBe($variance)
        ->and(bccomp((string) $journal->lines->sum('debit_amount'), (string) $journal->lines->sum('credit_amount'), 4))->toBe(0)
        ->and($movement->fresh()->getRawOriginal())->toBe($before[3])
        ->and(InventoryTransaction::query()->count())->toBe($before[5]);
    $after = [$journal->id, JournalEntry::query()->count(), $receiptLine->fresh()->getRawOriginal(),
        $invoiceLine->fresh()->getRawOriginal(), InventoryTransaction::query()->count()];
    $repeated = $invoices->approve($posted);
    expect([$repeated->journal_entry_id, JournalEntry::query()->count(), $receiptLine->fresh()->getRawOriginal(),
        $invoiceLine->fresh()->getRawOriginal(), InventoryTransaction::query()->count()])->toBe($after);
})->with([
    'present zero' => [true, '10', '0.0000'],
    'absent zero' => [false, '10', '0.0000'],
    'present positive' => [true, '12', '200.0000'],
    'present negative' => [true, '8', '-200.0000'],
    'absent positive' => [false, '12', '200.0000'],
    'absent negative' => [false, '8', '-200.0000'],
]);
