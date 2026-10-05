<?php

use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesWithholdingService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

test('withholding uses VAT inclusive gross independently from commercial discounts', function (?string $lineType, string $lineValue, ?string $headerType, string $headerValue, string $tax, string $gross, string $withheld, string $net): void {
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    $order = app(SalesOrderService::class)->create(salesCycleOrderPayload($f, ['payment_schedules' => [],
        'discount_type' => $headerType, 'discount_value' => $headerValue, 'withholding_rate' => '1',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'unit_price' => '1000',
            'discount_type' => $lineType, 'discount_value' => $lineValue, 'tax_amount' => $tax]]]));
    expect($order->tax_amount)->toBe($tax)->and($order->total_amount)->toBe($gross)
        ->and($order->withholding_basis)->toBe(SalesWithholdingService::GrossIncludingTax)
        ->and($order->withholding_basis_amount)->toBe($gross)->and($order->withholding_amount)->toBe($withheld)->and($order->net_payable_amount)->toBe($net);
})->with([
    [null, '0', null, '0', '140.0000', '1140.0000', '11.4000', '1128.6000'],
    ['fixed', '100', 'percentage', '10', '113.4000', '923.4000', '9.2340', '914.1660'],
    ['percentage', '10', 'fixed', '90', '113.4000', '923.4000', '9.2340', '914.1660'],
]);

test('withholding defaults to zero preserves prices and rejects invalid rates', function (): void {
    $calculator = app(SalesWithholdingService::class);
    expect($calculator->calculate('225454.5000')['net_payable_amount'])->toBe('225454.5000')
        ->and($calculator->calculate('0.0000', '1')['withholding_amount'])->toBe('0.0000')
        ->and($calculator->calculate('0.0050', '1')['withholding_amount'])->toBe('0.0001');
    foreach (['-1', '101', '1.00001', '1e2'] as $rate) {
        expect(fn () => $calculator->calculate('1140', $rate))->toThrow(DomainException::class);
    }
});

test('direct draft invoices persist the withholding input and posting is blocked atomically until the accounting policy exists', function (): void {
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['customer_invoices.create', 'customer_invoices.edit', 'customer_invoices.view', 'customer_invoices.view_prices', 'customer_invoices.print', 'customer_invoices.post'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
    createSalesPriceList($f, null, [['product' => $f['service'], 'price' => '1000']]);
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->createDirect([...salesCycleOrderPayload($f), 'customer_doc_num' => $f['customer']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(), 'withholding_rate' => '1',
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'tax_amount' => '140']]]);
    expect($invoice->total_amount)->toBe('1140.0000')->and($invoice->tax_amount)->toBe('140.0000')
        ->and($invoice->withholding_amount)->toBe('11.4000')->and($invoice->net_payable_amount)->toBe('1128.6000');
    $before = [$invoice->fresh()->getAttributes(), DB::table('journal_entries')->count(), DB::table('inventory_documents')->count()];
    expect(fn () => $invoices->post($invoice))->toThrow(DomainException::class, __('sales_ui.withholding_posting_pending'));
    expect([$invoice->fresh()->getAttributes(), DB::table('journal_entries')->count(), DB::table('inventory_documents')->count()])->toBe($before);
    foreach (['en', 'ar'] as $locale) {
        $this->withSession(['locale' => $locale]);
        app()->setLocale($locale);
        $this->get(route('admin.sales.sales-invoices.show', $invoice))->assertOk()->assertSee('1,128.60');
        $pdf = $this->get(route('admin.sales.sales-invoices.print', $invoice))->assertOk();
        $invalid = $this->postJson(route('admin.sales.sales-invoices.store'), [
            'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
            'invoice_date' => now()->toDateString(), 'exchange_rate' => '1', 'withholding_rate' => '101',
            'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1']],
        ])->assertUnprocessable()->assertJsonValidationErrors('withholding_rate');
        expect($invalid->json('errors.withholding_rate.0'))->toContain(__('sales_ui.withholding_rate'));
        file_put_contents('/tmp/mgypack-withholding-print-'.$locale.'-20261004.pdf', $pdf->getContent());
        expect(salesPdfText($pdf->getContent()))->toContain('1,128.60')->toContain('11.40')->toContain('140.00');
    }
    $invoice = $invoices->amend($invoice, [['invoice_line_public_id' => $invoice->lines->sole()->public_id, 'quantity' => '2']], [['due_date' => now()->toDateString(), 'amount' => '2280']], '0.5');
    expect($invoice->withholding_rate)->toBe('0.5000')->and($invoice->withholding_amount)->toBe('11.4000')->and($invoice->net_payable_amount)->toBe('2268.6000');
});

test('order partial invoice copying retains the withholding basis and does not alter tax or commercial allocations', function (): void {
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, ['payment_schedules' => [], 'withholding_rate' => '1',
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'unit_price' => '333.33333333', 'tax_amount' => '140']]])));
    foreach (['379.9999', '379.9999', '380.0002'] as $total) {
        $invoice = app(CustomerInvoiceService::class)->createFromOrder($order, [['sales_order_line_id' => $order->lines->sole()->id, 'quantity' => '1']], [['amount' => $total, 'due_date' => now()->toDateString()]]);
        expect($invoice->withholding_rate)->toBe('1.0000')->and($invoice->withholding_basis_amount)->toBe($total);
    }
    expect(bcadd((string) DB::table('customer_invoices')->where('sales_order_id', $order->id)->sum('withholding_amount'), '0', 4))->toBe($order->withholding_amount)
        ->and(bcadd((string) DB::table('customer_invoices')->where('sales_order_id', $order->id)->sum('tax_amount'), '0', 4))->toBe('140.0000');
});
