<?php

use App\Services\PostingAccountResolver;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Services\ProductionCostService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceCostAdjustmentService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

/** @return array<string, mixed> */
function purchaseCostFixture(string $method = 'moving_average'): array
{
    expect(DB::getDriverName())->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111099', 'SYNTHETIC PPV Supplier');
    $fixture['firstSupplier']->update(['account_id' => $supplierAccount->id]);
    foreach (['purchase_invoices.approve', 'purchase_invoices.reverse'] as $permission) {
        $fixture['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $accountQuery = Account::query()->forCompany($fixture['company']->id)
        ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::PurchasePriceVariance));
    $accountQuery->delete();
    if ($method !== 'moving_average') {
        $fixture['user']->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.manage', 'web'));
        app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, [
            'branch_store_id' => $fixture['store']->id, 'method' => $method,
            'effective_from' => now()->toDateString(), 'reason' => 'SYNTHETIC purchase cost policy',
        ], $fixture['user']->id);
    }
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(), 'exchange_rate' => 1,
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC PPV regression',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '100', 'unit_price' => '10', 'tax_rate' => '0']],
    ])['record']);
    $orderLine = $order->lines->sole();
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->receive($order, [
        'document_date' => now()->toDateString(), 'supplier_delivery_note' => 'SYNTHETIC-PPV',
        'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => '100']],
    ]);
    $receiptLine = $receipt->lines->sole();
    $receiving->inspect($receipt, [
        'inspection_at' => now()->toDateString(),
        'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'accepted_quantity' => '100',
            'rejected_quantity' => '0', 'disposition' => 'accepted']],
    ]);
    $receiving->postReceipt($receipt->fresh());
    $receiptLine->refresh();
    $movement = InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receiptLine->id)->sole();
    expect($movement->unit_cost)->toBe('10.00000000')->and($movement->total_cost)->toBe('1000.00000000');

    return $fixture + compact('order', 'orderLine', 'receiptLine', 'movement', 'supplierAccount');
}

/** @param array<string, mixed> $fixture */
function purchaseCostInvoice(array $fixture, string $quantity, string $price, int $number = 9851): PurchaseInvoice
{
    $order = $fixture['order'];
    $orderLine = $fixture['orderLine'];
    $receiptLine = $fixture['receiptLine'];
    $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([
        ['quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => '0'],
    ], null, 0);
    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => $number, 'doc_num' => 'SYNTHETIC-PINV-COST-'.$number,
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'supplier_id' => $fixture['firstSupplier']->id,
        'purchase_order_id' => $order->id, 'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->id, 'exchange_rate' => 1,
        ...$calculation['invoice'], 'remaining_amount' => $calculation['invoice']['total_amount'],
        'status' => PurchaseInvoice::StatusDraft,
    ]);
    $invoice->lines()->create([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'line_number' => 1, 'product_id' => $fixture['raw']->id, 'unit_id' => $fixture['unit']->id,
        'purchase_order_line_id' => $orderLine->id, 'receipt_line_id' => $receiptLine->id,
        ...$calculation['lines'][0],
    ]);

    return $invoice;
}

/** @param array<string, mixed> $fixture */
function purchaseCostIssue(array $fixture, string $quantity, array $lineOverrides = [], string $type = InventoryDocument::TypeIssue): InventoryDocument
{
    return app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'source_stock_status' => InventoryTransaction::StatusAvailable,
        'document_type' => $type, 'document_date' => now()->toDateString(),
    ], [['product_id' => $fixture['raw']->id, 'quantity' => $quantity, ...$lineOverrides]]);
}

test('missing PPV capitalizes positive negative and zero variances once and reverses without rewriting history', function (string $price, string $variance): void {
    $fixture = purchaseCostFixture();
    $invoice = purchaseCostInvoice($fixture, '100', $price);
    $original = $fixture['movement']->getRawOriginal();
    $service = app(PurchaseInvoiceService::class);
    expect(app(PurchaseInvoiceCostAdjustmentService::class)->notice($fixture['company']->id))->toBe(__('purchase_invoices.messages.cost_fallback_warning'));
    $posted = $service->approve($invoice);
    expect($posted->status)->toBe(PurchaseInvoice::StatusApproved)
        ->and($fixture['movement']->fresh()->getRawOriginal())->toBe($original)
        ->and($fixture['movement']->fresh()->completedTotalCost())->toBe(bcmul('100', $price, 8))
        ->and(InventoryValueAdjustment::query()->count())->toBe($variance === '0.0000' ? 0 : 1);
    $count = [JournalEntry::query()->count(), InventoryTransaction::query()->count(), InventoryValueAdjustment::query()->count()];
    $service->approve($posted);
    expect([JournalEntry::query()->count(), InventoryTransaction::query()->count(), InventoryValueAdjustment::query()->count()])->toBe($count);
    $grni = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::GoodsReceivedNotInvoiced, 'SYNTHETIC');
    $netGrni = purchaseCostAccountBalance($grni->id);
    expect(bcadd((string) $netGrni, '0', 4))->toBe('0.0000');
    foreach (JournalEntry::query()->with('lines')->get() as $journal) {
        expect(bccomp((string) $journal->lines->sum('debit_amount'), (string) $journal->lines->sum('credit_amount'), 4))->toBe(0);
    }
    $cancelled = $service->reverse($posted, 'SYNTHETIC cost reversal');
    expect($cancelled->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($fixture['movement']->fresh()->getRawOriginal())->toBe($original)
        ->and($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000')
        ->and($fixture['receiptLine']->fresh()->grni_cleared_quantity)->toBe('0.00000000');
    $after = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    $service->reverse($cancelled, 'SYNTHETIC same reversal');
    expect([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($after);
})->with(['positive' => ['12', '200.0000'], 'negative' => ['8', '-200.0000'], 'zero' => ['10', '0.0000']]);

test('partial invoices distribute only their own difference to remaining stock and actual issued expense', function (string $method): void {
    $fixture = purchaseCostFixture($method);
    $issue = purchaseCostIssue($fixture, '40')->transactions->sole();
    $originalIssue = $issue->getRawOriginal();
    $service = app(PurchaseInvoiceService::class);
    $first = $service->approve(purchaseCostInvoice($fixture, '50', '12'));
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1100.00000000')
        ->and($issue->fresh()->completedTotalCost())->toBe('440.00000000');
    $second = $service->approve(purchaseCostInvoice($fixture, '50', '14', 9852));
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1300.00000000')
        ->and($issue->fresh()->completedTotalCost())->toBe('520.00000000')
        ->and($issue->fresh()->getRawOriginal())->toBe($originalIssue);
    $expense = InventoryValueAdjustment::query()->with('lines')->get()->flatMap->lines->where('effect', 'expense')->sum('amount');
    expect(bcadd((string) $expense, '0', 8))->toBe('120.00000000');
    $service->reverse($first, 'SYNTHETIC remove first allocation');
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1200.00000000')
        ->and($issue->fresh()->completedTotalCost())->toBe('480.00000000');
    $service->reverse($second, 'SYNTHETIC remove second allocation');
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000')
        ->and($issue->fresh()->completedTotalCost())->toBe('400.00000000');
})->with(['moving_average', 'fifo']);

test('missing or stale receipt lineage keeps the invoice draft and rolls back all posting', function (): void {
    $fixture = purchaseCostFixture();
    $fixture['receiptLine']->update(['product_snapshot' => []]);
    $invoice = purchaseCostInvoice($fixture, '100', '12');
    $before = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class, __('purchase_invoices.messages.cost_lineage_missing'));
    expect($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft)
        ->and([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($before)
        ->and(InventoryValueAdjustment::query()->count())->toBe(0);
});

test('an accepted return before invoicing clears its cost adjustment through GRNI without billing returned quantities', function (): void {
    $fixture = purchaseCostFixture();
    $settlement = app(ProcurementSettlementService::class);
    $return = $settlement->approvePurchaseReturn($settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num, 'return_date' => now()->toDateString(), 'reason_code' => 'latent_defect',
        'lines' => [['receipt_line_public_id' => $fixture['receiptLine']->public_id, 'quantity' => '20', 'from_quarantine' => false]],
    ]));
    $returned = InventoryTransaction::query()->where('posting_key', 'purchase-return:'.$return->lines->sole()->id)->sole();
    $original = $returned->getRawOriginal();
    $service = app(PurchaseInvoiceService::class);
    $posted = $service->approve(purchaseCostInvoice($fixture, '80', '12'));
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1200.00000000')
        ->and($returned->fresh()->completedTotalCost())->toBe('240.00000000')
        ->and($returned->fresh()->getRawOriginal())->toBe($original);
    $grni = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::GoodsReceivedNotInvoiced, 'SYNTHETIC');
    $balance = purchaseCostAccountBalance($grni->id);
    expect(bcadd((string) $balance, '0', 4))->toBe('0.0000');
    expect(InventoryValueAdjustment::query()->with('lines')->sole()->lines->where('source_transaction_id', $returned->id)
        ->where('account_id', $grni->id)->sole()->effect)->toBe('counterpart');
    $service->reverse($posted, 'SYNTHETIC reverse remaining accepted quantity');
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000')
        ->and($returned->fresh()->completedTotalCost())->toBe('200.00000000');
});

test('cost adjustments follow a warehouse transfer while GRNI clears in the source branch and rounding remains balanced', function (string $price, string $variance, bool $allowed = true): void {
    $fixture = purchaseCostFixture();
    $branch = Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC destination', 'type' => 'warehouse', 'status' => 'active',
    ]);
    $destination = BranchStore::query()->create(['branch_id' => $branch->id, 'name' => 'SYNTHETIC destination stock']);
    $transfer = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'destination_branch_store_id' => $destination->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => now()->toDateString(),
    ], [['product_id' => $fixture['raw']->id, 'quantity' => '50']]);
    if (! $allowed) {
        $role = Role::query()->create([
            'name' => 'SYNTHETIC purchase cost restricted approver', 'guard_name' => 'web',
            'doc_number' => 998121, 'doc_num' => 'SYNTHETIC-PURCHASE-COST-ROLE',
            'company_access_restricted' => true, 'branch_access_restricted' => true, 'financial_period_access_restricted' => true,
        ]);
        foreach (['company' => $fixture['company']->id, 'branch' => $fixture['branch']->id, 'financial_period' => $fixture['period']->id] as $type => $id) {
            DB::table('role_'.$type.'_access')->insert(['role_id' => $role->id, $type.'_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $fixture['user']->assignRole($role);
        app(RequestMemo::class)->forget('operating_scope_access.role_scope.'.$fixture['user']->id);
        app(RequestMemo::class)->forget('operating_scope_access.restricted_ids.branches.'.$role->id);
        $invoice = purchaseCostInvoice($fixture, '100', $price);
        $count = JournalEntry::query()->count();
        expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(AuthorizationException::class)
            ->and($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft)
            ->and(JournalEntry::query()->count())->toBe($count)->and(InventoryValueAdjustment::query()->count())->toBe(0);

        return;
    }
    $posted = app(PurchaseInvoiceService::class)->approve(purchaseCostInvoice($fixture, '100', $price));
    $adjustment = InventoryValueAdjustment::query()->with('lines', 'journalEntry.lines')->sole();
    expect($transfer->transactions->where('branch_store_id', $destination->id)->sole()->fresh()->completedTotalCost())->toBe(bcmul('50', $price, 8))
        ->and($adjustment->journalEntry->branch_id)->toBeNull()
        ->and(bccomp((string) $adjustment->journalEntry->lines->sum('debit_amount'), (string) $adjustment->journalEntry->lines->sum('credit_amount'), 4))->toBe(0);
    $grni = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::GoodsReceivedNotInvoiced, 'SYNTHETIC');
    expect($adjustment->journalEntry->lines->where('account_id', $grni->id)->pluck('branch_id')->unique()->all())->toBe([$fixture['branch']->id]);
    $balance = purchaseCostAccountBalance($grni->id, $fixture['branch']->id);
    expect(bcadd((string) $balance, '0', 4))->toBe('0.0000');
    if (ltrim($variance, '-') === '0.0001') {
        expect($adjustment->lines->where('effect', 'gl_precision'))->toHaveCount(2);
    }
    expect($adjustment->lines->reduce(fn (string $sum, $line): string => bcadd($sum, $line->amount, 8), '0.00000000'))->toBe('0.00000000');
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC transferred cost reversal');
    expect($transfer->transactions->where('branch_store_id', $destination->id)->sole()->fresh()->completedTotalCost())->toBe('500.00000000');
})->with(['positive' => ['12', '200.0000'], 'negative' => ['8', '-200.0000'], 'positive rounding' => ['10.000001', '0.0001'], 'negative rounding' => ['9.999999', '-0.0001'], 'destination access denied' => ['12', '200.0000', false]]);

test('fully invoiced cost adjusted goods can be returned and restored at their actual cost before invoice reversal', function (): void {
    $fixture = purchaseCostFixture('fifo');
    $service = app(PurchaseInvoiceService::class);
    $posted = $service->approve(purchaseCostInvoice($fixture, '100', '12'));
    $settlement = app(ProcurementSettlementService::class);
    $return = $settlement->approvePurchaseReturn($settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num, 'purchase_invoice_doc_num' => $posted->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'latent_defect',
        'lines' => [['receipt_line_public_id' => $fixture['receiptLine']->public_id, 'quantity' => '20', 'from_quarantine' => false]],
    ]));
    $movement = InventoryTransaction::query()->where('posting_key', 'purchase-return:'.$return->lines->sole()->id)->sole();
    expect($movement->total_cost)->toBe('240.00000000')->and($posted->fresh()->credited_amount)->toBe('240.0000');
    expect(fn () => $service->reverse($posted, 'SYNTHETIC active return'))->toThrow(DomainException::class);
    $settlement->reversePurchaseReturn($return, 'SYNTHETIC restore returned goods');
    $cancelled = $service->reverse($posted, 'SYNTHETIC after return restored');
    expect($cancelled->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000')
        ->and($movement->fresh()->completedTotalCost())->toBe('200.00000000');
});

test('returns with a remaining unmatched cost split stop atomically for review instead of using an arbitrary expense account', function (bool $billed): void {
    $fixture = purchaseCostFixture();
    $posted = app(PurchaseInvoiceService::class)->approve(purchaseCostInvoice($fixture, '50', '12'));
    $settlement = app(ProcurementSettlementService::class);
    $return = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $fixture['order']->doc_num,
        ...($billed ? ['purchase_invoice_doc_num' => $posted->doc_num] : []),
        'return_date' => now()->toDateString(), 'reason_code' => 'latent_defect',
        'lines' => [['receipt_line_public_id' => $fixture['receiptLine']->public_id, 'quantity' => '20', 'from_quarantine' => false]],
    ]);
    $before = [JournalEntry::query()->count(), InventoryTransaction::query()->count(), $fixture['receiptLine']->fresh()->getRawOriginal()];
    expect(fn () => $settlement->approvePurchaseReturn($return))->toThrow(DomainException::class, __('purchase_invoices.messages.cost_return_requires_review'));
    expect($return->fresh()->status)->toBe('draft')
        ->and([JournalEntry::query()->count(), InventoryTransaction::query()->count(), $fixture['receiptLine']->fresh()->getRawOriginal()])->toBe($before);
})->with(['billed split' => [true], 'unbilled split' => [false]]);

test('localized draft warnings preserve save access and disappear when an eligible PPV account is configured', function (string $locale): void {
    app()->setLocale($locale);
    $fixture = purchaseCostFixture();
    foreach (['purchase_invoices.view', 'purchase_invoices.create', 'purchases.prices.view'] as $permission) {
        $fixture['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $admin = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $admin);
    $this->actingAs($fixture['user'])->get(route('admin.purchases.purchase-invoices.create'))
        ->assertOk()->assertSee(__('purchase_invoices.messages.cost_fallback_warning'));
    Account::withTrashed()->forCompany($fixture['company']->id)
        ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::PurchasePriceVariance))->restore();
    $this->get(route('admin.purchases.purchase-invoices.create'))->assertOk()->assertDontSee(__('purchase_invoices.messages.cost_fallback_warning'));
    expect(app(PurchaseInvoiceCostAdjustmentService::class)->notice($fixture['company']->id))->toBeNull();
})->with(['ar', 'en']);

test('closed posting periods and missing approval authority prevent cost and invoice posting', function (bool $closePeriod): void {
    $fixture = purchaseCostFixture();
    $invoice = purchaseCostInvoice($fixture, '100', '12');
    if ($closePeriod) {
        $fixture['period']->update(['is_closed' => true]);
    } else {
        $fixture['user']->revokePermissionTo('purchase_invoices.approve');
    }
    $before = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow($closePeriod ? DomainException::class : AuthorizationException::class);
    expect($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft)
        ->and([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($before);
})->with(['closed period' => [true], 'approval denied' => [false]]);

function purchaseCostAccountBalance(int $accountId, ?int $branchId = null): string
{
    return JournalEntryLine::query()->where('account_id', $accountId)
        ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))->get()
        ->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub($line->debit_amount, $line->credit_amount, 4), 4), '0.0000');
}

test('documented legacy production cost follows raw material into WIP finished stock and later issued output', function (bool $receiveOutput, bool $standardCosts = false): void {
    require_once __DIR__.'/../ManufacturingInventorySupport.php';
    $fixture = purchaseCostFixture();
    foreach (['production.orders.edit', 'production.orders.release', 'production.runs.account_materials', 'production.runs.setup', 'production.runs.progress'] as $permission) {
        $fixture['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    ProductComponent::query()->create([
        'company_id' => $fixture['company']->id, 'product_id' => $fixture['finished']->id,
        'component_product_id' => $fixture['raw']->id, 'unit_id' => $fixture['unit']->id,
        'calculation_method' => 'direct', 'quantity' => '2', 'created_by' => $fixture['user']->id,
    ]);
    $fixture['machine'] = ProductionMachine::query()->create([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'code' => 'SYNTHETIC-PURCHASE-COST-MACHINE', 'name' => 'SYNTHETIC purchase cost machine', 'created_by' => $fixture['user']->id,
    ]);
    $fixture['mold'] = ProductionMold::query()->create([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'code' => 'SYNTHETIC-PURCHASE-COST-MOLD', 'name' => 'SYNTHETIC purchase cost mold', 'created_by' => $fixture['user']->id,
    ]);
    $fixture['machine']->molds()->attach($fixture['mold']);
    $fixture['mold']->products()->attach($fixture['finished']);
    $details = manufacturingIntegrityRun($fixture, '10');
    $cycle = $details['cycle'];
    $run = $details['run'];
    $cycle->reserveRun($run, $fixture['store']->id);
    $cycle->issueMaterials($run->fresh(), $fixture['store']->id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run->fresh())));
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [
        $run->requirements->sole()->id => ['consumed_quantity' => '20', 'waste_quantity' => '0'],
    ]);
    if ($receiveOutput) {
        $inspection = $cycle->recordInspection($run->fresh(), ['result' => 'passed', 'disposition' => 'release']);
        $cycle->reviewInspection($inspection, true);
        $output = $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '10')->transactions->sole();
        if ($standardCosts) {
            require_once __DIR__.'/../InventoryStandardCostSupport.php';
            $fixture = standardCostAccounts($fixture);
            $fixture['preparer'] = $fixture['user'];
            $fixture['approver'] = closureSyntheticUser();
            foreach (['inventory.cost_policies.standard.prepare', 'inventory.cost_policies.standard.settle'] as $permission) {
                $fixture['preparer']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
            $fixture['approver']->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.standard.approve', 'web'));
            $standardService = app(InventoryStandardCostService::class);
            $standard = prepareSyntheticStandard($fixture);
            standardCostActor($fixture['approver']);
            $standardService->approveVersion($standard, $fixture['approver']->id, 'SYNTHETIC purchase standard approval');
            standardCostActor($fixture['preparer']);
            $run = $cycle->completeRun($run->fresh());
            $settlement = $standardService->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC purchase standard settlement', $fixture['preparer']->id);
            standardCostActor($fixture['approver']);
            $standardService->approveSettlement($settlement, $fixture['approver']->id, 'SYNTHETIC independent standard approval');
            standardCostActor($fixture['preparer']);
            $invoice = purchaseCostInvoice($fixture, '100', '12');
            $count = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
            expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class, __('purchase_invoices.messages.cost_standard_settlement_required'))
                ->and($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft)
                ->and([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($count)
                ->and($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000');

            return;
        }
        $issuedOutput = purchaseCostIssue([...$fixture, 'raw' => $fixture['finished']], '5', ['batch_lot' => $run->batch_lot], InventoryDocument::TypeSalesDelivery)->transactions->sole();
    }
    $posted = app(PurchaseInvoiceService::class)->approve(purchaseCostInvoice($fixture, '100', '12'));
    $position = app(ProductionCostService::class)->runPosition($run->fresh());
    if ($receiveOutput) {
        expect($output->fresh()->completedTotalCost())->toBe('240.00000000')
            ->and($issuedOutput->fresh()->completedTotalCost())->toBe('120.00000000')
            ->and($position['wip'])->toBe('0.00000000');
        $cogs = app(PostingAccountResolver::class)->resolve($fixture['company']->id, PostingAccountResolver::CostOfGoodsSold, 'SYNTHETIC');
        expect(InventoryValueAdjustment::query()->with('lines')->get()->flatMap->lines->where('account_id', $cogs->id)->where('effect', 'expense')->sole()->amount)->toBe('20.00000000');
    } else {
        expect($position['wip'])->toBe('240.00000000');
    }
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC production cost reversal');
    expect($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000');
    if ($receiveOutput) {
        expect($output->fresh()->completedTotalCost())->toBe('200.00000000')
            ->and($issuedOutput->fresh()->completedTotalCost())->toBe('100.00000000');
    }
})->with(['WIP' => [false], 'received output' => [true], 'standard cost settlement preserved' => [true, true]]);

test('a missing PPV account does not prevent saving a receipt linked invoice draft', function (): void {
    $fixture = purchaseCostFixture();
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'purchase_order_doc_num' => $fixture['order']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'invoice_date' => now()->toDateString(),
        'exchange_rate' => '1', 'payment_type' => 'credit',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $fixture['orderLine']->public_id,
            'receipt_line_public_id' => $fixture['receiptLine']->public_id,
            'quantity' => '100', 'unit_price' => '12', 'tax_rate' => '0',
        ]],
    ])['record'];
    expect($invoice->status)->toBe(PurchaseInvoice::StatusDraft)->and($invoice->total_amount)->toBe('1200.0000')
        ->and($invoice->journal_entry_id)->toBeNull()->and(InventoryValueAdjustment::query()->count())->toBe(0)
        ->and($fixture['movement']->fresh()->completedTotalCost())->toBe('1000.00000000');
});

test('ambiguous PPV configuration blocks posting rather than silently choosing cost capitalization', function (): void {
    $fixture = purchaseCostFixture();
    $account = Account::withTrashed()->forCompany($fixture['company']->id)
        ->whereHas('classification', fn ($query) => $query->where('code', PostingAccountResolver::PurchasePriceVariance))->sole();
    $account->restore();
    $copy = $account->replicate();
    $copy->forceFill([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $fixture['company']->id),
        'account_code' => 'SYNTHETIC-PPV-DUPLICATE', 'is_system' => false,
    ])->save();
    $invoice = purchaseCostInvoice($fixture, '100', '12');
    $before = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class)
        ->and($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft)
        ->and([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($before)
        ->and(InventoryValueAdjustment::query()->count())->toBe(0)
        ->and(app(PurchaseInvoiceCostAdjustmentService::class)->notice($fixture['company']->id))->not->toBe(__('purchase_invoices.messages.cost_fallback_warning'));
});

test('a later invoice adjusts a closed period receipt only through the active open posting period', function (): void {
    $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));
    $fixture = purchaseCostFixture();
    $original = $fixture['movement']->getRawOriginal();
    $fixture['period']->update(['to_date' => '2026-10-08', 'is_closed' => true]);
    $later = FinancialPeriod::query()->create([
        ...app(DocumentNumberService::class)->next('financial_periods', FinancialPeriod::class),
        'company_id' => $fixture['company']->id, 'name' => 'SYNTHETIC later purchase cost period',
        'from_date' => '2026-10-09', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));
    $fixture['period'] = $later;
    procurementUseBranch($fixture, $fixture['branch']);
    $posted = app(PurchaseInvoiceService::class)->approve(purchaseCostInvoice($fixture, '100', '12'));
    $adjustment = InventoryValueAdjustment::query()->with('journalEntry')->sole();
    expect($posted->financial_period_id)->toBe($later->id)
        ->and($adjustment->financial_period_id)->toBe($later->id)
        ->and($adjustment->journalEntry->financial_period_id)->toBe($later->id)
        ->and($fixture['movement']->fresh()->getRawOriginal())->toBe($original);
});

test('new issues use the completed cost and invoice reversal follows their current destination without duplicate loading', function (): void {
    $fixture = purchaseCostFixture('fifo');
    $service = app(PurchaseInvoiceService::class);
    $posted = $service->approve(purchaseCostInvoice($fixture, '100', '12'));
    $adjustment = InventoryValueAdjustment::query()->sole();
    expect(app(PurchaseInvoiceCostAdjustmentService::class)->post($posted, $adjustment->source_snapshot)->id)->toBe($adjustment->id);
    $issue = purchaseCostIssue($fixture, '30')->transactions->sole();
    $original = $issue->getRawOriginal();
    expect($issue->completedTotalCost())->toBe('360.00000000');
    $service->reverse($posted, 'SYNTHETIC later issue cost reversal');
    expect($issue->fresh()->completedTotalCost())->toBe('300.00000000')->and($issue->fresh()->getRawOriginal())->toBe($original)
        ->and(InventoryValueAdjustment::query()->count())->toBe(2);
});
