<?php

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnCorrectionService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

/** @param array<string,mixed> $f */
function laterReturnActor(array $f, User $user): void
{
    $session = salesCycleSession($f);
    if (isset($f['target'])) {
        $session[OperatingContextService::FinancialPeriodIdKey] = $f['target']->id;
        $session[OperatingContextService::FinancialPeriodDocNumKey] = $f['target']->doc_num;
    }
    test()->actingAs($user)->withSession($session);
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }
    request()->session()->put($session);
    request()->setUserResolver(fn (): User => $user);
}

/** @return array<string,mixed> */
function laterReturnFixture(string $state = 'service', bool $unbilled = false): array
{
    Carbon::setTestNow('2026-09-28 12:00:00');
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    $f = salesCycleFixture(isolatedCompany: true);
    $f['period']->update(['from_date' => '2026-09-01', 'to_date' => '2026-09-30']);
    $permissions = ['sales_returns.view', 'sales_returns.create', 'sales_returns.authorize', 'sales_returns.receive', 'sales_returns.inspect',
        'sales_returns.close', 'sales_returns.correct_receipt', 'sales_returns.correct_disposition', 'sales_returns.correct_closed',
        'sales_returns.correct_prepare', 'sales_returns.correct_approve', 'sales_returns.correct_later_period',
        'customer_credits.reverse_allocation', 'customer_credits.reverse_refund', 'customer_invoices.view'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $f['user']->givePermissionTo($permissions);
    $f['reviewer'] = closureSyntheticUser();
    $f['reviewer']->givePermissionTo($permissions);
    laterReturnActor($f, $f['user']);
    $returns = app(SalesReturnService::class);
    if ($state === 'service') {
        $invoice = salesPostedServiceInvoice($f, '100', quantity: '2');
    } else {
        $orders = app(SalesOrderService::class);
        $order = $orders->approve($orders->create(salesCycleOrderPayload($f)));
        $line = $order->lines->firstWhere('product_id', $f['finished']->id);
        $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $line->id, 'quantity' => '20']]);
        if (! $unbilled) {
            $invoices = app(CustomerInvoiceService::class);
            $invoice = $invoices->post($invoices->createFromOrder($order,
                [['sales_order_line_id' => $line->id, 'delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '20']],
                [['due_date' => '2026-09-28', 'amount' => '200']], $delivery));
        }
        $f += compact('order', 'delivery');
    }
    if (! $unbilled) {
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
            'customer_id' => $f['customer']->id, 'receipt_date' => '2026-09-28', 'currency_id' => $f['currency']->id,
            'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id,
            'amount' => $state === 'service' ? '80' : '180', 'receipt_type' => CustomerReceipt::TypeCollection,
        ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->id, 'amount' => $state === 'service' ? '80' : '180']]);
        $return = $returns->authorize($returns->create($invoice, SalesReturn::ReasonOrderEntry, 'SYNTHETIC original return',
            [['customer_invoice_line_id' => $invoice->lines->sole()->id, 'quantity' => $state === 'service' ? '1' : '5']]));
        $f['invoice'] = $invoice;
    } else {
        $return = $returns->authorize($returns->createFromDelivery($delivery, SalesReturn::ReasonWrongItem, 'SYNTHETIC unbilled return',
            [['delivery_line_id' => $delivery->lines->sole()->id, 'quantity' => '5']]));
    }
    if ($state !== 'service') {
        $return = $returns->receive($return);
        if ($state !== 'received') {
            $return = $returns->inspect($return, [['sales_return_line_id' => $return->lines->sole()->id,
                'saleable_quantity' => '2', 'rework_quantity' => '2', 'scrap_quantity' => '1']]);
        }
    }
    if (in_array($state, ['service', 'closed'], true)) {
        $return = $returns->close($return);
    }
    $f += ['return' => $return, 'credit' => $return->creditNote];

    return $f;
}

/** @param array<string,mixed> $f @return array<string,mixed> */
function laterReturnCloseSource(array $f): array
{
    $f['period']->update(['is_closed' => true]);
    $number = (int) FinancialPeriod::withTrashed()->max('doc_number') + 1;
    $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => $number,
        'doc_num' => 'SYNTHETIC-RETURN-OCTOBER-'.$number, 'name' => 'SYNTHETIC October corrections',
        'from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'is_closed' => false]);
    Carbon::setTestNow('2026-10-03 14:00:00');
    CarbonImmutable::setTestNow('2026-10-03 14:00:00');
    laterReturnActor($f, $f['user']);

    return $f;
}

/** @param array<string,mixed> $f @return array<string,mixed> */
function laterReturnPayload(array $f, array $changes = []): array
{
    return ['source_fingerprint' => app(SalesReturnCorrectionService::class)->preview($f['return'])['fingerprint'],
        'operation' => 'return', 'posting_date' => '2026-10-02', 'reason' => 'SYNTHETIC correct original return quantity',
        'lines' => [['sales_return_line_id' => $f['return']->lines->sole()->id, 'quantity' => '0.5']], ...$changes];
}
