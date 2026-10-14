<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\SupplyOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

function directSupplyFixture(): array
{
    $f = procurementFixture();
    $administrative = procurementAdministrativeBranch($f);
    procurementUseBranch($f, $administrative);
    foreach (['purchases.supply_orders.create', 'purchases.supply_orders.edit', 'purchases.supply_orders.view', 'purchases.supply_orders.issue', 'purchases.supply_orders.cancel', 'purchases.prices.view', 'purchase_invoices.create', 'purchases.goods_receipt_inspection.create'] as $ability) {
        Permission::findOrCreate($ability, 'web');
        $f['user']->givePermissionTo($ability);
    }
    test()->actingAs($f['user']);
    $context = app(OperatingContextService::class)->snapshot(request());
    $invoice = PurchaseInvoice::query()->create(['company_id' => $f['company']->id, 'branch_id' => $context['branch_id'], 'financial_period_id' => $f['period']->id,
        'doc_number' => 99001, 'doc_num' => 'PINV-DIRECT-SYNTHETIC', 'supplier_id' => $f['firstSupplier']->id, 'currency_id' => $f['currency']->id,
        'invoice_date' => now()->toDateString(), 'status' => PurchaseInvoice::StatusApproved, 'total_amount' => '329.1667', 'remaining_amount' => '329.1667']);
    $line = $invoice->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'line_number' => 1,
        'product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10', 'unit_price' => '32.916666666666666']);

    return [...$f, 'invoice' => $invoice, 'invoiceLine' => $line, 'administrative' => $administrative];
}

function directSupplyPayload(array $f, string $quantity): array
{
    return ['source_type' => SupplyOrder::SourcePurchaseInvoice, 'source_doc_num' => $f['invoice']->doc_num,
        'issue_date' => now()->toDateString(), 'branch_store_uuid' => $f['store']->public_uuid,
        'lines' => [['purchase_invoice_line_public_id' => $f['invoiceLine']->public_id, 'ordered_quantity' => $quantity]]];
}

test('native direct invoice supply selection reserves partial quantities edits its draft and releases cancelled or deleted allocations', function (): void {
    $f = directSupplyFixture();
    $selector = route('admin.purchases.select2.invoices', ['purpose' => 'supply_order']);
    $this->getJson($selector)->assertOk()->assertJsonPath('results.0.id', $f['invoice']->doc_num);
    $this->get(route('admin.purchases.supply-orders.create', ['purchase_invoice' => $f['invoice']->doc_num]))->assertOk()
        ->assertSee('name="branch_store_uuid"', false)->assertSee('purchase_invoice_line_public_id', false);
    $ineligibleStore = $f['store']->replicate(['public_uuid']);
    $ineligibleStore->name = 'SYNTHETIC finished product store';
    $ineligibleStore->classification = Product::ClassificationFinishedProduct;
    $ineligibleStore->save();
    $this->get(route('admin.purchases.supply-orders.create', ['purchase_invoice' => $f['invoice']->doc_num]))->assertOk()->assertDontSee($ineligibleStore->public_uuid);
    $invalidStorePayload = directSupplyPayload($f, '1');
    $invalidStorePayload['branch_store_uuid'] = $ineligibleStore->public_uuid;
    $this->postJson(route('admin.purchases.supply-orders.store'), $invalidStorePayload)->assertUnprocessable();
    expect(SupplyOrder::query()->count())->toBe(0);
    $created = $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '6'))->assertOk();
    $supply = SupplyOrder::query()->where('doc_num', $created->json('data.doc_num'))->sole();
    expect($supply->purchase_order_id)->toBeNull()->and($supply->purchase_invoice_id)->toBe($f['invoice']->id)
        ->and($supply->lines->sole()->purchase_order_line_id)->toBeNull()->and($supply->lines->sole()->purchase_invoice_line_id)->toBe($f['invoiceLine']->id);
    $this->get(route('admin.purchases.supply-orders.edit', $supply))->assertOk();
    $this->putJson(route('admin.purchases.supply-orders.update', $supply), directSupplyPayload($f, '7'))->assertOk();
    $before = [SupplyOrder::query()->count(), DB::table('supply_order_lines')->count(), DB::table('activity_log')->count()];
    $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '4'))->assertUnprocessable();
    expect([SupplyOrder::query()->count(), DB::table('supply_order_lines')->count(), DB::table('activity_log')->count()])->toBe($before);
    $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '3'))->assertOk();
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $this->postJson(route('admin.purchases.supply-orders.issue', $supply))->assertOk();
    $this->postJson(route('admin.purchases.supply-orders.issue', $supply))->assertOk();
    expect(DB::table('inventory_transactions')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
    procurementUseBranch($f, $f['branch']);
    $this->getJson(route('admin.purchases.select2.supply-orders', ['purpose' => 'inspection']))->assertOk()->assertJsonPath('results.0.id', $supply->doc_num);
    $this->get(route('admin.purchases.goods-receipt-inspection.create', $supply))->assertOk();
    procurementUseBranch($f, $f['administrative']);
    $this->postJson(route('admin.purchases.supply-orders.cancel', $supply), ['cancel_reason' => 'SYNTHETIC supplier revised shipment'])->assertOk();
    $this->getJson($selector)->assertOk()->assertJsonPath('results.0.id', $f['invoice']->doc_num);
    $other = SupplyOrder::query()->where('id', '<>', $supply->id)->sole();
    app(SupplyOrderService::class)->deleteDraft($other);
    expect(app(SupplyOrderService::class)->sourceLines($f['invoice'])->sole()->supply_available_quantity)->toBe('10.00000000');
});

test('direct supply sources reject draft cancelled service zero deleted and foreign operating contexts', function (): void {
    $f = directSupplyFixture();
    $selector = route('admin.purchases.select2.invoices', ['purpose' => 'supply_order']);
    foreach ([PurchaseInvoice::StatusDraft, PurchaseInvoice::StatusCancelled] as $status) {
        $f['invoice']->update(['status' => $status]);
        $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
        $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '1'))->assertUnprocessable();
    }
    $f['invoice']->update(['status' => PurchaseInvoice::StatusClosed]);
    $this->getJson($selector)->assertOk()->assertJsonCount(1, 'results');
    $f['invoiceLine']->update(['product_id' => $f['service']->id]);
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $f['invoiceLine']->update(['product_id' => $f['raw']->id, 'quantity' => '0']);
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $f['invoiceLine']->update(['quantity' => '10']);
    $f['invoiceLine']->delete();
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $f['invoiceLine']->restore();
    $f['invoice']->delete();
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $f['invoice']->restore();
    $otherPeriod = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'name' => 'SYNTHETIC other period',
        'from_date' => now()->subYear()->startOfYear()->toDateString(), 'to_date' => now()->subYear()->endOfYear()->toDateString(), 'is_closed' => false]);
    $f['invoice']->update(['financial_period_id' => $otherPeriod->id]);
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '1'))->assertNotFound();
    $f['invoice']->update(['financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id]);
    $this->getJson($selector)->assertOk()->assertJsonCount(0, 'results');
    $this->postJson(route('admin.purchases.supply-orders.store'), directSupplyPayload($f, '1'))->assertNotFound();
    expect(SupplyOrder::query()->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
});

test('purchase order invoice and supply selectors exclude exhausted active reservations and restore cancelled availability', function (): void {
    $f = directSupplyFixture();
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $f['firstSupplier']->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC selection proof',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '10', 'unit_price' => '2']]])['record']);
    $invoice = $f['invoice'];
    $invoice->update(['purchase_order_id' => $order->id]);
    $f['invoiceLine']->update(['purchase_order_line_id' => $order->lines->sole()->id, 'quantity' => '10']);
    $invoiceSelector = route('admin.purchases.select2.purchase-orders', ['purpose' => 'invoice']);
    $supplySelector = route('admin.purchases.select2.invoices', ['purpose' => 'supply_order']);
    $this->getJson($invoiceSelector)->assertOk()->assertJsonCount(0, 'results');
    $f['invoiceLine']->update(['quantity' => '6']);
    $this->getJson($invoiceSelector)->assertOk()->assertJsonPath('results.0.id', $order->doc_num);
    $this->getJson($supplySelector)->assertOk()->assertJsonPath('results.0.id', $invoice->doc_num);
    $supply = app(SupplyOrderService::class)->create(['source_type' => SupplyOrder::SourcePurchaseInvoice, 'source_doc_num' => $invoice->doc_num,
        'issue_date' => now()->toDateString(), 'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'ordered_quantity' => '6']]]);
    $this->getJson($supplySelector)->assertOk()->assertJsonCount(0, 'results');
    $this->getJson(route('admin.purchases.select2.purchase-orders', ['purpose' => 'supply_order']))->assertOk()->assertJsonCount(1, 'results');
    app(SupplyOrderService::class)->cancel($supply, 'SYNTHETIC release invoice allocation');
    $this->getJson($supplySelector)->assertOk()->assertJsonCount(1, 'results');
    $invoice->update(['status' => PurchaseInvoice::StatusCancelled]);
    $this->getJson($supplySelector)->assertOk()->assertJsonCount(0, 'results');
    $this->getJson($invoiceSelector)->assertOk()->assertJsonCount(1, 'results');
});
