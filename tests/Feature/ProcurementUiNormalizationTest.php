<?php

use Dom\HTMLDocument;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\MenuService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Services\ProcurementAttachmentService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
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

test('dashboard purchase request count follows the same branch visibility as the purchasing cycle', function (): void {
    $fixture = procurementUiFixture();
    procurementManualRequisition($fixture, 10);
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->id,
        'doc_number' => 9202,
        'doc_num' => 'Branch-PROC-OTHER',
        'name' => 'Other Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $otherStore = BranchStore::query()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Other Factory Store',
        'position' => 1,
    ]);
    PurchaseRequisition::query()->create([
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $otherBranch->id,
        'branch_store_id' => $otherStore->id,
        'doc_number' => 9202,
        'doc_num' => 'PR-OTHER-BRANCH',
        'request_date' => now()->toDateString(),
        'status' => PurchaseRequisition::StatusSubmitted,
    ]);

    $factoryDashboard = $this->get(route('dashboard'))->assertOk();
    expect($factoryDashboard->getContent())->toMatch(
        '/'.preg_quote(__('dashboard.plastics.metrics.purchase_requisitions.title'), '/').'.*?plastics-dashboard-metric-value[^>]*>\s*1\s*</s',
    );

    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $administrativeDashboard = $this->get(route('dashboard'))->assertOk();
    expect($administrativeDashboard->getContent())->toMatch(
        '/'.preg_quote(__('dashboard.plastics.metrics.purchase_requisitions.title'), '/').'.*?plastics-dashboard-metric-value[^>]*>\s*2\s*</s',
    );
});

test('administrative branches manage legacy purchasing documents while factory branches remain read only', function (): void {
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);

    $order = app(PurchaseOrderService::class)->create([
        'document_date' => now()->toDateString(),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Legacy administration transition test.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10,
            'unit_price' => 5,
        ]],
    ])['record'];
    $order->forceFill(['branch_id' => $fixture['branch']->getKey()])->save();

    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 99991,
        'doc_num' => 'PINV-UI-LEGACY',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'total_amount' => 0,
        'remaining_amount' => 0,
        'status' => PurchaseInvoice::StatusDraft,
    ]);

    $this->get(route('admin.purchases.purchase-orders.show', $order))
        ->assertOk()
        ->assertSee(route('admin.purchases.purchase-orders.edit', $order), false)
        ->assertSee(route('admin.purchases.purchase-orders.submit', $order), false);
    expect($this->getJson(route('admin.purchases.purchase-orders.data', [
        'draw' => 1, 'start' => 0, 'length' => 100,
    ]))->assertOk()->getContent())->toContain($order->doc_num);
    $this->get(route('admin.purchases.purchase-invoices.show', $invoice))
        ->assertOk()
        ->assertSee(route('admin.purchases.purchase-invoices.edit', $invoice), false)
        ->assertSee(route('admin.purchases.purchase-invoices.approve', $invoice), false);

    procurementUseBranch($fixture, $fixture['branch']);
    $this->get(route('admin.purchases.purchase-orders.show', $order))
        ->assertOk()
        ->assertDontSee(route('admin.purchases.purchase-orders.edit', $order), false)
        ->assertDontSee(route('admin.purchases.purchase-orders.submit', $order), false);
    $this->postJson(route('admin.purchases.purchase-orders.submit', $order))->assertForbidden();
    $this->get(route('admin.purchases.purchase-invoices.show', $invoice))
        ->assertOk()
        ->assertDontSee(route('admin.purchases.purchase-invoices.edit', $invoice), false)
        ->assertDontSee(route('admin.purchases.purchase-invoices.approve', $invoice), false);
    $this->postJson(route('admin.purchases.purchase-invoices.approve', $invoice))->assertForbidden();

    procurementUseBranch($fixture, $administrativeBranch);
    $order->forceFill(['status' => 'approved'])->save();
    $this->getJson(route('admin.purchases.select2.purchase-orders', ['purpose' => 'invoice']))
        ->assertOk()
        ->assertJsonFragment(['id' => $order->doc_num]);
    $this->get(route('admin.purchases.purchase-invoices.create', ['purchase_order' => $order->doc_num]))
        ->assertOk()
        ->assertSee(__('purchase_invoices.attributes.optional'));
    $this->get(route('admin.purchases.procurement-cycle-report.index', ['report_type' => ProcurementCycleReport::OrderedVsReceived]))
        ->assertOk()
        ->assertViewHas('filters', fn (array $filters): bool => ($filters['branch_id'] ?? null) === null)
        ->assertViewHas('rows', fn ($rows): bool => $rows->contains('document', $order->doc_num));

    procurementUseBranch($fixture, $fixture['branch']);
    $this->get(route('admin.purchases.procurement-cycle-report.index', ['report_type' => ProcurementCycleReport::OrderedVsReceived]))
        ->assertOk()
        ->assertViewHas('filters', fn (array $filters): bool => (int) $filters['branch_id'] === $fixture['branch']->getKey());
    $this->get(route('admin.purchases.procurement-cycle-report.index', [
        'report_type' => ProcurementCycleReport::OrderedVsReceived,
        'branch_id' => $administrativeBranch->getKey(),
    ]))->assertUnprocessable();
    $this->get(route('admin.purchases.goods-receipt-inspection.create', $order))
        ->assertOk()
        ->assertSee($order->doc_num)
        ->assertSee(__('Attachments'));
});

test('purchase invoices allow an optional order and preserve deliberately unlinked variance lines', function (): void {
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111097', 'Direct Invoice Supplier Payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $response = $this->postJson(route('admin.purchases.purchase-invoices.store'), [
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 10,
        ]],
    ])->assertOk()->assertJsonPath('success', true);

    $directInvoice = PurchaseInvoice::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    expect($directInvoice->purchase_order_id)->toBeNull()
        ->and($directInvoice->purchase_type)->toBe('direct');
    $directInvoice = app(PurchaseInvoiceService::class)->approve($directInvoice);
    expect($directInvoice->matching_status)->toBe('direct_invoice')
        ->and($directInvoice->journal_entry_id)->not->toBeNull();

    $order = app(PurchaseOrderService::class)->create([
        'document_date' => now()->toDateString(),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Invoice variance regression fixture.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10,
            'unit_price' => 5,
        ]],
    ])['record'];
    $order->forceFill(['status' => 'approved'])->save();
    $orderLine = $order->lines()->sole();
    procurementUseBranch($fixture, $fixture['branch']);
    $inspection = app(ProcurementReceivingService::class)->inspectPurchaseSource($order->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 7,
            'accepted_quantity' => 7,
            'rejected_quantity' => 0,
        ]],
    ]);
    $receipt = app(ProcurementReceivingService::class)->postReceipt(
        app(ProcurementReceivingService::class)->createReceiptFromInspection($inspection, [
            'document_date' => now()->toDateString(),
            'lines' => [['inspection_line_public_id' => $inspection->lines()->sole()->public_id]],
        ]),
    );
    $receiptLine = $receipt->lines()->sole();
    procurementUseBranch($fixture, $administrativeBranch);

    $invoice = app(PurchaseInvoiceService::class)->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [
            [
                'product_doc_num' => $fixture['raw']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'purchase_order_line_public_id' => $orderLine->public_id,
                'receipt_line_public_id' => $receiptLine->public_id,
                'quantity' => 7,
                'unit_price' => 6,
            ],
            [
                'product_doc_num' => $fixture['raw']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'quantity' => 1,
                'unit_price' => 9,
            ],
        ],
    ])['record'];

    expect($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines->first()->purchase_order_line_id)->toBe($orderLine->getKey())
        ->and($invoice->lines->last()->purchase_order_line_id)->toBeNull();
    $invoice = app(PurchaseInvoiceService::class)->approve($invoice);
    $matchingNotes = json_decode((string) $invoice->matching_notes, true, flags: JSON_THROW_ON_ERROR);
    expect($invoice->matching_status)->toBe('approved_with_variance')
        ->and(collect($matchingNotes['line_variances'])->pluck('match_type')->all())->toBe(['linked', 'unlinked']);

    $orderLinkedDraft = app(PurchaseInvoiceService::class)->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 1,
            'unit_price' => 1,
        ]],
    ])['record'];
    $invoiceData = $this->getJson(route('admin.purchases.purchase-invoices.data').'?draw=1&start=0&length=10')->assertOk();
    $draftRow = collect($invoiceData->json('data'))->first(fn (array $row): bool => str_contains($row['doc_num'], $orderLinkedDraft->doc_num));
    expect($draftRow)->not->toBeNull()
        ->and($draftRow['can_edit'])->toBeTrue()
        ->and($draftRow['edit_url'])->toBe(route('admin.purchases.purchase-invoices.edit', $orderLinkedDraft))
        ->and($draftRow['view_url'])->toBe(route('admin.purchases.purchase-invoices.show', $orderLinkedDraft));
    $approvedRow = collect($invoiceData->json('data'))->first(fn (array $row): bool => str_contains($row['doc_num'], $directInvoice->doc_num));
    expect($approvedRow['can_edit'])->toBeFalse();

    $this->get(route('admin.purchases.purchase-invoices.create'))->assertOk()
        ->assertSee(__('purchase_invoices.messages.purchase_order_source_help'))
        ->assertSee(__('purchase_invoices.attributes.freight_amount'))
        ->assertSee(__('purchase_invoices.actions.duplicate_line'))
        ->assertSee(__('purchase_invoices.actions.delete_line'));
});

test('supplier invoice schedule uses one date and an explicit cashbox or bank source', function (): void {
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);

    expect(PurchaseInvoice::scheduleSourceTypes())->toBe([
        PurchaseInvoice::SourceCashbox,
        PurchaseInvoice::SourceBank,
    ]);

    $html = $this->get(route('admin.purchases.purchase-invoices.create'))->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $scheduleTable = $dom->querySelector('.js-purchase-invoice-schedules');
    $sourceOptions = collect($scheduleTable->querySelectorAll('.js-purchase-invoice-schedule-source option'))
        ->map(fn ($option): string => $option->getAttribute('value'))
        ->all();

    expect($sourceOptions)->toBe([PurchaseInvoice::SourceCashbox, PurchaseInvoice::SourceBank])
        ->and($scheduleTable->querySelector('.js-purchase-invoice-payment-date'))->toBeNull()
        ->and($scheduleTable->querySelector('.js-purchase-invoice-linked-voucher-cell'))->toBeNull()
        ->and($scheduleTable->textContent)->not->toContain(__('purchase_invoices.attributes.status'));

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111098', 'Schedule supplier payable');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111098', 'Schedule cash');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $administrativeBranch->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Schedule cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true,
        'status' => 'active',
    ]);
    $dueDate = now()->addWeek()->toDateString();
    $payload = [
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypePartial,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 10,
        ]],
        'payment_schedules' => [[
            'due_date' => $dueDate,
            'amount' => 20,
            'payment_source_type' => PurchaseInvoice::SourceCashbox,
            'cashbox_doc_num' => $cashbox->doc_num,
        ]],
    ];

    $invalidPayload = $payload;
    $invalidPayload['payment_schedules'][0]['payment_source_type'] = PurchaseInvoice::SourceScheduled;
    $this->postJson(route('admin.purchases.purchase-invoices.store'), $invalidPayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_schedules.0.payment_source_type');

    $response = $this->postJson(route('admin.purchases.purchase-invoices.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);
    $invoice = PurchaseInvoice::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $schedule = $invoice->paymentSchedules()->sole();
    expect($schedule->due_date->toDateString())->toBe($dueDate)
        ->and($schedule->payment_date?->toDateString())->toBe($dueDate)
        ->and($schedule->payment_source_type)->toBe(PurchaseInvoice::SourceCashbox);
});

test('editing preserves a historical invoice item and legacy scheduled payment without creating a voucher', function (): void {
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);

    $invoice = app(PurchaseInvoiceService::class)->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 120000,
        ]],
    ])['record'];
    $line = $invoice->lines()->sole();
    $dueDate = now()->addDays(13)->toDateString();
    $schedule = $invoice->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1,
        'due_date' => $dueDate,
        'amount' => 240000,
        'payment_source_type' => PurchaseInvoice::SourceScheduled,
        'status' => PurchaseInvoicePaymentSchedule::StatusScheduled,
        'created_by' => $fixture['user']->getKey(),
    ]);
    $fixture['raw']->delete();

    $html = $this->get(route('admin.purchases.purchase-invoices.edit', $invoice))
        ->assertOk()
        ->assertSee($fixture['raw']->doc_num)
        ->assertSee($fixture['raw']->name)
        ->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $dateInput = $dom->querySelector('[name="payment_schedules[0][due_date]"]');
    $sourceSelect = $dom->querySelector('[name="payment_schedules[0][payment_source_type]"]');

    expect($dateInput)->not->toBeNull()
        ->and($dateInput->getAttribute('value'))->toBe($dueDate)
        ->and($dateInput->hasAttribute('required'))->toBeTrue()
        ->and($sourceSelect?->querySelector('option[selected]')?->getAttribute('value'))->toBe(PurchaseInvoice::SourceScheduled);

    $payload = [
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'lines' => [[
            'public_id' => $line->public_id,
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => '120,000',
        ]],
        'payment_schedules' => [[
            'public_id' => $schedule->public_id,
            'due_date' => app(DateFormatService::class)->formatDate($dueDate),
            'amount' => '240,000',
            'payment_source_type' => PurchaseInvoice::SourceScheduled,
        ]],
    ];
    $voucherCount = CashVoucher::query()->count();

    $this->putJson(route('admin.purchases.purchase-invoices.update', $invoice), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    $preservedLine = $invoice->fresh()->lines()->sole();
    $preservedSchedule = $invoice->fresh()->paymentSchedules()->sole();
    expect($preservedLine->getKey())->toBe($line->getKey())
        ->and($preservedLine->product_id)->toBe($fixture['raw']->getKey())
        ->and($preservedLine->product?->trashed())->toBeTrue()
        ->and($preservedSchedule->getKey())->toBe($schedule->getKey())
        ->and($preservedSchedule->due_date->toDateString())->toBe($dueDate)
        ->and($preservedSchedule->amount)->toBe('240000.0000')
        ->and($preservedSchedule->payment_source_type)->toBe(PurchaseInvoice::SourceScheduled)
        ->and($preservedSchedule->cash_voucher_id)->toBeNull()
        ->and(CashVoucher::query()->count())->toBe($voucherCount);

    $invalidSchedulePayload = $payload;
    $invalidSchedulePayload['payment_schedules'][0]['due_date'] = '';
    $invalidSchedulePayload['payment_schedules'][0]['amount'] = '';
    $invalidSchedulePayload['payment_schedules'][0]['payment_source_type'] = PurchaseInvoice::SourceCashbox;
    $invalidScheduleResponse = $this->putJson(
        route('admin.purchases.purchase-invoices.update', $invoice),
        $invalidSchedulePayload,
    )->assertUnprocessable()
        ->assertJsonValidationErrors([
            'payment_schedules.0.due_date',
            'payment_schedules.0.amount',
            'payment_schedules.0.cashbox_doc_num',
        ]);
    $scheduleErrors = $invalidScheduleResponse->json('errors');
    expect($scheduleErrors['payment_schedules.0.due_date'][0])
        ->toBe(__('purchase_invoices.messages.schedule_due_date_required', ['position' => 1]))
        ->and($scheduleErrors['payment_schedules.0.amount'][0])
        ->toBe(__('purchase_invoices.messages.schedule_amount_required', ['position' => 1]))
        ->and($scheduleErrors['payment_schedules.0.cashbox_doc_num'][0])
        ->toBe(__('purchase_invoices.messages.schedule_cashbox_required', ['position' => 1]));

    $newInvoicePayload = $payload;
    unset($newInvoicePayload['lines'][0]['public_id'], $newInvoicePayload['payment_schedules']);
    $invalidProductResponse = $this->postJson(route('admin.purchases.purchase-invoices.store'), $newInvoicePayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.product_doc_num');
    expect($invalidProductResponse->json('errors')['lines.0.product_doc_num'][0])
        ->toBe(__('purchase_invoices.messages.line_product_invalid', ['position' => 1]));

    $script = file_get_contents(public_path('assets/js/modules/Purchases/purchase-invoices.js'));
    $scheduleDuplicateHandler = substr(
        $script,
        strpos($script, '$form.on(\'click\', \'.js-purchase-invoice-duplicate-schedule\''),
        900,
    );
    expect($script)
        ->toContain("removeAttr('data-date-picker-initialized')")
        ->toContain("find('.erp-date-picker-display').remove()")
        ->toContain('if ($clone.find(\'.js-purchase-invoice-schedule-source\').val() === \'scheduled\')');
    expect($scheduleDuplicateHandler)
        ->toContain('$clone.find(\'[name$="[public_id]"]\').val(\'\')')
        ->not->toContain('$clone.find(\'input[type="hidden"]\').val(\'\')');
});

test('request datatable exposes state actions and draft restore without resurrecting removed lines', function (): void {
    $fixture = procurementUiFixture();
    $request = procurementManualRequisition($fixture);
    $lineId = $request->lines->sole()->id;
    $this->get(route('admin.purchases.purchase-requisitions.index'))->assertOk()->assertSee('procurement-documents-table')->assertDontSee('Combine approved purchase requests');
    $data = $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=1&start=0&length=10')->assertOk();
    expect($data->json('recordsFiltered'))->toBe(1, $data->getContent())
        ->and($data->json('data.0.actions'))->toContain('/submit')
        ->and($data->json('data.0.can_edit'))->toBeTrue()
        ->and($data->json('data.0.edit_url'))->toBe(route('admin.purchases.purchase-requisitions.edit', $request))
        ->and($data->json('data.0.view_url'))->toBe(route('admin.purchases.purchase-requisitions.show', $request));
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
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $lookup = $this->getJson(route('admin.purchases.select2.requisitions'))->assertOk()->assertJsonStructure(['results', 'pagination' => ['more']]);
    expect(collect($lookup->json('results'))->pluck('id')->all())->toContain($first->doc_num, $second->doc_num)->not->toContain($draft->doc_num);
    $loaded = $this->getJson(route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$first->doc_num, $second->doc_num]]))->assertOk();
    expect($loaded->json('lines'))->toHaveCount(2)->and(array_column($loaded->json('lines'), 'ordered_quantity'))->toBe([10, 15]);
    $this->get(route('admin.purchases.purchase-orders.create'))->assertOk()
        ->assertSee('js-order-requisitions')
        ->assertSee($fixture['currency']->doc_num)
        ->assertSee('data-input-name="lines[0][attachment_file_doc_nums][]"', false)
        ->assertSee('data-document-summary', false)
        ->assertSee('js-total-subtotal', false)
        ->assertSee('js-total-discount', false)
        ->assertSee('js-total-taxable', false)
        ->assertSee('js-total-tax', false)
        ->assertSee('js-total-freight', false)
        ->assertSee('<div class="card mb-3 js-procurement-attachment-scope erp-document-attachments-card">', false)
        ->assertDontSee('<section class="card mb-3 js-procurement-attachment-scope', false)
        ->assertDontSee('[cost_center_doc_num]', false);
    $this->getJson(route('admin.purchases.select2.currency-rate', ['currency_doc_num' => $fixture['currency']->doc_num]))->assertOk()->assertJsonPath('rate', 1)->assertJsonPath('is_main', true);
});

test('supplier quotations are entered directly from approved requests or purchase orders', function (): void {
    $this->withoutExceptionHandling();
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10)));
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));

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
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    foreach (['request_for_quotations' => 'request-for-quotations', 'supplier_quotations' => 'supplier-quotation-entry', 'supply_orders' => 'supply-orders', 'goods_receipts' => 'goods-receipt-notes', 'purchase_returns' => 'purchase-returns'] as $screen => $route) {
        $this->get(route('admin.purchases.'.$route.'.index'))->assertOk()->assertSee('procurement-documents-table');
        $this->getJson(route('admin.purchases.procurement.data', $screen).'?draw=1&start=0&length=10')->assertOk()->assertJsonPath('recordsFiltered', 0);
    }
    procurementUseBranch($fixture, $fixture['branch']);
    $this->get(route('admin.purchases.goods-receipt-inspection.choose-source'))->assertOk()
        ->assertSee('js-select2-ajax')->assertSee(__('From Purchase Order'))->assertSee(__('From Supply Order'));
    $this->get(route('admin.purchases.goods-receipt-notes.choose-source'))->assertOk()
        ->assertSee('js-select2-ajax')->assertSee(__('Purchase inspection'))
        ->assertSee(__('Select a finalized purchase inspection; only accepted quantities will be loaded.'));
    procurementUseBranch($fixture, $administrativeBranch);
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
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
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

test('purchase inspection precedes receipt and accepted quantities feed the warehouse document', function (): void {
    $this->withoutExceptionHandling();
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 100)));
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $orders = app(PurchaseOrderService::class);
    $order = $orders->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 7, 'purchase_requisition_line_id' => $request->lines->sole()->id]],
    ])['record'];
    $this->postJson(route('admin.purchases.purchase-orders.submit', $order))->assertOk();
    $this->postJson(route('admin.purchases.purchase-orders.approve', $order))->assertOk();
    procurementUseBranch($fixture, $fixture['branch']);
    $this->get(route('admin.purchases.purchase-orders.show', $order))->assertOk()
        ->assertSee(__('Create Purchase Inspection'))->assertDontSee(__('Create supplier invoice'));
    $this->getJson(route('admin.purchases.select2.purchase-orders', ['purpose' => 'inspection']))->assertOk()->assertJsonPath('results.0.id', $order->doc_num);
    $receiving = app(ProcurementReceivingService::class);
    $html = $this->get(route('admin.purchases.goods-receipt-inspection.create', $order))->assertOk()->assertDontSee('100.00000000')->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $submit = collect($dom->querySelectorAll('button'))->first(fn ($button) => str_contains($button->textContent, __('procurement.ui.finalize_quality_inspection')));
    expect($submit)->not->toBeNull()->and($submit->closest('form')->getAttribute('action'))->toBe(route('admin.purchases.goods-receipt-inspection.store', $order));
    $inspection = $receiving->inspectPurchaseSource($order->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $order->lines->sole()->public_id,
            'delivered_quantity' => 50,
            'accepted_quantity' => 40,
            'rejected_quantity' => 10,
            'disposition' => 'return_supplier',
            'reason' => 'Packaging damage',
        ]],
    ]);
    expect($inspection->receipt_id)->toBeNull()
        ->and($inspection->purchase_order_id)->toBe($order->id)
        ->and((float) $inspection->lines->sole()->accepted_quantity)->toBe(40.0);
    $this->get(route('admin.purchases.goods-receipt-inspection.index'))->assertOk()
        ->assertSee('procurement-documents-table', false)
        ->assertSee('name="branch_store_uuid"', false)
        ->assertSee(route('admin.purchases.procurement.data', 'goods_receipt_inspections'), false);
    $inspectionData = $this->getJson(route('admin.purchases.procurement.data', 'goods_receipt_inspections').'?draw=1&start=0&length=10&search[value]='.$order->doc_num)->assertOk();
    expect($inspectionData->json('recordsFiltered'))->toBe(1)
        ->and($inspectionData->json('data.0.doc_num'))->toContain($inspection->doc_num)
        ->and($inspectionData->json('data.0.source'))->toBe($order->doc_num)
        ->and($inspectionData->json('data.0.location'))->toContain($fixture['branch']->name, $fixture['store']->name)
        ->and($inspectionData->json('data.0.status'))->not->toContain('procurement.ui.statuses')
        ->and($inspectionData->json('data.0.actions'))->toContain('/goods-receipt-notes/create/'.$inspection->doc_num);
    $this->getJson(route('admin.purchases.procurement.data', 'goods_receipt_inspections').'?draw=2&start=0&length=10&branch_store_uuid='.$fixture['store']->public_uuid)
        ->assertOk()
        ->assertJsonPath('recordsFiltered', 1);
    $this->get(route('admin.purchases.goods-receipt-inspection.show', $inspection))
        ->assertOk()
        ->assertSee($fixture['branch']->name)
        ->assertSee($fixture['store']->name);
    $rejectionRows = app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::QcRejection,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    );
    expect($rejectionRows->firstWhere('document', $inspection->doc_num))
        ->toMatchArray(['rejected' => '10.00000000', 'document_permission' => 'purchases.goods_receipt_inspection.view']);
    $this->get(route('admin.purchases.goods-receipt-notes.create', $inspection))->assertOk()
        ->assertSee($inspection->doc_num)
        ->assertSee(__('Accepted'))
        ->assertSee(__('Previously received'))
        ->assertSee(__('Remaining'))
        ->assertSee(__('Received now'))
        ->assertDontSee(__('Rejected for receipt'));
    $receipt = $receiving->createReceiptFromInspection($inspection, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $inspection->lines->sole()->public_id,
            'delivered_quantity' => 15,
        ]],
    ]);
    expect(InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->count())->toBe(0);
    expect((float) $receipt->lines->sole()->accepted_quantity)->toBe(15.0)
        ->and($receipt->goods_receipt_inspection_id)->toBe($inspection->id)
        ->and($receipt->lines->sole()->goods_receipt_inspection_line_id)->toBe($inspection->lines->sole()->id)
        ->and($inspection->fresh()->receipt_id)->toBeNull();
    $this->get(route('admin.purchases.goods-receipt-notes.edit', $receipt->doc_num))
        ->assertOk()
        ->assertSee($inspection->doc_num)
        ->assertSee('name="lines[0][delivered_quantity]"', false);
    $inspection->forceFill(['receipt_id' => $receipt->id])->save();
    $this->get(route('admin.purchases.goods-receipt-notes.edit', $receipt->doc_num))
        ->assertOk()
        ->assertSee('name="lines[0][delivered_quantity]"', false);
    $inspection->forceFill(['receipt_id' => null])->save();
    $receipt = $receiving->updateReceipt($receipt, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $inspection->lines->sole()->public_id,
            'delivered_quantity' => 18,
        ]],
    ]);
    expect((float) $receipt->lines->sole()->accepted_quantity)->toBe(18.0)
        ->and($receipt->lines->sole()->goods_receipt_inspection_line_id)->toBe($inspection->lines->sole()->id);
    $receipt = $receiving->postReceipt($receipt->fresh());
    expect(app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::IncomingQcPending,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    )->pluck('document')->all())->toContain($inspection->doc_num);
    $partialInspectionOptions = $this->getJson(route('admin.purchases.select2.inspections'))->assertOk();
    expect(collect($partialInspectionOptions->json('results'))->pluck('id')->all())->toContain($inspection->doc_num);
    $splitReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($inspection->fresh(), [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $inspection->lines->sole()->public_id,
            'delivered_quantity' => 22,
        ]],
    ]));
    expect((float) $splitReceipt->lines->sole()->accepted_quantity)->toBe(22.0)
        ->and($inspection->fresh()->hasReceiptableQuantity())->toBeFalse()
        ->and(fn () => $receiving->createReceiptFromInspection($inspection->fresh(), [
            'document_date' => now()->toDateString(),
            'lines' => [['inspection_line_public_id' => $inspection->lines->sole()->public_id]],
        ]))->toThrow(DomainException::class, __('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));
    expect(app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::IncomingQcPending,
        ['branch_id' => $fixture['branch']->id],
        $fixture['company']->id,
        $fixture['period']->id,
    )->pluck('document')->all())->not->toContain($inspection->doc_num);
    $completedInspectionOptions = $this->getJson(route('admin.purchases.select2.inspections'))->assertOk();
    expect(collect($completedInspectionOptions->json('results'))->pluck('id')->all())->not->toContain($inspection->doc_num);
    expect(app(ProcurementCycleReport::class)->documentChain($receipt)->pluck('doc_num')->all())
        ->toContain($order->doc_num, $inspection->doc_num, $receipt->doc_num, $splitReceipt->doc_num);
    $secondInspection = $receiving->inspectPurchaseSource($order->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $order->lines->sole()->public_id,
            'delivered_quantity' => 30,
            'accepted_quantity' => 30,
            'rejected_quantity' => 0,
        ]],
    ]);
    $secondReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($secondInspection, [
        'document_date' => now()->toDateString(),
        'lines' => [['inspection_line_public_id' => $secondInspection->lines->sole()->public_id]],
    ]));
    $this->get(route('admin.purchases.purchase-returns.create', ['receipt' => $receipt->doc_num]))
        ->assertOk()->assertSee($receipt->doc_num)->assertSee($fixture['raw']->name);
    procurementUseBranch($fixture, $administrativeBranch);
    $receiptOptions = $this->getJson(route('admin.purchases.select2.receipts', ['purpose' => 'invoice', 'purchase_order' => $order->doc_num]))->assertOk();
    expect(collect($receiptOptions->json('results'))->pluck('id')->all())->toContain($receipt->doc_num, $splitReceipt->doc_num, $secondReceipt->doc_num);
    $invoiceHtml = $this->get(route('admin.purchases.purchase-invoices.create', ['receipts' => [$receipt->doc_num]]))->assertOk()->assertSee('value="'.$receipt->doc_num.'" selected', false)->getContent();
    $invoiceDom = HTMLDocument::createFromString($invoiceHtml, LIBXML_NOERROR);
    $source = $invoiceDom->querySelector('input[name="lines[0][receipt_line_public_id]"]');
    expect($source->getAttribute('value'))->toBe($receipt->lines->sole()->public_id)
        ->and($invoiceDom->querySelector('.js-purchase-invoice-duplicate-line')->hasAttribute('hidden'))->toBeTrue();
    $this->getJson(route('admin.purchases.procurement.data', 'goods_receipts').'?search[value]='.$order->doc_num)->assertOk()->assertJsonPath('recordsFiltered', 3);
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

test('purchase request visibility and downstream actions follow operating branch type', function (): void {
    $fixture = procurementUiFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $factoryRequest = $sourcing->submitRequisition(procurementManualRequisition($fixture, 10));
    $factoryData = $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=0&start=0&length=10')->assertOk();
    expect($factoryData->json('data.0.actions'))->toContain('/approve');
    $this->postJson(route('admin.purchases.purchase-requisitions.approve', $factoryRequest))->assertOk();
    $factoryRequest = $factoryRequest->fresh();

    $warehouse = Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->id,
        'name' => 'Secondary Warehouse',
        'type' => Branch::TypeWarehouse,
        'status' => 'active',
    ]);
    $warehouseStore = BranchStore::query()->create(['branch_id' => $warehouse->id, 'name' => 'Warehouse Stock', 'position' => 1]);
    procurementUseBranch($fixture, $warehouse);
    $warehouseFixture = [...$fixture, 'branch' => $warehouse, 'store' => $warehouseStore];
    $warehouseRequest = $sourcing->submitRequisition(procurementManualRequisition($warehouseFixture, 15));

    $warehouseData = $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=1&start=0&length=10')->assertOk();
    expect($warehouseData->json('recordsFiltered'))->toBe(1)
        ->and($warehouseData->json('data.0.doc_num'))->toContain($warehouseRequest->doc_num)
        ->and($warehouseData->json('data.0.actions'))->not->toContain('/approve');
    $this->postJson(route('admin.purchases.purchase-requisitions.approve', $warehouseRequest))->assertForbidden();
    $this->get(route('admin.purchases.purchase-orders.create'))->assertForbidden();
    $this->get(route('admin.purchases.purchase-invoices.create'))->assertForbidden();

    $administration = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administration);
    $this->get(route('admin.purchases.purchase-requisitions.create'))->assertForbidden();
    $adminData = $this->getJson(route('admin.purchases.procurement.data', 'purchase_requisitions').'?draw=2&start=0&length=10')->assertOk();
    expect($adminData->json('recordsFiltered'))->toBe(2);
    $factoryRow = collect($adminData->json('data'))->first(fn (array $row): bool => str_contains($row['doc_num'], $factoryRequest->doc_num));
    expect($factoryRow['source'])->toContain($fixture['branch']->name)->toContain($fixture['store']->name);

    $this->get(route('admin.purchases.purchase-requisitions.show', $factoryRequest))->assertOk()
        ->assertSee($fixture['branch']->name)
        ->assertSee($fixture['store']->name);
    $this->get(route('admin.purchases.supplier-quotation-entry.create-source', [SupplierQuotation::SourcePurchaseRequisition, $factoryRequest->doc_num]))
        ->assertOk()
        ->assertSee($factoryRequest->doc_num)
        ->assertSee('source_line_public_id', false);
    $this->get(route('admin.purchases.purchase-orders.create', ['purchase_requisition_doc_nums' => [$factoryRequest->doc_num]]))->assertOk();

    app()->setLocale('ar');
    $this->get(route('admin.purchases.supplier-quotation-entry.choose-source'))->assertOk()
        ->assertSee('طلب شراء')
        ->assertDontSee('Purchase Request');
});

test('purchase navigation follows the operational document sequence', function (): void {
    config()->set('erp.phase_mode', 'expanded');
    $expectedOrder = [
        'suppliers',
        'purchase_requisitions',
        'purchase_orders',
        'supplier_quotations',
        'supply_orders',
        'purchase_inspections',
        'goods_receipts',
        'purchase_invoices',
        'supplier_payments',
        'purchase_returns',
    ];
    $purchases = collect(app(MenuService::class)->structure())->firstWhere('label', 'purchases');

    expect(config('menu_sections.leaf_order.purchases'))->toBe($expectedOrder)
        ->and(collect($purchases['children'])->pluck('label')->all())->toBe([...$expectedOrder, 'purchase_reports']);

    app()->setLocale('ar');
    expect(__('menu.purchase_inspections'))->toBe('فحوص المشتريات');
});

test('purchase reports menu exposes every implemented procurement report', function (): void {
    $purchasesMenu = require config_path('menu/purchases.php');
    $reportItems = collect($purchasesMenu[0]['children'])->firstWhere('label', 'purchase_reports')['children'];
    $exposedTypes = collect($reportItems)->pluck('route_params.report_type')->filter()->values()->all();
    $dedicatedTypes = [ProcurementCycleReport::SupplierStatement];
    app()->setLocale('en');

    expect(array_values(array_diff(ProcurementCycleReport::types(), [...$exposedTypes, ...$dedicatedTypes])))->toBe([])
        ->and($exposedTypes)->toContain(
            ProcurementCycleReport::PurchaseLedger,
            ProcurementCycleReport::ReceiptQualityStatus,
            ProcurementCycleReport::QcRejection,
            ProcurementCycleReport::DueSupplierInstallments,
            ProcurementCycleReport::ProductionAnalysis,
        )
        ->and(__('menu.report_pending_sourcing_actions'))->toBe('Pending RFQ / Quotation / Selection Actions');

    app()->setLocale('ar');
    expect(__('menu.report_pending_sourcing_actions'))->toBe('إجراءات طلبات وعروض الأسعار واختيار المورد المعلقة');
});

test('supplier payment form compiles all fields and Arabic labels', function (): void {
    $fixture = procurementUiFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $selectedOrder = PurchaseOrder::query()->create([
        'doc_number' => 9901,
        'doc_num' => 'PO-PAYMENT-SELECTED',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $administrativeBranch->id,
        'branch_store_id' => $fixture['store']->id,
        'supplier_id' => $fixture['firstSupplier']->id,
        'currency_id' => $fixture['currency']->id,
        'document_date' => now()->toDateString(),
        'status' => PurchaseOrder::StatusApproved,
    ]);
    $otherBranchOrder = PurchaseOrder::query()->create([
        'doc_number' => 9902,
        'doc_num' => 'PO-PAYMENT-OTHER-BRANCH',
        'company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id,
        'supplier_id' => $fixture['firstSupplier']->id,
        'currency_id' => $fixture['currency']->id,
        'document_date' => now()->toDateString(),
        'status' => PurchaseOrder::StatusApproved,
    ]);
    app()->setLocale('ar');

    $this->withSession(['_old_input' => ['purchase_order_doc_num' => $selectedOrder->doc_num]])
        ->get(route('admin.purchases.supplier-payments.create'))
        ->assertOk()
        ->assertSee('دفعة / دفعة مقدمة لمورد')
        ->assertSee('تخصيصات الفواتير / الأقساط')
        ->assertSee('name="supplier_doc_num"', false)
        ->assertSee('js-select2-ajax js-payment-supplier', false)
        ->assertSee(route('admin.purchases.select2.suppliers'), false)
        ->assertSee(route('admin.purchases.select2.supplier-payment-purchase-orders'), false)
        ->assertSee('value="'.$selectedOrder->doc_num.'" selected', false)
        ->assertSee('name="payment_method"', false)
        ->assertDontSee('@csrf')
        ->assertDontSee("{{ __('Supplier') }}", false);

    $fixture['user']->syncPermissions(['purchases.prices.view', 'supplier_payments.create']);
    $options = $this->getJson(route('admin.purchases.select2.supplier-payment-purchase-orders'))->assertOk();
    expect(collect($options->json('results'))->pluck('id')->all())
        ->toContain($selectedOrder->doc_num)
        ->not->toContain($otherBranchOrder->doc_num);
    $this->getJson(route('admin.purchases.select2.purchase-orders'))->assertForbidden();
});

test('purchase cycle tables consistently support permission aware double click editing', function (): void {
    $invoiceScript = file_get_contents(public_path('assets/js/modules/Purchases/purchase-invoices.js'));
    $orderScript = file_get_contents(public_path('assets/js/modules/Purchases/purchase-orders.js'));
    $procurementScript = file_get_contents(public_path('assets/js/modules/Purchases/procurement-index.js'));

    expect($invoiceScript)
        ->toContain('dblclick.purchaseInvoiceEditRow')
        ->toContain('row && row.can_edit ? row.edit_url : (row && row.view_url)')
        ->toContain('event.stopImmediatePropagation();')
        ->and($orderScript)
        ->toContain('dblclick.purchaseOrderEditRow')
        ->toContain('row && row.can_edit ? row.edit_url : (row && row.view_url)')
        ->and($procurementScript)
        ->toContain('dblclick.procurementEditRow')
        ->toContain('row?.can_edit ? row.edit_url : row?.view_url');
});

test('purchase invoice generic confirmations are translated without an English fallback', function (): void {
    app()->setLocale('ar');
    expect(__('purchase_invoices.js.confirm_title'))->toBe('تأكيد الإجراء؟');

    app()->setLocale('en');
    expect(__('purchase_invoices.js.confirm_title'))->toBe('Confirm action?');
});
