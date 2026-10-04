<?php

use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesAmountService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesIssueOrderService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SpecificInventoryCostCases.php';

/** @return array<string, mixed> */
function specificSalesFixture(array $options = []): array
{
    $fixture = specificCostFixture($options['distinct_batches'] ?? false, $options['serials'] ?? false, $options['cost_method'] ?? InventoryCostPolicy::SpecificIdentification);
    $token = Str::random(8);
    $unitId = $fixture['unit']->id;
    $quantity = $options['quantity'] ?? '4';
    $unitPrice = $options['unit_price'] ?? '50';
    $tax = $options['tax_amount'] ?? '0';
    if ($options['service'] ?? false) {
        $fixture['product']->update(['item_classification' => Product::ClassificationService]);
    }
    if (isset($options['conversion_factor'])) {
        $equivalent = ItemUnit::query()->create([
            'company_id' => $fixture['company']->id, 'doc_number' => (int) ItemUnit::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-MULTIPLE-'.$token, 'name' => 'SYNTHETIC multiple '.$token, 'status' => 'active',
            'equivalent_value' => $options['conversion_factor'], 'equivalent_unit_id' => $fixture['unit']->id,
        ]);
        $fixture['product']->update(['equivalent_unit_id' => $equivalent->id]);
        $unitId = $equivalent->id;
    }
    $total = bcadd(bcmul($quantity, $unitPrice, 4), $tax, 4);
    $classification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
    $parent = Account::query()->where('company_id', $fixture['company']->id)->where('account_classification_id', $classification->id)->where('is_group', true)->orderByDesc('level')->firstOrFail();
    $account = Account::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => (int) Account::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-LAYER-CUSTOMER-'.$token, 'account_code' => '1121'.$token, 'name' => 'SYNTHETIC layer customer '.$token,
        'parent_id' => $parent->id, 'level' => (int) $parent->level + 1, 'account_classification_id' => $classification->id,
        'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit,
        'is_group' => false, 'is_postable' => true, 'status' => 'active']);
    $customer = Customer::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => (int) Customer::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-LAYER-CUSTOMER-'.$token, 'name' => 'SYNTHETIC layer customer '.$token, 'account_id' => $account->id, 'account_group_id' => $parent->id, 'status' => 'active']);
    $currency = Currency::query()->where('company_id', $fixture['company']->id)->where('is_main', true)->firstOrFail();
    CustomerCommercialAgreement::query()->create(['company_id' => $fixture['company']->id, 'customer_id' => $customer->id, 'currency_id' => $currency->id,
        'customer_type' => CustomerCommercialAgreement::TypeCredit, 'credit_limit' => '10000', 'include_open_orders' => true,
        'required_advance_percentage' => 0, 'required_advance_minimum' => 0, 'blocking_enabled' => true, 'temporary_override_allowed' => true, 'status' => 'active']);
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(['company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id, 'customer_id' => $customer->id, 'currency_id' => $currency->id,
        'order_date' => $fixture['day'], 'expected_delivery_date' => $fixture['day'], 'lines' => [[
            'product_id' => $fixture['product']->id, 'unit_id' => $unitId, 'description' => 'SYNTHETIC selected sales issue', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'tax_amount' => $tax,
        ]], 'payment_schedules' => [['title' => 'SYNTHETIC due', 'amount' => $total, 'due_date' => $fixture['day']]]]));
    $invoices = app(CustomerInvoiceService::class);
    $invoice = $invoices->post($invoices->createFromOrder($order, array_map(fn (string $invoiceQuantity): array => ['sales_order_line_id' => $order->lines->sole()->id, 'quantity' => $invoiceQuantity], $options['invoice_quantities'] ?? [$quantity]), [['due_date' => $fixture['day'], 'amount' => $total]]));
    $issueOrder = $invoice->issueOrder()->first();
    Permission::findOrCreate('inventory.documents.view', 'web');
    $fixture['preparer']->givePermissionTo('inventory.documents.view');

    return [...$fixture, 'order' => $order, 'invoice' => $invoice, 'issueOrder' => $issueOrder];
}

test('actual warehouse sales issue selects the priced receipt layer and reverses the original cost', function (): void {
    $fixture = specificSalesFixture();
    $line = $fixture['invoice']->lines->sole();
    $this->withSession(['_old_input' => ['sales_issue_order_doc_num' => $fixture['issueOrder']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => $fixture['day'],
        'layer_selections' => [['invoice_line_id' => $line->id, 'receipt_layers' => [['layer_id' => $fixture['layer']->id, 'quantity' => '4']]]],
    ]])->get(route('admin.inventory.documents.sales-issue.create'))->assertOk()
        ->assertSee('value="'.$fixture['issueOrder']->doc_num.'" selected', false)
        ->assertSee('value="'.$fixture['store']->public_uuid.'" selected', false)
        ->assertSee('"old_layer_selections":{"'.$line->id.'":[{"layer_id":'.$fixture['layer']->id, false);
    $this->withSession(['_old_input' => []]);
    $this->get(route('admin.inventory.documents.sales-issue.create'))->assertOk()->assertSee('data-sales-issue-layer-group', false);
    $this->getJson(route('admin.inventory.documents.sales-issue-orders.details', [$fixture['issueOrder'], 'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => $fixture['day']]))
        ->assertOk()->assertJsonPath('data.requires_specific_layer', true)->assertJsonPath('data.lines.0.invoice_line_id', $line->id);
    $payload = ['sales_issue_order_doc_num' => $fixture['issueOrder']->doc_num, 'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => $fixture['day'],
        'layer_selections' => [['invoice_line_id' => $line->id, 'receipt_layers' => [['layer_id' => $fixture['layer']->id, 'quantity' => '4']]]]];
    $this->postJson(route('admin.inventory.documents.sales-issue.store'), [...$payload, 'layer_selections' => []])->assertUnprocessable();
    $response = $this->postJson(route('admin.inventory.documents.sales-issue.store'), $payload)->assertCreated();
    $document = InventoryDocument::query()->where('doc_num', $response->json('doc_num'))->sole();
    expect($document->lines->sole()->selected_receipt_layer_id)->toBe($fixture['layer']->id)
        ->and($document->transactions->sole()->total_cost)->toBe('80.00000000')
        ->and($fixture['order']->lines->sole()->fresh()->delivered_base_quantity)->toBe('4.00000000')
        ->and($fixture['layer']->fresh()->remaining_quantity)->toBe('6.00000000')
        ->and(bcadd((string) $document->journalEntry->lines()->sum('debit_amount'), '0', 4))->toBe('80.0000')
        ->and(bcadd((string) $document->journalEntry->lines()->sum('credit_amount'), '0', 4))->toBe('80.0000');
    app(InventoryDocumentPostingService::class)->reverse($document, 'SYNTHETIC selected sales cost reversal');
    expect($fixture['order']->lines->sole()->fresh()->delivered_base_quantity)->toBe('0.00000000')
        ->and($fixture['issueOrder']->fresh()->status)->toBe(SalesIssueOrder::StatusPending);
    $net = $document->transactions()->selectRaw('sum(quantity_in-quantity_out) as quantity, sum(case when quantity_in>0 then total_cost else -total_cost end) as value')->first();
    expect(bccomp((string) $net->quantity, '0', 8))->toBe(0)->and(bccomp((string) $net->value, '0', 8))->toBe(0);
});

test('selected sales return passes quarantine disposition and restores the original selected cost', function (): void {
    $fixture = specificSalesFixture();
    $invoiceLine = $fixture['invoice']->lines->sole();
    $document = app(SalesIssueOrderService::class)->issue($fixture['issueOrder'], $fixture['store'], $fixture['day'], [
        $invoiceLine->id => [['layer_id' => $fixture['layer']->id, 'quantity' => '4']],
    ]);
    $returns = app(SalesReturnService::class);
    $return = $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC partial selected return', [['customer_invoice_line_id' => $invoiceLine->id, 'quantity' => '1']], $fixture['store']->id);
    $return = $returns->receive($returns->authorize($return));
    expect($return->returnInventoryDocument->transactions->sole()->total_cost)->toBe('20.00000000');
    $return = $returns->inspect($return, [['sales_return_line_id' => $return->lines->sole()->id, 'saleable_quantity' => '1']]);
    $return = $returns->close($return->fresh());
    expect($return->credit_note_id)->not->toBeNull();
    $disposition = InventoryDocument::query()->where('source_document_type', SalesReturn::class)->where('source_document_id', $return->id)->where('document_type', InventoryDocument::TypeTransfer)->sole();
    expect($disposition->lines->sole()->selected_receipt_layer_id)->not->toBeNull()
        ->and($disposition->transactions()->where('quantity_in', '>', 0)->sole()->total_cost)->toBe('20.00000000')
        ->and($fixture['order']->lines->sole()->fresh()->returned_base_quantity)->toBe('1.00000000');
});

test('selected delivery releases only excess own reservations from another batch', function (string $delivered, string $remaining): void {
    $fixture = specificSalesFixture(['distinct_batches' => true]);
    $fulfillment = app(SalesFulfillmentService::class);
    $line = $fixture['order']->lines->sole();
    $reservation = $fulfillment->reserve($line, '4');
    expect($reservation->batch_lot)->toBe('SYNTHETIC-CHEAP');
    $orders = app(SalesOrderService::class);
    $otherOrder = $orders->approve($orders->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'customer_id' => $fixture['order']->customer_id, 'currency_id' => $fixture['order']->currency_id,
        'order_date' => $fixture['day'], 'expected_delivery_date' => $fixture['day'],
        'lines' => [['product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'description' => 'SYNTHETIC other reserved order', 'quantity' => '2', 'unit_price' => '50']],
        'payment_schedules' => [['title' => 'SYNTHETIC other due', 'amount' => '100', 'due_date' => $fixture['day']]],
    ]));
    $otherReservation = $fulfillment->reserve($otherOrder->lines->sole(), '2');
    $document = $fulfillment->deliverInvoice($fixture['invoice'], [['customer_invoice_line_id' => $fixture['invoice']->lines->sole()->id,
        'quantity' => $delivered, 'receipt_layers' => [['layer_id' => $fixture['layer']->id, 'quantity' => $delivered]]]],
        ['branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => $fixture['day']]);
    expect($reservation->fresh()->remaining_quantity)->toBe(bcadd($remaining, '0', 8))
        ->and($reservation->fresh()->released_quantity)->toBe(bcadd($delivered, '0', 8))
        ->and($reservation->fresh()->released_by)->toBe($fixture['preparer']->id)
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($line->fresh()->activeReservedQuantity())->toBe(bcadd($remaining, '0', 8))
        ->and($otherReservation->fresh()->remaining_quantity)->toBe('2.00000000')
        ->and($otherReservation->fresh()->released_quantity)->toBe('0.00000000');
    $audit = Activity::query()->where('subject_type', InventoryReservation::class)
        ->where('subject_id', $reservation->id)->where('event', 'inventory_reservation.released_after_delivery')->sole();
    expect(bcadd($audit->properties['released_base_quantity'], '0', 8))->toBe(bcadd($delivered, '0', 8));
    app(InventoryDocumentPostingService::class)->reverse($document, 'SYNTHETIC selected reserved delivery reversal');
    expect($line->fresh()->delivered_base_quantity)->toBe('0.00000000')
        ->and($reservation->fresh()->released_quantity)->toBe(bcadd($delivered, '0', 8))
        ->and($otherReservation->fresh()->remaining_quantity)->toBe('2.00000000');
})->with([['2', '2'], ['4', '0']]);

test('three selected base units preserve one invoice unit through delivery and reversal', function (): void {
    $fixture = specificSalesFixture(['quantity' => '1', 'conversion_factor' => '3', 'distinct_batches' => true]);
    $third = costTransitionMovement($fixture, $fixture['day'], InventoryDocument::TypeAdjustmentIn, '1', '30', ['batch_lot' => 'SYNTHETIC-THIRD']);
    $layers = [InventoryReceiptLayer::query()->where('receipt_transaction_id', $fixture['cheap']->transactions->sole()->id)->sole(),
        $fixture['layer'], InventoryReceiptLayer::query()->where('receipt_transaction_id', $third->transactions->sole()->id)->sole()];
    $line = $fixture['invoice']->lines->sole();
    expect($line->conversion_factor)->toBe('3.00000000');
    $document = app(SalesIssueOrderService::class)->issue($fixture['issueOrder'], $fixture['store'], $fixture['day'], [
        $line->id => array_map(fn ($layer): array => ['layer_id' => $layer->id, 'quantity' => '1'], $layers),
    ]);
    expect($document->lines->pluck('transaction_quantity')->all())->toBe(['0.33333333', '0.33333333', '0.33333334'])
        ->and(app(SalesAmountService::class)->sum($document->lines->pluck('transaction_quantity'), 8))->toBe('1.00000000')
        ->and($fixture['order']->lines->sole()->fresh()->delivered_quantity)->toBe('1.00000000')
        ->and($fixture['order']->fresh()->status)->toBe(SalesOrder::StatusFulfilled)
        ->and($fixture['issueOrder']->fresh()->status)->toBe(SalesIssueOrder::StatusIssued);
    app(InventoryDocumentPostingService::class)->reverse($document, 'SYNTHETIC converted delivery reversal');
    expect($fixture['order']->lines->sole()->fresh()->delivered_quantity)->toBe('0.00000000')
        ->and($fixture['order']->lines->sole()->fresh()->delivered_base_quantity)->toBe('0.00000000');
});

test('converted selected returns conserve source quantity value and customer credit across partial returns', function (array $quantities): void {
    $fixture = specificSalesFixture(['quantity' => '1', 'conversion_factor' => '3', 'distinct_batches' => true]);
    $third = costTransitionMovement($fixture, $fixture['day'], InventoryDocument::TypeAdjustmentIn, '1', '30', ['batch_lot' => 'SYNTHETIC-THIRD']);
    $layers = [InventoryReceiptLayer::query()->where('receipt_transaction_id', $fixture['cheap']->transactions->sole()->id)->sole(),
        $fixture['layer'], InventoryReceiptLayer::query()->where('receipt_transaction_id', $third->transactions->sole()->id)->sole()];
    $invoiceLine = $fixture['invoice']->lines->sole();
    app(SalesIssueOrderService::class)->issue($fixture['issueOrder'], $fixture['store'], $fixture['day'], [
        $invoiceLine->id => array_map(fn ($layer): array => ['layer_id' => $layer->id, 'quantity' => '1'], $layers),
    ]);
    $returns = app(SalesReturnService::class);
    $stockQuantity = '0';
    $stockValue = '0';
    $creditTotal = '0';
    foreach ($quantities as $quantity) {
        $return = $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC converted return', [['customer_invoice_line_id' => $invoiceLine->id, 'quantity' => $quantity]], $fixture['store']->id);
        $return = $returns->receive($returns->authorize($return));
        $receipt = $return->returnInventoryDocument;
        $stockQuantity = bcadd($stockQuantity, (string) $receipt->transactions()->sum('quantity_in'), 8);
        $stockValue = bcadd($stockValue, (string) $receipt->transactions()->sum('total_cost'), 8);
        $return = $returns->inspect($return, $return->lines->map(fn ($line): array => ['sales_return_line_id' => $line->id, 'saleable_quantity' => (string) $line->quantity])->all());
        foreach ($return->lines as $line) {
            expect($line->saleable_base_quantity)->toBe($line->base_quantity)
                ->and($line->scrap_base_quantity)->toBe('0.00000000');
        }
        $return = $returns->close($return->fresh());
        $creditTotal = bcadd($creditTotal, (string) $return->creditNote->total_amount, 4);
    }
    expect($stockQuantity)->toBe('3.00000000')->and($stockValue)->toBe('60.00000000')
        ->and($creditTotal)->toBe('50.0000')
        ->and($fixture['order']->lines->sole()->fresh()->returned_quantity)->toBe('1.00000000')
        ->and($fixture['order']->lines->sole()->fresh()->returned_base_quantity)->toBe('3.00000000');
    expect(fn () => $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC duplicate', [['customer_invoice_line_id' => $invoiceLine->id, 'quantity' => '0.1']], $fixture['store']->id))->toThrow(DomainException::class);
})->with([[['1']], [['0.2', '0.8']]]);

test('two invoice lines sharing one source order allocate distinct remaining delivery slices within one return', function (): void {
    $fixture = specificSalesFixture(['invoice_quantities' => ['2', '2'], 'distinct_batches' => true]);
    $invoiceLines = $fixture['invoice']->lines->values();
    $cheap = InventoryReceiptLayer::query()->where('receipt_transaction_id', $fixture['cheap']->transactions->sole()->id)->sole();
    $delivery = app(SalesIssueOrderService::class)->issue($fixture['issueOrder'], $fixture['store'], $fixture['day'], [
        $invoiceLines[0]->id => [['layer_id' => $cheap->id, 'quantity' => '2']],
        $invoiceLines[1]->id => [['layer_id' => $fixture['layer']->id, 'quantity' => '2']],
    ]);
    expect($delivery->lines)->toHaveCount(2);
    $returns = app(SalesReturnService::class);
    $return = $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC shared source return', $invoiceLines->map(fn ($line): array => ['customer_invoice_line_id' => $line->id, 'quantity' => '2'])->all(), $fixture['store']->id);
    expect($return->lines->pluck('delivery_line_id')->unique())->toHaveCount(2);
    $return = $returns->receive($returns->authorize($return));
    expect(bcadd((string) $return->returnInventoryDocument->transactions()->sum('quantity_in'), '0', 8))->toBe('4.00000000')
        ->and(bcadd((string) $return->returnInventoryDocument->transactions()->sum('total_cost'), '0', 8))->toBe('60.00000000');
});

test('sequential service returns preserve final monetary and tax entitlement', function (): void {
    $fixture = specificSalesFixture(['service' => true, 'quantity' => '3', 'unit_price' => '0.3', 'tax_amount' => '0.1']);
    $line = $fixture['invoice']->lines->sole();
    expect($line->line_total)->toBe('1.0000')->and($line->tax_amount)->toBe('0.1000');
    $returns = app(SalesReturnService::class);
    $credits = [];
    $taxes = [];
    for ($index = 0; $index < 3; $index++) {
        $return = $returns->create($fixture['invoice']->fresh(), SalesReturn::ReasonExcess, 'SYNTHETIC sequential service return', [['customer_invoice_line_id' => $line->id, 'quantity' => '1']]);
        $return = $returns->receive($returns->authorize($return));
        $return = $returns->close($returns->inspect($return, []));
        $credits[] = (string) $return->creditNote->total_amount;
        $taxes[] = (string) $return->creditNote->tax_amount;
    }
    expect($credits)->toBe(['0.3333', '0.3333', '0.3334'])
        ->and($taxes)->toBe(['0.0333', '0.0333', '0.0334'])
        ->and(app(SalesAmountService::class)->sum($credits))->toBe('1.0000');
});
