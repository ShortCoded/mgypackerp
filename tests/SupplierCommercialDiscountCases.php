<?php

use Illuminate\Support\Facades\DB;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProcurementSupport.php';

/** @return array<string, mixed> */
function supplierDiscountFixture(float $quantity = 3): array
{
    $f = procurementFixture(DB::getDriverName() === 'pgsql');
    foreach (['purchases.prices.view', 'purchases.supplier_quotation_entry.create', 'purchases.supplier_quotation_entry.edit', 'purchases.supplier_quotation_entry.view', 'purchases.supplier_quotation_entry.print', 'purchases.supplier_selection.create', 'purchases.supplier_selection.view', 'purchases.supplier_selection.approve', 'purchases.supplier_selection.print', 'purchases.supplier_quotation_comparison.view', 'purchases.supplier_quotation_comparison.print'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    test()->actingAs($f['user']);
    $sourcing = app(ProcurementSourcingService::class);
    $requisition = procurementManualRequisition($f, $quantity);
    $sourcing->submitRequisition($requisition);
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    $requisition = $sourcing->approveRequisition($requisition->fresh());
    $f['rfq'] = $sourcing->issueRequestForQuotation($sourcing->createRequestForQuotation($requisition, [
        'issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$f['firstSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $requisition->lines->sole()->public_id, 'quantity' => (string) $quantity]],
    ]));

    return $f;
}

/** @return array<string, mixed> */
function supplierDiscountData(array $f): array
{
    return ['supplier_doc_num' => $f['firstSupplier']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
        'quotation_date' => now()->toDateString(), 'exchange_rate' => '1', 'header_discount_type' => 'fixed', 'header_discount_value' => '0.0002',
        'lines' => [['rfq_line_public_id' => $f['rfq']->lines->sole()->public_id, 'offered_quantity' => $f['rfq']->lines->sole()->quantity,
            'unit_price' => '22.54545000', 'discount_type' => 'percentage', 'discount_value' => '10', 'tax_rate' => '14']]];
}

/** @return array<string, mixed> */
function supplierDiscountSelectionData(SupplierQuotation $quote, string $quantity = '1'): array
{
    return ['selection_date' => now()->toDateString(), 'lines' => [['quotation_line_public_id' => $quote->lines->sole()->public_id,
        'selected_quantity' => $quantity, 'inherit_source_discount' => true]]];
}

test('supplier quotation validation redisplays eligible supplier and currency selections without leaking uninvited choices', function (string $locale): void {
    $f = supplierDiscountFixture(1);
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $data = supplierDiscountData($f);
    $data['lines'][0]['discount_value'] = '101';
    $formUrl = route('admin.purchases.supplier-quotation-entry.create', $f['rfq']);
    $this->from($formUrl)->post(route('admin.purchases.supplier-quotation-entry.store', $f['rfq']), $data)
        ->assertRedirect($formUrl)->assertSessionHasErrors();
    $this->get($formUrl)->assertOk()
        ->assertSee('value="'.$f['firstSupplier']->doc_num.'" selected', false)
        ->assertSee('value="'.$f['currency']->doc_num.'" selected', false)
        ->assertSee(__('procurement.fields.commercial_header_discount_value'));

    $this->withSession(['_old_input' => ['supplier_doc_num' => $f['secondSupplier']->doc_num, 'currency_doc_num' => 'SYNTHETIC-UNKNOWN-CURRENCY']]);
    $this->get($formUrl)->assertOk()
        ->assertDontSee('value="'.$f['secondSupplier']->doc_num.'" selected', false)
        ->assertDontSee('value="SYNTHETIC-UNKNOWN-CURRENCY" selected', false);
})->with(['en', 'ar']);

test('supplier typed quote HTTP validation editing and legacy fixed amount meaning share the purchase order calculation', function (): void {
    $f = supplierDiscountFixture(1);
    $data = supplierDiscountData($f);
    $data['header_discount_value'] = '50';
    $data['lines'][0]['unit_price'] = '1000';
    $response = $this->postJson(route('admin.purchases.supplier-quotation-entry.store', $f['rfq']), $data)->assertOk();
    $quote = SupplierQuotation::query()->with('lines')->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    expect($quote->total_amount)->toBe('969.0000')->and($quote->lines->sole()->discount_type)->toBe('percentage')
        ->and($quote->lines->sole()->discount_value)->toBe('10.0000')->and($quote->lines->sole()->discount_amount)->toBe('100.0000')
        ->and($quote->lines->sole()->header_discount_amount)->toBe('50.0000')->and($quote->tax_amount)->toBe('119.0000');
    foreach (['101', '-1', '0.00001', '1e2'] as $invalid) {
        $bad = $data;
        $bad['lines'][0]['discount_value'] = $invalid;
        $this->putJson(route('admin.purchases.supplier-quotation-entry.update', $quote->doc_num), $bad)->assertUnprocessable();
    }
    $bad = [...$data, 'header_discount_type' => 'percentage', 'header_discount_value' => '101'];
    $this->putJson(route('admin.purchases.supplier-quotation-entry.update', $quote->doc_num), $bad)->assertUnprocessable()->assertJsonValidationErrors('header_discount_value');
    $data['header_discount_type'] = 'percentage';
    $data['header_discount_value'] = '5';
    $this->putJson(route('admin.purchases.supplier-quotation-entry.update', $quote->doc_num), $data)->assertOk();
    expect($quote->fresh()->total_amount)->toBe('974.7000');
    unset($data['lines'][0]['discount_type'], $data['lines'][0]['discount_value']);
    $data['lines'][0]['discount_amount'] = '70';
    $data['header_discount_type'] = null;
    $data['header_discount_value'] = '0';
    $quote = app(ProcurementSourcingService::class)->updateSupplierQuotation($quote, $data);
    expect($quote->lines->sole()->discount_type)->toBe('fixed')->and($quote->lines->sole()->discount_value)->toBe('70.0000')
        ->and($quote->total_amount)->toBe('1060.2000');
    $quote = app(ProcurementSourcingService::class)->submitSupplierQuotation($quote);
    expect(fn () => app(ProcurementSourcingService::class)->updateSupplierQuotation($quote, $data))->toThrow(DomainException::class);
});

test('supplier partial selections and purchase orders preserve quote type inputs money final residual and unchanged draft edits', function (): void {
    $f = supplierDiscountFixture();
    $service = app(ProcurementSourcingService::class);
    $quote = $service->submitSupplierQuotation($service->createSupplierQuotation($f['rfq'], supplierDiscountData($f)));
    $orderTotal = '0.0000';
    $amounts = array_fill_keys(['subtotal_amount', 'discount_amount', 'header_discount_amount', 'tax_amount'], '0.0000');
    $selections = [];
    for ($index = 0; $index < 3; $index++) {
        $selection = $service->createSupplierSelection($f['rfq'], supplierDiscountSelectionData($quote));
        $selections[] = $selection;
        $line = $selection->lines->sole();
        if ($index === 1) {
            $before = [$line->public_id, $line->subtotal_amount, $line->discount_amount, $line->header_discount_amount, $line->tax_amount, $line->line_total];
            $selection = $service->updateSupplierSelection($selection, supplierDiscountSelectionData($quote));
            $line = $selection->lines->sole();
            expect([$line->public_id, $line->subtotal_amount, $line->discount_amount, $line->header_discount_amount, $line->tax_amount, $line->line_total])->toBe($before);
        }
        expect($line->discount_type)->toBe('percentage')->and($line->discount_value)->toBe('10.0000');
        $order = $service->approveSelection($selection)->sole();
        expect($order->lines->sole()->discount_type)->toBe('percentage')->and($order->lines->sole()->discount_value)->toBe('10.0000')
            ->and($order->lines->sole()->discount_amount)->toBe($line->discount_amount)->and($order->lines->sole()->header_discount_amount)->toBe($line->header_discount_amount)
            ->and($order->lines->sole()->tax_amount)->toBe($line->tax_amount)->and($order->total_amount)->toBe($line->line_total);
        $orderTotal = bcadd($orderTotal, $order->total_amount, 4);
        foreach ($amounts as $column => $amount) {
            $amounts[$column] = bcadd($amount, $order->lines->sole()->{$column}, 4);
        }
        if ($index === 0) {
            $data = ['supplier_doc_num' => $f['firstSupplier']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
                'branch_store_uuid' => $f['store']->public_uuid, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
                'lines' => [['public_id' => $order->lines->sole()->public_id, 'product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
                    'ordered_quantity' => '1', 'unit_price' => $line->unit_price, 'discount_type' => $line->discount_type, 'discount_value' => $line->discount_value, 'tax_rate' => $line->tax_rate]]];
            $edited = app(PurchaseOrderService::class)->update($order, $data)['record'];
            expect($edited->total_amount)->toBe($order->total_amount);
        }
    }
    expect($orderTotal)->toBe($quote->total_amount);
    foreach ($amounts as $column => $amount) {
        expect($amount)->toBe($quote->lines->sole()->{$column});
    }
    expect(fn () => $service->updateSupplierSelection($selections[0], supplierDiscountSelectionData($quote)))->toThrow(DomainException::class);
});

test('supplier selection draft HTTP editing distinguishes negotiated discount and enforces existing approval price and source scope', function (): void {
    $f = supplierDiscountFixture(2);
    $service = app(ProcurementSourcingService::class);
    $quote = $service->submitSupplierQuotation($service->createSupplierQuotation($f['rfq'], supplierDiscountData($f)));
    $payload = supplierDiscountSelectionData($quote);
    $created = $this->postJson(route('admin.purchases.supplier-selection.store', $f['rfq']), $payload)->assertOk();
    $selection = SupplierSelection::query()->with('lines')->where('doc_num', $created->json('data.doc_num'))->firstOrFail();
    $payload['lines'][0] += ['discount_type' => 'fixed', 'discount_value' => '1'];
    $payload['lines'][0]['inherit_source_discount'] = false;
    $this->putJson(route('admin.purchases.supplier-selection.update', $selection), $payload)->assertOk();
    $selection->refresh()->load('lines');
    expect($selection->lines->sole()->discount_type)->toBe('fixed')->and($selection->lines->sole()->discount_amount)->toBe('1.0000')
        ->and($selection->lines->sole()->source_discount_snapshot['inherited'])->toBeFalse();
    $bad = $payload;
    $bad['lines'][0]['discount_type'] = 'percentage';
    $bad['lines'][0]['discount_value'] = '101';
    $this->putJson(route('admin.purchases.supplier-selection.update', $selection), $bad)->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_value');
    $f['user']->revokePermissionTo('purchases.supplier_selection.approve');
    $this->get(route('admin.purchases.supplier-selection.edit', $selection))->assertForbidden();
    $f['user']->givePermissionTo('purchases.supplier_selection.approve');
    $f['user']->revokePermissionTo('purchases.prices.view');
    $this->putJson(route('admin.purchases.supplier-selection.update', $selection), $payload)->assertForbidden();
    procurementFixture(true);
    expect(fn () => $service->createSupplierSelection($f['rfq'], supplierDiscountSelectionData($quote)))->toThrow(DomainException::class);
});

test('supplier tiny fixed partial selection discounts conserve source amounts without negative net or applying discounts twice', function (): void {
    $f = supplierDiscountFixture(2);
    $service = app(ProcurementSourcingService::class);
    $data = supplierDiscountData($f);
    $data['header_discount_value'] = '0.0001';
    $data['lines'][0] = [...$data['lines'][0], 'unit_price' => '0.0001', 'discount_type' => 'fixed', 'discount_value' => '0.0001', 'tax_rate' => '0'];
    $quote = $service->submitSupplierQuotation($service->createSupplierQuotation($f['rfq'], $data));
    $net = '0.0000';
    $discount = '0.0000';
    $header = '0.0000';
    for ($index = 0; $index < 2; $index++) {
        $selection = $service->createSupplierSelection($f['rfq'], supplierDiscountSelectionData($quote));
        $order = $service->approveSelection($selection)->sole();
        expect($order->lines->sole()->discount_type)->toBe('fixed')->and($order->total_amount)->toBe('0.0000');
        $discount = bcadd($discount, $order->lines->sole()->discount_amount, 4);
        $header = bcadd($header, $order->header_discount_amount, 4);
        $net = bcadd($net, $order->total_amount, 4);
    }
    expect($net)->toBe($quote->total_amount)->and($discount)->toBe('0.0001')->and($header)->toBe('0.0001');
});

test('supplier discount forms comparison show and prints preserve entered type and amounts in both locales', function (string $locale): void {
    $this->withoutExceptionHandling();
    $f = supplierDiscountFixture(1);
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $service = app(ProcurementSourcingService::class);
    $data = supplierDiscountData($f);
    $data['header_discount_value'] = '50';
    $data['lines'][0]['unit_price'] = '1000';
    $quote = $service->createSupplierQuotation($f['rfq'], $data);
    $this->get(route('admin.purchases.supplier-quotation-entry.edit', $quote->doc_num))->assertOk()->assertSee('discount_type', false)->assertSee('header_discount_value', false);
    $quote = $service->submitSupplierQuotation($quote);
    $selection = $service->createSupplierSelection($f['rfq'], supplierDiscountSelectionData($quote));
    $this->get(route('admin.purchases.supplier-selection.edit', $selection))->assertOk()->assertSee('discount_value', false);
    $this->get(route('admin.purchases.supplier-selection.show', $selection))->assertOk()->assertSee(__('purchase_orders.discount_types.percentage'));
    $this->get(route('admin.purchases.supplier-quotation-comparison.show', $f['rfq']))->assertOk()->assertSee(__('procurement.fields.commercial_header_discount'));
    foreach ([['supplier-quotation', $quote], ['supplier-selection', $selection], ['quotation-comparison', $f['rfq']]] as [$type, $document]) {
        $pdf = $this->get(route('admin.purchases.procurement.print', [$type, $document->doc_num]))->assertOk();
        file_put_contents('/tmp/mgypack-supplier-discount-'.$type.'-'.$locale.'-20261004.pdf', $pdf->getContent());
    }
})->with(['en', 'ar']);
