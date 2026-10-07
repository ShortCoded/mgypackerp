<?php

use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\BankAccount;
use Modules\Production\Services\ProductionExpenseRequestService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';
require_once __DIR__.'/ProductionAnyStageMaterialSupport.php';

/** @return array<string, mixed> */
function cancellationRestoreFixture(string $sourceType = 'order'): array
{
    $f = salesCycleFixture(true);
    foreach (['customer_invoices.view', 'customer_invoices.view_prices', 'customer_invoices.view_trashed', 'customer_invoices.restore',
        'customer_invoices.create', 'customer_invoices.delete', 'sales_orders.view', 'sales_orders.invoice', 'sales_requests.view'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    test()->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    $invoices = app(CustomerInvoiceService::class);
    if ($sourceType === 'order') {
        $orders = app(SalesOrderService::class);
        $f['source'] = $orders->approve($orders->create(salesCycleOrderPayload($f)));
        $f['invoice'] = $invoices->createFromOrder($f['source'], [['sales_order_line_id' => $f['source']->lines->first()->id, 'quantity' => '100']],
            [['amount' => '1000', 'due_date' => now()->toDateString()]]);
    } else {
        createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '100']]);
        $requests = app(SalesRequestService::class);
        $f['source'] = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'financial_period_id' => $f['period']->id, 'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id,
            'request_date' => now()->toDateString(), 'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1']]]);
        $requests->transition($f['source'], 'submitted');
        $requests->transition($f['source']->fresh(), 'approved');
        $f['invoice_payload'] = [...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num,
            'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(),
            'lines' => [['source_request_line_public_id' => $f['source']->fresh()->lines->sole()->public_id,
                'product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
                'quantity' => '1', 'discount_amount' => '0', 'tax_amount' => '0']],
            'payment_schedules' => [['amount' => '100', 'due_date' => now()->toDateString()]]];
        $f['invoice'] = $invoices->createDirect($f['invoice_payload'], $f['source']->fresh());
    }

    return $f;
}

/** @return array<string, mixed> */
function cancellationExpenseFixture(): array
{
    $f = anyStageMaterialFixture();
    foreach (['production.expenses.view', 'production.expenses.reverse', 'production.runs.view'] as $ability) {
        $f['user']->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $currency = Currency::query()->where('company_id', $f['company']->id)->where('is_main', true)->sole();
    $parent = Account::query()->where('company_id', $f['company']->id)->where('account_code', '1112')->firstOrFail();
    $ledger = $parent->replicate(['id', 'doc_number', 'doc_num', 'account_code']);
    $ledger->forceFill(['doc_number' => (int) Account::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-CANCEL-BANK-'.$f['company']->id, 'account_code' => '11129993',
        'name' => 'SYNTHETIC expense withdrawal bank', 'parent_id' => $parent->id, 'level' => $parent->level + 1,
        'is_group' => false, 'is_postable' => true, 'is_system' => false])->save();
    $bank = BankAccount::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) BankAccount::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-CANCEL-BANK-'.$f['company']->id, 'account_id' => $ledger->id,
        'currency_id' => $currency->id, 'account_name' => 'SYNTHETIC expense bank', 'account_number' => 'SYNTHETIC-991', 'status' => 'active']);
    $account = Account::query()->where('company_id', $f['company']->id)->where('account_code', '523')->firstOrFail();
    $expenses = app(ProductionExpenseRequestService::class);
    $f['expense'] = $expenses->approve($expenses->create($f['runs'][0], ['amount' => '50', 'currency_id' => $currency->id,
        'payment_channel' => 'bank', 'bank_account_id' => $bank->id, 'expense_account_id' => $account->id,
        'reason' => 'SYNTHETIC approved unpaid expense']));

    return $f;
}
