<?php

use Dom\HTMLDocument;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\MenuService;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Services\ProcurementAttachmentService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

function procurementUiFixture(): array
{
    $fixture = procurementFixture();
    test()->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $fixture['employee'] = HrEmployee::query()->create(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'doc_num' => 'EMP-UI-1', 'doc_number' => 1, 'full_name' => 'Warehouse Requester', 'name' => 'Warehouse Requester', 'status' => 'active']);

    return $fixture;
}

test('inventory request uses employee and inferred context with bounded product selectors', function (): void {
    $fixture = procurementUiFixture();
    $this->get(route('admin.purchases.purchase-requisitions.create'))->assertOk()
        ->assertSee('name="requester_employee_id"', false)->assertSee('name="branch_store_uuid"', false)->assertSee('js-select2-ajax', false)
        ->assertDontSee('name="priority"', false)->assertDontSee('name="department"', false)
        ->assertDontSee('name="suggested_supplier_doc_num"', false)->assertDontSee('name="lead_time_days"', false)
        ->assertDontSee('Polymer Resin')->assertDontSee('Resin Supplier One');
    $payload = ['request_date' => now()->toDateString(), 'branch_store_uuid' => $fixture['store']->public_uuid, 'requester_employee_id' => $fixture['employee']->id,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => 10]], 'submit_action' => 'save_view'];
    $response = $this->postJson(route('admin.purchases.purchase-requisitions.store'), $payload)->assertSuccessful();
    $request = PurchaseRequisition::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($request->requester_employee_id)->toBe($fixture['employee']->id)->and($request->requested_by)->toBeNull()
        ->and($request->created_by)->toBe($fixture['user']->id)->and($request->branch_store_id)->toBe($fixture['store']->id);
    $this->getJson(route('admin.purchases.purchase-requisitions.availability', ['product_doc_num' => $fixture['raw']->doc_num]))->assertOk()->assertJsonStructure(['on_hand', 'reserved', 'available']);
    $this->postJson(route('admin.purchases.purchase-requisitions.store'), [...$payload, 'requester_employee_id' => 99999])->assertUnprocessable();
});

test('administrative branches cannot create inventory requests and multiple stores are never chosen arbitrarily', function (): void {
    $fixture = procurementUiFixture();
    BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'Second store', 'position' => 2]);
    $request = procurementManualRequisition($fixture);
    expect($request->branch_store_id)->toBe($fixture['store']->id);
    $store = app(ProcurementSourcingService::class)->requisitionStore(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id]);
    expect($store)->toBeNull();
    $fixture['branch']->update(['type' => Branch::TypeAdministrative]);
    $this->get(route('admin.purchases.purchase-requisitions.create'))->assertForbidden();
    expect(fn () => procurementManualRequisition($fixture))->toThrow(DomainException::class);
});

test('request datatable exposes state actions and draft restore without resurrecting removed lines', function (): void {
    $fixture = procurementUiFixture();
    $request = procurementManualRequisition($fixture);
    $lineId = $request->lines->sole()->id;
    $this->get(route('admin.purchases.purchase-requisitions.index'))->assertOk()->assertSee('procurement-documents-table')->assertDontSee('Combine approved purchase requests');
    $data = $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=1&start=0&length=10')->assertOk();
    expect($data->json('recordsFiltered'))->toBe(1, $data->getContent())->and($data->json('data.0.actions'))->toContain('/submit');
    $this->deleteJson(route('admin.purchases.purchase-requisitions.destroy', $request))->assertOk();
    $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=2&start=0&length=10&trash_filter=trashed')->assertOk()->assertJsonPath('recordsFiltered', 1);
    $this->patchJson(route('admin.purchases.procurement.restore', ['purchase_requisitions', $request->doc_num]))->assertOk();
    expect($request->fresh()->lines->sole()->id)->toBe($lineId);
    $approved = app(ProcurementSourcingService::class)->approveRequisition(app(ProcurementSourcingService::class)->submitRequisition($request->fresh()));
    expect(fn () => app(ProcurementSourcingService::class)->deleteRequisition($approved))->toThrow(DomainException::class);
});

test('approved request selectors and source import preserve each source line and default base currency', function (): void {
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $first = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10)));
    $second = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 15)));
    $draft = procurementManualRequisition($fixture, 20);
    $lookup = $this->getJson(route('admin.purchases.select2.requisitions'))->assertOk()->assertJsonStructure(['results', 'pagination' => ['more']]);
    expect(collect($lookup->json('results'))->pluck('id')->all())->toContain($first->doc_num, $second->doc_num)->not->toContain($draft->doc_num);
    $loaded = $this->getJson(route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$first->doc_num, $second->doc_num]]))->assertOk();
    expect($loaded->json('lines'))->toHaveCount(2)->and(array_column($loaded->json('lines'), 'ordered_quantity'))->toBe([10, 15]);
    $this->get(route('admin.purchases.purchase-orders.create'))->assertOk()
        ->assertSee('js-order-requisitions')
        ->assertSee($fixture['currency']->doc_num)
        ->assertSee('data-input-name="lines[0][attachment_file_doc_nums][]"', false)
        ->assertDontSee('[cost_center_doc_num]', false);
    $this->getJson(route('admin.purchases.select2.currency-rate', ['currency_doc_num' => $fixture['currency']->doc_num]))->assertOk()->assertJsonPath('rate', 1)->assertJsonPath('is_main', true);
});

test('supplier quotations are entered directly from approved requests or purchase orders', function (): void {
    $this->withoutExceptionHandling();
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10)));

    $this->get(route('admin.purchases.supplier-quotation-entry.choose-source'))->assertOk()
        ->assertSee(__('From Purchase Request'))->assertSee(__('From Purchase Order'))->assertDontSee(__('Create Request for Quotation'));
    $this->get(route('admin.purchases.supplier-quotation-entry.create-source', [SupplierQuotation::SourcePurchaseRequisition, $request->doc_num]))
        ->assertOk()->assertSee($request->doc_num)->assertSee('source_line_public_id', false);

    $payload = ['quotation_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
        'lines' => [['source_line_public_id' => $request->lines->sole()->public_id, 'offered_quantity' => 10, 'unit_price' => 5]],
    ];
    $response = $this->postJson(route('admin.purchases.supplier-quotation-entry.store-source', [SupplierQuotation::SourcePurchaseRequisition, $request->doc_num]), $payload)->assertOk();
    $requestQuotation = SupplierQuotation::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($requestQuotation->purchase_requisition_id)->toBe($request->id)
        ->and($requestQuotation->request_for_quotation_id)->toBeNull()
        ->and($requestQuotation->lines->sole()->purchase_requisition_line_id)->toBe($request->lines->sole()->id);

    $order = app(PurchaseOrderService::class)->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 10, 'unit_price' => 5, 'purchase_requisition_line_id' => $request->lines->sole()->id]],
    ])['record'];
    $this->postJson(route('admin.purchases.purchase-orders.submit', $order))->assertOk();
    $this->postJson(route('admin.purchases.purchase-orders.approve', $order))->assertOk();

    $order = $order->fresh('lines');
    $payload['supplier_doc_num'] = $fixture['secondSupplier']->doc_num;
    $payload['lines'][0]['source_line_public_id'] = $order->lines->sole()->public_id;
    $response = $this->postJson(route('admin.purchases.supplier-quotation-entry.store-source', [SupplierQuotation::SourcePurchaseOrder, $order->doc_num]), $payload)->assertOk();
    $orderQuotation = SupplierQuotation::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($orderQuotation->purchase_order_id)->toBe($order->id)
        ->and($orderQuotation->lines->sole()->purchase_order_line_id)->toBe($order->lines->sole()->id)
        ->and($orderQuotation->source_doc_num)->toBe($order->doc_num);
    $this->get(route('admin.purchases.supplier-quotation-entry.show', $orderQuotation))->assertOk()->assertSee($order->doc_num);
    $chain = app(ProcurementCycleReport::class)->documentChain($orderQuotation);
    expect($chain->pluck('doc_num')->all())->toContain($request->doc_num, $order->doc_num, $requestQuotation->doc_num, $orderQuotation->doc_num);
});

test('all procurement lists use canonical server pagination and source create screens', function (): void {
    procurementUiFixture();
    foreach (['request_for_quotations' => 'request-for-quotations', 'supplier_quotations' => 'supplier-quotation-entry', 'supply_orders' => 'supply-orders', 'goods_receipts' => 'goods-receipt-notes', 'purchase_returns' => 'purchase-returns'] as $screen => $route) {
        $this->get(route('admin.purchases.'.$route.'.index'))->assertOk()->assertSee('procurement-documents-table');
        $this->getJson(route('admin.purchases.procurement.data', $screen).'?draw=1&start=0&length=10')->assertOk()->assertJsonPath('recordsFiltered', 0);
    }
    $this->get(route('admin.purchases.goods-receipt-notes.choose-source'))->assertOk()
        ->assertSee('js-select2-ajax')->assertSee(__('Supply Order'))
        ->assertSee(__('Select an issued supply order; remaining quantities are loaded automatically.'));
    $this->get(route('admin.purchases.purchase-invoices.create'))->assertOk()
        ->assertSee('data-load-invoice-source', false)
        ->assertSee('data-input-name="lines[0][attachment_file_doc_nums][]"', false);
});

test('sourcing drafts edit in the same workflow and restore their original line identifiers', function (): void {
    Storage::fake('public');
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $attachment = procurementDocumentAttachment($fixture['company']);
    $attachments = app(ProcurementAttachmentService::class);
    $requisition = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10)));
    $data = ['issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num], 'lines' => [['requisition_line_public_id' => $requisition->lines->sole()->public_id, 'quantity' => 10, 'attachment_file_doc_nums' => [$attachment->doc_num]]]];
    $rfq = $sourcing->createRequestForQuotation($requisition, $data);
    expect($attachments->documents($rfq->lines->sole(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    $lineId = $rfq->lines->sole()->id;
    $stamp = $rfq->updated_at->toISOString();
    $this->travel(2)->minutes();
    $sourcing->updateRequestForQuotation($rfq, $data);
    expect($rfq->fresh()->updated_at->toISOString())->toBe($stamp)->and($rfq->fresh()->lines->sole()->id)->toBe($lineId);
    $this->get(route('admin.purchases.request-for-quotations.edit', $rfq))->assertOk()->assertSee('js-select2-ajax');
    $this->putJson(route('admin.purchases.request-for-quotations.update', $rfq), [...$data, 'commercial_notes' => 'Updated terms'])->assertOk();
    $this->deleteJson(route('admin.purchases.request-for-quotations.destroy', $rfq))->assertOk();
    $this->patchJson(route('admin.purchases.procurement.restore', ['request_for_quotations', $rfq->doc_num]))->assertOk();
    $rfq = $sourcing->issueRequestForQuotation($rfq->fresh());
    $quoteData = ['quotation_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'lines' => [['rfq_line_public_id' => $rfq->lines->sole()->public_id, 'offered_quantity' => 10, 'unit_price' => 5, 'attachment_file_doc_nums' => [$attachment->doc_num]]]];
    $quote = $sourcing->createSupplierQuotation($rfq, $quoteData);
    expect($attachments->documents($quote->lines->sole(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    $quoteLineId = $quote->lines->sole()->id;
    $stamp = $quote->updated_at->toISOString();
    $this->travel(2)->minutes();
    $sourcing->updateSupplierQuotation($quote, $quoteData);
    expect($quote->fresh()->updated_at->toISOString())->toBe($stamp);
    $this->get(route('admin.purchases.supplier-quotation-entry.edit', $quote))->assertOk()->assertSee('js-select2-ajax');
    $quoteData['lines'][0]['unit_price'] = 7;
    $this->putJson(route('admin.purchases.supplier-quotation-entry.update', $quote), $quoteData)->assertOk();
    expect((float) $quote->fresh()->total_amount)->toBe(70.0)->and($quote->fresh()->lines->sole()->id)->toBe($quoteLineId);
    $this->deleteJson(route('admin.purchases.supplier-quotation-entry.destroy', $quote))->assertOk();
    $this->patchJson(route('admin.purchases.procurement.restore', ['supplier_quotations', $quote->doc_num]))->assertOk();
    $sourcing->submitSupplierQuotation($quote->fresh());
    $this->putJson(route('admin.purchases.supplier-quotation-entry.update', $quote), $quoteData)->assertUnprocessable();
    expect(fn () => $sourcing->deleteSourcingDraft($quote->fresh()))->toThrow(DomainException::class);
});

test('receipt inspection and invoice forms keep source links and submit buttons outside nested file picker forms', function (): void {
    $this->withoutExceptionHandling();
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 100)));
    $orders = app(PurchaseOrderService::class);
    $order = $orders->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 7, 'purchase_requisition_line_id' => $request->lines->sole()->id]],
    ])['record'];
    $this->postJson(route('admin.purchases.purchase-orders.submit', $order))->assertOk();
    $this->postJson(route('admin.purchases.purchase-orders.approve', $order))->assertOk();
    $this->getJson(route('admin.purchases.select2.purchase-orders', ['purpose' => 'receipt']))->assertOk()->assertJsonPath('results.0.id', $order->doc_num);
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->postReceipt($receiving->createReceipt($order->fresh(), ['document_date' => now()->toDateString(), 'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => 40]]]));
    $html = $this->get(route('admin.purchases.goods-receipt-inspection.create', $receipt))->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $submit = collect($dom->querySelectorAll('button'))->first(fn ($button) => str_contains($button->textContent, __('Finalize inspection and post accepted stock')));
    expect($submit)->not->toBeNull()->and($submit->closest('form')->getAttribute('action'))->toBe(route('admin.purchases.goods-receipt-inspection.store', $receipt));
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id, 'accepted_quantity' => 40, 'rejected_quantity' => 0]]]);
    $this->getJson(route('admin.purchases.select2.receipts', ['purpose' => 'invoice']))->assertOk()->assertJsonPath('results.0.id', $receipt->doc_num);
    $invoiceHtml = $this->get(route('admin.purchases.purchase-invoices.create', ['receipts' => [$receipt->doc_num]]))->assertOk()->assertSee('value="'.$receipt->doc_num.'" selected', false)->getContent();
    $invoiceDom = HTMLDocument::createFromString($invoiceHtml, LIBXML_NOERROR);
    $source = $invoiceDom->querySelector('input[name="lines[0][receipt_line_public_id]"]');
    expect($source->getAttribute('value'))->toBe($receipt->lines->sole()->public_id)
        ->and($invoiceDom->querySelector('.js-purchase-invoice-duplicate-line')->hasAttribute('hidden'))->toBeTrue();
    $this->getJson(route('admin.purchases.procurement.data', 'goods_receipts').'?search[value]='.$order->doc_num)->assertOk()->assertJsonPath('recordsFiltered', 1);
});

test('editing the simplified request preserves legacy hidden metadata and timestamps on a no-op', function (): void {
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->createRequisition(['request_date' => now()->toDateString(), 'requester_employee_id' => $fixture['employee']->id, 'department' => 'Legacy production', 'priority' => 'high', 'lead_time_days' => 5,
        'suggested_supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => 100]]]);
    $stamp = $request->updated_at->toISOString();
    $this->travel(2)->minutes();
    $sourcing->updateRequisition($request, ['request_date' => $request->request_date->toDateString(), 'requester_employee_id' => $fixture['employee']->id,
        'lines' => [['public_id' => $request->lines->sole()->public_id, 'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => 100]]]);
    expect($request->fresh()->updated_at->toISOString())->toBe($stamp)->and($request->fresh()->department)->toBe('Legacy production');
    $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?search[value]=Warehouse')->assertOk()->assertJsonPath('recordsFiltered', 1);
});

test('purchase navigation follows the operational document sequence', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $expectedOrder = [
        'purchase_requisitions',
        'purchase_orders',
        'supplier_quotations',
        'supply_orders',
        'goods_receipts',
        'purchase_invoices',
        'supplier_payments',
        'purchase_returns',
        'suppliers',
        'purchase_reports',
    ];
    $purchases = collect(app(MenuService::class)->structure())->firstWhere('label', 'purchases');

    expect(config('menu_sections.leaf_order.purchases'))->toBe($expectedOrder)
        ->and(collect($purchases['children'])->pluck('label')->all())->toBe($expectedOrder);
});
