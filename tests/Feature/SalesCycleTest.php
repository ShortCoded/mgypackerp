<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrGovernorate;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\ElectronicInvoiceSubmission;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerSalesOverviewService;
use Modules\Sales\Services\ElectronicInvoicePayloadBuilder;
use Modules\Sales\Services\ElectronicInvoiceService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('sales reservations and deliveries preserve warehouse batch positions', function () {
    $fixture = salesCycleFixture();
    $opening = InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->firstOrFail();
    $opening->forceFill(['quantity_in' => '40', 'total_cost' => '200'])->save();
    InventoryTransaction::query()->create([
        ...$opening->only([
            'company_id', 'financial_period_id', 'branch_id', 'branch_store_id', 'transaction_date',
            'transaction_type', 'product_id', 'unit_id', 'stock_status', 'source_type', 'source_id',
            'source_doc_num', 'unit_cost', 'created_by',
        ]),
        'posting_key' => 'sales-cycle-opening-stock-second-batch',
        'batch_lot' => 'SALES-OPENING-BATCH-2',
        'quantity_in' => '60',
        'quantity_out' => 0,
        'total_cost' => '300',
    ]);

    $order = app(SalesOrderService::class)->approve(
        app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)),
    );
    $line = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $fulfillment = app(SalesFulfillmentService::class);
    $reservation = $fulfillment->reserve($line, '30');
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $line->getKey(),
        'quantity' => '60',
    ]]);

    expect($reservation->batch_lot)->toBe('SALES-OPENING-BATCH')
        ->and($delivery->lines)->toHaveCount(2)
        ->and($delivery->lines->pluck('batch_lot')->all())->toBe([
            'SALES-OPENING-BATCH',
            'SALES-OPENING-BATCH-2',
        ])
        ->and($delivery->lines->pluck('quantity')->all())->toBe(['40.00000000', '20.00000000'])
        ->and(InventoryTransaction::query()
            ->where('transaction_type', InventoryDocument::TypeSalesDelivery)
            ->whereNull('batch_lot')
            ->count())->toBe(0)
        ->and(InventoryTransaction::query()
            ->where('product_id', $fixture['finished']->getKey())
            ->groupBy('batch_lot')
            ->havingRaw('sum(quantity_in - quantity_out) < 0')
            ->exists())->toBeFalse();
});

test('stock sale, mixed service, installments, collection, and quality returns remain line-traceable', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $receipts = app(CustomerReceiptService::class);
    $returns = app(SalesReturnService::class);
    $order = $orders->create(salesCycleOrderPayload($fixture));
    $order = $orders->approve($order);
    expect($order->status)->toBe(SalesOrder::StatusApproved);
    $goodsLine = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $serviceLine = $order->lines->firstWhere('product_id', $fixture['service']->getKey());

    $reservation = $fulfillment->reserve($goodsLine, '30');
    expect($reservation->status)->toBe(InventoryReservation::StatusActive);
    $firstDelivery = $fulfillment->deliver($order, [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '60']]);
    expect($goodsLine->fresh()->remainingDeliveryQuantity())->toBe('40.00000000')
        ->and($firstDelivery->lines)->toHaveCount(1)
        ->and($firstDelivery->lines->first()->source_line_id)->toBe($goodsLine->getKey());
    $secondDelivery = $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '40']]);
    expect($order->fresh()->status)->toBe(SalesOrder::StatusFulfilled)
        ->and($reservation->fresh()->status)->toBe(InventoryReservation::StatusConsumed)
        ->and(InventoryTransaction::query()->where('transaction_type', 'sales_delivery')->count())->toBe(2)
        ->and(InventoryTransaction::query()->where('product_id', $serviceLine->product_id)->count())->toBe(0);
    expect(fn () => $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '1']]))->toThrow(DomainException::class);

    $invoice = $invoices->createFromOrder($order->fresh(), [
        ['sales_order_line_id' => $goodsLine->getKey(), 'delivery_line_id' => $firstDelivery->lines->first()->getKey(), 'quantity' => '60'],
        ['sales_order_line_id' => $goodsLine->getKey(), 'delivery_line_id' => $secondDelivery->lines->first()->getKey(), 'quantity' => '40'],
        ['sales_order_line_id' => $serviceLine->getKey(), 'quantity' => '1'],
    ], [
        ['due_date' => now()->addWeek()->toDateString(), 'amount' => '500'],
        ['due_date' => now()->addMonth()->toDateString(), 'amount' => '600'],
    ], $firstDelivery);
    $invoice = $invoices->post($invoice);
    expect($invoice->posting_status)->toBe('posted')->and($invoice->lines)->toHaveCount(3)->and($invoice->paymentSchedules)->toHaveCount(2);
    expect($invoice->deliveries()->pluck('inventory_documents.id')->sort()->values()->all())
        ->toBe(collect([$firstDelivery->getKey(), $secondDelivery->getKey()])->sort()->values()->all());
    expect($invoices->post($invoice)->journal_entry_id)->toBe($invoice->journal_entry_id);
    $deliveryJournals = JournalEntry::query()->where('source_type', 'sales_delivery_cogs')->pluck('id');
    expect($deliveryJournals)->toHaveCount(2)
        ->and(InventoryTransaction::query()->where('transaction_type', 'sales_delivery')->count())->toBe(2)
        ->and((float) DB::table('journal_entry_lines')->whereIn('journal_entry_id', $deliveryJournals)->sum('debit_amount'))->toEqual(500.0)
        ->and((float) DB::table('journal_entry_lines')->whereIn('journal_entry_id', $deliveryJournals)->sum('credit_amount'))->toEqual(500.0)
        ->and(InventoryTransaction::query()->where('transaction_type', 'sales_delivery')->sum('quantity_out'))->toEqual(100)
        ->and((float) $invoice->journalEntry->lines->sum('debit_amount'))->toEqual(1100.0)
        ->and((float) $invoice->journalEntry->lines->sum('credit_amount'))->toEqual(1100.0)
        ->and($invoice->journalEntry->lines->where('account_id', $fixture['customer']->account_id)->first()?->debit_amount)->toBe('1100.0000');

    $firstSchedule = $invoice->paymentSchedules->first();
    $secondSchedule = $invoice->paymentSchedules->last();
    expect(fn () => $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'transfer', 'cashbox_id' => $fixture['cashbox']->getKey(), 'amount' => '1', 'receipt_type' => CustomerReceipt::TypeCollection]))
        ->toThrow(DomainException::class, __('Bank, cheque, and transfer collections require a bank account.'));
    $firstReceipt = $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $fixture['cashbox']->getKey(), 'amount' => '500', 'receipt_type' => CustomerReceipt::TypeCollection], [['customer_invoice_payment_schedule_id' => $firstSchedule->getKey(), 'amount' => '500']]);
    expect($firstReceipt->unallocated_amount)->toBe('0.0000')
        ->and($firstReceipt->cash_voucher_id)->not->toBeNull()
        ->and($firstReceipt->cashVoucher?->status)->toBe(CashVoucher::StatusApproved)
        ->and($invoice->fresh()->remaining_amount)->toBe('600.0000');
    expect(fn () => $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $fixture['cashbox']->getKey(), 'amount' => '1', 'receipt_type' => CustomerReceipt::TypeCollection], [['customer_invoice_payment_schedule_id' => $firstSchedule->getKey(), 'amount' => '1']]))
        ->toThrow(DomainException::class);
    $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $fixture['cashbox']->getKey(), 'amount' => '650', 'receipt_type' => CustomerReceipt::TypeCollection], [['customer_invoice_payment_schedule_id' => $secondSchedule->getKey(), 'amount' => '600']]);
    expect($invoice->fresh()->remaining_amount)->toBe('0.0000')->and(CustomerReceipt::query()->latest('id')->value('unallocated_amount'))->toBe('50.0000');
    $bankReceipt = $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'transfer', 'bank_account_id' => $fixture['bankAccount']->getKey(), 'reference_no' => 'TRF-E2E-001', 'amount' => '10', 'receipt_type' => CustomerReceipt::TypeAdvance]);
    $chequeReceipt = $receipts->createAndApprove(['company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'customer_id' => $fixture['customer']->getKey(), 'receipt_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1, 'payment_method' => 'cheque', 'bank_account_id' => $fixture['bankAccount']->getKey(), 'reference_no' => 'CHQ-E2E-001', 'cheque_due_date' => now()->addWeek()->toDateString(), 'external_bank_name' => 'Fixture Bank', 'amount' => '10', 'receipt_type' => CustomerReceipt::TypeAdvance]);
    expect($bankReceipt->bank_account_id)->toBe($fixture['bankAccount']->getKey())
        ->and($chequeReceipt->cheque_id)->not->toBeNull()
        ->and($chequeReceipt->cheque?->status)->toBe(Cheque::StatusReceived);

    $invoiceGoodsLine = $invoice->lines->first();
    $saleableReturn = $returns->create($invoice, SalesReturn::ReasonExcess, null, [['customer_invoice_line_id' => $invoiceGoodsLine->getKey(), 'quantity' => '20']]);
    $returns->authorize($saleableReturn);
    $saleableReturn = $returns->receive($saleableReturn);
    $returns->inspect($saleableReturn, [['sales_return_line_id' => $saleableReturn->lines->first()->getKey(), 'saleable_quantity' => '20']]);
    $saleableReturn = $returns->close($saleableReturn->fresh());
    expect($saleableReturn->status)->toBe(SalesReturn::StatusClosed)->and($saleableReturn->creditNote->document_type)->toBe(CustomerInvoice::TypeCreditNote)
        ->and((string) InventoryTransaction::query()->where('product_id', $fixture['finished']->getKey())->sum(DB::raw('quantity_in - quantity_out')))->toBe('20');

    $defectiveReturn = $returns->create($invoice, SalesReturn::ReasonManufacturingDefect, 'Cracked on arrival', [['customer_invoice_line_id' => $invoiceGoodsLine->getKey(), 'quantity' => '10']]);
    $returns->authorize($defectiveReturn);
    $defectiveReturn = $returns->receive($defectiveReturn);
    $returns->inspect($defectiveReturn, [['sales_return_line_id' => $defectiveReturn->lines->first()->getKey(), 'scrap_quantity' => '10']]);
    $returns->close($defectiveReturn->fresh());
    expect((string) InventoryTransaction::query()->where('product_id', $fixture['finished']->getKey())->sum(DB::raw('quantity_in - quantity_out')))->toBe('30');
    expect(JournalEntry::query()->where('source_type', 'sales_return_quarantine_receipt')->count())->toBe(2)
        ->and((float) DB::table('journal_entry_lines')->whereIn('journal_entry_id', JournalEntry::query()->where('source_type', 'sales_return_quarantine_receipt')->pluck('id'))->sum('debit_amount'))->toEqual(150.0)
        ->and(JournalEntry::query()->where('source_type', 'sales_return_financial_disposition')->count())->toBe(2)
        ->and(InventoryTransaction::query()->where('transaction_type', 'sales_return_receipt')->count())->toBe(2);
    $stockByStatus = app(InventoryAvailabilityService::class)->statusPosition(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );
    expect($stockByStatus[InventoryTransaction::StatusAvailable])->toBe('20.00000000')
        ->and($stockByStatus[InventoryTransaction::StatusScrap])->toBe('10.00000000');
    $customerLedger = DB::table('journal_entry_lines')->where('customer_id', $fixture['customer']->getKey())
        ->selectRaw('coalesce(sum(debit_amount), 0) as debits, coalesce(sum(credit_amount), 0) as credits')->first();
    expect((float) $customerLedger->debits)->toEqual(1100.0)
        ->and((float) $customerLedger->credits)->toEqual(1460.0)
        ->and(bcsub((string) $customerLedger->debits, (string) $customerLedger->credits, 4))->toBe('-360.0000');
    expect(fn () => $returns->create($invoice, SalesReturn::ReasonOther, null, [['customer_invoice_line_id' => $invoiceGoodsLine->getKey(), 'quantity' => '31']]))->toThrow(DomainException::class);
});

test('fully paid invoice credit remains a customer credit and conserves every return disposition', function () {
    $fixture = salesCycleFixture();
    InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->update([
        'quantity_in' => '10000',
        'total_cost' => '50000',
    ]);
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $receipts = app(CustomerReceiptService::class);
    $returns = app(SalesReturnService::class);

    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Taxable finished goods return',
            'quantity' => '10000',
            'unit_price' => '0.1',
            'discount_amount' => 0,
            'tax_amount' => '140',
        ]],
        'payment_schedules' => [[
            'title' => 'Paid in full',
            'amount' => '1140',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '10000',
    ]]);
    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $orderLine->getKey(),
        'delivery_line_id' => $delivery->lines->sole()->getKey(),
        'quantity' => '10000',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '1140',
    ]], $delivery));
    $receipts->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '1140',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->getKey(),
        'amount' => '1140',
    ]]);
    $invoice = $invoice->fresh();
    expect($invoice->remaining_amount)->toBe('0.0000')
        ->and($invoice->paid_amount)->toBe('1140.0000');
    expect(fn () => $orders->reopen($order->fresh(), 'Unsafe fulfilled-order mutation.'))
        ->toThrow(DomainException::class, __('The sales order cannot be reopened from its current status.'));
    expect(fn () => $invoices->reopen($invoice, 'Unsafe paid-invoice mutation.'))
        ->toThrow(DomainException::class, __('Only an unsettled posted invoice may be reopened.'));

    $return = $returns->create($invoice->fresh(), SalesReturn::ReasonManufacturingDefect, 'Ten thousand pieces require controlled QC disposition.', [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(),
        'quantity' => '10000',
    ]]);
    $returns->authorize($return);
    $return = $returns->receive($return);
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->sole()->getKey(),
        'saleable_quantity' => '7000',
        'quarantine_quantity' => '0',
        'rework_quantity' => '2000',
        'scrap_quantity' => '1000',
    ]]);
    $return = $returns->close($return);
    $returnLine = $return->lines->sole();
    $creditNote = $return->creditNote;

    $invoice = $invoice->fresh();
    expect($returnLine->saleable_quantity)->toBe('7000.00000000')
        ->and($returnLine->quarantine_quantity)->toBe('0.00000000')
        ->and($returnLine->rework_quantity)->toBe('2000.00000000')
        ->and($returnLine->scrap_quantity)->toBe('1000.00000000')
        ->and(bcadd(bcadd($returnLine->saleable_quantity, $returnLine->quarantine_quantity, 8), bcadd($returnLine->rework_quantity, $returnLine->scrap_quantity, 8), 8))->toBe('10000.00000000')
        ->and($returnLine->original_unit_cost)->toBe('5.00000000')
        ->and($creditNote->total_amount)->toBe('1140.0000')
        ->and($creditNote->posting_status)->toBe('posted')
        ->and($creditNote->is_closed)->toBeTrue()
        ->and($creditNote->isEditable())->toBeFalse()
        ->and($invoice->remaining_amount)->toBe('0.0000')
        ->and($invoice->credited_amount)->toBe('0.0000')
        ->and($creditNote->credit_available_amount)->toBe('1140.0000');
    $overview = app(CustomerSalesOverviewService::class)->forCustomer($fixture['customer'], $fixture['branch']->id);
    expect((float) $overview['credits']->get($fixture['currency']->id)->available)->toBe(1140.0);
    expect(fn () => $returns->close($return))->toThrow(DomainException::class, __('The return has not completed its required authorization and quality stages.'));
    expect(JournalEntry::query()->where('source_type', 'customer_credit_note')->where('source_id', $creditNote->getKey())->count())->toBe(1);

    $creditJournal = $creditNote->journalEntry()->with('lines.account.classification')->firstOrFail();
    $creditLines = $creditJournal->lines->mapWithKeys(fn ($line): array => [
        $line->account->classification->code => [
            'debit' => $line->debit_amount,
            'credit' => $line->credit_amount,
        ],
    ]);
    expect($creditJournal->source_type)->toBe('customer_credit_note')
        ->and($creditLines['sales_returns'])->toBe(['debit' => '1000.0000', 'credit' => '0.0000'])
        ->and($creditLines['output_vat_payable'])->toBe(['debit' => '140.0000', 'credit' => '0.0000'])
        ->and($creditLines['accounts_receivable'])->toBe(['debit' => '0.0000', 'credit' => '1140.0000'])
        ->and($creditJournal->lines->sum('debit_amount'))->toEqual(1140.0)
        ->and($creditJournal->lines->sum('credit_amount'))->toEqual(1140.0);

    $costJournal = JournalEntry::query()->with('lines.account.classification')
        ->where('source_type', 'sales_return_quarantine_receipt')
        ->where('source_id', $return->getKey())
        ->sole();
    $costLines = $costJournal->lines->mapWithKeys(fn ($line): array => [
        $line->account->classification->code => [
            'debit' => $line->debit_amount,
            'credit' => $line->credit_amount,
        ],
    ]);
    expect($costLines['quarantine_inventory'])->toBe(['debit' => '50000.0000', 'credit' => '0.0000'])
        ->and($costLines['cost_of_goods_sold'])->toBe(['debit' => '0.0000', 'credit' => '50000.0000']);

    $dispositionJournal = JournalEntry::query()
        ->where('source_type', 'sales_return_financial_disposition')
        ->where('source_id', $return->getKey())
        ->sole();
    expect((float) $dispositionJournal->lines()->sum('debit_amount'))->toEqual(50000.0)
        ->and((float) $dispositionJournal->lines()->sum('credit_amount'))->toEqual(50000.0);

    $returnTransactions = InventoryTransaction::query()
        ->where('transaction_type', 'sales_return_receipt')
        ->where('product_id', $fixture['finished']->getKey())
        ->get();
    $returnByStatus = $returnTransactions->groupBy('stock_status')
        ->map(fn ($transactions): string => bcadd((string) $transactions->sum('quantity_in'), '0', 8));
    expect($returnTransactions)->toHaveCount(1)
        ->and($returnByStatus[InventoryTransaction::StatusQuarantine])->toBe('10000.00000000')
        ->and($returnTransactions->sum('quantity_in'))->toEqual(10000.0)
        ->and($returnTransactions->every(fn (InventoryTransaction $transaction): bool => $transaction->unit_cost === '5.00000000'))->toBeTrue();

    $availability = app(InventoryAvailabilityService::class)->forProduct(
        $fixture['company']->getKey(),
        $fixture['store']->getKey(),
        $fixture['finished']->getKey(),
    );
    expect($availability['on_hand'])->toBe('7000.00000000')
        ->and($availability['available'])->toBe('7000.00000000')
        ->and($availability['physical_on_hand'])->toBe('10000.00000000');

    $reportBalances = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['finished']->getKey(),
    ])->groupBy('stock_status')->map(
        fn ($balances): string => bcadd((string) $balances->sum('on_hand'), '0', 8),
    );
    expect($reportBalances[InventoryTransaction::StatusAvailable])->toBe('7000.00000000')
        ->and($reportBalances[InventoryTransaction::StatusRework])->toBe('2000.00000000')
        ->and($reportBalances[InventoryTransaction::StatusScrap])->toBe('1000.00000000');

    $customerLedger = DB::table('journal_entry_lines')
        ->where('customer_id', $fixture['customer']->getKey())
        ->selectRaw('coalesce(sum(debit_amount), 0) as debits, coalesce(sum(credit_amount), 0) as credits')
        ->first();
    expect((float) $customerLedger->debits)->toEqual(1140.0)
        ->and((float) $customerLedger->credits)->toEqual(2280.0)
        ->and(bcsub((string) $customerLedger->debits, (string) $customerLedger->credits, 4))->toBe('-1140.0000');

    $statement = app(LedgerQueryService::class)->accountLedger([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'account_id' => $fixture['customer']->account_id,
        'from_date' => $fixture['period']->from_date->toDateString(),
        'to_date' => $fixture['period']->to_date->toDateString(),
        'branch_id' => $fixture['branch']->getKey(),
    ]);
    expect($statement['period'])->toBe(['debit' => '1140.0000', 'credit' => '2280.0000'])
        ->and($statement['ending'])->toBe(['debit' => '0.0000', 'credit' => '1140.0000']);
});

test('available customer credit allocates and refunds exactly once without duplicate subledger or GL effects', function () {
    $fixture = salesCycleFixture();
    $originalInvoice = salesPostedServiceInvoice($fixture, '10000', quantity: '5');
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '10000',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $originalInvoice->paymentSchedules->sole()->getKey(),
        'amount' => '10000',
    ]]);

    $return = app(SalesReturnService::class)->create(
        $originalInvoice->fresh(),
        SalesReturn::ReasonOrderEntry,
        'Service billed in error.',
        [['customer_invoice_line_id' => $originalInvoice->lines->sole()->getKey(), 'quantity' => '1']],
    );
    $return = app(SalesReturnService::class)->authorize($return);
    $creditNote = app(SalesReturnService::class)->close($return)->creditNote;
    $targetInvoice = salesPostedServiceInvoice($fixture, '1200');
    $credits = app(CustomerCreditService::class);
    $journalCountBeforeAllocation = JournalEntry::query()->count();

    $allocation = $credits->allocate(
        $creditNote,
        $targetInvoice,
        '1200',
        now()->toDateString(),
        idempotencyKey: '8f2de8ec-1fe8-4adc-8172-403b3c876a19',
    );
    $sameAllocation = $credits->allocate(
        $creditNote,
        $targetInvoice,
        '1200',
        now()->toDateString(),
        idempotencyKey: '8f2de8ec-1fe8-4adc-8172-403b3c876a19',
    );

    expect($sameAllocation->is($allocation))->toBeTrue()
        ->and(CustomerCreditAllocation::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe($journalCountBeforeAllocation)
        ->and($targetInvoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($targetInvoice->fresh()->credited_amount)->toBe('1200.0000')
        ->and($creditNote->fresh()->credit_available_amount)->toBe('800.0000')
        ->and($creditNote->fresh()->credit_allocated_amount)->toBe('1200.0000');
    expect(fn () => $credits->allocate(
        $creditNote,
        $targetInvoice,
        '1',
        now()->toDateString(),
        idempotencyKey: '9169cbef-f3e8-4b55-b27b-ac5eb96c9e91',
    ))->toThrow(DomainException::class);

    $refundData = [
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'refund_date' => now()->toDateString(),
        'payment_method' => CustomerCreditRefund::MethodCash,
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'amount' => '800',
        'idempotency_key' => '8508dd1d-c8f8-41b3-9ebc-8a887b9ac9d8',
        'notes' => 'Refund remaining customer credit.',
    ];
    $journalCountBeforeRefund = JournalEntry::query()->count();
    $fixture['period']->forceFill(['is_closed' => true])->save();
    expect(fn () => $credits->refund($creditNote->fresh(), $refundData))
        ->toThrow(DomainException::class, __('journal_entries.messages.period_closed'))
        ->and(CustomerCreditRefund::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($journalCountBeforeRefund)
        ->and($creditNote->fresh()->credit_available_amount)->toBe('800.0000');
    $fixture['period']->forceFill(['is_closed' => false])->save();

    $refund = $credits->refund($creditNote->fresh(), $refundData);
    $sameRefund = $credits->refund($creditNote->fresh(), $refundData);
    $refundJournal = $refund->journalEntry()->with('lines')->firstOrFail();

    expect($sameRefund->is($refund))->toBeTrue()
        ->and(CustomerCreditRefund::query()->count())->toBe(1)
        ->and($creditNote->fresh()->credit_available_amount)->toBe('0.0000')
        ->and($creditNote->fresh()->credit_refunded_amount)->toBe('800.0000')
        ->and((float) $refundJournal->lines->firstWhere('account_id', $fixture['customer']->account_id)?->debit_amount)->toBe(800.0)
        ->and((float) $refundJournal->lines->firstWhere('account_id', $fixture['cashbox']->account_id)?->credit_amount)->toBe(800.0);

    Permission::findOrCreate('customer_credits.refund', 'web');
    $fixture['user']->givePermissionTo('customer_credits.refund');
    $refundPdf = $this->actingAs($fixture['user'])
        ->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.customer-credit-refunds.print', $refund))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition');
    expect(str_starts_with($refundPdf->getContent(), '%PDF-'))->toBeTrue()
        ->and(salesPdfText($refundPdf->getContent()))->toContain($refund->doc_num, $creditNote->doc_num, '800');

    expect(fn () => $credits->refund($creditNote->fresh(), [
        ...$refundData,
        'amount' => '1',
        'idempotency_key' => 'de9414b4-fde1-4779-8855-06c5faf7bd12',
    ]))->toThrow(DomainException::class);

    $customerLedger = DB::table('journal_entry_lines')
        ->where('customer_id', $fixture['customer']->getKey())
        ->selectRaw('coalesce(sum(debit_amount), 0) as debits, coalesce(sum(credit_amount), 0) as credits')
        ->first();
    expect((float) $customerLedger->debits)->toEqual(12000.0)
        ->and((float) $customerLedger->credits)->toEqual(12000.0);
});

test('electronic invoices validate immutable payloads and persist accepted rejected and retryable outcomes', function () {
    $fixture = salesCycleFixture();
    $fixture['company']->forceFill(['vat_registration_number' => '200000000'])->save();
    $fixture['customer']->forceFill(['tax_number' => '300000000'])->save();
    config([
        'e_invoice.enabled' => true,
        'e_invoice.provider' => 'mock',
        'e_invoice.environment' => 'sandbox',
        'e_invoice.issuer_taxpayer_id' => null,
        'e_invoice.branch_code' => 'FACTORY-01',
        'e_invoice.mock_result' => 'accepted',
    ]);

    $acceptedInvoice = salesPostedServiceInvoice($fixture, '1000', '140');
    $payload = app(ElectronicInvoicePayloadBuilder::class)->build($acceptedInvoice);
    $service = app(ElectronicInvoiceService::class);
    $accepted = $service->queue($acceptedInvoice)->refresh();
    $sameAccepted = $service->queue($acceptedInvoice)->refresh();

    expect($payload['issuer']['taxpayer_id'])->toBe('200000000')
        ->and($payload['receiver']['taxpayer_id'])->toBe('300000000')
        ->and($payload['lines'][0]['unit_code'])->toBe($fixture['unit']->doc_num)
        ->and($payload['lines'][0]['tax_code'])->toBe('VAT')
        ->and($payload['totals']['total'])->toBe('1140.0000')
        ->and($accepted->status)->toBe(ElectronicInvoiceSubmission::StatusAccepted)
        ->and($accepted->provider_reference)->toStartWith('MOCK-')
        ->and($acceptedInvoice->fresh()->electronic_invoice_status)->toBe(ElectronicInvoiceSubmission::StatusAccepted)
        ->and($sameAccepted->is($accepted))->toBeTrue()
        ->and(ElectronicInvoiceSubmission::query()->where('customer_invoice_id', $acceptedInvoice->getKey())->count())->toBe(1)
        ->and($sameAccepted->attempt_count)->toBe(1);

    $creditReturn = app(SalesReturnService::class)->create(
        $acceptedInvoice,
        SalesReturn::ReasonOrderEntry,
        'Electronic correction.',
        [['customer_invoice_line_id' => $acceptedInvoice->lines->sole()->getKey(), 'quantity' => '1']],
    );
    $creditNote = app(SalesReturnService::class)->close(app(SalesReturnService::class)->authorize($creditReturn))->creditNote;
    $creditPayload = app(ElectronicInvoicePayloadBuilder::class)->build($creditNote);
    expect($creditPayload['document_type'])->toBe(CustomerInvoice::TypeCreditNote)
        ->and($creditPayload['original_document_reference'])->toBe($accepted->provider_reference);

    config(['e_invoice.mock_result' => 'rejected']);
    $rejectedInvoice = salesPostedServiceInvoice($fixture, '200');
    $rejected = $service->queue($rejectedInvoice)->refresh();
    expect($rejected->status)->toBe(ElectronicInvoiceSubmission::StatusRejected)
        ->and($rejected->error_classification)->toBe('provider_business_rejection')
        ->and($rejectedInvoice->fresh()->electronic_invoice_status)->toBe(ElectronicInvoiceSubmission::StatusRejected);

    config(['e_invoice.mock_result' => 'retryable_failure']);
    $retryInvoice = salesPostedServiceInvoice($fixture, '300');
    expect(fn () => $service->queue($retryInvoice))->toThrow(RuntimeException::class, 'Mock retryable provider failure.');
    $failed = ElectronicInvoiceSubmission::query()->where('customer_invoice_id', $retryInvoice->getKey())->sole();
    expect($failed->status)->toBe(ElectronicInvoiceSubmission::StatusFailed)
        ->and($failed->error_classification)->toBe('retryable_transport')
        ->and($failed->attempt_count)->toBe(1)
        ->and($retryInvoice->fresh()->electronic_invoice_status)->toBe('submission_failed');

    config(['e_invoice.mock_result' => 'accepted']);
    $retried = $service->submit($failed)->refresh();
    expect($retried->status)->toBe(ElectronicInvoiceSubmission::StatusAccepted)
        ->and($retried->attempt_count)->toBe(2)
        ->and($retryInvoice->fresh()->electronic_invoice_status)->toBe(ElectronicInvoiceSubmission::StatusAccepted);
});

test('service-only direct sale invoices and collects without inventory reservation production or COGS', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $invoices = app(CustomerInvoiceService::class);
    $receipts = app(CustomerReceiptService::class);
    $initialInventoryTransactions = InventoryTransaction::query()->count();

    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['service']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Service-only direct order',
            'quantity' => '2',
            'unit_price' => '500',
            'discount_amount' => 0,
            'tax_amount' => '140',
        ]],
        'payment_schedules' => [[
            'title' => 'Service settlement',
            'amount' => '1140',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $invoice = $invoices->post($invoices->createFromOrder($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '2',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '1140',
    ]]));
    $receipt = $receipts->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '1140',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->getKey(),
        'amount' => '1140',
    ]]);

    $invoiceLines = $invoice->journalEntry()->with('lines.account.classification')->firstOrFail()->lines
        ->mapWithKeys(fn ($line): array => [
            $line->account->classification->code => [
                'debit' => $line->debit_amount,
                'credit' => $line->credit_amount,
            ],
        ]);
    expect($order->quotation_id)->toBeNull()
        ->and($orderLine->isService())->toBeTrue()
        ->and($invoice->delivery_document_id)->toBeNull()
        ->and($invoiceLines['accounts_receivable'])->toBe(['debit' => '1140.0000', 'credit' => '0.0000'])
        ->and($invoiceLines['service_revenue'])->toBe(['debit' => '0.0000', 'credit' => '1000.0000'])
        ->and($invoiceLines['output_vat_payable'])->toBe(['debit' => '0.0000', 'credit' => '140.0000'])
        ->and($receipt->status)->toBe(CustomerReceipt::StatusApproved)
        ->and($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and(InventoryReservation::query()->count())->toBe(0)
        ->and(ProductionOrder::query()->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe($initialInventoryTransactions)
        ->and(JournalEntry::query()->where('source_type', 'sales_delivery_cogs')->orWhere('source_type', 'sales_return_cogs')->count())->toBe(0);
});

test('closed period rejects invoice receipt and credit posting without partial state', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $invoices = app(CustomerInvoiceService::class);
    $returns = app(SalesReturnService::class);

    $draftOrder = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['service']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Closed-period draft invoice',
            'quantity' => '1',
            'unit_price' => '100',
        ]],
        'payment_schedules' => [[
            'title' => 'Draft invoice due',
            'amount' => '100',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $draftInvoice = $invoices->createFromOrder($draftOrder, [[
        'sales_order_line_id' => $draftOrder->lines->sole()->getKey(),
        'quantity' => '1',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '100',
    ]]);

    $postedOrder = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['service']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Closed-period receipt and credit source',
            'quantity' => '1',
            'unit_price' => '200',
        ]],
        'payment_schedules' => [[
            'title' => 'Posted invoice due',
            'amount' => '200',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $postedInvoice = $invoices->post($invoices->createFromOrder($postedOrder, [[
        'sales_order_line_id' => $postedOrder->lines->sole()->getKey(),
        'quantity' => '1',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '200',
    ]]));
    $return = $returns->authorize($returns->create($postedInvoice, SalesReturn::ReasonOther, 'Closed-period credit proof.', [[
        'customer_invoice_line_id' => $postedInvoice->lines->sole()->getKey(),
        'quantity' => '1',
    ]]));

    $fixture['period']->update(['is_closed' => true]);
    $journalCount = JournalEntry::query()->count();
    $creditNoteCount = CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count();
    $periodClosedMessage = __('journal_entries.messages.period_closed');

    expect(fn () => $invoices->post($draftInvoice))->toThrow(DomainException::class, $periodClosedMessage);
    expect(fn () => app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '200',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $postedInvoice->paymentSchedules->sole()->getKey(),
        'amount' => '200',
    ]]))->toThrow(DomainException::class, $periodClosedMessage);
    expect(fn () => $returns->close($return))->toThrow(DomainException::class, $periodClosedMessage);

    expect($draftInvoice->fresh()->posting_status)->toBe('unposted')
        ->and($draftInvoice->journal_entry_id)->toBeNull()
        ->and(CustomerReceipt::query()->count())->toBe(0)
        ->and(CashVoucher::query()->count())->toBe(0)
        ->and(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeCreditNote)->count())->toBe($creditNoteCount)
        ->and($return->fresh()->status)->toBe(SalesReturn::StatusAuthorized)
        ->and($return->credit_note_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe($journalCount);
});

test('physical sales delivery starts only from a posted invoice and preserves invoice lineage', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $invoices = app(CustomerInvoiceService::class);
    $fulfillment = app(SalesFulfillmentService::class);

    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'branch_store_id' => null,
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Invoice-controlled delivery',
            'quantity' => '5',
            'unit_price' => '20',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Invoice-controlled delivery',
            'amount' => '100',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $invoice = $invoices->createFromOrder($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '5',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '100',
    ]]);
    $deliveryLines = [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(),
        'quantity' => '5',
    ]];
    $logistics = [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ];

    expect(fn () => $fulfillment->deliverInvoice($invoice, $deliveryLines, $logistics))
        ->toThrow(DomainException::class, __('Only a posted sales invoice can be delivered.'));

    $invoice = $invoices->post($invoice);
    $delivery = $fulfillment->deliverInvoice($invoice, $deliveryLines, $logistics);

    expect($delivery->document_type)->toBe(InventoryDocument::TypeSalesDelivery)
        ->and($delivery->status)->toBe(InventoryDocument::StatusPosted)
        ->and($delivery->source_doc_num)->toBe($invoice->doc_num)
        ->and($delivery->branch_store_id)->toBe($fixture['store']->getKey())
        ->and($delivery->lines->sole()->source_line_id)->toBe($orderLine->getKey())
        ->and($invoice->fresh()->delivery_document_id)->toBe($delivery->getKey())
        ->and($invoice->deliveries()->whereKey($delivery->getKey())->exists())->toBeTrue()
        ->and($orderLine->fresh()->delivered_quantity)->toBe('5.00000000');
});

test('sales eligibility exposes only finished products and services and rejects internal items server side', function () {
    $fixture = salesCycleFixture();
    expect(Product::query()->salesEligible()->pluck('item_classification')->unique()->sort()->values()->all())->toBe([Product::ClassificationFinishedProduct, Product::ClassificationService]);
    $payload = salesCycleOrderPayload($fixture, ['lines' => [['product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'description' => 'Raw', 'quantity' => '1', 'unit_price' => '10']], 'payment_schedules' => [['title' => 'Due', 'amount' => '10', 'due_date' => now()->toDateString()]]]);
    expect(fn () => app(SalesOrderService::class)->create($payload))->toThrow(DomainException::class, __('Only finished products and services may be sold.'));
});

test('transaction units snapshot base quantities through reservation delivery invoice and saleable return', function () {
    $fixture = salesCycleFixture();
    $carton = ItemUnit::query()->create([
        'company_id' => $fixture['company']->getKey(), 'doc_number' => 8002,
        'doc_num' => 'Unit-CARTON', 'name' => 'Carton', 'status' => 'active',
    ]);
    $fixture['finished']->update(['equivalent_value' => '0.1', 'equivalent_unit_id' => $carton->getKey()]);
    $payload = salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $carton->getKey(),
            'description' => 'Five cartons', 'quantity' => '5', 'unit_price' => '100',
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '500', 'due_date' => now()->addMonth()->toDateString()]],
    ]);
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $returns = app(SalesReturnService::class);
    $order = $orders->approve($orders->create($payload));
    $line = $order->lines->first();

    expect($line->unit_id)->toBe($carton->getKey())
        ->and($line->conversion_factor)->toBe('10.00000000')
        ->and($line->base_quantity)->toBe('50.00000000');

    $reservation = $fulfillment->reserve($line, '5');
    $delivery = $fulfillment->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '5']]);
    expect($reservation->transaction_quantity)->toBe('5.00000000')
        ->and($reservation->quantity)->toBe('50.00000000')
        ->and($delivery->lines->first()->transaction_quantity)->toBe('5.00000000')
        ->and($delivery->lines->first()->quantity)->toBe('50.00000000');

    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $line->getKey(), 'delivery_line_id' => $delivery->lines->first()->getKey(), 'quantity' => '5',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '500']], $delivery));
    expect($invoice->lines->first()->quantity)->toBe('5.00000000')
        ->and($invoice->lines->first()->base_quantity)->toBe('50.00000000');

    $return = $returns->create($invoice, SalesReturn::ReasonOther, null, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '1',
    ]]);
    $returns->authorize($return);
    $return = $returns->receive($return);
    $returns->inspect($return, [['sales_return_line_id' => $return->lines->first()->getKey(), 'saleable_quantity' => '1']]);
    expect($return->lines->first()->fresh()->saleable_base_quantity)->toBe('10.00000000')
        ->and($return->returnInventoryDocument->lines->first()->fresh()->quantity)->toBe('10.00000000');
});

test('full invoice CRUD automatically reverses a safe posted invoice before amendment and reposts a new revision', function () {
    $fixture = salesCycleFixture();
    $payload = salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Correctable delivery', 'quantity' => '10', 'unit_price' => '10',
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '100', 'due_date' => now()->addMonth()->toDateString()]],
    ]);
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $order = $orders->approve($orders->create($payload));
    $orderLine = $order->lines->first();
    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '10',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '100']]));
    $originalJournalId = $invoice->journal_entry_id;

    expect($invoice->canAmend())->toBeTrue();
    $invoice = $invoices->amend($invoice, [[
        'invoice_line_public_id' => $invoice->lines()->firstOrFail()->public_id,
        'quantity' => '8',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '80']]);
    $originalJournal = JournalEntry::query()->findOrFail($originalJournalId);
    expect($invoice->status)->toBe(CustomerInvoice::StatusReopened)
        ->and($invoice->posting_status)->toBe('reopen_pending_repost')
        ->and($invoice->posting_revision)->toBe(1)
        ->and($invoice->reversal_journal_entry_id)->not->toBeNull()
        ->and($originalJournal->reversed_entry_id)->toBe($invoice->reversal_journal_entry_id)
        ->and($invoice->total_amount)->toBe('80.0000')
        ->and($invoice->lines->first()->quantity)->toBe('8.00000000')
        ->and($orderLine->fresh()->invoiced_quantity)->toBe('8.00000000');

    $invoice = $invoices->post($invoice);
    expect($invoice->posting_status)->toBe('posted')
        ->and($invoice->journal_entry_id)->not->toBe($originalJournalId)
        ->and($invoice->journalEntry->source_type)->toBe('customer_invoice_post_1')
        ->and($invoices->post($invoice)->journal_entry_id)->toBe($invoice->journal_entry_id);

    $fulfillment->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(),
        'quantity' => '8',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]);

    app(SalesReturnService::class)->create($invoice, SalesReturn::ReasonOther, 'Pending source-driven return.', [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '1',
    ]]);
    expect(fn () => $invoices->reopen($invoice->fresh(), 'Unsafe descendant mutation attempt.'))
        ->toThrow(DomainException::class, __('An invoice with a delivery, return, receipt, or credit note cannot be reopened.'));
});

test('full invoice CRUD feature can be disabled and safely deletes only unused drafts', function () {
    $fixture = salesCycleFixture();
    $invoices = app(CustomerInvoiceService::class);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['service']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Deletable draft service',
            'quantity' => '2',
            'unit_price' => '50',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Due',
            'amount' => '100',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $draft = $invoices->createFromOrder($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '2',
    ]], [['due_date' => now()->toDateString(), 'amount' => '100']]);

    expect($draft->canDeleteDraft())->toBeTrue()
        ->and($orderLine->fresh()->invoiced_quantity)->toBe('2.00000000');
    $invoices->deleteDraft($draft);
    expect(CustomerInvoice::withTrashed()->findOrFail($draft->getKey())->trashed())->toBeTrue()
        ->and($orderLine->fresh()->invoiced_quantity)->toBe('0.00000000');

    $posted = salesPostedServiceInvoice($fixture, '100');
    config()->set('erp_features.sales.allow_full_invoice_crud', false);
    expect($posted->fresh()->canAmend())->toBeFalse()
        ->and(fn () => $invoices->amend($posted->fresh(), [[
            'invoice_line_public_id' => $posted->lines()->sole()->public_id,
            'quantity' => '1',
        ]], [['due_date' => now()->toDateString(), 'amount' => '100']]))
        ->toThrow(DomainException::class, __('Only a draft or safely reopened invoice may be amended.'));
});

test('reservation oversubscription is rejected and an audited release restores reservable quantity', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Reservation control', 'quantity' => '100', 'unit_price' => '10',
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '1000', 'due_date' => now()->addMonth()->toDateString()]],
    ])));
    $line = $order->lines->first();
    $reservation = $fulfillment->reserve($line, '60');

    expect(fn () => $fulfillment->reserve($line->fresh(), '41'))
        ->toThrow(DomainException::class, __('Reservation exceeds the remaining order quantity.'));
    $reservation = $fulfillment->releaseReservation($reservation, 'Customer requested a controlled release.');
    expect($reservation->status)->toBe(InventoryReservation::StatusReleased)
        ->and($reservation->release_reason)->toBe('Customer requested a controlled release.')
        ->and($line->fresh()->reserved_quantity)->toBe('0.00000000')
        ->and($fulfillment->reserve($line->fresh(), '100')->transaction_quantity)->toBe('100.00000000');
});

test('production demand preserves order-line lineage and has no direct completion bypass', function () {
    $fixture = salesCycleFixture();
    $payload = salesCycleOrderPayload($fixture, [
        'lines' => [['product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'description' => 'Long production run', 'quantity' => '150', 'unit_price' => '10']],
        'payment_schedules' => [['title' => 'Due', 'amount' => '1500', 'due_date' => now()->addMonth()->toDateString()]],
    ]);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create($payload));
    $orderLine = $order->lines->first();
    $production = app(SalesProductionDemandService::class)->create($order, [['sales_order_line_id' => $orderLine->getKey(), 'quantity' => '50']]);

    expect($production->sales_order_id)->toBe($order->getKey())
        ->and($production->lines->first()->sales_order_line_id)->toBe($orderLine->getKey())
        ->and($orderLine->fresh()->produced_quantity)->toBe('0.00000000')
        ->and(array_intersect(['unit_price', 'line_total', 'total_amount'], $production->getFillable()))->toBe([]);

    expect(method_exists(SalesProductionDemandService::class, 'receiveCompletion'))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('admin.production.work-orders.complete'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('admin.sales.production-requests.complete'))->toBeNull()
        ->and($orderLine->fresh()->produced_quantity)->toBe('0.00000000')
        ->and($production->fresh()->status)->toBe(ProductionOrder::StatusDraft);

});

test('credit hold requires a separately audited authorized override reason', function () {
    $fixture = salesCycleFixture();
    CustomerCommercialAgreement::query()->where('customer_id', $fixture['customer']->getKey())->update(['credit_limit' => '50']);
    $order = app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture));
    $order = app(SalesOrderService::class)->approve($order);
    expect($order->status)->toBe(SalesOrder::StatusHeldCredit)->and($order->credit_status)->toBe('blocked');
    $order = app(SalesOrderService::class)->overrideCreditHold($order, 'Owner approved this controlled exception.');
    expect($order->status)->toBe(SalesOrder::StatusApproved)->and($order->creditOverrides)->toHaveCount(1)
        ->and($order->creditOverrides->first()->blocking_condition['credit_exceeded'])->toBeTrue()
        ->and($order->creditOverrides->first()->overridden_by)->toBe($fixture['user']->getKey());
});

test('released orders require controlled reopen and cannot be amended after fulfillment planning starts', function () {
    $fixture = salesCycleFixture();
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));

    expect(fn () => $orders->update($order, salesCycleOrderPayload($fixture)))
        ->toThrow(DomainException::class, __('Released sales orders must be reopened before amendment.'));

    $order = $orders->update($orders->reopen($order, 'Customer confirmed an amended requested date.'), salesCycleOrderPayload($fixture));
    $order = $orders->approve($orders->submit($order));
    $goodsLine = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    app(SalesFulfillmentService::class)->reserve($goodsLine, '1');

    expect(fn () => $orders->reopen($order->fresh(), 'Attempt to change an already planned order.'))
        ->toThrow(DomainException::class, __('An order with reservations, production, deliveries, or invoices cannot be amended; use controlled downstream reversal documents.'));
});

test('implemented sales cycle routes are not shadowed by UI shell placeholders', function () {
    expect(app('router')->getRoutes()->getByName('admin.sales.sales-orders.index')?->getActionName())->toContain('SalesCycleController@orders')
        ->and(app('router')->getRoutes()->getByName('admin.sales.sales-orders.show')?->getActionName())->toContain('SalesCycleController@showOrder')
        ->and(app('router')->getRoutes()->getByName('admin.sales.sales-returns.index')?->getActionName())->toContain('SalesCycleController@returns')
        ->and(app('router')->getRoutes()->getByName('admin.reports.sales.sales-orders.index')?->getActionName())->toContain('SalesCycleReportController@index');
});

test('restricted production and warehouse browser responses do not expose commercial values', function () {
    $fixture = salesCycleFixture();
    foreach (['production.orders.view', 'production.orders.print', 'sales_deliveries.view', 'sales_deliveries.print', 'sales_orders.production', 'sales_orders.reserve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo(['production.orders.view', 'production.orders.print', 'sales_deliveries.view', 'sales_deliveries.print', 'sales_orders.production', 'sales_orders.reserve']);
    $payload = salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
            'description' => 'CONFIDENTIAL-COMMERCIAL-LINE', 'quantity' => '1', 'unit_price' => '987.6543',
            'specifications' => ['packaging' => 'Sealed export carton'],
        ]],
        'payment_schedules' => [['title' => 'Due', 'amount' => '987.6543', 'due_date' => now()->addMonth()->toDateString()]],
    ]);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create($payload));
    $line = $order->lines->first();
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->postJson(route('admin.sales.sales-orders.reservations.store', $order), [
            'sales_order_line_public_id' => $line->public_id,
            'quantity' => '1000',
        ])->assertUnprocessable()->assertJsonPath('message', 'Reservation exceeds the remaining order quantity.')
        ->assertJsonMissing(['SQLSTATE']);
    $openingStock = InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->sole();
    $openingStock->update(['quantity_in' => '0', 'total_cost' => '0']);
    $production = app(SalesProductionDemandService::class)->create($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '1']]);
    $openingStock->update(['quantity_in' => '100', 'total_cost' => '500']);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '1']]);

    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.production.work-orders.show', $production))
        ->assertOk()->assertSee('Sealed export carton')->assertDontSee('987.6543')->assertDontSee('Credit limit');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.production-requests.show', $production))
        ->assertOk()->assertSee('Canonical manufacturing execution')->assertDontSee('Post Production Receipt')->assertDontSee('987.6543');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.production.work-orders.print', $production))
        ->assertOk()->assertDontSee('987.6543')->assertDontSee('Unit price');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.delivery-notes.show', $delivery))
        ->assertOk()->assertDontSee('987.6543')->assertDontSee('Unit price')->assertDontSee('Sealed export carton');
});

test('authorized users can load the concrete create edit collection reporting and print screens', function () {
    $fixture = salesCycleFixture();
    $permissions = [
        'sales_orders.create', 'sales_orders.edit', 'sales_orders.view', 'sales_orders.print', 'sales_orders.view_prices',
        'customer_invoices.view', 'customer_invoices.edit', 'customer_invoices.print', 'customer_invoices.view_prices',
        'customer_receipts.create', 'reports.sales.sales_orders.view', 'reports.sales.sales_orders.print',
        'reports.sales.sales_orders.export',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $orders = app(SalesOrderService::class);
    $draftOrder = $orders->create(salesCycleOrderPayload($fixture));
    $approved = $orders->approve($orders->create(salesCycleOrderPayload($fixture)));
    $goodsLine = $approved->lines->firstWhere('product_id', $fixture['finished']->getKey());
    $delivery = app(SalesFulfillmentService::class)->deliver($approved, [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '1']]);
    $invoice = app(CustomerInvoiceService::class)->createFromOrder($approved->fresh(), [[
        'sales_order_line_id' => $goodsLine->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '1',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '10']], $delivery);
    $session = salesCycleSession($fixture);

    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.create'))
        ->assertOk()->assertSee('Create Sales Order')->assertSee('Line total')
        ->assertSee('js-select2-ajax')->assertDontSee($fixture['finished']->name)->assertDontSee($fixture['service']->name)
        ->assertDontSee($fixture['raw']->name)->assertDontSee($fixture['semiFinished']->name);
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.edit', $draftOrder))
        ->assertOk()->assertSee('Edit Sales Order')->assertDontSee('Customer reference / PO');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-invoices.edit', $invoice))
        ->assertOk()->assertSee('Correct Sales Invoice')->assertSee('Corrected quantity');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.customer-receipts.create', ['invoice' => $invoice->doc_num]))
        ->assertOk()->assertSee('Customer Receipt / Collection')->assertSee('Cheque')->assertSee('Bank transfer');
    $orderPdf = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.print', $draftOrder));
    $orderPdf->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition', 'inline; filename="sales-order-'.$draftOrder->doc_num.'.pdf"');
    expect($orderPdf->getContent())->toStartWith('%PDF-');
    $reportPdf = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.reports.sales.sales-orders.print'));
    $reportPdf->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition', 'inline; filename="sales-operational-report.pdf"');
    expect($reportPdf->getContent())->toStartWith('%PDF-');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.reports.sales.sales-orders.export'))
        ->assertOk()->assertDownload();
});

test('every formal sales document streams canonical inline mPDF with operational price privacy', function () {
    $fixture = salesCycleFixture();
    $salesRepresentative = HrEmployee::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'doc_number' => 98701,
        'doc_num' => 'EMP-SALES-PRINT',
        'full_name' => 'Printed Sales Representative',
        'name' => 'Printed Sales Representative',
        'status' => 'active',
    ]);
    CustomerCommercialAgreement::query()->where('customer_id', $fixture['customer']->getKey())->update(['credit_limit' => '20000']);
    $permissions = [
        'sales_orders.print', 'sales_orders.view_prices', 'sales_orders.production',
        'sales_deliveries.print', 'customer_invoices.print', 'customer_invoices.view_prices',
        'customer_receipts.print', 'sales_returns.view', 'sales_returns.print', 'reports.sales.sales_orders.print',
        'production.orders.print', 'cash_receipt_vouchers.print', 'cheques.print',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);

    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $receipts = app(CustomerReceiptService::class);
    $returns = app(SalesReturnService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'business_employee_id' => $salesRepresentative->getKey(),
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Privacy-controlled finished item',
            'quantity' => '10',
            'unit_price' => '876.54',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Full settlement',
            'amount' => '8765.40',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $openingStock = InventoryTransaction::query()->where('posting_key', 'sales-cycle-opening-stock')->sole();
    $openingStock->update(['quantity_in' => '0', 'total_cost' => '0']);
    $production = app(SalesProductionDemandService::class)->create($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '10',
    ]]);
    $openingStock->update(['quantity_in' => '100', 'total_cost' => '500']);
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $orderLine->getKey(),
        'quantity' => '10',
    ]]);
    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $orderLine->getKey(),
        'delivery_line_id' => $delivery->lines->sole()->getKey(),
        'quantity' => '10',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '8765.40',
    ]], $delivery));
    $fixture['company']->forceFill(['show_company_identity_on_prints' => true])->save();
    $identityImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAKAAAAAyCAIAAABUA0cyAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABR0lEQVR4nO3bUY6CMBgA4XWz91hvocfYPSnX4BgcxYcmTfNTaolFzTjfk8GChBGoJJ5+L39f4vp+9Q7oWAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAxnYDgDwxkYzsBwBob76Rm0zFN1+fn6H8aUS6rrpgF3N1gOqC6sbrBnfz5NV+CkcbDyoV/mqXGUl3lKA0KzsOVyYV6luhvrdxUMuETnHuHsXMfrKRHWap/xYcuNj/5Yjwbe2+O4g16e8dbNdlyiq/fF56ve1PNr6wZj7sEv8W778552BG64e48caGvypaoxv4PTDKucHm8Z9VXonHzpwAcd6wY9PfrnwzbuMeYSvSXNevbOzsJaXocfcfLPZ2w+i4YzMJyB4QwMZ2A4A8MZGM7AcAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAx3A4Npkgj1aQnLAAAAAElFTkSuQmCC';
    $invoice->update(['print_identity_snapshot' => [
        ...($invoice->print_identity_snapshot ?? []),
        'name' => 'Sales PDF Identity Company',
        'legal_name' => 'Sales PDF Legal Identity',
        'logo_source' => $identityImage,
        'authorized_signatory_name' => 'PDF Authorized Signatory',
        'authorized_signatory_title' => 'Finance Director',
        'authorized_signatory_signature_source' => $identityImage,
        'company_stamp_source' => $identityImage,
    ]]);
    $schedule = $invoice->paymentSchedules->sole();
    $cashReceipt = $receipts->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'sales_order_id' => $order->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '3500',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $schedule->getKey(),
        'amount' => '3500',
    ]]);
    $chequeReceipt = $receipts->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'sales_order_id' => $order->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cheque',
        'bank_account_id' => $fixture['bankAccount']->getKey(),
        'reference_no' => 'CHQ-PDF-001',
        'cheque_due_date' => now()->addWeek()->toDateString(),
        'external_bank_name' => 'PDF Fixture Bank',
        'amount' => '5265.40',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $schedule->getKey(),
        'amount' => '5265.40',
    ]]);
    $return = $returns->create($invoice, SalesReturn::ReasonManufacturingDefect, 'PDF disposition proof.', [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(),
        'quantity' => '10',
    ]]);
    $returns->authorize($return);
    $return = $returns->receive($return);
    $return = $returns->inspect($return, [[
        'sales_return_line_id' => $return->lines->sole()->getKey(),
        'saleable_quantity' => '7',
        'quarantine_quantity' => '3',
    ]]);
    $return = $returns->close($return);
    $session = salesCycleSession($fixture);

    $routes = [
        'sales order' => route('admin.sales.sales-orders.print', $order),
        'sales-origin production request' => route('admin.sales.production-requests.print', $production),
        'production work order' => route('admin.production.work-orders.print', $production),
        'delivery note' => route('admin.sales.delivery-notes.print', $delivery),
        'sales invoice' => route('admin.sales.sales-invoices.print', $invoice),
        'sales invoice legal' => route('admin.sales.sales-invoices.print', [$invoice, 'copy' => 'legal']),
        'payment schedule' => route('admin.sales.sales-invoices.payment-schedule.print', $invoice),
        'cash customer receipt' => route('admin.sales.customer-receipts.print', $cashReceipt),
        'cheque customer receipt' => route('admin.sales.customer-receipts.print', $chequeReceipt),
        'canonical finance cash voucher' => route('admin.finance.cash-receipt-vouchers.print', $cashReceipt->cashVoucher),
        'canonical finance received cheque' => route('admin.finance.cheques.print', $chequeReceipt->cheque),
        'sales return' => route('admin.sales.sales-returns.print', $return),
        'return quality disposition' => route('admin.sales.sales-returns.quality-disposition.print', $return),
        'sales credit note' => route('admin.sales.sales-invoices.print', $return->creditNote),
        'sales operational report' => route('admin.reports.sales.sales-orders.print'),
    ];
    $responses = [];
    foreach ($routes as $name => $url) {
        $response = $this->actingAs($fixture['user'])->withSession($session)->get($url);
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->headers->get('content-disposition'))->toStartWith('inline; filename=')
            ->and($response->getContent())->toStartWith('%PDF-');
        $responses[$name] = $response;
    }

    $invoiceText = salesPdfText($responses['sales invoice']->getContent());
    expect($invoiceText)->toContain('Unit price')->toContain('876.54')
        ->toContain('Sales PDF Legal Identity')->toContain(__('sales_ui.operational_invoice_copy'))
        ->not->toContain(__('sales_ui.legal_invoice_copy'))->not->toContain('PDF Authorized Signatory')->not->toContain('Finance Director')
        ->and(salesPdfImageCount($responses['sales invoice']->getContent()))->toBeGreaterThanOrEqual(1);
    $legalInvoiceText = salesPdfText($responses['sales invoice legal']->getContent());
    expect($legalInvoiceText)->toContain('Unit price')->toContain('876.54')
        ->toContain('Sales PDF Legal Identity')->toContain(__('sales_ui.legal_invoice_copy'))
        ->toContain('PDF Authorized Signatory')->toContain('Finance Director')
        ->not->toContain(__('sales_ui.operational_invoice_copy'))
        ->and(salesPdfImageCount($responses['sales invoice legal']->getContent()))->toBeGreaterThanOrEqual(3);
    foreach (['sales-origin production request', 'production work order', 'delivery note', 'return quality disposition'] as $operationalDocument) {
        $text = salesPdfText($responses[$operationalDocument]->getContent());
        expect($text)->not->toContain('Unit price')->not->toContain('876.54');
    }
    foreach (['sales order', 'sales-origin production request', 'production work order', 'delivery note', 'sales invoice', 'payment schedule', 'cash customer receipt', 'cheque customer receipt', 'sales return', 'return quality disposition', 'sales credit note'] as $salesDocument) {
        expect(salesPdfText($responses[$salesDocument]->getContent()))
            ->toContain($salesRepresentative->full_name);
    }
    expect(salesPdfText($responses['cash customer receipt']->getContent()))->toContain($cashReceipt->cashVoucher->doc_num)
        ->and(salesPdfText($responses['cheque customer receipt']->getContent()))->toContain($chequeReceipt->cheque->doc_num);

    $fixture['user']->update(['locale' => 'ar']);
    app()->setLocale('ar');
    $returnPage = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-returns.show', $return));
    $returnPage->assertOk()
        ->assertSee(__('Saleable').'، '.__('Quarantine'))
        ->assertSee(__('Print Quality Disposition'))
        ->assertDontSee('Saleable,Quarantine')
        ->assertDontSee(__('Return and quality actions'));
    $returnPdf = $this->get(route('admin.sales.sales-returns.print', $return))->assertOk();
    expect(salesPdfText($returnPdf->getContent()))
        ->not->toContain('Saleable,Quarantine', $fixture['user']->name);
});

test('sales PDF routes enforce print authorization', function () {
    $fixture = salesCycleFixture();
    $order = app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture));

    $this->actingAs($fixture['user'])
        ->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.sales-orders.print', $order))
        ->assertForbidden();
});

test('25-line sales order and invoice remain complete across English and Arabic mPDF pages', function () {
    $fixture = salesCycleFixture();
    $permissions = ['sales_orders.print', 'sales_orders.view_prices', 'customer_invoices.print', 'customer_invoices.view_prices'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);

    $lines = collect(range(1, 25))->map(fn (int $lineNumber): array => [
        'product_id' => $fixture['service']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'description' => sprintf('STRESS-LINE-%02d English multi-page service description with preserved totals and Arabic content وصف عربي متعدد الصفحات للتحقق من اكتمال السطر', $lineNumber),
        'quantity' => '1',
        'unit_price' => '10',
        'discount_amount' => 0,
        'tax_amount' => 0,
    ])->all();
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => $lines,
        'payment_schedules' => [[
            'title' => '25-line total',
            'amount' => '250',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $invoiceLines = $order->lines->map(fn ($line): array => [
        'sales_order_line_id' => $line->getKey(),
        'quantity' => '1',
    ])->all();
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, $invoiceLines, [[
        'due_date' => now()->toDateString(),
        'amount' => '250',
    ]]));

    $baseSession = salesCycleSession($fixture);
    foreach (['en', 'ar'] as $locale) {
        $session = [...$baseSession, 'locale' => $locale];
        $orderPdf = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.print', $order));
        $invoicePdf = $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-invoices.print', $invoice));

        foreach ([$orderPdf, $invoicePdf] as $response) {
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            expect($response->headers->get('content-disposition'))->toStartWith('inline; filename=')
                ->and($response->getContent())->toStartWith('%PDF-')
                ->and(salesPdfPageCount($response->getContent()))->toBeGreaterThan(1);
            $text = salesPdfText($response->getContent());
            foreach (range(1, 25) as $lineNumber) {
                expect($text)->toContain(sprintf('STRESS-LINE-%02d', $lineNumber));
            }
            expect($text)->toContain('250');
        }

        $orderText = salesPdfText($orderPdf->getContent());
        $invoiceText = salesPdfText($invoicePdf->getContent());
        foreach ([$orderText, $invoiceText] as $text) {
            $linePages = collect(explode("\f", $text))->filter(fn (string $page): bool => str_contains($page, 'STRESS-LINE'));
            expect($linePages->count())->toBeGreaterThan(1)
                ->and($linePages->every(fn (string $page): bool => str_contains($page, '#')))->toBeTrue()
                ->and(preg_match_all('/\d+\/\d+/', $text))->toBeGreaterThan(1);
        }
    }
});

test('sales reports separate operational fulfillment financial aging and product analysis', function () {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('reports.sales.sales_orders.view', 'web');
    $fixture['user']->givePermissionTo('reports.sales.sales_orders.view');
    $session = salesCycleSession($fixture);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Report product',
            'quantity' => '5',
            'unit_price' => '20',
        ]],
        'payment_schedules' => [[
            'title' => 'Report installment',
            'amount' => '100',
            'due_date' => now()->toDateString(),
        ]],
    ])));
    $invoice = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder($order, [[
        'sales_order_line_id' => $order->lines->sole()->getKey(),
        'quantity' => '2',
    ]], [[
        'due_date' => now()->toDateString(),
        'amount' => '40',
    ]]));
    app(SalesFulfillmentService::class)->deliverInvoice($invoice, [[
        'customer_invoice_line_id' => $invoice->lines->sole()->getKey(),
        'quantity' => '1',
    ]], [
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'document_date' => now()->toDateString(),
    ]);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'customer_doc_num' => $fixture['customer']->doc_num,
            'product_doc_num' => $fixture['finished']->doc_num,
            'warehouse_uuid' => $fixture['store']->public_uuid,
            'branch_doc_num' => $fixture['branch']->doc_num,
            'order_doc_num' => $order->doc_num,
            'order_status' => SalesOrder::StatusPartiallyFulfilled,
        ]))
        ->assertOk()
        ->assertSee('Sales Cycle Operational Report')
        ->assertSee('Sales financial summary')
        ->assertSee('Quotation Status / History')
        ->assertSee('Invoice to Delivery Fulfillment')
        ->assertSee('Invoiced')
        ->assertSee('Remaining Delivery')
        ->assertSee($order->doc_num)
        ->assertSee($invoice->doc_num)
        ->assertSee($fixture['customer']->name);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'financial',
            'customer_doc_num' => $fixture['customer']->doc_num,
        ]))
        ->assertOk()
        ->assertSee('Sales Financial Analysis')
        ->assertSee('Customer Aging')
        ->assertSee('Sales / Outstanding by Customer')
        ->assertSee($fixture['customer']->name);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'products',
            'product_doc_num' => $fixture['finished']->doc_num,
        ]))
        ->assertOk()
        ->assertSee('Sales by Product')
        ->assertSee('Sales by Item')
        ->assertSee('Customer / Item Sales Analysis')
        ->assertSee($fixture['finished']->name);
});

test('sales reports filter every customer based section by normalized geography', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('reports.sales.sales_orders.view', 'web');
    $fixture['user']->givePermissionTo('reports.sales.sales_orders.view');

    $country = HrCountry::query()->create(['doc_number' => 98901, 'doc_num' => 'Country-98901', 'name' => 'Report Country']);
    $governorate = HrGovernorate::query()->create(['doc_number' => 98901, 'doc_num' => 'Governorate-98901', 'name' => 'Report Governorate', 'country_id' => $country->id]);
    $city = HrCity::query()->create(['doc_number' => 98901, 'doc_num' => 'City-98901', 'name' => 'Report City', 'governorate_id' => $governorate->id]);
    $area = HrArea::query()->create(['doc_number' => 98901, 'doc_num' => 'Area-98901', 'name' => 'Report Area', 'city_id' => $city->id]);
    $fixture['customer']->update(['country_id' => $country->id, 'governorate_id' => $governorate->id, 'city_id' => $city->id, 'area_id' => $area->id]);

    $invoice = salesPostedServiceInvoice($fixture, '100', '0', '1');
    $session = salesCycleSession($fixture);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices', 'area_doc_num' => $area->doc_num]))
        ->assertOk()
        ->assertSee($invoice->doc_num)
        ->assertSee('Report Area');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices', 'area_doc_num' => 'Area-DOES-NOT-EXIST']))
        ->assertOk()
        ->assertDontSee($invoice->doc_num);

    $this->actingAs($fixture['user'])->withSession($session)
        ->getJson(route('admin.select2.countries'))
        ->assertOk();
});

test('sales screens and validation follow language changes while retaining document data', function (): void {
    $fixture = salesCycleFixture();
    $permissions = ['sales_orders.view', 'sales_orders.create'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);
    $order = app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture));

    foreach (['ar', 'en', 'ar'] as $locale) {
        $fixture['user']->forceFill(['locale' => $locale])->save();
        $session = [...salesCycleSession($fixture), 'locale' => $locale];
        $arabic = $locale === 'ar';

        $this->actingAs($fixture['user'])->withSession($session)
            ->get(route('admin.sales.sales-orders.index'))
            ->assertOk()
            ->assertSee($arabic ? 'أوامر المبيعات' : 'Sales Orders')
            ->assertSee('value="pending_approval"', false)
            ->assertSee($arabic ? 'بانتظار الاعتماد' : 'Pending Approval')
            ->assertSee('sales-cycle-table');
        $this->actingAs($fixture['user'])->withSession($session)->getJson(route('admin.sales.sales-orders.index', ['draw' => 1, 'length' => 10, 'start' => 0]))->assertOk()->assertJsonMissingPath('error')->assertJsonPath('data.0.customer', 'Sales Cycle Customer');

        $this->actingAs($fixture['user'])->withSession($session)
            ->get(route('admin.sales.sales-orders.show', $order))
            ->assertOk()
            ->assertSee('<h5 class="mb-1">'.($arabic ? 'أمر مبيعات' : 'Sales Order').'</h5>', false)
            ->assertSee($arabic ? 'العميل' : 'Customer')
            ->assertSee('window.salesCycleMessages', false)
            ->assertSee('Sales Cycle Customer');

        $response = $this->actingAs($fixture['user'])->withSession($session)
            ->postJson(route('admin.sales.sales-orders.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_doc_num', 'currency_doc_num', 'lines']);

        expect($response->json('errors.customer_doc_num.0'))
            ->toContain($arabic ? 'العميل' : 'Customer')
            ->not->toContain('customer doc num');
    }
});
