<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

/** @return array<string, mixed> */
function salesCycleFixture(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);

    $user = User::factory()->create();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Finished Goods', 'position' => 1]);
    $unit = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 8001, 'doc_num' => 'Unit-SALES', 'name' => 'Piece', 'status' => 'active']);
    $finished = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 8001, 'doc_num' => 'Product-FG', 'name' => 'Finished Crate', 'item_classification' => Product::ClassificationFinishedProduct, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $service = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 8002, 'doc_num' => 'Product-SERVICE', 'name' => 'Delivery Service', 'item_classification' => Product::ClassificationService, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $raw = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 8003, 'doc_num' => 'Product-RAW', 'name' => 'Polymer Resin', 'item_classification' => Product::ClassificationRawMaterial, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $semiFinished = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 8004, 'doc_num' => 'Product-SEMI', 'name' => 'Untrimmed Part', 'item_classification' => Product::ClassificationSemiFinished, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);

    $receivableClassification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $receivableParent = Account::query()->where('company_id', $company->getKey())->where('account_classification_id', $receivableClassification->getKey())->where('is_group', true)->orderByDesc('level')->firstOrFail();
    $customerAccount = Account::query()->create(['doc_number' => 9801, 'doc_num' => 'Account-Customer-Sales', 'company_id' => $company->getKey(), 'account_code' => '112199801', 'name' => 'Sales Cycle Customer', 'parent_id' => $receivableParent->getKey(), 'level' => ((int) $receivableParent->level) + 1, 'account_classification_id' => $receivableClassification->getKey(), 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit, 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $customer = Customer::query()->create(['doc_number' => 9801, 'doc_num' => 'Customer-SALES', 'company_id' => $company->getKey(), 'account_id' => $customerAccount->getKey(), 'account_group_id' => $receivableParent->getKey(), 'name' => 'Sales Cycle Customer', 'status' => 'active']);
    CustomerCommercialAgreement::query()->create(['company_id' => $company->getKey(), 'customer_id' => $customer->getKey(), 'currency_id' => $currency->getKey(), 'customer_type' => CustomerCommercialAgreement::TypeCredit, 'credit_limit' => '10000', 'include_open_orders' => true, 'required_advance_percentage' => 0, 'required_advance_minimum' => 0, 'blocking_enabled' => true, 'temporary_override_allowed' => true, 'status' => 'active']);

    $cashClassification = AccountClassification::query()->where('code', 'cash')->firstOrFail();
    $cashParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1111')->firstOrFail();
    $cashAccount = Account::query()->create(['doc_number' => 9802, 'doc_num' => 'Account-Cash-Sales', 'company_id' => $company->getKey(), 'account_code' => '11119802', 'name' => 'Sales Cashbox Account', 'parent_id' => $cashParent->getKey(), 'level' => ((int) $cashParent->level) + 1, 'account_classification_id' => $cashClassification->getKey(), 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit, 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $cashbox = Cashbox::query()->create(['doc_number' => 9801, 'doc_num' => 'Cashbox-SALES', 'company_id' => $company->getKey(), 'branch_id' => $branch->getKey(), 'account_id' => $cashAccount->getKey(), 'name' => 'Sales Collection Cashbox', 'status' => 'active']);
    $bankParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1112')->firstOrFail();
    $bankLedgerAccount = Account::query()->create(['doc_number' => 9803, 'doc_num' => 'Account-Bank-Sales', 'company_id' => $company->getKey(), 'account_code' => '11129803', 'name' => 'Sales Collection Bank Account', 'parent_id' => $bankParent->getKey(), 'level' => ((int) $bankParent->level) + 1, 'account_classification_id' => $cashClassification->getKey(), 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit, 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $bankAccount = BankAccount::query()->create(['doc_number' => 9801, 'doc_num' => 'BankAccount-SALES', 'company_id' => $company->getKey(), 'account_id' => $bankLedgerAccount->getKey(), 'currency_id' => $currency->getKey(), 'account_name' => 'Sales Collection Bank', 'account_number' => 'E2E-9801', 'status' => 'active']);

    InventoryTransaction::query()->create(['posting_key' => 'sales-cycle-opening-stock', 'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(), 'branch_store_id' => $store->getKey(), 'transaction_date' => now()->toDateString(), 'transaction_type' => 'opening_stock', 'product_id' => $finished->getKey(), 'unit_id' => $unit->getKey(), 'quantity_in' => '100', 'quantity_out' => 0, 'source_type' => 'test_opening_stock', 'source_id' => 1, 'source_doc_num' => 'TEST-STOCK', 'unit_cost' => '5', 'total_cost' => '500', 'created_by' => $user->getKey()]);

    return compact('user', 'company', 'branch', 'period', 'currency', 'store', 'unit', 'finished', 'service', 'raw', 'semiFinished', 'customer', 'cashbox', 'bankAccount');
}

/** @param array<string, mixed> $fixture @return array<string, mixed> */
function salesCycleSession(array $fixture): array
{
    return [
        'locale' => 'en',
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $fixture['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $fixture['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
}

/** @param array<string, mixed> $fixture */
function salesCycleOrderPayload(array $fixture, array $overrides = []): array
{
    return [
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'branch_store_id' => $fixture['store']->getKey(),
        'customer_id' => $fixture['customer']->getKey(), 'currency_id' => $fixture['currency']->getKey(),
        'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'exchange_rate' => 1, 'lines' => [
            ['product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'description' => 'Finished Crate', 'quantity' => '100', 'unit_price' => '10', 'discount_amount' => 0, 'tax_amount' => 0],
            ['product_id' => $fixture['service']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'description' => 'Delivery Service', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => 0, 'tax_amount' => 0],
        ],
        'payment_schedules' => [['title' => 'Full order value', 'amount' => '1100', 'due_date' => now()->addMonth()->toDateString()]],
        ...$overrides,
    ];
}

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

    $reservation = $fulfillment->reserve($goodsLine, '100');
    expect($reservation->status)->toBe(InventoryReservation::StatusActive);
    $firstDelivery = $fulfillment->deliver($order, [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '60']]);
    expect($goodsLine->fresh()->remainingDeliveryQuantity())->toBe('40.00000000')
        ->and($firstDelivery->lines)->toHaveCount(1)
        ->and($firstDelivery->lines->first()->source_line_id)->toBe($goodsLine->getKey());
    $secondDelivery = $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '40']]);
    expect($order->fresh()->status)->toBe(SalesOrder::StatusFulfilled)
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
        ->toThrow(DomainException::class, 'require a bank account');
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
    expect((string) InventoryTransaction::query()->where('product_id', $fixture['finished']->getKey())->sum(DB::raw('quantity_in - quantity_out')))->toBe('20');
    expect(JournalEntry::query()->where('source_type', 'sales_return_cogs')->count())->toBe(1)
        ->and((float) DB::table('journal_entry_lines')->whereIn('journal_entry_id', JournalEntry::query()->where('source_type', 'sales_return_cogs')->pluck('id'))->sum('debit_amount'))->toEqual(100.0)
        ->and(InventoryTransaction::query()->where('transaction_type', 'sales_return_receipt')->count())->toBe(1);
    $customerLedger = DB::table('journal_entry_lines')->where('customer_id', $fixture['customer']->getKey())
        ->selectRaw('coalesce(sum(debit_amount), 0) as debits, coalesce(sum(credit_amount), 0) as credits')->first();
    expect((float) $customerLedger->debits)->toEqual(1100.0)
        ->and((float) $customerLedger->credits)->toEqual(1470.0)
        ->and(bcsub((string) $customerLedger->debits, (string) $customerLedger->credits, 4))->toBe('-370.0000');
    expect(fn () => $returns->create($invoice, SalesReturn::ReasonOther, null, [['customer_invoice_line_id' => $invoiceGoodsLine->getKey(), 'quantity' => '31']]))->toThrow(DomainException::class);
});

test('sales eligibility exposes only finished products and services and rejects internal items server side', function () {
    $fixture = salesCycleFixture();
    expect(Product::query()->salesEligible()->pluck('item_classification')->unique()->sort()->values()->all())->toBe([Product::ClassificationFinishedProduct, Product::ClassificationService]);
    $payload = salesCycleOrderPayload($fixture, ['lines' => [['product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(), 'description' => 'Raw', 'quantity' => '1', 'unit_price' => '10']], 'payment_schedules' => [['title' => 'Due', 'amount' => '10', 'due_date' => now()->toDateString()]]]);
    expect(fn () => app(SalesOrderService::class)->create($payload))->toThrow(DomainException::class, 'Only finished products and services may be sold.');
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

test('posted invoice correction reverses the original journal before amendment and reposts a new revision', function () {
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
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $orderLine->getKey(), 'quantity' => '10',
    ]]);
    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $orderLine->getKey(),
        'delivery_line_id' => $delivery->lines->first()->getKey(),
        'quantity' => '10',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '100']], $delivery));
    $originalJournalId = $invoice->journal_entry_id;

    $invoice = $invoices->reopen($invoice, 'Customer accepted a controlled quantity correction.');
    $originalJournal = JournalEntry::query()->findOrFail($originalJournalId);
    expect($invoice->status)->toBe(CustomerInvoice::StatusReopened)
        ->and($invoice->posting_status)->toBe('reopen_pending_repost')
        ->and($invoice->posting_revision)->toBe(1)
        ->and($invoice->reversal_journal_entry_id)->not->toBeNull()
        ->and($originalJournal->reversed_entry_id)->toBe($invoice->reversal_journal_entry_id);

    $invoice = $invoices->amend($invoice, [[
        'invoice_line_public_id' => $invoice->lines()->firstOrFail()->public_id,
        'quantity' => '8',
    ]], [['due_date' => now()->addMonth()->toDateString(), 'amount' => '80']]);
    expect($invoice->total_amount)->toBe('80.0000')
        ->and($invoice->lines->first()->quantity)->toBe('8.00000000')
        ->and($orderLine->fresh()->invoiced_quantity)->toBe('8.00000000');

    $invoice = $invoices->post($invoice);
    expect($invoice->posting_status)->toBe('posted')
        ->and($invoice->journal_entry_id)->not->toBe($originalJournalId)
        ->and($invoice->journalEntry->source_type)->toBe('customer_invoice_post_1')
        ->and($invoices->post($invoice)->journal_entry_id)->toBe($invoice->journal_entry_id);

    app(SalesReturnService::class)->create($invoice, SalesReturn::ReasonOther, 'Pending source-driven return.', [[
        'customer_invoice_line_id' => $invoice->lines->first()->getKey(), 'quantity' => '1',
    ]]);
    expect(fn () => $invoices->reopen($invoice->fresh(), 'Unsafe descendant mutation attempt.'))
        ->toThrow(DomainException::class, 'return or credit note');
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
        ->toThrow(DomainException::class, 'remaining order quantity');
    $reservation = $fulfillment->releaseReservation($reservation, 'Customer requested a controlled release.');
    expect($reservation->status)->toBe(InventoryReservation::StatusReleased)
        ->and($reservation->release_reason)->toBe('Customer requested a controlled release.')
        ->and($line->fresh()->reserved_quantity)->toBe('0.00000000')
        ->and($fulfillment->reserve($line->fresh(), '100')->transaction_quantity)->toBe('100.00000000');
});

test('production demand preserves order-line lineage and increments produced quantity only from receipts', function () {
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

    $productionLine = $production->lines->first();
    app(SalesProductionDemandService::class)->receiveCompletion($production, $fixture['store']->getKey(), [['production_order_line_id' => $productionLine->getKey(), 'quantity' => '25']]);
    expect($orderLine->fresh()->produced_quantity)->toBe('25.00000000')->and($production->fresh()->status)->toBe(ProductionOrder::StatusPartiallyCompleted);
    app(SalesProductionDemandService::class)->receiveCompletion($production->fresh(), $fixture['store']->getKey(), [['production_order_line_id' => $productionLine->getKey(), 'quantity' => '25']]);
    expect($orderLine->fresh()->produced_quantity)->toBe('50.00000000')->and($production->fresh()->status)->toBe(ProductionOrder::StatusCompleted);

    $fulfillment = app(SalesFulfillmentService::class);
    $fulfillment->reserve($orderLine->fresh(), '150');
    $delivery = $fulfillment->deliver($order->fresh(), [['sales_order_line_id' => $orderLine->getKey(), 'quantity' => '150']]);
    expect($delivery->lines->first()->source_line_id)->toBe($orderLine->getKey())
        ->and($orderLine->fresh()->delivered_quantity)->toBe('150.00000000');
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
        ->toThrow(DomainException::class, 'must be reopened');

    $order = $orders->update($orders->reopen($order, 'Customer confirmed an amended requested date.'), salesCycleOrderPayload($fixture));
    $order = $orders->approve($orders->submit($order));
    $goodsLine = $order->lines->firstWhere('product_id', $fixture['finished']->getKey());
    app(SalesFulfillmentService::class)->reserve($goodsLine, '1');

    expect(fn () => $orders->reopen($order->fresh(), 'Attempt to change an already planned order.'))
        ->toThrow(DomainException::class, 'reservations, production, deliveries, or invoices');
});

test('implemented sales cycle routes are not shadowed by UI shell placeholders', function () {
    expect(app('router')->getRoutes()->getByName('admin.sales.sales-orders.index')?->getActionName())->toContain('SalesCycleController@orders')
        ->and(app('router')->getRoutes()->getByName('admin.sales.sales-orders.show')?->getActionName())->toContain('SalesCycleController@showOrder')
        ->and(app('router')->getRoutes()->getByName('admin.sales.sales-returns.index')?->getActionName())->toContain('SalesCycleController@returns')
        ->and(app('router')->getRoutes()->getByName('admin.reports.sales.sales-orders.index')?->getActionName())->toContain('SalesCycleReportController@index');
});

test('restricted production and warehouse browser responses do not expose commercial values', function () {
    $fixture = salesCycleFixture();
    foreach (['production.work_orders.view', 'production.work_orders.print', 'sales_deliveries.view', 'sales_deliveries.print', 'sales_orders.reserve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo(['production.work_orders.view', 'production.work_orders.print', 'sales_deliveries.view', 'sales_deliveries.print', 'sales_orders.reserve']);
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
    $production = app(SalesProductionDemandService::class)->create($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '1']]);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->getKey(), 'quantity' => '1']]);

    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.production.work-orders.show', $production))
        ->assertOk()->assertSee('Sealed export carton')->assertDontSee('987.6543')->assertDontSee('Credit limit');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.production.work-orders.print', $production))
        ->assertOk()->assertDontSee('987.6543')->assertDontSee('Unit price');
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture))
        ->get(route('admin.sales.delivery-notes.show', $delivery))
        ->assertOk()->assertDontSee('987.6543')->assertDontSee('Unit price');
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
        ->assertSee($fixture['finished']->name)->assertSee($fixture['service']->name)
        ->assertDontSee($fixture['raw']->name)->assertDontSee($fixture['semiFinished']->name);
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.edit', $draftOrder))
        ->assertOk()->assertSee('Edit Sales Order')->assertSee('Customer reference / PO');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-invoices.edit', $invoice))
        ->assertOk()->assertSee('Correct Sales Invoice')->assertSee('Corrected quantity');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.customer-receipts.create', ['invoice' => $invoice->doc_num]))
        ->assertOk()->assertSee('Customer Receipt / Collection')->assertSee('Cheque')->assertSee('Bank transfer');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.sales.sales-orders.print', $draftOrder))
        ->assertOk()->assertSee('Sales Order')->assertSee('Unit price');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.reports.sales.sales-orders.print'))
        ->assertOk()->assertSee('Sales Cycle Operational Report');
    $this->actingAs($fixture['user'])->withSession($session)->get(route('admin.reports.sales.sales-orders.export'))
        ->assertOk()->assertDownload();
});

test('sales operational report renders order, sales, aging, and return analyses in the selected context', function () {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('reports.sales.sales_orders.view', 'web');
    $fixture['user']->givePermissionTo('reports.sales.sales_orders.view');
    $session = salesCycleSession($fixture);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index'))
        ->assertOk()
        ->assertSee('Sales Cycle Operational Report')
        ->assertSee('Customer Aging')
        ->assertSee('Sales by Item')
        ->assertSee('Customer Order History')
        ->assertSee('Upcoming Collections')
        ->assertSee('Customer / Item Sales Analysis');
});
