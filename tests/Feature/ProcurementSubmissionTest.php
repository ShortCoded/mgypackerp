<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Services\DateFormatService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\ProcurementAttachmentService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('purchase document retries replay once reject changed payload and recheck permissions', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $user = $fixture['user'];
    $user->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 100)));
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $payload = ['_submission_token' => (string) Str::uuid(),
        'document_date' => app(DateFormatService::class)->formatDate(now()),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'exchange_rate' => 1,
        'lines' => [['purchase_requisition_line_id' => $request->lines->sole()->id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 10, 'unit_price' => 2]],
    ];
    $url = route('admin.purchases.purchase-orders.store');
    $invalid = $payload;
    $invalid['lines'][0]['ordered_quantity'] = -1;
    $this->postJson($url, $invalid)->assertUnprocessable();
    expect(DB::table('document_submissions')->count())->toBe(0);
    $response = $this->postJson($url, $payload)->assertSuccessful();
    $this->postJson(route('admin.purchases.purchase-orders.approve', PurchaseOrder::query()->sole()))->assertUnprocessable();
    $this->postJson($url, $payload)->assertStatus($response->status())->assertExactJson($response->json());
    expect(PurchaseOrder::query()->count())->toBe(1)->and(DB::table('document_submissions')->count())->toBe(1);
    $this->postJson($url, [...$payload, 'notes' => 'Changed request'])->assertConflict();
    $user->revokePermissionTo('purchase_orders.create');
    $user->unsetRelation('permissions');
    $this->postJson($url, $payload)->assertForbidden();
    expect(PurchaseOrder::query()->count())->toBe(1);
});

test('purchase order submission rejection and approvals retain audit without inventory or journal posting', function (): void {
    $fixture = procurementFixture();
    $service = app(PurchaseOrderService::class);
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 100)));
    $order = $service->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['purchase_requisition_line_id' => $request->lines->sole()->id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 2]],
    ])['record'];
    expect(fn () => $service->reject($order, 'Not approved'))->toThrow(DomainException::class);
    $submitted = $service->submit($order);
    expect($submitted->status)->toBe(PurchaseOrder::StatusSubmitted)->and($submitted->submitted_by)->toBe($fixture['user']->id);
    $stamp = $submitted->submitted_at->toISOString();
    $this->travel(1)->minutes();
    expect($service->submit($submitted)->submitted_at->toISOString())->toBe($stamp);
    $rejected = $service->reject($submitted, 'Supplier terms need revision');
    expect($rejected->status)->toBe(PurchaseOrder::StatusRejected)->and($rejected->rejected_by)->toBe($fixture['user']->id)
        ->and($rejected->rejection_reason)->toBe('Supplier terms need revision');
    expect(fn () => $service->approve($rejected))->toThrow(DomainException::class);
    expect($request->lines->sole()->remainingToOrder())->toBe(100.0)
        ->and(DB::table('inventory_transactions')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
});

test('shared line cards load styles and behavior once and preserve inline errors and retry token', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    $response = $this->get(route('admin.purchases.purchase-orders.create'))->assertOk();
    expect(substr_count($response->getContent(), 'assets/js/modules/Core/line-item-cards.js'))->toBe(1)
        ->and(substr_count($response->getContent(), 'assets/css/line-item-cards.css'))->toBe(1);
    $response->assertSee('data-line-card-editor', false)->assertSee('name="_submission_token"', false);
});

test('procurement attachments reuse archive access remain company scoped and do not duplicate on draft retries', function (): void {
    Storage::fake('public');
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $file = procurementDocumentAttachment($fixture['company']);
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->createRequisition([
        'request_date' => now()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'requested_quantity' => 100,
            'attachment_file_doc_nums' => [$file->doc_num],
        ]],
    ]);
    $attachments = app(ProcurementAttachmentService::class);
    expect($attachments->documents($request->lines->sole(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition($request));
    $payload = ['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'attachment_file_doc_nums' => [$file->doc_num],
        'lines' => [['purchase_requisition_line_id' => $request->lines->sole()->id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 2,
            'attachment_file_doc_nums' => [$file->doc_num]]],
    ];
    $orders = app(PurchaseOrderService::class);
    $order = $orders->create($payload)['record'];
    expect($attachments->documents($order))->toHaveCount(1)
        ->and($attachments->documents($order->lines->sole(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    $payload['lines'][0]['public_id'] = $order->lines->sole()->public_id;
    $updatedAt = $order->updated_at;
    $orders->update($order, $payload);
    expect($attachments->documents($order))->toHaveCount(1)
        ->and($attachments->documents($order->lines->sole(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1)
        ->and($order->fresh()->updated_at?->toISOString())->toBe($updatedAt?->toISOString());
    $this->get(route('admin.purchases.purchase-orders.show', $order))->assertOk()
        ->assertSee($file->original_name)
        ->assertSee('erp-status-indicator', false);
    $file->forceFill(['attachable_id' => $fixture['company']->id + 10000])->save();
    expect(fn () => $orders->create($payload))->toThrow(DomainException::class);
    $fixture['user']->revokePermissionTo(['file_manager.view', 'file_manager.download']);
    $fixture['user']->unsetRelation('permissions');
    $this->get(route('admin.purchases.purchase-orders.show', $order))->assertOk()->assertDontSee($file->original_name);
    $this->get(route('admin.file-manager.files.download', $file->doc_num))->assertForbidden();
});

test('restricted warehouse and purchasing users cannot see prices or invoke finance and approval actions', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(['purchases.purchase_requisitions.view', 'purchases.purchase_requisitions.create', 'reports.purchases.view', 'reports.purchases.export']);
    $request = procurementManualRequisition($fixture);
    $this->get(route('admin.purchases.purchase-requisitions.show', $request))->assertOk();
    $this->post(route('admin.purchases.purchase-requisitions.approve', $request))->assertForbidden();
    $this->get(route('admin.purchases.procurement-cycle-report.index', ['report_type' => 'supplier_statement']))->assertForbidden();
    foreach (['index', 'print', 'export.excel'] as $action) {
        $this->get(route('admin.purchases.procurement-cycle-report.'.$action, ['report_type' => 'goods_received_not_invoiced']))->assertForbidden();
    }
    $this->get(route('admin.purchases.supplier-payments.create'))->assertForbidden();
    $this->get(route('admin.purchases.purchase-invoices.create'))->assertForbidden();
});

test('saving a cash invoice without changes preserves audit schedules vouchers and allocations', function (): void {
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111081', 'No-op supplier');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111081', 'No-op cash');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->id])->save();
    $cashbox = Cashbox::query()->create(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'doc_number' => 9801, 'doc_num' => 'CASH-NOOP', 'name' => 'No-op cashbox', 'status' => 'active', 'account_id' => $cashAccount->id]);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->id, 'currency_id' => $fixture['currency']->id, 'status' => 'active', 'is_default' => true]);
    $payload = ['invoice_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'cash', 'cashbox_doc_num' => $cashbox->doc_num,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'quantity' => 2, 'unit_price' => 27]]];
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create($payload)['record'];
    $payload['lines'][0]['public_id'] = $invoice->lines->sole()->public_id;
    $payload['lines'][0]['quantity'] = '2.00000000';
    $payload['lines'][0]['unit_price'] = '27.00000000';
    $tables = ['purchase_invoices', 'purchase_invoice_lines', 'purchase_invoice_payment_schedules', 'cash_vouchers', 'cash_voucher_lines', 'supplier_payment_contexts', 'supplier_payment_allocations'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    $this->travel(2)->minutes();
    $result = $service->update($invoice, $payload);
    expect($result['changed'])->toBeFalse();
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table], $table);
    }
    $payload['lines'][0]['notes'] = 'Changed line note';
    $result = $service->update($invoice, $payload);
    expect($result['changed'])->toBeTrue()->and($result['record']->updated_by)->toBe($fixture['user']->id);
});

test('draft receipts and returns keep line identities and audit untouched on a no op save', function (): void {
    $fixture = procurementFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 100)));
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['document_date' => now()->toDateString(), 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['purchase_requisition_line_id' => $request->lines->sole()->id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 100, 'unit_price' => 2]]])['record']);
    $receiving = app(ProcurementReceivingService::class);
    $receiptPayload = ['document_date' => now()->toDateString(), 'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => 90]]];
    $receipt = $receiving->createReceipt($order, $receiptPayload);
    $staleDraftPayload = ['document_date' => now()->toDateString(), 'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => 10]]];
    $staleDraft = $receiving->createReceipt($order, $staleDraftPayload);
    $before = [$receipt->getAttributes(), $receipt->lines->sole()->getAttributes()];
    $this->travel(2)->minutes();
    $updated = $receiving->updateReceipt($receipt, $receiptPayload);
    expect([$updated->getAttributes(), $updated->lines->sole()->getAttributes()])->toBe($before);
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(['purchases.goods_receipt_notes.view', 'purchases.goods_receipt_notes.edit']);
    $this->get(route('admin.purchases.goods-receipt-notes.edit', $staleDraft->doc_num))->assertOk()
        ->assertSee('name="lines[0][delivered_quantity]"', false)->assertSee($order->lines->sole()->public_id);
    $overReservedPayload = ['document_date' => now()->toDateString(), 'lines' => [['purchase_order_line_public_id' => $order->lines->sole()->public_id, 'delivered_quantity' => 11]]];
    expect(fn () => $receiving->updateReceipt($staleDraft, $overReservedPayload))->toThrow(DomainException::class);
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id, 'accepted_quantity' => 90, 'rejected_quantity' => 0]]]);
    $receipt = $receiving->postReceipt($receipt->fresh());
    $settlement = app(ProcurementSettlementService::class);
    $returnPayload = ['purchase_order_doc_num' => $order->doc_num, 'return_date' => now()->toDateString(), 'reason_code' => 'supplier_defect',
        'lines' => [['receipt_line_public_id' => $receipt->lines->sole()->public_id, 'quantity' => 10]]];
    $return = $settlement->createPurchaseReturn($returnPayload);
    $fixture['user']->givePermissionTo(['purchases.purchase_returns.view', 'purchases.purchase_returns.edit']);
    $this->get(route('admin.purchases.purchase-returns.edit', $return->doc_num))->assertOk()
        ->assertSee('value="supplier_defect" selected', false);
    $before = [$return->getAttributes(), $return->lines->sole()->getAttributes()];
    $this->travel(2)->minutes();
    $updated = $settlement->updatePurchaseReturn($return, $returnPayload);
    expect([$updated->getAttributes(), $updated->lines->sole()->getAttributes()])->toBe($before);
});

test('procurement navigation follows the operational cycle and removes unused supplier shells', function (): void {
    $inventory = require config_path('menu/inventory.php');
    $purchasesMenu = require config_path('menu/purchases.php');
    $purchaseRoutes = collect($purchasesMenu[0]['children'])->pluck('route')->filter()->values();
    expect(collect($inventory[0]['children'])->firstWhere('route', 'admin.purchases.purchase-requisitions.index'))->toBeNull()
        ->and($purchaseRoutes->take(8)->all())->toBe([
            'admin.purchases.purchase-requisitions.index',
            'admin.purchases.supplier-quotation-entry.index',
            'admin.purchases.purchase-orders.index',
            'admin.purchases.supply-orders.index',
            'admin.purchases.goods-receipt-notes.index',
            'admin.purchases.purchase-invoices.index',
            'admin.purchases.purchase-returns.index',
            'admin.purchases.supplier-payments.index',
        ]);
    $purchases = require config_path('erp_ui_screens/purchases.php');
    foreach (['supplier-payments', 'supplier-advances', 'supplier-debit-notes', 'supplier-contracts', 'supplier-contract-milestones', 'supplier-evaluation', 'supplier-price-lists', 'supplier-product-catalog', 'supplier-attachments', 'supplier-complaints'] as $slug) {
        $screen = collect($purchases['screens'])->firstWhere('slug', $slug);
        expect($screen['menu_visible'])->toBeFalse($slug)->and($screen['shell_enabled'])->toBeFalse($slug);
    }
    foreach (['admin.finance.supplier-payments.index', 'admin.finance.supplier-advances.index', 'admin.purchases.supplier-contracts.index', 'admin.purchases.supplier-evaluation.index'] as $route) {
        expect(Route::has($route))->toBeFalse($route);
    }
    expect(Route::has('admin.purchases.supplier-payments.index'))->toBeTrue();
});
