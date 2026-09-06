<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
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
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Symfony\Component\Process\Process;

function salesPdfText(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'sales-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->mustRun();

        return $process->getOutput();
    } finally {
        @unlink($path);
    }
}

function salesPdfPageCount(string $content): int
{
    $path = tempnam(sys_get_temp_dir(), 'sales-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdfinfo', $path]);
        $process->mustRun();
        preg_match('/^Pages:\s+(\d+)$/m', $process->getOutput(), $matches);

        return (int) ($matches[1] ?? 0);
    } finally {
        @unlink($path);
    }
}

function salesPdfImageCount(string $content): int
{
    $path = tempnam(sys_get_temp_dir(), 'sales-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdfimages', '-list', $path]);
        $process->mustRun();

        return preg_match_all('/^\s*\d+\s+\d+\s+/m', $process->getOutput());
    } finally {
        @unlink($path);
    }
}

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

    $accountId = fn (string $code): int => (int) Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $code)
        ->valueOrFail('id');
    InventoryAccountingMapping::query()->create([
        'company_id' => $company->getKey(),
        'raw_material_inventory_account_id' => $accountId('1131'),
        'packaging_inventory_account_id' => $accountId('1134'),
        'semi_finished_inventory_account_id' => $accountId('1132'),
        'finished_goods_inventory_account_id' => $accountId('1133'),
        'wip_account_id' => $accountId('1132'),
        'production_waste_account_id' => $accountId('551'),
        'warehouse_damage_loss_account_id' => $accountId('551'),
        'inventory_adjustment_gain_account_id' => $accountId('432'),
        'inventory_adjustment_loss_account_id' => $accountId('551'),
        'quarantine_inventory_account_id' => $accountId('1134'),
        'rework_inventory_account_id' => $accountId('1132'),
        'grni_account_id' => $accountId('212'),
        'purchase_price_variance_account_id' => $accountId('551'),
        'created_by' => $user->getKey(),
    ]);

    InventoryTransaction::query()->create(['posting_key' => 'sales-cycle-opening-stock', 'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(), 'branch_store_id' => $store->getKey(), 'transaction_date' => now()->toDateString(), 'transaction_type' => 'opening_stock', 'product_id' => $finished->getKey(), 'unit_id' => $unit->getKey(), 'batch_lot' => 'SALES-OPENING-BATCH', 'quantity_in' => '100', 'quantity_out' => 0, 'source_type' => 'test_opening_stock', 'source_id' => 1, 'source_doc_num' => 'TEST-STOCK', 'unit_cost' => '5', 'total_cost' => '500', 'created_by' => $user->getKey()]);

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

/** @param array<string, mixed> $fixture */
function salesPostedServiceInvoice(array $fixture, string $netAmount, string $taxAmount = '0', string $quantity = '1'): CustomerInvoice
{
    $total = bcadd($netAmount, $taxAmount, 4);
    $unitPrice = bcdiv($netAmount, $quantity, 4);
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['service']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Service billing line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'tax_amount' => $taxAmount,
        ]],
        'payment_schedules' => [[
            'title' => 'Service invoice',
            'amount' => $total,
            'due_date' => now()->toDateString(),
        ]],
    ])));

    return app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createFromOrder(
        $order,
        [['sales_order_line_id' => $order->lines->sole()->getKey(), 'quantity' => $quantity]],
        [['due_date' => now()->toDateString(), 'amount' => $total]],
    ));
}
