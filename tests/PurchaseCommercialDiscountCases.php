<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\PurchaseDiscountSourceService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderCalculationService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProcurementSupport.php';

/** @return array<string, mixed> */
function purchaseDiscountFixture(): array
{
    $f = procurementFixture(DB::getDriverName() === 'pgsql');
    foreach (['purchases.prices.view', 'purchases.direct_procurement.override', 'purchase_orders.create', 'purchase_orders.edit', 'purchase_orders.view', 'purchase_orders.print', 'purchase_invoices.create', 'purchase_invoices.edit', 'purchase_invoices.view', 'purchase_invoices.print'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    $f['firstSupplier']->forceFill(['account_id' => procurementPostingAccount($f['company'], '2111', '2111001', 'SYNTHETIC discount supplier payable')->id])->save();
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    test()->actingAs($f['user']);

    return $f;
}

/** @return array<string, mixed> */
function purchaseDiscountOrderData(array $f, array $overrides = []): array
{
    return array_replace(['supplier_doc_num' => $f['firstSupplier']->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC commercial discount test',
        'header_discount_type' => 'fixed', 'header_discount_value' => '50', 'lines' => [[
            'product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '1', 'unit_price' => '1000',
            'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14',
        ]]], $overrides);
}

/** @return array<string, mixed> */
function purchaseDiscountInvoiceData(array $f, ?PurchaseOrder $order = null, string $quantity = '1'): array
{
    $data = ['supplier_doc_num' => $f['firstSupplier']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'financial_period_doc_num' => $f['period']->doc_num, 'invoice_date' => now()->toDateString(), 'exchange_rate' => '1',
        'payment_type' => PurchaseInvoice::PaymentTypeCredit, 'direct_procurement_override' => true,
        'direct_procurement_reason' => 'SYNTHETIC commercial discount test', 'lines' => [[
            'product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => $quantity,
            'unit_price' => $order?->lines->sole()->unit_price ?? '1000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => $order?->lines->sole()->tax_rate ?? '14',
        ]]];
    if ($order) {
        $data['purchase_order_doc_num'] = $order->doc_num;
        $data['lines'][0]['purchase_order_line_public_id'] = $order->lines->sole()->public_id;
        $data['lines'][0]['inherit_source_discount'] = true;
        $data['inherit_header_discount'] = true;
        $suggested = app(PurchaseDiscountSourceService::class)->calculate($data, suggestDefaults: true)['defaults'];
        $data = [...$data, ...$suggested];
    } else {
        $data += ['purchase_type' => 'direct', 'header_discount_type' => 'fixed', 'header_discount_value' => '50'];
    }

    return $data;
}

test('purchase order header discounts reuse native net VAT calculations and preserve zero bases and input modes', function (): void {
    $calculator = app(PurchaseOrderCalculationService::class);
    $result = $calculator->calculate([
        ['ordered_quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14'],
        ['ordered_quantity' => '1', 'unit_price' => '0'],
    ], 0, 'fixed', '50');
    expect($result['order']['total_amount'])->toBe('969.0000')->and($result['order']['header_discount_amount'])->toBe('50.0000')
        ->and($result['lines'][0]['discount_type'])->toBe('percentage')->and($result['lines'][0]['discount_value'])->toBe('10.0000')
        ->and($result['lines'][0]['header_discount_amount'])->toBe('50.0000')->and($result['lines'][0]['tax_amount'])->toBe('119.0000')
        ->and($result['lines'][1]['header_discount_amount'])->toBe('0.0000');
    foreach ([['percentage', '101'], ['fixed', '901'], ['fixed', '-1'], ['fixed', '0.00001'], [null, '5']] as [$type, $value]) {
        expect(fn () => $calculator->calculate([['ordered_quantity' => '1', 'unit_price' => '1000', 'discount_type' => 'percentage', 'discount_value' => '10']], 0, $type, $value))->toThrow(DomainException::class);
    }
    $tiny = $calculator->calculate(array_fill(0, 5, ['ordered_quantity' => '1', 'unit_price' => '0.0001']), 0, 'fixed', '0.0002');
    $net = '0.0000';
    $shares = '0.0000';
    foreach ($tiny['lines'] as $line) {
        expect(bccomp($line['header_discount_amount'], $line['total_before_tax'], 4))->toBeLessThanOrEqual(0);
        $net = bcadd($net, $line['line_total'], 4);
        $shares = bcadd($shares, $line['header_discount_amount'], 4);
    }
    expect($net)->toBe($tiny['order']['total_amount'])->and($shares)->toBe('0.0002');
});

test('purchase order HTTP header input is validated computed once and cannot edit an approved source', function (): void {
    $f = purchaseDiscountFixture();
    $data = purchaseDiscountOrderData($f);
    $response = $this->postJson(route('admin.purchases.purchase-orders.store'), $data)->assertOk();
    $order = PurchaseOrder::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($order->total_amount)->toBe('969.0000')->and($order->header_discount_value)->toBe('50.0000');
    foreach (['101', '-1', '5.00001', '1e2'] as $value) {
        $this->postJson(route('admin.purchases.purchase-orders.store'), [...$data, 'header_discount_type' => 'percentage', 'header_discount_value' => $value])->assertUnprocessable()->assertJsonValidationErrors('header_discount_value');
    }
    $service = app(PurchaseOrderService::class);
    $order = $service->update($order, [...$data, 'header_discount_type' => 'percentage', 'header_discount_value' => '5'])['record'];
    expect($order->header_discount_amount)->toBe('45.0000')->and($order->total_amount)->toBe('974.7000');
    $order = $service->approve($order);
    expect(fn () => $service->update($order, [...$data, 'header_discount_value' => '20']))->toThrow(DomainException::class);
});

test('partial purchase invoice conversion conserves source inputs booked amounts and residual and unchanged edits', function (): void {
    $f = purchaseDiscountFixture();
    $data = purchaseDiscountOrderData($f, ['header_discount_value' => '0.0002']);
    $data['lines'][0]['ordered_quantity'] = '3';
    $data['lines'][0]['unit_price'] = '22.54545000';
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create($data)['record']);
    $service = app(PurchaseInvoiceService::class);
    $ids = [];
    for ($index = 0; $index < 3; $index++) {
        $payload = purchaseDiscountInvoiceData($f, $order);
        $invoice = $service->create($payload)['record'];
        $ids[] = $invoice->id;
        expect($invoice->lines->sole()->discount_type)->toBe('percentage')->and($invoice->lines->sole()->discount_value)->toBe('10.0000')
            ->and($invoice->lines->sole()->source_discount_snapshot['inherited'])->toBeTrue();
    }
    $line = $order->lines->sole();
    foreach (['discount_amount', 'tax_amount', 'subtotal_amount', 'header_discount_amount'] as $column) {
        expect(bcadd((string) DB::table('purchase_invoice_lines')->where('purchase_order_line_id', $line->id)->sum($column), '0', 4))->toBe((string) $line->{$column});
    }
    expect(bcadd((string) PurchaseInvoice::query()->whereIn('id', $ids)->sum('total_amount'), '0', 4))->toBe($order->total_amount);
    $first = PurchaseInvoice::query()->with('lines')->findOrFail($ids[0]);
    $before = [$first->total_amount, $first->header_discount_amount, $first->lines->sole()->discount_amount, $first->lines->sole()->tax_amount];
    $payload = purchaseDiscountInvoiceData($f);
    $payload['purchase_order_doc_num'] = $order->doc_num;
    $payload['header_discount_type'] = $first->header_discount_type;
    $payload['header_discount_value'] = $first->header_discount_value;
    $payload['lines'][0] = [...$payload['lines'][0], 'public_id' => $first->lines->sole()->public_id,
        'purchase_order_line_public_id' => $line->public_id, 'unit_price' => $line->unit_price];
    $first = $service->update($first, $payload)['record'];
    expect([$first->total_amount, $first->header_discount_amount, $first->lines->sole()->discount_amount, $first->lines->sole()->tax_amount])->toBe($before);
});

test('purchase source fixed discounts preserve allocated fixed meaning and eight place quantities without trusting client amounts', function (): void {
    $f = purchaseDiscountFixture();
    $data = purchaseDiscountOrderData($f, ['header_discount_value' => '0.0002']);
    $data['lines'][0] = [...$data['lines'][0], 'ordered_quantity' => '10000.00000001', 'unit_price' => '22.54545000', 'discount_type' => 'fixed', 'discount_value' => '0.1234'];
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create($data)['record']);
    $payload = purchaseDiscountInvoiceData($f, $order, '5000.00000001');
    $payload['lines'][0]['discount_amount'] = '99999';
    $invoice = app(PurchaseInvoiceService::class)->create($payload)['record'];
    expect($invoice->lines->sole()->quantity)->toBe('5000.00000001')->and($invoice->lines->sole()->discount_type)->toBe('fixed')
        ->and($invoice->lines->sole()->discount_value)->toBe('0.0617')->and($invoice->lines->sole()->discount_amount)->toBe('0.0617');
    $payload = purchaseDiscountInvoiceData($f, $order, '5000');
    $second = app(PurchaseInvoiceService::class)->create($payload)['record'];
    expect(bcadd($invoice->total_amount, $second->total_amount, 4))->toBe($order->total_amount)
        ->and(bcadd($invoice->lines->sole()->header_discount_amount, $second->lines->sole()->header_discount_amount, 4))->toBe($order->header_discount_amount);
});

test('purchase partial discounts keep every rounded net nonnegative and conserve fully discounted and tiny remaining sources', function (string $quantity, string $price, string $header): void {
    $f = purchaseDiscountFixture();
    $data = purchaseDiscountOrderData($f, ['header_discount_value' => $header]);
    $data['lines'][0] = [...$data['lines'][0], 'ordered_quantity' => $quantity, 'unit_price' => $price,
        'discount_type' => 'fixed', 'discount_value' => '0.0001', 'tax_rate' => '0'];
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create($data)['record']);
    $total = '0.0000';
    for ($index = 0; $index < (int) $quantity; $index++) {
        $payload = purchaseDiscountInvoiceData($f, $order);
        $saved = $this->postJson(route('admin.purchases.purchase-invoices.store'), $payload)->assertOk();
        $invoice = PurchaseInvoice::query()->with('lines')->where('doc_num', $saved->json('data.doc_num'))->firstOrFail();
        $line = $invoice->lines->sole();
        expect(bccomp(bcadd($line->discount_amount, $line->header_discount_amount, 4), $line->subtotal_amount, 4))->toBeLessThanOrEqual(0);
        $total = bcadd($total, $invoice->total_amount, 4);
    }
    expect($total)->toBe($order->total_amount);
    foreach (['subtotal_amount', 'discount_amount', 'header_discount_amount'] as $column) {
        expect(bcadd((string) DB::table('purchase_invoice_lines')->where('purchase_order_line_id', $order->lines->sole()->id)->sum($column), '0', 4))->toBe($order->lines->sole()->{$column});
    }
})->with([['2', '0.0001', '0.0001'], ['4', '0.00005', '0']]);

test('purchase discount posting uses native exact net cost VAT and legacy header fallback and locks the posted invoice', function (): void {
    $f = purchaseDiscountFixture();
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->approve($service->create(purchaseDiscountInvoiceData($f))['record']);
    expect($invoice->total_amount)->toBe('969.0000')->and($invoice->tax_amount)->toBe('119.0000');
    $journal = $invoice->journalEntry()->with('lines')->firstOrFail();
    expect(bcadd((string) $journal->lines->sum('debit_amount'), '0', 4))->toBe('969.0000')
        ->and(bcadd((string) $journal->lines->sum('credit_amount'), '0', 4))->toBe('969.0000');
    expect(app(PurchaseInvoiceCalculationService::class)->netAmountsByLine($invoice))->toBe([$invoice->lines->sole()->id => '850.0000']);
    expect($journal->lines->filter(fn ($line) => bccomp($line->debit_amount, '0', 4) > 0)->pluck('debit_amount')->sort()->values()->all())->toBe(['119.0000', '850.0000']);
    $invoice->lines->sole()->header_discount_amount = '0.0000';
    expect(app(PurchaseInvoiceCalculationService::class)->netAmountsByLine($invoice))->toBe([$invoice->lines->sole()->id => '850.0000']);
    expect(fn () => $service->update($invoice, purchaseDiscountInvoiceData($f)))->toThrow(DomainException::class);
    $invoice->header_discount_amount = '99999999999999.9999';
    $invoice->lines->sole()->header_discount_amount = '99999999999999.9999';
    $invoice->lines->sole()->total_before_tax = '100000000000000.9999';
    expect(app(PurchaseInvoiceCalculationService::class)->netAmountsByLine($invoice))->toBe([$invoice->lines->sole()->id => '1.0000']);
});

test('purchase discount create edit preview and PDF render both entered modes and booked totals in both locales', function (string $locale): void {
    $this->withoutExceptionHandling();
    $f = purchaseDiscountFixture();
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create(purchaseDiscountOrderData($f))['record']);
    $orderResponse = $this->get(route('admin.purchases.purchase-orders.show', $order))->assertOk()->assertSee('header_discount', false);
    $orderHtml = new DOMDocument;
    $orderHtml->loadHTML($orderResponse->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $orderSummary = new DOMXPath($orderHtml);
    foreach (['js-total-taxable' => '850', 'js-total-tax' => '119', 'js-total-amount' => '969'] as $class => $amount) {
        $cell = $orderSummary->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]')->item(0);
        expect($cell)->not->toBeNull()->and(trim($cell->textContent))->toBe($amount);
    }
    $this->get(route('admin.purchases.purchase-invoices.create', ['purchase_order' => $order->doc_num]))->assertOk()->assertSee('data-inherit-header-discount="1"', false)->assertSee('value="percentage" selected', false);
    $payload = purchaseDiscountInvoiceData($f, $order);
    $preview = $this->postJson(route('admin.purchases.purchase-invoices.discount-preview'), $payload)->assertOk();
    expect($preview->json('data.calculation.invoice.total_amount'))->toBe('969.0000');
    $invoice = app(PurchaseInvoiceService::class)->create($payload)['record'];
    $this->get(route('admin.purchases.purchase-invoices.edit', $invoice))->assertOk()->assertSee('discount-preview', false);
    foreach ([['purchase-orders', $order], ['purchase-invoices', $invoice]] as [$route, $document]) {
        $pdf = $this->get(route('admin.purchases.'.$route.'.print', $document))->assertOk();
        file_put_contents('/tmp/mgypack-purchase-discount-'.$route.'-'.$locale.'-20261004.pdf', $pdf->getContent());
    }
})->with(['en', 'ar']);

test('purchase invoice HTTP save and amendment use the same partial source quote as preview and keep negotiated inputs distinct', function (): void {
    $f = purchaseDiscountFixture();
    $invalid = purchaseDiscountInvoiceData($f);
    $invalid['header_discount_value'] = '901';
    $this->postJson(route('admin.purchases.purchase-invoices.store'), $invalid)->assertUnprocessable()->assertJsonValidationErrors('header_discount_value');
    $invalid['header_discount_value'] = '0';
    $invalid['lines'][0]['discount_type'] = 'fixed';
    $invalid['lines'][0]['discount_value'] = '1001';
    $this->postJson(route('admin.purchases.purchase-invoices.store'), $invalid)->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_value');
    $data = purchaseDiscountOrderData($f, ['header_discount_value' => '10']);
    $data['lines'][0] = [...$data['lines'][0], 'ordered_quantity' => '2', 'unit_price' => '100', 'discount_type' => 'fixed', 'discount_value' => '20'];
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create($data)['record']);
    $payload = purchaseDiscountInvoiceData($f, $order);
    $created = $this->postJson(route('admin.purchases.purchase-invoices.store'), $payload)->assertOk();
    $invoice = PurchaseInvoice::query()->with('lines')->where('doc_num', $created->json('data.doc_num'))->firstOrFail();
    expect($invoice->total_amount)->toBe('96.9000')->and($invoice->header_discount_amount)->toBe('5.0000');
    $payload['lines'][0]['public_id'] = $invoice->lines->sole()->public_id;
    $payload['lines'][0]['quantity'] = '2';
    $this->putJson(route('admin.purchases.purchase-invoices.update', $invoice), $payload)->assertOk();
    $invoice->refresh()->load('lines');
    expect($invoice->total_amount)->toBe('193.8000')->and($invoice->header_discount_amount)->toBe('10.0000')
        ->and($invoice->lines->sole()->discount_value)->toBe('20.0000');
    $payload['lines'][0]['quantity'] = '1';
    $this->putJson(route('admin.purchases.purchase-invoices.update', $invoice), $payload)->assertOk();
    $invoice->refresh()->load('lines');
    expect($invoice->total_amount)->toBe('96.9000')->and($invoice->header_discount_amount)->toBe('5.0000');
    $payload['lines'][0]['quantity'] = '2';
    $payload['inherit_header_discount'] = false;
    $payload['header_discount_value'] = '5';
    $payload['lines'][0]['inherit_source_discount'] = false;
    $payload['lines'][0]['discount_value'] = '30';
    $this->putJson(route('admin.purchases.purchase-invoices.update', $invoice), $payload)->assertOk();
    $invoice->refresh()->load('lines');
    expect($invoice->total_amount)->toBe('188.1000')->and($invoice->lines->sole()->source_discount_snapshot['inherited'])->toBeFalse();
});

test('purchase source preview keeps existing price permission and company boundaries and cannot mutate source data', function (): void {
    $f = purchaseDiscountFixture();
    $order = app(PurchaseOrderService::class)->approve(app(PurchaseOrderService::class)->create(purchaseDiscountOrderData($f))['record']);
    $before = [$order->getAttributes(), $order->lines->sole()->getAttributes()];
    $payload = purchaseDiscountInvoiceData($f, $order);
    $this->postJson(route('admin.purchases.purchase-invoices.discount-preview'), $payload)->assertOk();
    expect([$order->fresh()->getAttributes(), $order->lines->sole()->fresh()->getAttributes()])->toBe($before);
    $f['user']->revokePermissionTo('purchases.prices.view');
    $this->postJson(route('admin.purchases.purchase-invoices.discount-preview'), $payload)->assertForbidden();
    $f['user']->givePermissionTo('purchases.prices.view');
    procurementFixture(true);
    expect(fn () => app(PurchaseDiscountSourceService::class)->calculate($payload))->toThrow(ModelNotFoundException::class);
});
