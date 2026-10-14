<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\UnpricedInventoryReceiptService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\SupplyOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

function directReceivingFixture(): array
{
    $f = procurementFixture();
    test()->seed(PermissionSeeder::class);
    $f['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    test()->actingAs($f['user']);
    $f['firstSupplier']->update(['account_id' => procurementPostingAccount($f['company'], '2111', '2111098', 'SYNTHETIC direct invoice payable')->id]);
    $admin = procurementAdministrativeBranch($f);
    procurementUseBranch($f, $admin);
    $invoices = app(PurchaseInvoiceService::class);
    $invoice = $invoices->approve($invoices->create([
        'supplier_doc_num' => $f['firstSupplier']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num,
        'invoice_date' => now()->toDateString(), 'exchange_rate' => '1', 'payment_type' => 'credit',
        'header_discount_type' => 'percentage', 'header_discount_value' => '10',
        'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
            'quantity' => '10', 'unit_price' => '10', 'tax_rate' => '14']],
    ])['record']);
    expect(DB::table('inventory_transactions')->count())->toBe(0);
    $supply = app(SupplyOrderService::class)->issue(app(SupplyOrderService::class)->create([
        'source_type' => SupplyOrder::SourcePurchaseInvoice, 'source_doc_num' => $invoice->doc_num,
        'issue_date' => now()->toDateString(), 'branch_store_uuid' => $f['store']->public_uuid,
        'lines' => [['purchase_invoice_line_public_id' => $invoice->lines->sole()->public_id, 'ordered_quantity' => '10']],
    ]));
    procurementUseBranch($f, $f['branch']);

    return [...$f, 'admin' => $admin, 'invoice' => $invoice, 'supply' => $supply];
}

function directInspectionPayload(array $f, string $accepted = '10'): array
{
    return ['inspection_at' => now()->toDateString(), 'lines' => [[
        'supply_order_line_public_id' => $f['supply']->lines->sole()->public_id,
        'delivered_quantity' => '10', 'accepted_quantity' => $accepted, 'rejected_quantity' => bcsub('10', $accepted, 8),
        'reason' => $accepted === '10' ? null : 'SYNTHETIC damaged packaging',
    ]]];
}

function directReceiptPayload($inspection, string $quantity): array
{
    return ['document_date' => now()->toDateString(), 'lines' => [[
        'inspection_line_public_id' => $inspection->lines->sole()->public_id, 'supply_order_line_public_id' => $inspection->lines->sole()->supplyOrderLine->public_id, 'delivered_quantity' => $quantity,
    ]]];
}

test('approved direct invoice supports native inspection and full or partial receipts without another GL recognition', function (array $quantities): void {
    $this->withoutExceptionHandling();
    $f = directReceivingFixture();
    $receiving = app(ProcurementReceivingService::class);
    $journalIds = DB::table('journal_entries')->pluck('id')->all();
    $this->get(route('admin.purchases.goods-receipt-inspection.create', $f['supply']))->assertOk();
    $this->postJson(route('admin.purchases.goods-receipt-inspection.store', $f['supply']->doc_num), directInspectionPayload($f))->assertOk();
    $inspection = GoodsReceiptInspection::query()->where('supply_order_id', $f['supply']->id)->sole();
    expect($inspection->purchase_order_id)->toBeNull();
    $this->getJson(route('admin.purchases.select2.supply-orders', ['purpose' => 'inspection']))->assertOk()->assertJsonCount(0, 'results');
    $received = '0';
    foreach ($quantities as $quantity) {
        $this->get(route('admin.purchases.goods-receipt-notes.create', $inspection->doc_num))->assertOk()
            ->assertSee('data-procurement-form', false)->assertSee('assets/js/modules/Purchases/procurement-cycle.js', false);
        $response = $this->postJson(route('admin.purchases.goods-receipt-notes.store', $inspection->doc_num), directReceiptPayload($inspection, $quantity))->assertOk();
        $receipt = UnpricedInventoryReceipt::query()->where('doc_num', $response->json('data.doc_num'))->sole();
        $this->get(route('admin.purchases.goods-receipt-notes.edit', $receipt->doc_num))->assertOk();
        expect(fn () => app(UnpricedInventoryReceiptService::class)->approve($receipt))->toThrow(DomainException::class);
        $this->postJson(route('admin.purchases.goods-receipt-notes.post', $receipt->doc_num))->assertOk();
        $this->postJson(route('admin.purchases.goods-receipt-notes.post', $receipt->doc_num))->assertOk();
        $received = bcadd($received, $quantity, 8);
        $movement = InventoryTransaction::query()->where('source_id', $receipt->id)->sole();
        expect($movement->quantity_in)->toBe(bcadd($quantity, '0', 8))
            ->and($movement->total_cost)->toBe(bcmul($quantity, '9', 8))
            ->and($receipt->fresh()->lines->sole()->grni_journal_entry_id)->toBeNull()
            ->and($f['supply']->fresh()->status)->toBe(bccomp($received, '10', 8) === 0 ? SupplyOrder::StatusFullyReceived : SupplyOrder::StatusPartiallyReceived);
        $this->get(route('admin.purchases.goods-receipt-notes.show', $receipt->doc_num))->assertOk();
        expect(DB::table('journal_entries')->pluck('id')->all())->toBe($journalIds);
    }
    expect((float) InventoryReceiptLayer::query()->sum('remaining_quantity'))->toBe(10.0)
        ->and((float) InventoryTransaction::query()->sum('total_cost'))->toBe(90.0);
    $this->getJson(route('admin.purchases.select2.invoices', ['purpose' => 'supply_order']))->assertOk()->assertJsonCount(0, 'results');
    $this->getJson(route('admin.purchases.select2.inspections', ['purpose' => 'receipt']))->assertOk()->assertJsonCount(0, 'results');
    $before = [DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count(), DB::table('unpriced_inventory_receipts')->count()];
    expect(fn () => $receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '1')))->toThrow(DomainException::class);
    expect([DB::table('inventory_transactions')->count(), DB::table('journal_entries')->count(), DB::table('unpriced_inventory_receipts')->count()])->toBe($before);
})->with(['full' => [['10']], 'partial' => [['4', '6']]]);

test('direct receipt drafts reserve only accepted quantity and block over receipt without stock or GL effects', function (): void {
    $f = directReceivingFixture();
    $receiving = app(ProcurementReceivingService::class);
    $inspection = $receiving->inspectPurchaseSource($f['supply'], directInspectionPayload($f, '8'));
    $first = $receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '5'));
    expect(fn () => $receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '4')))->toThrow(DomainException::class);
    expect(DB::table('inventory_transactions')->count())->toBe(0);
    $receiving->updateReceipt($first, directReceiptPayload($inspection, '3'));
    $second = $receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '5'));
    expect(fn () => $receiving->inspectPurchaseSource($f['supply'], directInspectionPayload($f)))->toThrow(DomainException::class);
    $receiving->postReceipt($first->fresh());
    $receiving->postReceipt($second);
    expect((float) InventoryTransaction::query()->sum('quantity_in'))->toBe(8.0)
        ->and((float) InventoryTransaction::query()->sum('total_cost'))->toBe(72.0)
        ->and($f['supply']->fresh()->status)->toBe(SupplyOrder::StatusPartiallyReceived);
});

test('direct invoice reversal waits for receipt reversal and cancelled supply while receipt reversal is idempotent and preserves invoice GL', function (): void {
    $f = directReceivingFixture();
    $receiving = app(ProcurementReceivingService::class);
    $inspection = $receiving->inspectPurchaseSource($f['supply'], directInspectionPayload($f));
    $receipt = $receiving->postReceipt($receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '10')));
    procurementUseBranch($f, $f['admin']);
    expect(app(PurchaseInvoiceService::class)->reversalPlan($f['invoice'])['can_reverse'])->toBeFalse();
    expect(fn () => app(PurchaseInvoiceService::class)->reverse($f['invoice'], 'SYNTHETIC reversal'))->toThrow(DomainException::class);
    expect(fn () => app(SupplyOrderService::class)->cancel($f['supply'], 'SYNTHETIC reversal'))->toThrow(DomainException::class);
    procurementUseBranch($f, $f['branch']);
    expect($receiving->receiptReversalPlan($receipt)['can_reverse'])->toBeTrue();
    $receiving->reverseReceipt($receipt, 'SYNTHETIC unused receipt correction');
    $receiving->reverseReceipt($receipt, 'SYNTHETIC repeat');
    expect((float) InventoryTransaction::query()->sum('quantity_in'))->toBe(10.0)
        ->and((float) InventoryTransaction::query()->sum('quantity_out'))->toBe(10.0)
        ->and(DB::table('journal_entries')->count())->toBe(1)
        ->and((float) InventoryReceiptLayer::query()->sum('remaining_quantity'))->toBe(0.0);
    procurementUseBranch($f, $f['admin']);
    app(SupplyOrderService::class)->cancel($f['supply']->fresh(), 'SYNTHETIC cancelled after stock reversal');
    app(PurchaseInvoiceService::class)->reverse($f['invoice'], 'SYNTHETIC reverse original recognition');
    expect(DB::table('journal_entries')->count())->toBe(2);
});

test('direct receipt stock consumption or foreign operating context prevents unsafe reversal', function (): void {
    $f = directReceivingFixture();
    $receiving = app(ProcurementReceivingService::class);
    procurementUseBranch($f, $f['admin']);
    expect(fn () => $receiving->inspectPurchaseSource($f['supply'], directInspectionPayload($f)))->toThrow(DomainException::class);
    procurementUseBranch($f, $f['branch']);
    $inspection = $receiving->inspectPurchaseSource($f['supply'], directInspectionPayload($f));
    $receipt = $receiving->postReceipt($receiving->createReceiptFromInspection($inspection, directReceiptPayload($inspection, '10')));
    $movement = InventoryTransaction::query()->sole();
    $issue = InventoryTransaction::query()->create([
        ...$movement->only(['company_id', 'financial_period_id', 'branch_id', 'branch_store_id', 'product_id', 'unit_id', 'stock_status', 'unit_cost']),
        'posting_key' => 'SYNTHETIC-direct-consumption', 'transaction_date' => now()->toDateString(),
        'source_type' => 'SYNTHETIC consumption', 'source_id' => 1, 'source_doc_num' => 'SYNTHETIC-consumption',
        'transaction_type' => 'production_issue', 'quantity_in' => 0, 'quantity_out' => 1,
    ]);
    app(InventoryLayerService::class)->allocateIssue($issue, $movement->id);
    expect($receiving->receiptReversalPlan($receipt)['can_reverse'])->toBeFalse();
    expect(fn () => $receiving->reverseReceipt($receipt, 'SYNTHETIC consumed stock'))->toThrow(DomainException::class);
    expect($receipt->fresh()->posting_status)->toBe('posted')->and(DB::table('journal_entries')->count())->toBe(1);
});
