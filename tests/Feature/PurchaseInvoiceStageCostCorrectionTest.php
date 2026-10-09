<?php

use App\Services\PostingAccountResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryStandardCostService;
use Modules\Inventory\Services\InventoryValueAdjustmentService;
use Modules\Production\Models\ProductionStageOutputCostOwner;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionInvoiceCostCorrectionService;
use Modules\Production\Services\ProductionStageOutputCostService;
use Modules\Production\Services\ProductionStageTransferService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionStageTransferSupport.php';
require_once __DIR__.'/../ProcurementSupport.php';

function stagePriceFixture(): array
{
    expect(DB::getDriverName())->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $f = physicalStageFixture('10', '2026-10-09 12:00:00', function (array $f): array {
        physicalStageActor($f);
        $currency = Currency::query()->where('company_id', $f['company']->id)->orderByDesc('is_main')->firstOrFail();
        $supplierAccount = procurementPostingAccount($f['company'], '2111', '2111099', 'SYNTHETIC stage price supplier');
        $supplier = Supplier::query()->create([...app(DocumentNumberService::class)->nextForCompany('suppliers', Supplier::class, $f['company']->id),
            'company_id' => $f['company']->id, 'name' => 'SYNTHETIC stage price supplier', 'account_id' => $supplierAccount->id, 'status' => 'active']);
        Account::query()->forCompany($f['company']->id)->whereHas('classification', fn ($q) => $q->where('code', PostingAccountResolver::PurchasePriceVariance))->delete();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->approve($orders->create(['supplier_doc_num' => $supplier->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
            'currency_doc_num' => $currency->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
            'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC stage cost evidence',
            'lines' => [['product_doc_num' => $f['raw']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '100', 'unit_price' => '10', 'tax_rate' => '0']]])['record']);
        $orderLine = $order->lines->sole();
        $receiving = app(ProcurementReceivingService::class);
        $receipt = $receiving->receive($order, ['document_date' => now()->toDateString(), 'supplier_delivery_note' => 'SYNTHETIC-STAGE-PRICE',
            'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => '100']]]);
        $receiptLine = $receipt->lines->sole();
        $receiving->inspect($receipt, ['inspection_at' => now()->toDateString(), 'lines' => [['receipt_line_public_id' => $receiptLine->public_id,
            'accepted_quantity' => '100', 'rejected_quantity' => '0', 'disposition' => 'accepted']]]);
        $receiving->postReceipt($receipt->fresh());
        $movement = InventoryTransaction::query()->where('posting_key', 'purchase-receipt:'.$receiptLine->id)->sole();

        return [...$f, ...compact('supplier', 'supplierAccount', 'currency', 'order', 'orderLine', 'receiptLine', 'movement')];
    });
    $permissions = ['production.runs.correct', 'production.runs.correct_later_period', 'purchase_invoices.view', 'purchases.prices.view', 'purchase_invoices.approve', 'purchase_invoices.reverse'];
    foreach ($permissions as $permission) {
        $f['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        $f['approver']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    physicalStageActor($f);

    return $f;
}

function stagePriceInvoice(array $f, string $price = '12'): PurchaseInvoice
{
    $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([['quantity' => '100', 'unit_price' => $price, 'tax_rate' => '0']], null, 0);
    $invoice = PurchaseInvoice::query()->create([...app(DocumentNumberService::class)->nextForCompany('purchase_invoices', PurchaseInvoice::class, $f['company']->id),
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'supplier_id' => $f['supplier']->id, 'purchase_order_id' => $f['order']->id, 'invoice_date' => now()->toDateString(),
        'currency_id' => $f['currency']->id, 'exchange_rate' => '1', ...$calculation['invoice'], 'remaining_amount' => $calculation['invoice']['total_amount'], 'status' => PurchaseInvoice::StatusDraft]);
    $invoice->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'line_number' => 1, 'product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id,
        'purchase_order_line_id' => $f['orderLine']->id, 'receipt_line_id' => $f['receiptLine']->id, ...$calculation['lines'][0]]);

    return $invoice;
}

function stagePricePipeline(array $f, bool $issue = true): array
{
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    physicalStageProgress($f, 1, '2', '2');
    physicalStagePost($f, 1, '1');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][2]->fresh());
    physicalStageProgress($f, 2, '1', '1');
    physicalStageProgress($f, 1, '2', '2');
    physicalStagePost($f, 1, '2');
    physicalStageProgress($f, 2, '2', '2');
    physicalStageActor($f);
    $fg = $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '2')->transactions->sole();
    $sale = $issue ? app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_date' => now()->toDateString(),
        'document_type' => InventoryDocument::TypeSalesDelivery], [['product_id' => $f['finished']->id, 'quantity' => '1']])->transactions->sole() : null;

    return [...$f, ...compact('fg', 'sale')];
}

function stagePricePrepare(array $f, PurchaseInvoice $invoice, bool $reverse = false): object
{
    physicalStageActor($f);
    $service = app(ProductionInvoiceCostCorrectionService::class);
    $preview = $service->preview($invoice, $reverse);
    expect($preview['blocker'])->toBeNull();

    return $service->prepare($invoice, $reverse, 'SYNTHETIC supplier price correction', 'SYNTHETIC invoice and signed price notice', $preview['plan']['posting_date'], $preview['fingerprint']);
}

function stagePriceApproved(array $f, PurchaseInvoice $invoice, bool $reverse = false): object
{
    $row = stagePricePrepare($f, $invoice, $reverse);
    physicalStageActor($f, true);

    return app(ProductionInvoiceCostCorrectionService::class)->approve($invoice, (int) $row->id);
}

function stagePriceImmutable(): array
{
    return collect(['production_stage_transfers', 'production_stage_input_consumptions', 'production_stage_input_adjustments',
        'production_progress_entries', 'quality_inspections', 'inventory_documents', 'inventory_document_lines', 'journal_entries', 'journal_entry_lines'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
}

test('stage price correction freezes approval then posts and reverses values across stages stock and COGS', function (): void {
    $f = stagePricePipeline(stagePriceFixture());
    $invoice = stagePriceInvoice($f);
    $original = stagePriceImmutable();
    $positions = app(ProductionCostService::class)->positions($f['runs']);
    $row = stagePricePrepare($f, $invoice);
    expect(stagePriceImmutable())->toBe($original)->and(InventoryValueAdjustment::query()->count())->toBe(0);
    $service = app(ProductionInvoiceCostCorrectionService::class);
    expect(fn () => $service->approve($invoice, (int) $row->id))->toThrow(DomainException::class, __('production_run_correction.independent_approval'));
    physicalStageActor($f, true);
    $approved = $service->approve($invoice, (int) $row->id);
    expect($approved->status)->toBe('approved')->and(stagePriceImmutable())->toBe($original);
    physicalStageActor($f);
    $posted = app(PurchaseInvoiceService::class)->approve($invoice);
    expect(DB::table('production_run_corrections')->find($row->id)->status)->toBe('consumed')
        ->and($f['fg']->fresh()->completedTotalCost())->toBe('62.00000000')
        ->and($f['sale']->fresh()->completedTotalCost())->toBe('31.00000000')
        ->and(app(ProductionCostService::class)->positions($f['runs']->map->fresh())->pluck('wip')->all())->toBe(['144.00000000', '59.00000000', '45.00000000']);
    $after = stagePriceImmutable();
    foreach ($original as $table => $rows) {
        expect(array_slice($after[$table], 0, count($rows)))->toBe($rows);
    }
    $counts = [InventoryValueAdjustment::query()->count(), JournalEntry::query()->count()];
    app(PurchaseInvoiceService::class)->approve($posted);
    expect([InventoryValueAdjustment::query()->count(), JournalEntry::query()->count()])->toBe($counts);
    $wip = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id))->firstWhere('key', 'wip');
    expect($wip['subledger'])->toBe('248.0000')->and($wip['gl'])->toBe('248.0000')->and($wip['difference'])->toBe('0.0000');
    expect(fn () => app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC no price reversal approval'))->toThrow(DomainException::class);
    stagePriceApproved($f, $posted, true);
    physicalStageActor($f);
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC independently reviewed inverse');
    expect($f['fg']->fresh()->completedTotalCost())->toBe('54.00000000')->and($f['sale']->fresh()->completedTotalCost())->toBe('27.00000000')
        ->and(app(ProductionCostService::class)->positions($f['runs']->map->fresh())->pluck('wip')->all())->toBe($positions->pluck('wip')->all())
        ->and(InventoryValueAdjustment::query()->count())->toBe(2);
});

test('stage price plan rejects bypass missing permission stale evidence and stale approved stock atomically', function (): void {
    $f = stagePriceFixture();
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f);
    $service = app(ProductionInvoiceCostCorrectionService::class);
    $preview = $service->preview($invoice);
    $counts = [JournalEntry::query()->count(), InventoryTransaction::query()->count()];
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class);
    expect(fn () => app(InventoryValueAdjustmentService::class)->postPurchaseVariance($invoice, $preview['plan'], request()))->toThrow(DomainException::class, __('production_invoice_cost.approval_required'));
    $stripped = $preview['plan'];
    unset($stripped['stage_cost_graph']);
    expect(fn () => app(InventoryValueAdjustmentService::class)->postPurchaseVariance($invoice, $stripped, request()))->toThrow(DomainException::class);
    expect([JournalEntry::query()->count(), InventoryTransaction::query()->count()])->toBe($counts);
    $row = stagePricePrepare($f, $invoice);
    physicalStageActor($f, true);
    $f['approver']->revokePermissionTo('production.runs.correct_approve');
    expect(fn () => $service->approve($invoice, (int) $row->id))->toThrow(AuthorizationException::class);
    $f['approver']->givePermissionTo('production.runs.correct_approve');
    $service->approve($invoice, (int) $row->id);
    physicalStageActor($f);
    app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_date' => now()->toDateString(),
        'document_type' => InventoryDocument::TypeIssue], [['product_id' => $f['raw']->id, 'quantity' => '1']]);
    $before = stagePriceImmutable();
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class, __('production_run_correction.stale'));
    expect(stagePriceImmutable())->toBe($before)->and($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft);
});

test('stage cost corrected transfer prices future consumption and partial warehouse receipt without stock from handover', function (string $price): void {
    $f = stagePriceFixture();
    physicalStageProgress($f, 0, '6', '12');
    $owner = physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f, $price);
    stagePriceApproved($f, $invoice);
    physicalStageActor($f);
    $posted = app(PurchaseInvoiceService::class)->approve($invoice);
    $stageUnit = bcadd(bcmul($price, '2', 8), '5', 8);
    expect(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['incoming'])->toBe(bcmul($price, '8', 8));
    physicalStageActor($f, true);
    expect(fn () => app(ProductionStageTransferService::class)->reverse($owner->fresh(), 'SYNTHETIC unsafe original journal reversal'))->toThrow(DomainException::class, __('production_invoice_cost.reverse_price_first'));
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    physicalStageProgress($f, 1, '2', '2');
    $stock = InventoryTransaction::query()->count();
    $transfer = physicalStagePost($f, 1, '1');
    expect($transfer->total_cost)->toBe($stageUnit)->and(InventoryTransaction::query()->count())->toBe($stock);
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][2]->fresh());
    physicalStageProgress($f, 2, '1', '1');
    $fg = null;
    $receiptCost = bcadd($stageUnit, '2', 8);
    if (bccomp($receiptCost, bcadd($receiptCost, '0', 4), 8) !== 0) {
        $beforeReceipt = [InventoryTransaction::query()->count(), JournalEntry::query()->count(), $f['runs'][2]->fresh()->received_base_quantity];
        expect(fn () => $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '1'))
            ->toThrow(DomainException::class, __('production_stage_transfer.precision_requires_owner'));
        expect([InventoryTransaction::query()->count(), JournalEntry::query()->count(), $f['runs'][2]->fresh()->received_base_quantity])->toBe($beforeReceipt);
    } else {
        $fg = $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '1')->transactions->sole();
        expect($fg->completedTotalCost())->toBe($receiptCost);
    }
    stagePriceApproved($f, $posted, true);
    physicalStageActor($f);
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC reverse after later physical consumption');
    $fg ??= $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '1')->transactions->sole();
    expect($fg->fresh()->completedTotalCost())->toBe('27.00000000')
        ->and(app(ProductionStageTransferService::class)->position($f['runs'][1]->fresh())['incoming'])->toBe('80.00000000');
})->with(['positive' => '12', 'negative' => '8', 'fractional' => '12.000001']);

test('stage cost plan displays real frozen AR EN workflow and permission gates', function (): void {
    $f = stagePriceFixture();
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f);
    $row = stagePricePrepare($f, $invoice);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.purchases.purchase-invoices.stage-cost.index', $invoice))
            ->assertOk()->assertSee(__('production_invoice_cost.title'))->assertSee(__('production_invoice_cost.price_evidence'))
            ->assertSee($f['runs'][0]->run_number)->assertDontSee('production_invoice_cost.prepared');
        $this->get(route('admin.production.runs.corrections.index', $f['runs'][0]))->assertOk()->assertSee(__('production_invoice_cost.review'));
    }
    physicalStageActor($f, true);
    $this->postJson(route('admin.purchases.purchase-invoices.stage-cost.approve', [$invoice, $row->id]))->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson(route('admin.purchases.purchase-invoices.stage-cost.reject', [$invoice, $row->id]))->assertOk()->assertJsonPath('data.status', 'rejected');
    stagePriceApproved($f, $invoice);
    physicalStageActor($f, true);
    app(PurchaseInvoiceService::class)->approve($invoice);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.production.runs.stage-transfers.index', $f['runs'][0]))
            ->assertOk()->assertSee(__('production_invoice_cost.effective_cost'))->assertSee(__('production_invoice_cost.original_cost'));
    }
    $f['approver']->revokePermissionTo('purchases.prices.view');
    $this->get(route('admin.purchases.purchase-invoices.stage-cost.index', $invoice))->assertForbidden();
});

test('stage correction preserves standard output prices and settles late material difference through existing independent revision', function (): void {
    require_once __DIR__.'/../InventoryStandardCostSupport.php';
    $f = stagePriceFixture();
    physicalStageProgress($f, 0, '10', '20');
    physicalStagePost($f, 0, '10');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    physicalStageProgress($f, 1, '10', '10');
    physicalStagePost($f, 1, '10');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][2]->fresh());
    physicalStageProgress($f, 2, '10', '10');
    $fg = $f['cycle']->receiveFinishedGoods($f['runs'][2]->fresh(), $f['store']->id, '10')->transactions->sole();
    $run = $f['cycle']->completeRun($f['runs'][2]->fresh());
    $f = standardCostAccounts($f);
    $f['preparer'] = $f['user'];
    $f['preparer']->givePermissionTo([Permission::findOrCreate('inventory.cost_policies.standard.prepare', 'web'), Permission::findOrCreate('inventory.cost_policies.standard.settle', 'web')]);
    $f['approver']->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.standard.approve', 'web'));
    $standards = app(InventoryStandardCostService::class);
    $standard = prepareSyntheticStandard($f);
    physicalStageActor($f, true);
    $standards->approveVersion($standard, $f['approver']->id, 'SYNTHETIC independent approved standard');
    physicalStageActor($f);
    $settlement = $standards->prepareSettlement($run, now()->toDateString(), 'SYNTHETIC stage standard settlement', $f['user']->id);
    physicalStageActor($f, true);
    $standards->approveSettlement($settlement, $f['approver']->id, 'SYNTHETIC independent stage standard approval');
    expect($fg->fresh()->completedTotalCost())->toBe('35.00000000')->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('0.00000000');
    $originalSettlement = $settlement->fresh()->getRawOriginal();
    physicalStageActor($f);
    $sale = app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_date' => now()->toDateString(),
        'document_type' => InventoryDocument::TypeSalesDelivery], [['product_id' => $f['finished']->id, 'quantity' => '5']])->transactions->sole();
    $invoice = stagePriceInvoice($f);
    stagePriceApproved($f, $invoice);
    physicalStageActor($f);
    $posted = app(PurchaseInvoiceService::class)->approve($invoice);
    expect($fg->fresh()->completedTotalCost())->toBe('35.00000000')->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('40.00000000');
    $revision = $standards->prepareSettlement($run->fresh(), now()->toDateString(), 'SYNTHETIC stage late material revision', $f['user']->id);
    physicalStageActor($f, true);
    $standards->approveSettlement($revision, $f['approver']->id, 'SYNTHETIC independent late material revision');
    expect($fg->fresh()->completedTotalCost())->toBe('35.00000000')->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('0.00000000');
    stagePriceApproved($f, $posted, true);
    physicalStageActor($f);
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC standard inverse preserves standard');
    expect($fg->fresh()->completedTotalCost())->toBe('35.00000000')->and($sale->fresh()->completedTotalCost())->toBe('17.50000000')
        ->and(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('-40.00000000')
        ->and($settlement->fresh()->getRawOriginal())->toBe($originalSettlement);
    $inverseRevision = $standards->prepareSettlement($run->fresh(), now()->toDateString(), 'SYNTHETIC stage inverse material revision', $f['user']->id);
    physicalStageActor($f, true);
    $standards->approveSettlement($inverseRevision, $f['approver']->id, 'SYNTHETIC independent inverse material revision');
    expect(app(ProductionCostService::class)->runPosition($run->fresh())['wip'])->toBe('0.00000000')
        ->and($sale->fresh()->completedTotalCost())->toBe('17.50000000');
});

function stagePriceLossOwner(array $f, string $treatment = 'abnormal'): ProductionStageOutputCostOwner
{
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $f['cycle']->startRun($f['runs'][1]->fresh());
    $run = $f['runs'][1]->fresh();
    $f['cycle']->recordProgress($run, ['good_base_quantity' => '1', 'rejected_base_quantity' => '1', 'rework_base_quantity' => '1', 'scrap_base_quantity' => '1',
        'stage_input_base_quantity' => '4', 'material_evidence' => [['requirement_public_id' => $run->requirements->sole()->public_id, 'measured_quantity' => '4']]]);
    $quality = [];
    $type = QualityInspectionType::query()->create(['company_id' => $f['company']->id, 'code' => 'SYNTHETIC-STAGE-DISPOSITION',
        'name' => 'SYNTHETIC stage loss disposition', 'is_final_production' => false, 'is_active' => true]);
    foreach (['rejected' => 'hold', 'rework' => 'rework', 'scrap' => 'scrap'] as $kind => $disposition) {
        $inspection = $f['cycle']->recordInspection($run->fresh(), ['quality_inspection_type_id' => $type->id, 'result' => 'failed',
            'disposition' => $disposition, 'affected_base_quantity' => '1', 'evidence' => 'SYNTHETIC signed stage disposition']);
        physicalStageActor($f, true);
        $f['cycle']->reviewInspection($inspection, true);
        $quality[$kind] = $inspection->id;
        physicalStageActor($f);
    }
    $costs = app(ProductionStageOutputCostService::class);
    $owner = $costs->prepareAllocation($run->fresh(), $treatment, true, $quality, 'SYNTHETIC classified output', 'SYNTHETIC equally completed stage units',
        $costs->preview($run->fresh())['fingerprint'], (string) Str::uuid());
    physicalStageActor($f, true);

    return $costs->approve($owner);
}

test('stage correction reprices rejected rework and abnormal loss with unchanged quality and loss journal', function (): void {
    $f = stagePriceFixture();
    $owner = stagePriceLossOwner($f);
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f);
    $original = stagePriceImmutable();
    stagePriceApproved($f, $invoice);
    physicalStageActor($f);
    $posted = app(PurchaseInvoiceService::class)->approve($invoice);
    $costs = app(ProductionStageOutputCostService::class);
    $position = $costs->position($f['runs'][1]->fresh());
    expect($position['held_components']['rejected']['material'])->toBe('29.00000000')
        ->and($position['held_components']['rework']['material'])->toBe('29.00000000')->and($position['expensed_cost'])->toBe('29.00000000');
    $waste = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id))->firstWhere('key', 'production_waste');
    expect($waste['subledger'])->toBe('29.0000')->and($waste['gl'])->toBe('29.0000')->and($waste['difference'])->toBe('0.0000');
    $after = stagePriceImmutable();
    foreach ($original as $table => $rows) {
        expect(array_slice($after[$table], 0, count($rows)))->toBe($rows);
    }
    stagePriceApproved($f, $posted, true);
    physicalStageActor($f);
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC independently approved classified cost inverse');
    $position = $costs->position($f['runs'][1]->fresh());
    expect($position['held_components']['rejected']['material'])->toBe('25.00000000')->and($position['held_components']['rework']['material'])->toBe('25.00000000')
        ->and($position['expensed_cost'])->toBe('25.00000000')->and($owner->fresh()->status)->toBe('posted');
});

test('stage correction follows recovery after repricing and inverse retains zero recovered hold', function (): void {
    $f = stagePriceFixture();
    $parent = stagePriceLossOwner($f);
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f);
    stagePriceApproved($f, $invoice);
    physicalStageActor($f);
    $posted = app(PurchaseInvoiceService::class)->approve($invoice);
    $costs = app(ProductionStageOutputCostService::class);
    $run = $f['runs'][1]->fresh();
    $inspection = $f['cycle']->recordInspection($run, ['quality_inspection_type_id' => QualityInspectionType::query()->where('company_id', $f['company']->id)->where('code', 'SYNTHETIC-STAGE-DISPOSITION')->sole()->id, 'result' => 'passed',
        'disposition' => 'release', 'affected_base_quantity' => '1', 'evidence' => 'SYNTHETIC rejected unit cleared after review']);
    $recovery = $costs->prepareRecovery($parent, 'rejected', '1', $inspection->id, 'SYNTHETIC recovered output', 'SYNTHETIC signed release evidence',
        $costs->recoveryPreview($parent)['fingerprint'], (string) Str::uuid());
    physicalStageActor($f, true);
    $recovery = $costs->approve($recovery);
    expect($costs->position($run->fresh())['held_components']['rejected']['material'])->toBe('0.00000000')->and($recovery->posting_snapshot['total_cost'])->toBe('29.00000000');
    stagePriceApproved($f, $posted, true);
    physicalStageActor($f);
    app(PurchaseInvoiceService::class)->reverse($posted, 'SYNTHETIC inverse after actual recovered output');
    expect($costs->position($run->fresh())['held_components']['rejected']['material'])->toBe('0.00000000')
        ->and($costs->position($run->fresh())['held_components']['rework']['material'])->toBe('25.00000000')
        ->and($recovery->fresh()->posting_snapshot['total_cost'])->toBe('29.00000000');
    physicalStageActor($f, true);
    $costs->reverse($recovery->fresh(), 'SYNTHETIC reverse unused recovered quantity');
    expect($costs->position($run->fresh())['held_components']['rejected']['material'])->toBe('25.00000000');
});

test('stage correction rejects changed price evidence changed quality and closed period before any accounting writes', function (): void {
    $f = stagePriceFixture();
    physicalStageProgress($f, 0, '6', '12');
    physicalStagePost($f, 0, '4');
    physicalStageActor($f);
    $invoice = stagePriceInvoice($f);
    $row = stagePricePrepare($f, $invoice);
    $definition = json_decode($row->corrected_output, true);
    $definition['price_evidence'] = 'SYNTHETIC altered evidence';
    DB::table('production_run_corrections')->where('id', $row->id)->update(['corrected_output' => json_encode($definition)]);
    physicalStageActor($f, true);
    expect(fn () => app(ProductionInvoiceCostCorrectionService::class)->approve($invoice, (int) $row->id))->toThrow(DomainException::class, __('production_run_correction.stale'));
    DB::table('production_run_corrections')->where('id', $row->id)->update(['corrected_output' => $row->corrected_output]);
    app(ProductionInvoiceCostCorrectionService::class)->approve($invoice, (int) $row->id);
    $inspection = $f['runs'][0]->inspections()->sole();
    DB::table('quality_inspections')->where('id', $inspection->id)->update(['affected_base_quantity' => '5']);
    physicalStageActor($f);
    $before = stagePriceImmutable();
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class);
    expect(stagePriceImmutable())->toBe($before)->and(InventoryValueAdjustment::query()->count())->toBe(0);
    DB::table('quality_inspections')->where('id', $inspection->id)->update(['affected_base_quantity' => $inspection->affected_base_quantity]);
    $f['period']->update(['is_closed' => true]);
    $before = stagePriceImmutable();
    expect(fn () => app(PurchaseInvoiceService::class)->approve($invoice))->toThrow(DomainException::class);
    expect(stagePriceImmutable())->toBe($before)->and(DB::table('production_run_corrections')->find($row->id)->status)->toBe('approved')
        ->and($invoice->fresh()->status)->toBe(PurchaseInvoice::StatusDraft);
});
