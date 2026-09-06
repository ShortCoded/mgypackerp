<?php

use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementSourcingService;
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
        ->assertSee('name="requester_employee_id"', false)->assertSee('js-select2-ajax', false)
        ->assertDontSee('name="priority"', false)->assertDontSee('name="department"', false)
        ->assertDontSee('name="suggested_supplier_doc_num"', false)->assertDontSee('name="lead_time_days"', false)
        ->assertDontSee('Polymer Resin')->assertDontSee('Resin Supplier One');
    $payload = ['request_date' => now()->toDateString(), 'requester_employee_id' => $fixture['employee']->id,
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
    $this->get(route('admin.purchases.purchase-orders.create'))->assertOk()->assertSee('js-order-requisitions')->assertSee($fixture['currency']->doc_num)->assertDontSee('[cost_center_doc_num]', false);
    $this->getJson(route('admin.purchases.select2.currency-rate', ['currency_doc_num' => $fixture['currency']->doc_num]))->assertOk()->assertJsonPath('rate', 1)->assertJsonPath('is_main', true);
});

test('all procurement lists use canonical server pagination and source create screens', function (): void {
    procurementUiFixture();
    foreach (['request_for_quotations' => 'request-for-quotations', 'supplier_quotations' => 'supplier-quotation-entry', 'goods_receipts' => 'goods-receipt-notes', 'purchase_returns' => 'purchase-returns'] as $screen => $route) {
        $this->get(route('admin.purchases.'.$route.'.index'))->assertOk()->assertSee('procurement-documents-table');
        $this->getJson(route('admin.purchases.procurement.data', $screen).'?draw=1&start=0&length=10')->assertOk()->assertJsonPath('recordsFiltered', 0);
    }
    $this->get(route('admin.purchases.goods-receipt-notes.choose-source'))->assertOk()->assertSee('js-select2-ajax');
    $this->get(route('admin.purchases.purchase-invoices.create'))->assertOk()->assertSee('data-load-invoice-source',false);
});

test('sourcing drafts edit in the same workflow and restore their original line identifiers', function (): void {
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $requisition = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10)));
    $data = ['issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num], 'lines' => [['requisition_line_public_id' => $requisition->lines->sole()->public_id, 'quantity' => 10]]];
    $rfq = $sourcing->createRequestForQuotation($requisition, $data);
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
    $quoteData = ['quotation_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'lines' => [['rfq_line_public_id' => $rfq->lines->sole()->public_id, 'offered_quantity' => 10, 'unit_price' => 5]]];
    $quote = $sourcing->createSupplierQuotation($rfq, $quoteData);
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
