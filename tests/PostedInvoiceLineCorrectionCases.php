<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\PostedInvoiceLineCorrection;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PostedInvoiceLineCorrectionService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesIssueOrderService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';
require_once __DIR__.'/ProcurementCorrectionSupport.php';

/** @return array<string,mixed> */
function postedItemCorrectionFixture(string $kind, bool $linked = false, bool $override = false): array
{
    if ($kind === 'sales') {
        $f = salesCycleFixture(true);
        createSalesPriceList($f, null, [['product' => $f['finished'], 'price' => '10'], ['product' => $f['service'], 'price' => '25']]);
        postedItemCorrectionActor($f, $f['user']);
        $f['invoice'] = app(CustomerInvoiceService::class)->post(app(CustomerInvoiceService::class)->createDirect([
            'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'customer_doc_num' => $f['customer']->doc_num,
            'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(), 'exchange_rate' => '1',
            'lines' => [['product_doc_num' => $f['finished']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '3'],
                ['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1']]]));
        $permissions = ['customer_invoices.view', 'customer_invoices.correct_prepare', 'customer_invoices.correct_approve',
            'customer_invoices.create', 'customer_invoices.post', 'sales_orders.invoice'];
    } else {
        $f = procurementCorrectionFixture();
        $f['branch'] = $f['admin'];
        postedItemCorrectionActor($f, $f['user']);
        $data = $linked ? $f['invoice_data'] : ['supplier_doc_num' => $f['firstSupplier']->doc_num, 'currency_doc_num' => $f['currency']->doc_num,
            'invoice_date' => now()->toDateString(), 'exchange_rate' => '1', 'payment_type' => 'credit',
            'direct_procurement_override' => $override, 'direct_procurement_reason' => $override ? 'SYNTHETIC approved direct procurement' : null,
            'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '3', 'unit_price' => '2'],
                ['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_price' => '5']]];
        $f['invoice'] = app(PurchaseInvoiceService::class)->approve(app(PurchaseInvoiceService::class)->create($data)['record']);
        $permissions = ['purchases.prices.view', 'purchase_invoices.view', 'purchase_invoices.reverse', 'purchase_invoices.create', 'purchase_invoices.approve'];
    }
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $f['approver'] = closureSyntheticUser();
    $f['approver']->givePermissionTo($permissions);
    $f['kind'] = $kind;
    $f['prefix'] = $kind === 'sales' ? 'admin.sales.sales-invoices' : 'admin.purchases.purchase-invoices';
    postedItemCorrectionActor($f, $f['user']);

    return $f;
}

/** @param array<string,mixed> $f */
function postedItemCorrectionActor(array $f, User $user): void
{
    test()->actingAs($user)->withSession(salesCycleSession($f));
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($f));
}

/** @param array<string,mixed> $f @return array<string,mixed> */
function postedItemCorrectionPayload(array $f): array
{
    $first = $f['invoice']->lines->first();
    $product = $f['service'];

    return ['source_fingerprint' => app(PostedInvoiceLineCorrectionService::class)->preview($f['kind'], $f['invoice']->doc_num)['fingerprint'],
        'posting_date' => now()->toDateString(), 'reason' => 'SYNTHETIC item recording correction',
        'lines' => [['original_line_public_id' => $first->public_id, 'product_doc_num' => $product->doc_num,
            'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '2', 'unit_price' => '7'],
            ['product_doc_num' => $product->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_price' => '4']]];
}

test('posted invoice correction replaces adds removes and atomically posts independently approved financial effects', function (string $kind): void {
    $f = postedItemCorrectionFixture($kind);
    $beforeLines = $f['invoice']->lines()->get()->map->getAttributes()->all();
    $stock = DB::table('inventory_transactions')->get()->toArray();
    $journals = DB::table('journal_entries')->count();
    $payload = postedItemCorrectionPayload($f);
    $url = route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.proposal_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.proposal_id', $id);
    expect(DB::table('journal_entries')->count())->toBe($journals)
        ->and($f['invoice']->lines()->get()->map->getAttributes()->all())->toBe($beforeLines);
    $approve = route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC self review'])->assertUnprocessable();
    postedItemCorrectionActor($f, $f['approver']);
    if ($kind === 'sales') {
        $childId = PostedInvoiceLineCorrection::findOrFail($id)->sales_correction_id;
        $this->postJson(route('admin.sales.sales-invoices.corrections.approve', [$f['invoice']->doc_num, $childId]),
            ['approval_reason' => 'SYNTHETIC separate recovery attempt'])->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', __('posted_invoice_correction.pending'));
    }
    $replacementId = $this->postJson($approve, ['approval_reason' => 'SYNTHETIC independent review'])->assertOk()->json('data.replacement_invoice_id');
    $class = $kind === 'sales' ? CustomerInvoice::class : PurchaseInvoice::class;
    $replacement = $class::query()->with('lines', 'journalEntry.lines')->findOrFail($replacementId);
    expect($replacement->lines)->toHaveCount(2)->and($replacement->lines->pluck('product_id')->unique()->all())->toBe([$f['service']->id])
        ->and($replacement->total_amount)->toBe($kind === 'sales' ? '75.0000' : '18.0000')
        ->and(DB::table('inventory_transactions')->get()->toArray())->toEqual($stock)
        ->and($f['invoice']->lines()->get()->map->getAttributes()->all())->toEqual($beforeLines)
        ->and(bccomp((string) $replacement->journalEntry->lines->sum('debit_amount'), (string) $replacement->journalEntry->lines->sum('credit_amount'), 4))->toBe(0);
    $proposal = PostedInvoiceLineCorrection::query()->findOrFail($id);
    expect($proposal->impact['before_lines'])->toHaveCount(2)->and($proposal->impact['after_total'])->toBe($replacement->total_amount)
        ->and($proposal->source_snapshot)->not->toBeEmpty()->and($proposal->status)->toBe('approved');
    $count = DB::table('journal_entries')->count();
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC independent review'])->assertOk()->assertJsonPath('data.replacement_invoice_id', $replacementId);
    expect(DB::table('journal_entries')->count())->toBe($count)
        ->and(DB::table('activity_log')->where('event', 'invoice_line_correction.approved')->count())->toBe(1);
})->with(['sales', 'purchase']);

test('posted invoice correction renders Arabic and English impact with allowed and denied actions', function (string $kind): void {
    $f = postedItemCorrectionFixture($kind);
    $this->withoutExceptionHandling();
    $url = route($f['prefix'].'.line-corrections.index', $f['invoice']->doc_num);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get($url)->assertOk()->assertSee(__('posted_invoice_correction.title'))
            ->assertSee('data-correction-add', false)->assertDontSee('posted_invoice_correction.', false);
    }
    $this->withExceptionHandling();
    $payload = postedItemCorrectionPayload($f);
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    $this->get($url)->assertOk()->assertSee(__('posted_invoice_correction.before'))->assertSee(__('posted_invoice_correction.after'));
    $permission = $kind === 'sales' ? 'customer_invoices.correct_prepare' : 'purchase_invoices.create';
    $f['user']->revokePermissionTo($permission);
    $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertForbidden();
    postedItemCorrectionActor($f, $f['approver']);
    $f['approver']->revokePermissionTo($kind === 'sales' ? 'customer_invoices.post' : 'purchase_invoices.approve');
    $this->postJson(route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]), ['approval_reason' => 'SYNTHETIC denied review'])->assertForbidden();
})->with(['sales', 'purchase']);

test('posted invoice correction rejects stale source and changed proposals without effects', function (string $kind): void {
    $f = postedItemCorrectionFixture($kind);
    $payload = postedItemCorrectionPayload($f);
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    $before = DB::table('journal_entries')->count();
    DB::table($f['invoice']->getTable())->where('id', $f['invoice']->id)->update(['notes' => 'SYNTHETIC concurrent change']);
    postedItemCorrectionActor($f, $f['approver']);
    $approve = route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC stale review'])->assertUnprocessable()->assertJsonPath('errors.correction.0', __('posted_invoice_correction.stale'));
    expect(DB::table('journal_entries')->count())->toBe($before)->and(PostedInvoiceLineCorrection::find($id)->status)->toBe('prepared');
    DB::table('posted_invoice_line_corrections')->where('id', $id)->update(['reason' => 'SYNTHETIC tampering']);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC tampered review'])->assertUnprocessable();
    expect(DB::table('journal_entries')->count())->toBe($before);
})->with(['sales', 'purchase']);

test('posted invoice correction rolls back reversal if replacement posting fails', function (string $kind): void {
    $f = postedItemCorrectionFixture($kind);
    $payload = postedItemCorrectionPayload($f);
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    $snapshot = [DB::table('journal_entries')->get()->toArray(), DB::table($f['invoice']->getTable())->get()->toArray(),
        DB::table('inventory_transactions')->get()->toArray()];
    postedItemCorrectionActor($f, $f['approver']);
    JournalEntry::creating(function ($journal) use ($kind, $f): void {
        if ($journal->source_type === ($kind === 'sales' ? 'customer_invoice' : 'purchase_invoice')
            && (int) $journal->source_id !== (int) $f['invoice']->id) {
            throw new DomainException('SYNTHETIC replacement posting failure');
        }
    });
    $this->postJson(route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]), ['approval_reason' => 'SYNTHETIC rollback review'])->assertUnprocessable();
    expect([DB::table('journal_entries')->get()->toArray(), DB::table($f['invoice']->getTable())->get()->toArray(),
        DB::table('inventory_transactions')->get()->toArray()])->toEqual($snapshot)
        ->and(PostedInvoiceLineCorrection::find($id)->status)->toBe('prepared');
})->with(['sales', 'purchase']);

test('purchase linked receipt identity is preserved and product conflict is translated', function (): void {
    $f = postedItemCorrectionFixture('purchase', true);
    $stock = DB::table('inventory_transactions')->get()->toArray();
    $payload = postedItemCorrectionPayload($f);
    $payload['lines'] = $f['invoice']->lines->map(fn ($line): array => ['original_line_public_id' => $line->public_id,
        'product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num,
        'source_line_public_id' => $line->purchaseOrderLine->public_id, 'receipt_line_public_id' => $line->receiptLine->public_id,
        'quantity' => (string) $line->quantity, 'unit_price' => '3'])->all();
    $conflict = $payload;
    $conflict['lines'][0]['product_doc_num'] = $f['service']->doc_num;
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $conflict)->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', __('posted_invoice_correction.source_conflict', ['document' => $f['order']->doc_num]));
    }
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $this->postJson(route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]), ['approval_reason' => 'SYNTHETIC receipt match review'])->assertOk();
    expect(DB::table('inventory_transactions')->get()->toArray())->toEqual($stock)
        ->and($f['receipts']->sum(fn ($receipt) => $receipt->lines()->sum('grni_cleared_quantity')))->toEqual(10);
});

test('purchase reverse permission works without cancel permission and cannot authorize draft cancellation', function (): void {
    $f = postedItemCorrectionFixture('purchase');
    expect($f['user']->can('purchase_invoices.cancel'))->toBeFalse();
    $this->postJson(route($f['prefix'].'.reverse', $f['invoice']->doc_num), ['cancel_reason' => 'SYNTHETIC authorized reversal'])->assertOk();
    $this->postJson(route($f['prefix'].'.cancel', $f['invoice']->doc_num), ['cancel_reason' => 'SYNTHETIC denied cancellation'])->assertForbidden();
    $f['user']->revokePermissionTo('purchase_invoices.reverse');
    Permission::findOrCreate('purchase_invoices.cancel', 'web');
    $f['user']->givePermissionTo('purchase_invoices.cancel');
    $this->postJson(route($f['prefix'].'.reverse', $f['invoice']->doc_num), ['cancel_reason' => 'SYNTHETIC denied reversal'])->assertForbidden();
});

test('posted correction preserves installment proportions and rejects closed periods and foreign contexts', function (string $kind): void {
    $f = postedItemCorrectionFixture($kind);
    $schedules = $f['invoice']->paymentSchedules();
    $schedules->delete();
    $total = $f['invoice']->total_amount;
    $f['invoice']->paymentSchedules()->create(['sequence' => 1, 'line_number' => 1,
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'due_date' => now()->addMonth()->toDateString(), 'amount' => bcmul($total, '0.4', 4), 'payment_source_type' => 'scheduled']);
    $f['invoice']->paymentSchedules()->create(['sequence' => 2, 'line_number' => 2,
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'due_date' => now()->addMonths(2)->toDateString(), 'amount' => bcmul($total, '0.6', 4), 'payment_source_type' => 'scheduled']);
    $payload = postedItemCorrectionPayload($f);
    $this->withSession([OperatingContextService::BranchIdKey => $f['branch']->id + 1000])
        ->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertStatus($kind === 'sales' ? 404 : 403);
    postedItemCorrectionActor($f, $f['user']);
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $approve = route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]);
    $this->travel(2)->seconds();
    $f['period']->update(['is_closed' => true]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC closed period'])->assertUnprocessable();
    expect(PostedInvoiceLineCorrection::find($id)->status)->toBe('prepared');
    $this->travel(2)->seconds();
    $f['period']->update(['is_closed' => false]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC stale period review'])->assertUnprocessable();
    $this->postJson(route($f['prefix'].'.line-corrections.reject', [$f['invoice']->doc_num, $id]))->assertOk();
    postedItemCorrectionActor($f, $f['user']);
    $payload = postedItemCorrectionPayload($f);
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $approve = route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]);
    $class = $kind === 'sales' ? CustomerInvoice::class : PurchaseInvoice::class;
    $replacement = $class::findOrFail($this->postJson($approve, ['approval_reason' => 'SYNTHETIC installment review'])->assertOk()->json('data.replacement_invoice_id'));
    expect($replacement->paymentSchedules)->toHaveCount(2)
        ->and($replacement->paymentSchedules[0]->amount)->toBe($kind === 'sales' ? '30.0000' : '7.2000')
        ->and($replacement->paymentSchedules[1]->amount)->toBe($kind === 'sales' ? '45.0000' : '10.8000');
})->with(['sales', 'purchase']);

test('sales issued delivery and active collection have explicit translated manual recovery conflicts', function (): void {
    require_once __DIR__.'/CustomerInvoiceCorrectionSupport.php';
    $f = postedItemCorrectionFixture('sales');
    $stock = DB::table('inventory_transactions')->count();
    $delivery = app(SalesIssueOrderService::class)->issue($f['invoice']->issueOrder, $f['store'], now()->toDateString());
    $journals = DB::table('journal_entries')->count();
    $payload = postedItemCorrectionPayload($f);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route($f['prefix'].'.line-corrections.index', $f['invoice']->doc_num))->assertOk()
            ->assertSee(__('posted_invoice_correction.delivery_conflict', ['document' => $f['invoice']->doc_num]))->assertDontSee('data-correction-add', false);
        $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertUnprocessable();
    }
    expect(DB::table('journal_entries')->count())->toBe($journals)->and(PostedInvoiceLineCorrection::count())->toBe(0)
        ->and($delivery->fresh()->status)->toBe('posted')->and(DB::table('inventory_transactions')->count())->toBeGreaterThan($stock);
    $receipt = invoiceCorrectionCollection($f, $f['invoice'], '5');
    $preview = app(PostedInvoiceLineCorrectionService::class)->preview('sales', $f['invoice']->doc_num);
    expect(implode(' ', $preview['blockers']))->toContain($receipt->doc_num)
        ->toContain(__('posted_invoice_correction.settlement_conflict', ['document' => $f['invoice']->doc_num]));
});

test('sales request sourced corrections can choose valid source items but cannot detach mismatched products', function (): void {
    $f = postedItemCorrectionFixture('sales');
    $requests = app(SalesRequestService::class);
    $source = $requests->save(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'request_date' => now()->toDateString(),
        'lines' => [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3'],
            ['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '5']]]);
    $source = $requests->transition($requests->transition($source, 'submitted'), 'approved');
    $service = app(CustomerInvoiceService::class);
    $f['invoice'] = $service->post($service->createDirect(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'customer_doc_num' => $f['customer']->doc_num, 'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(),
        'lines' => [['product_doc_num' => $f['finished']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '3',
            'source_request_line_public_id' => $source->lines[0]->public_id]]], $source));
    $payload = postedItemCorrectionPayload($f);
    $payload['lines'] = [$payload['lines'][0]];
    $payload['lines'][0]['source_line_public_id'] = $source->lines[0]->public_id;
    $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertUnprocessable()
        ->assertJsonPath('errors.correction.0', __('posted_invoice_correction.source_conflict', ['document' => $source->doc_num]));
    $payload['lines'][0]['source_line_public_id'] = $source->lines[1]->public_id;
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $this->postJson(route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]), ['approval_reason' => 'SYNTHETIC source match'])->assertOk();
    expect($source->lines()->orderBy('id')->pluck('converted_quantity')->all())->toBe(['0.00000000', '2.00000000']);
});

test('sales service order corrections keep approved source identities and exact invoiced counters', function (): void {
    $f = postedItemCorrectionFixture('sales');
    $alternate = Product::query()->create(['company_id' => $f['company']->id,
        'doc_number' => 8040, 'doc_num' => 'SYNTHETIC-ALT-SERVICE', 'name' => 'SYNTHETIC alternate service',
        'item_unit_id' => $f['unit']->id, 'item_classification' => Product::ClassificationService, 'status' => 'active']);
    createSalesPriceList($f, null, [['product' => $alternate, 'price' => '25']]);
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($f, [
        'lines' => [['product_id' => $f['service']->id, 'unit_id' => $f['unit']->id, 'quantity' => '3', 'unit_price' => '25'],
            ['product_id' => $alternate->id, 'unit_id' => $f['unit']->id, 'quantity' => '4', 'unit_price' => '25']],
        'payment_schedules' => [['title' => 'SYNTHETIC service order', 'due_date' => now()->addMonth()->toDateString(), 'amount' => '175']],
    ])));
    $service = app(CustomerInvoiceService::class);
    $f['invoice'] = $service->post($service->createFromOrder($order, [['sales_order_line_id' => $order->lines[0]->id, 'quantity' => '1']],
        [['due_date' => now()->addMonth()->toDateString(), 'amount' => '25']]));
    $payload = postedItemCorrectionPayload($f);
    $payload['lines'][0]['product_doc_num'] = $alternate->doc_num;
    $payload['lines'][0]['source_line_public_id'] = $order->lines[1]->public_id;
    $payload['lines'][1]['source_line_public_id'] = $order->lines[0]->public_id;
    $id = $this->postJson(route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num), $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $this->postJson(route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]), ['approval_reason' => 'SYNTHETIC approved service sources'])->assertOk();
    expect($order->lines()->orderBy('id')->pluck('invoiced_quantity')->all())->toBe(['1.00000000', '2.00000000']);
});

test('authorized direct procurement corrections retain the override permission boundary for both actors', function (): void {
    $f = postedItemCorrectionFixture('purchase', false, true);
    $payload = postedItemCorrectionPayload($f);
    $store = route($f['prefix'].'.line-corrections.store', $f['invoice']->doc_num);
    $this->postJson($store, $payload)->assertForbidden();
    expect(PostedInvoiceLineCorrection::count())->toBe(0);
    Permission::findOrCreate('purchases.direct_procurement.override', 'web');
    $f['user']->givePermissionTo('purchases.direct_procurement.override');
    $id = $this->postJson($store, $payload)->assertOk()->json('data.proposal_id');
    postedItemCorrectionActor($f, $f['approver']);
    $approve = route($f['prefix'].'.line-corrections.approve', [$f['invoice']->doc_num, $id]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC denied direct review'])->assertForbidden();
    $f['approver']->givePermissionTo('purchases.direct_procurement.override');
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC authorized direct review'])->assertOk();
});
