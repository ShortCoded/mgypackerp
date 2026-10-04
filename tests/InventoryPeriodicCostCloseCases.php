<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\RequestMemo;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlPrecisionCorrectionService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryPeriodicCostCloseReport;
use Modules\Inventory\Services\InventoryPeriodicCostCloseService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Production\Services\ProductionCostService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesFulfillmentService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryPeriodicCostCloseSupport.php';

test('periodic close finalizes source linked raw staging WIP finished goods waste and sales costs without rewriting production history', function (): void {
    $fixture = manufacturingInventoryFixture('-SYNTHETIC-PERIODIC');
    $fixture['branch'] = Branch::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 999813, 'doc_num' => 'SYNTHETIC-PWA-FACTORY', 'name' => 'SYNTHETIC periodic factory',
        'type' => Branch::TypeFactory, 'status' => 'active']);
    $fixture['store'] = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC periodic production warehouse']);
    $fixture['machine']->update(['branch_id' => $fixture['branch']->id]);
    $fixture['mold']->update(['branch_id' => $fixture['branch']->id]);
    request()->session()->put(manufacturingIntegritySession($fixture));
    $fixture['product'] = $fixture['raw'];
    $fixture['preparer'] = $fixture['user'];
    $fixture['approver'] = closureSyntheticUser();
    foreach (['inventory.cost_policies.view', 'inventory.cost_policies.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.cost_policies.view', 'inventory.cost_policies.manage']);
    $fixture = periodicCostFixture($fixture);
    $today = now()->toDateString();
    costTransitionMovement($fixture, $today, InventoryDocument::TypeReceipt, '20', '2');
    $details = manufacturingIntegrityRun($fixture, '10');
    $cycle = $details['cycle'];
    $run = $details['run'];
    $cycle->reserveRun($run, $fixture['store']->id);
    $cycle->issueMaterials($run, $fixture['store']->id);
    $run = $cycle->startRun($cycle->completeSetup($cycle->startSetup($run)));
    $cycle->recordProgress($run, ['good_base_quantity' => '10']);
    $requirement = $run->requirements()->sole();
    $cycle->accountMaterials($run->fresh(), $fixture['store']->id, [$requirement->id => ['consumed_quantity' => '19', 'waste_quantity' => '1']]);
    $cycle->receiveFinishedGoods($run->fresh(), $fixture['store']->id, '10');
    $run = $cycle->completeRun($run->fresh());
    $customer = Customer::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 999813, 'doc_num' => 'SYNTHETIC-PWA-CUSTOMER', 'name' => 'SYNTHETIC periodic customer', 'status' => 'active']);
    $order = SalesOrder::query()->create(['company_id' => $fixture['company']->id,
        'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
        'doc_number' => 999813, 'doc_num' => 'SYNTHETIC-PWA-SO', 'customer_id' => $customer->id,
        'currency_id' => Currency::query()->where('company_id', $fixture['company']->id)->where('is_main', true)->sole()->id,
        'order_date' => $today, 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => SalesOrder::StatusApproved, 'credit_status' => 'approved', 'total_amount' => '100']);
    $salesLine = $order->lines()->create(['line_number' => 1, 'product_id' => $fixture['finished']->id, 'unit_id' => $fixture['unit']->id,
        'description' => $fixture['finished']->name, 'quantity' => '10', 'base_quantity' => '10', 'conversion_factor' => '1',
        'unit_price' => '10', 'line_total' => '100', 'product_classification_snapshot' => Product::ClassificationFinishedProduct]);
    $delivery = app(SalesFulfillmentService::class)->deliver($order, [['sales_order_line_id' => $salesLine->id, 'quantity' => '4']]);
    expect($delivery->transactions->sole()->total_cost)->toBe('15.20000000');
    costTransitionMovement($fixture, $today, InventoryDocument::TypeReceipt, '20', '4');
    $physical = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->orderBy('id')->get()
        ->mapWithKeys(fn ($row): array => [$row->id => $row->getAttributes()])->all();
    $approved = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['from_date' => $today, 'to_date' => $today, 'posting_date' => $today]));
    $cost = app(ProductionCostService::class)->runPosition($run->fresh());
    expect($cost['issued'])->toBe('60.00000000')->and($cost['waste'])->toBe('3.00000000')
        ->and($cost['finished_goods'])->toBe('57.00000000')->and($cost['wip'])->toBe('0.00000000')
        ->and($cost['material_valuation_complete'])->toBeTrue()
        ->and($delivery->transactions->sole()->completedTotalCost())->toBe('22.80000000')
        ->and(InventoryTransaction::query()->whereIn('id', array_keys($physical))->orderBy('id')->get()
            ->mapWithKeys(fn ($row): array => [$row->id => $row->getAttributes()])->all())->toBe($physical);
    foreach ($approved->valueAdjustment->lines->where('effect', 'stock') as $line) {
        expect($line->inventoryTransaction->quantity_in)->toBe('0.00000000')->and($line->inventoryTransaction->quantity_out)->toBe('0.00000000');
    }
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    foreach (['raw_materials', 'wip', 'finished_goods', 'production_waste'] as $key) {
        expect($reconciliation->firstWhere('key', $key)['difference'])->toBe('0.0000');
    }
});

test('periodic policy posts provisional issues and independently finalizes exact stock expense and next period costs', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    expect($issue->transactions->sole()->cost_method)->toBe(InventoryCostPolicy::PeriodicWeightedAverage)
        ->and($issue->transactions->sole()->cost_basis)->toBe('periodic_provisional_moving_average')
        ->and($issue->transactions->sole()->total_cost)->toBe('80.00000000');
    $close = preparePeriodicCost($fixture);
    expect($close->impact_snapshot['period_inputs'][0]['average'])->toBe('15.00000000')
        ->and($close->impact_snapshot['period_inputs'][0]['outflows'][0]['final_cost'])->toBe('120.00000000')
        ->and($close->impact_snapshot['source_total'])->toBe('0.00000000')
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->where('source_type', InventoryPeriodicCostClose::class)->count())->toBe(0);
    $fixture['preparer']->givePermissionTo('inventory.cost_policies.periodic.approve');
    expect(fn () => app(InventoryPeriodicCostCloseService::class)->approve($close, $fixture['preparer']->id, 'SYNTHETIC self approval'))->toThrow(DomainException::class);
    $approved = approvePeriodicCost($fixture, $close);
    $adjustment = $approved->valueAdjustment->load('lines', 'journalEntry.lines');
    $book = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->first();
    expect($approved->status)->toBe(InventoryPeriodicCostClose::StatusFinalized)
        ->and($issue->transactions->sole()->fresh()->total_cost)->toBe('80.00000000')
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('120.00000000')
        ->and(bccomp((string) $book->quantity, '12', 8))->toBe(0)->and(bccomp((string) $book->value, '180', 8))->toBe(0)
        ->and($adjustment->lines->where('effect', 'stock')->sole()->amount)->toBe('-40.00000000')
        ->and($adjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('40.00000000')
        ->and($adjustment->journalEntry->lines->sum('debit_amount'))->toBe(40.0)
        ->and($adjustment->journalEntry->lines->sum('credit_amount'))->toBe(40.0);
    expect(fn () => app(InventoryPeriodicCostCloseService::class)->approve($close, $fixture['approver']->id, 'SYNTHETIC duplicate'))->toThrow(DomainException::class);
    expect(fn () => $approved->update(['reason' => 'Changed history']))->toThrow(DomainException::class);
    expect(fn () => costTransitionMovement($fixture, $day(3), InventoryDocument::TypeIssue, '1'))->toThrow(DomainException::class);
    $next = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '1');
    expect($next->transactions->sole()->total_cost)->toBe('15.00000000')
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('15.00000000');
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciliation->every(fn ($row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue();
});

test('periodic close propagates exact source issue reversals without inventing net stock or expense differences', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    app(InventoryDocumentPostingService::class)->reverse($issue, 'SYNTHETIC source-linked reversal');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture));
    expect($close->impact_snapshot['period_inputs'][0]['average'])->toBe('15.00000000')
        ->and($close->impact_snapshot['period_inputs'][0]['closing_quantity'])->toBe('20.00000000')
        ->and($issue->transactions->sole()->fresh()->total_cost)->toBe('80.00000000')
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('120.00000000')
        ->and($close->valueAdjustment->journal_entry_id)->toBeNull()
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('15.00000000');
});

test('successive periodic periods carry the approved opening value and consume the exact eight digit residue', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '5', '0.33333333');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '2');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '2', '0.99999999');
    approvePeriodicCost($fixture, preparePeriodicCost($fixture));
    $finalIssue = costTransitionMovement($fixture, $day(5), InventoryDocument::TypeIssue, '5');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $next = preparePeriodicCost($fixture, ['from_date' => $day(5), 'to_date' => $day(6), 'posting_date' => $day(6)]);
    expect($next->impact_snapshot['period_inputs'][0]['opening_quantity'])->toBe('5.00000000')
        ->and($next->impact_snapshot['period_inputs'][0]['opening_value'])->toBe('2.61904759')
        ->and($next->impact_snapshot['period_inputs'][0]['outflows'][0]['final_cost'])->toBe('2.61904759');
    approvePeriodicCost($fixture, $next);
    $remainingValue = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->get()
        ->reduce(fn (string $sum, InventoryTransaction $row): string => bcadd($sum, $row->signedValue(), 8), '0.00000000');
    expect($finalIssue->transactions->sole()->completedTotalCost())->toBe('2.61904759')
        ->and($remainingValue)->toBe('0.00000000');
});

test('periodic screens expose the prepared sources in both languages and enforce scoped AJAX and independent HTTP approval', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $session = request()->session()->all();
    $this->actingAs($fixture['preparer'])->withSession($session)->post(route('admin.inventory.periodic-cost-closes.prepare'), [
        'branch_doc_num' => $fixture['branch']->doc_num, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'from_date' => $day(0), 'to_date' => $day(4), 'posting_date' => $day(4),
        'counterpart_account_id' => $fixture['clearing']->id, 'reason' => 'SYNTHETIC HTTP periodic preparation',
    ])->assertRedirect();
    $close = InventoryPeriodicCostClose::query()->where('company_id', $fixture['company']->id)->sole();
    foreach (['ar', 'en'] as $locale) {
        $fixture['preparer']->forceFill(['locale' => $locale])->save();
        app()->setLocale($locale);
        $this->get(route('admin.inventory.periodic-cost-closes.index'))->assertOk();
        $this->get(route('admin.inventory.periodic-cost-closes.show', $close))->assertOk()
            ->assertSee('periodic-cost-inputs', false)->assertSee(__('inventory_periodic_cost.title'));
    }
    $this->getJson(route('admin.inventory.periodic-cost-closes.select2.accounts'))->assertOk()
        ->assertJsonPath('results.0.id', (string) $fixture['clearing']->id)->assertJsonStructure(['pagination' => ['more']]);
    $this->post(route('admin.inventory.periodic-cost-closes.approve', $close), ['approval_reference' => 'SYNTHETIC forbidden approval'])->assertForbidden();
    $this->actingAs($fixture['approver'])->withSession($session)->post(route('admin.inventory.periodic-cost-closes.approve', $close), [
        'approval_reference' => 'SYNTHETIC independent HTTP approval',
    ])->assertRedirect(route('admin.inventory.periodic-cost-closes.show', $close));
    expect($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusFinalized);
});

test('a warehouse periodic close ignores unrelated later receipts of the same product in another warehouse', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $other = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC unrelated future warehouse']);
    $receipt = costTransitionMovement([...$fixture, 'movement_store' => $other], $day(5), InventoryDocument::TypeReceipt, '2', '99');
    $before = $receipt->transactions->sole()->getAttributes();
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture));
    expect($close->impact_snapshot['period_inputs'])->toHaveCount(1)
        ->and($close->impact_snapshot['period_inputs'][0]['average'])->toBe('15.00000000')
        ->and($receipt->transactions->sole()->fresh()->getAttributes())->toBe($before)
        ->and(InventoryTransaction::query()->where('branch_store_id', $other->id)->where('transaction_type', InventoryTransaction::TypeValueAdjustment)->count())->toBe(0);
});

test('closed source periods finalize differences in the explicitly selected open posting period', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $fixture['period']->update(['is_closed' => true]);
    $current = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => 998002, 'doc_num' => 'SYNTHETIC-PWA-CURRENT', 'name' => 'SYNTHETIC periodic posting period',
        'from_date' => $fixture['period']->to_date->copy()->addDay(), 'to_date' => $fixture['period']->to_date->copy()->addMonth(), 'is_closed' => false]);
    request()->session()->put([OperatingContextService::FinancialPeriodIdKey => $current->id,
        OperatingContextService::FinancialPeriodDocNumKey => $current->doc_num]);
    $approved = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['posting_date' => $current->from_date->toDateString()]));
    expect($fixture['period']->fresh()->is_closed)->toBeTrue()->and($approved->financial_period_id)->toBe($fixture['period']->id)
        ->and($approved->posting_period_id)->toBe($current->id)->and($approved->valueAdjustment->financial_period_id)->toBe($current->id)
        ->and($approved->valueAdjustment->journalEntry->financial_period_id)->toBe($current->id)
        ->and($issue->transactions->sole()->fresh()->financial_period_id)->toBe($fixture['period']->id)
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('120.00000000');
});

test('periodic exports preserve all frozen sections eight digit costs and formula safe text with separately enforced export and print permissions', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $first = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10.12345678');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    $last = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20.12345678');
    foreach ([$first, $issue, $last] as $source) {
        periodicSyntheticLegacyJournal($source);
    }
    $approved = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['reason' => '=SUM(1,2) SYNTHETIC untrusted export text']));
    $session = request()->session()->all();
    $this->actingAs($fixture['approver'])->withSession($session);
    $this->get(route('admin.inventory.periodic-cost-closes.export', [$approved, 'format' => 'xlsx']))->assertForbidden();
    $this->get(route('admin.inventory.periodic-cost-closes.print', $approved))->assertForbidden();
    foreach (['inventory.cost_policies.periodic.export', 'inventory.cost_policies.periodic.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['approver']->givePermissionTo($permission);
    }
    foreach (['ar', 'en'] as $locale) {
        $fixture['approver']->forceFill(['locale' => $locale])->save();
        app()->setLocale($locale);
        $approved->load(['scopeBranch', 'scopeStore', 'valueAdjustment.journalEntry']);
        $sections = app(InventoryPeriodicCostCloseReport::class)->sections($approved);
        expect($sections)->toHaveCount(5)->and($sections[4]['rows'])->toHaveCount(6)
            ->and($sections[4]['rows'][0][1])->toBe($first->journalEntry->doc_num);
        $this->get(route('admin.inventory.periodic-cost-closes.show', $approved))->assertOk()
            ->assertSee($first->journalEntry->doc_num)->assertSee($issue->journalEntry->doc_num)->assertSee($last->journalEntry->doc_num);
        $rows = (new InventoryPeriodicCostCloseExport($sections))->array();
        $excel = $this->get(route('admin.inventory.periodic-cost-closes.export', [$approved, 'format' => 'xlsx']))->assertOk();
        $workbook = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $expected) {
                $cell = $workbook->getActiveSheet()->getCell([$column + 1, $index + 1]);
                expect($cell->getValue() ?? '')->toBe($expected)
                    ->and($cell->getDataType())->not->toBe(DataType::TYPE_FORMULA);
            }
        }
        $workbook->disconnectWorksheets();
        $csv = $this->get(route('admin.inventory.periodic-cost-closes.export', [$approved, 'format' => 'csv']))->assertOk();
        $handle = fopen($csv->baseResponse->getFile()->getPathname(), 'r');
        try {
            foreach ($rows as $row) {
                $actual = fgetcsv($handle, separator: ',', enclosure: '"', escape: '');
                foreach ($row as $column => $expected) {
                    $safe = str_starts_with($expected, '=') ? "'".$expected : $expected;
                    expect($actual[$column] ?? '')->toBe($safe);
                }
            }
        } finally {
            fclose($handle);
        }
        $pdf = $this->get(route('admin.inventory.periodic-cost-closes.print', $approved))->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF-');
        file_put_contents('/tmp/mgypack-periodic-close-'.DB::getDriverName().'-'.$locale.'-20261003.pdf', $pdf->getContent());
    }
});

test('a failed periodic journal rolls back the adjustment ledger and completed allocation bases', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $close = preparePeriodicCost($fixture);
    $before = InventoryTransaction::query()->count();
    $this->partialMock(JournalEntryService::class, fn ($mock) => $mock
        ->shouldReceive('createPostedFromSource')->once()->andThrow(new DomainException('SYNTHETIC periodic journal failure')));
    expect(fn () => approvePeriodicCost($fixture, $close))->toThrow(DomainException::class)
        ->and($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusPrepared)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->where('source_type', InventoryPeriodicCostClose::class)->count())->toBe(0)
        ->and(InventoryTransaction::query()->count())->toBe($before)
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('80.00000000');
});

test('periodic approval and report access require authority over every downstream branch reached by a warehouse transfer', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $destinationBranch = Branch::query()->create(['company_id' => $fixture['company']->id,
        'doc_number' => max(998115, (int) Branch::withTrashed()->max('doc_number') + 1),
        'doc_num' => 'SYNTHETIC-PWA-DESTINATION', 'name' => 'SYNTHETIC periodic destination branch', 'type' => Branch::TypeWarehouse, 'status' => 'active']);
    $store = BranchStore::query()->create(['branch_id' => $destinationBranch->id, 'name' => 'SYNTHETIC periodic transferred stock']);
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $transfer = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id, 'destination_branch_store_id' => $store->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => $day(2),
    ], [['product_id' => $fixture['product']->id, 'quantity' => '4']]);
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20');
    $close = preparePeriodicCost($fixture);
    $role = Role::query()->create(['name' => 'SYNTHETIC periodic restricted approver', 'guard_name' => 'web',
        'doc_number' => 998115, 'doc_num' => 'SYNTHETIC-PWA-ROLE', 'company_access_restricted' => true,
        'branch_access_restricted' => true, 'financial_period_access_restricted' => true]);
    foreach (['company' => $fixture['company']->id, 'branch' => $fixture['branch']->id, 'financial_period' => $fixture['period']->id] as $type => $id) {
        DB::table('role_'.$type.'_access')->insert(['role_id' => $role->id, $type.'_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $fixture['approver']->assignRole($role);
    app(RequestMemo::class)->forget("operating_scope_access.role_scope.{$fixture['approver']->id}");
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    expect(fn () => approvePeriodicCost($fixture, $close))->toThrow(AuthorizationException::class)
        ->and($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusPrepared)->and($close->valueAdjustment)->toBeNull();
    $this->actingAs($fixture['approver'])->withSession(request()->session()->all())
        ->get(route('admin.inventory.periodic-cost-closes.show', $close))->assertForbidden();
    DB::table('role_branch_access')->insert(['role_id' => $role->id, 'branch_id' => $destinationBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    app(RequestMemo::class)->forget("operating_scope_access.restricted_ids.branches.{$role->id}");
    $approved = approvePeriodicCost($fixture, $close);
    expect($approved->status)->toBe(InventoryPeriodicCostClose::StatusFinalized)
        ->and($transfer->transactions->where('branch_store_id', $store->id)->sole()->completedTotalCost())->toBe('60.00000000');
    foreach ($approved->valueAdjustment->journalEntry->lines->groupBy('branch_id') as $lines) {
        expect(bccomp((string) $lines->sum('debit_amount'), (string) $lines->sum('credit_amount'), 4))->toBe(0);
    }
});

test('reciprocal source linked warehouse transfers converge to separate anchored periodic costs', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $other = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC reciprocal transfer warehouse']);
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, ['branch_store_id' => $other->id,
        'method' => InventoryCostPolicy::PeriodicWeightedAverage, 'effective_from' => $day(0)], $fixture['preparer']->id);
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    costTransitionMovement([...$fixture, 'movement_store' => $other], $day(1), InventoryDocument::TypeReceipt, '10', '20');
    $transfer = fn ($source, $destination, $date, $quantity) => app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_id' => $fixture['branch']->id, 'branch_store_id' => $source->id, 'destination_branch_store_id' => $destination->id,
        'document_type' => InventoryDocument::TypeTransfer, 'document_date' => $date,
    ], [['product_id' => $fixture['product']->id, 'quantity' => $quantity]]);
    $first = $transfer($fixture['store'], $other, $day(2), '4');
    $second = $transfer($other, $fixture['store'], $day(3), '2');
    $issue = costTransitionMovement($fixture, $day(4), InventoryDocument::TypeIssue, '2');
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture, ['scope_store_id' => null, 'scope_branch_id' => $fixture['branch']->id]));
    expect(array_column($close->impact_snapshot['period_inputs'], 'average'))->toBe(['11.25000000', '17.50000000'])
        ->and($first->transactions->where('quantity_out', '>', 0)->sole()->completedTotalCost())->toBe('45.00000000')
        ->and($second->transactions->where('quantity_out', '>', 0)->sole()->completedTotalCost())->toBe('35.00000000')
        ->and($issue->transactions->sole()->completedTotalCost())->toBe('22.50000000')
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('11.25000000')
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $other->id, $fixture['product']->id))->toBe('17.50000000');
    foreach ($close->valueAdjustment->lines->where('effect', 'stock') as $line) {
        expect($line->inventoryTransaction->quantity_in)->toBe('0.00000000')->and($line->inventoryTransaction->quantity_out)->toBe('0.00000000');
    }
});

test('periodic close rejects stale inputs and can reject and prepare a fresh exact preview', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '5', '0.33333333');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '2');
    $close = preparePeriodicCost($fixture);
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '2', '0.99999999');
    expect(fn () => approvePeriodicCost($fixture, $close))->toThrow(DomainException::class, __('inventory_periodic_cost.errors.stale'));
    expect($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusPrepared)
        ->and($close->valueAdjustment)->toBeNull();
    app(InventoryPeriodicCostCloseService::class)->reject($close, $fixture['approver']->id, 'SYNTHETIC changed sources');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $new = preparePeriodicCost($fixture);
    expect($new->impact_snapshot['period_inputs'][0]['available_value'])->toBe('3.66666663')
        ->and($new->impact_snapshot['period_inputs'][0]['average'])->toBe('0.52380952');
    approvePeriodicCost($fixture, $new);
    expect(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->where('source_type', InventoryPeriodicCostClose::class)->count())->toBe(1);
});

test('periodic close preserves warehouse averages and refuses unpriced inputs or missing approval authority', function (): void {
    $fixture = periodicCostFixture();
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10');
    $other = BranchStore::query()->create(['branch_id' => $fixture['branch']->id, 'name' => 'SYNTHETIC separate periodic warehouse']);
    app(InventoryCostPolicyService::class)->createVersion($fixture['company']->id, ['branch_store_id' => $other->id,
        'method' => InventoryCostPolicy::PeriodicWeightedAverage, 'effective_from' => $day(0)], $fixture['preparer']->id);
    costTransitionMovement([...$fixture, 'movement_store' => $other], $day(1), InventoryDocument::TypeReceipt, '10', '100');
    costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '2');
    costTransitionMovement([...$fixture, 'movement_store' => $other], $day(2), InventoryDocument::TypeIssue, '2');
    $close = preparePeriodicCost($fixture, ['scope_store_id' => null, 'scope_branch_id' => $fixture['branch']->id]);
    expect(array_column($close->impact_snapshot['period_inputs'], 'average'))->toBe(['10.00000000', '100.00000000']);
    $fixture['approver']->revokePermissionTo('inventory.cost_policies.periodic.approve');
    expect(fn () => approvePeriodicCost($fixture, $close))->toThrow(AuthorizationException::class);
    $fixture['approver']->givePermissionTo('inventory.cost_policies.periodic.approve');
    $approved = approvePeriodicCost($fixture, $close);
    expect($approved->status)->toBe(InventoryPeriodicCostClose::StatusFinalized)
        ->and($approved->valueAdjustment->journal_entry_id)->toBeNull();
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    costTransitionMovement($fixture, $day(5), InventoryDocument::TypeReceipt, '2');
    expect(fn () => preparePeriodicCost($fixture, ['from_date' => $day(5), 'to_date' => $day(6), 'posting_date' => $day(6)]))
        ->toThrow(DomainException::class, __('inventory_periodic_cost.errors.unpriced'));
});

test('periodic approval repairs only proven legacy source journal rounding without changing exact costs or historical postings', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    $first = costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '10.12345678');
    $issue = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '8');
    $second = costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '20.12345678');
    foreach ([$first, $issue, $second] as $document) {
        periodicSyntheticLegacyJournal($document);
    }
    $journals = DB::table('journal_entry_lines')->whereIn('journal_entry_id', [$first->journal_entry_id, $issue->journal_entry_id, $second->journal_entry_id])->orderBy('id')->get()->toArray();
    $physical = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->orderBy('id')->get()->map->getAttributes()->all();
    $close = preparePeriodicCost($fixture);
    $precision = collect($close->impact_snapshot['effects'])->where('effect', 'gl_precision');
    expect($precision)->toHaveCount(6)->and($precision->pluck('source_transaction_id')->unique())->toHaveCount(3)
        ->and($precision->where('source_transaction_id', $first->transactions->sole()->id)->pluck('amount')->sort()->values()->all())->toBe(['-0.00010000', '0.00010000']);
    $approved = approvePeriodicCost($fixture, $close);
    expect($approved->valueAdjustment->lines->where('effect', 'gl_precision'))->toHaveCount(6)
        ->and($approved->valueAdjustment->lines->where('effect', 'gl_precision')->every(fn ($row): bool => $row->inventory_transaction_id === null && $row->precision_key !== null))->toBeTrue()
        ->and(InventoryTransaction::query()->whereIn('id', array_column($physical, 'id'))->orderBy('id')->get()->map->getAttributes()->all())->toBe($physical)
        ->and(DB::table('journal_entry_lines')->whereIn('journal_entry_id', [$first->journal_entry_id, $issue->journal_entry_id, $second->journal_entry_id])->orderBy('id')->get()->toArray())->toEqual($journals);
    $balance = InventoryTransaction::query()->where('branch_store_id', $fixture['store']->id)->selectRaw('sum('.InventoryTransaction::signedValueSql().') as value')->first();
    expect(bccomp((string) $balance->value, '181.48148136', 8))->toBe(0);
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id));
    expect($reconciliation->firstWhere('key', 'raw_materials')['subledger'])->toBe('181.4815')
        ->and($reconciliation->firstWhere('key', 'raw_materials')['gl'])->toBe('181.4815')
        ->and($reconciliation->every(fn ($row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue($reconciliation->toJson());
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    expect(fn () => preparePeriodicCost($fixture))->toThrow(DomainException::class);
    expect(InventoryValueAdjustmentLine::query()->whereNotNull('precision_key')->whereHas('adjustment', fn ($q) => $q->where('company_id', $fixture['company']->id))->count())->toBe(6);
});

test('new eight digit source costs round once at the journal boundary and require no historical precision correction', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $date = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $date, InventoryDocument::TypeReceipt, '10', '10.12345678');
    expect($receipt->journalEntry->lines->firstWhere('debit_amount', '101.2346'))->not->toBeNull()
        ->and($receipt->lines->sole()->product_snapshot['inventory_accounting']['booked_amount'])->toBe('101.2346')
        ->and($receipt->transactions->sole()->total_cost)->toBe('101.23456780');
    $close = preparePeriodicCost($fixture);
    expect(collect($close->impact_snapshot['effects'])->where('effect', 'gl_precision'))->toHaveCount(0);
    approvePeriodicCost($fixture, $close);
});

test('precision preparation refuses a mismatched source journal instead of absorbing an unrelated inventory discrepancy', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $date = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $date, InventoryDocument::TypeReceipt, '10', '10.12345678');
    DB::table('journal_entry_lines')->where('journal_entry_id', $receipt->journal_entry_id)->where('debit_amount', '>', 0)->update(['debit_amount' => '101.0000']);
    expect(fn () => preparePeriodicCost($fixture))->toThrow(DomainException::class, __('inventory_periodic_cost.errors.precision_source'));
    expect(InventoryPeriodicCostClose::query()->where('company_id', $fixture['company']->id)->exists())->toBeFalse();
});

test('precision proof rejects changed source currency rate even when its posted groups are balanced', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $receipt = costTransitionMovement($fixture, $fixture['period']->from_date->copy()->addDay()->toDateString(), InventoryDocument::TypeReceipt, '10', '10.12345678');
    DB::table('journal_entries')->where('id', $receipt->journal_entry_id)->update(['exchange_rate' => '2']);
    expect(fn () => preparePeriodicCost($fixture))->toThrow(DomainException::class, __('inventory_periodic_cost.errors.precision_source'));
    expect(InventoryPeriodicCostClose::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
});

test('legacy multi line precision corrections are attributable and full source reversal includes them exactly once', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $date = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = app(InventoryMovementService::class)->createAndPost([
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'financial_period_id' => $fixture['period']->id,
        'branch_store_id' => $fixture['store']->id, 'document_type' => InventoryDocument::TypeReceipt, 'document_date' => $date,
    ], [['product_id' => $fixture['product']->id, 'quantity' => '1', 'unit_cost' => '1.12345678'],
        ['product_id' => $fixture['product']->id, 'quantity' => '1', 'unit_cost' => '2.12345678']]);
    periodicSyntheticLegacyJournal($receipt);
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture));
    expect($close->valueAdjustment->lines->where('effect', 'gl_precision'))->toHaveCount(4)
        ->and($close->valueAdjustment->journalEntry->lines->sum('debit_amount'))->toBe(0.0002);
    app(InventoryDocumentPostingService::class)->reverse($receipt, 'SYNTHETIC reverse source and approved precision', $fixture['period']->from_date->copy()->addDays(5)->toDateString());
    $reversal = JournalEntry::query()->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $receipt->id)->sole();
    expect($reversal->lines->sum('debit_amount'))->toBe(0.0002)->and($reversal->lines->sum('credit_amount'))->toBe(0.0002);
    $accounting = app(InventoryAccountingPostingService::class);
    expect($accounting->completionReversalCoversPrecision($receipt->fresh()))->toBeTrue();
    $wrongPeriod = FinancialPeriod::query()->whereKeyNot($fixture['period']->id)->value('id');
    $sourceReversal = $receipt->fresh()->reversal_journal_entry_id;
    foreach ([$reversal->id, $sourceReversal] as $journalId) {
        $header = (array) DB::table('journal_entries')->where('id', $journalId)->first();
        foreach (['financial_period_id' => $wrongPeriod, 'branch_id' => null,
            'entry_date' => $fixture['period']->from_date->copy()->addDays(6)->toDateString()] as $field => $value) {
            DB::table('journal_entries')->where('id', $journalId)->update([$field => $value]);
            $proof = $journalId === $reversal->id
                ? fn () => $accounting->completionReversalCoversPrecision($receipt->fresh())
                : fn () => app(InventoryGlPrecisionCorrectionService::class)->plan($receipt->transactions()->get());
            expect($proof)->toThrow(DomainException::class, __('inventory_periodic_cost.errors.precision_source'));
            DB::table('journal_entries')->where('id', $journalId)->update([$field => $header[$field]]);
        }
    }
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);
    $next = preparePeriodicCost($fixture, ['from_date' => $fixture['period']->from_date->copy()->addDays(5)->toDateString(),
        'to_date' => $fixture['period']->from_date->copy()->addDays(6)->toDateString(), 'posting_date' => $fixture['period']->from_date->copy()->addDays(6)->toDateString()]);
    expect(collect($next->impact_snapshot['effects'])->where('effect', 'gl_precision'))->toHaveCount(0);
});

test('failure after source precision line creation rolls back every correction and approval atomically', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $receipt = costTransitionMovement($fixture, $fixture['period']->from_date->copy()->addDay()->toDateString(), InventoryDocument::TypeReceipt, '10', '10.12345678');
    periodicSyntheticLegacyJournal($receipt);
    $close = preparePeriodicCost($fixture);
    expect(collect($close->impact_snapshot['effects'])->where('effect', 'gl_precision'))->toHaveCount(2);
    $before = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count();
    $this->partialMock(JournalEntryService::class, fn ($mock) => $mock->shouldReceive('createPostedFromSource')->once()->andThrow(new DomainException('SYNTHETIC precision journal failure')));
    expect(fn () => approvePeriodicCost($fixture, $close))->toThrow(DomainException::class)
        ->and($close->fresh()->status)->toBe(InventoryPeriodicCostClose::StatusPrepared)
        ->and(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and(InventoryValueAdjustmentLine::query()->whereIn('source_transaction_id', $receipt->transactions->modelKeys())->count())->toBe(0)
        ->and(InventoryTransaction::query()->where('company_id', $fixture['company']->id)->count())->toBe($before);
});

test('reversing one periodic issue consumes its actual share of a grouped rounding carry exactly once', function (): void {
    $fixture = periodicCostFixture(isolatedCompany: true);
    $day = fn (int $offset): string => $fixture['period']->from_date->copy()->addDays($offset)->toDateString();
    costTransitionMovement($fixture, $day(1), InventoryDocument::TypeReceipt, '10', '1');
    $first = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '1');
    $last = costTransitionMovement($fixture, $day(2), InventoryDocument::TypeIssue, '1');
    costTransitionMovement($fixture, $day(3), InventoryDocument::TypeReceipt, '10', '1.00008000');
    $close = approvePeriodicCost($fixture, preparePeriodicCost($fixture));
    expect($close->valueAdjustment->lines->where('effect', 'expense')->pluck('amount')->all())->toBe(['0.00004000', '0.00004000']);
    $old = DB::table('journal_entry_lines')->where('journal_entry_id', $last->journal_entry_id)->orderBy('id')->get()->toArray();
    $date = $day(5);
    app(InventoryDocumentPostingService::class)->reverse($last, 'SYNTHETIC grouped source carry reversal', $date);
    $journal = JournalEntry::query()->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $last->id)->sole();
    expect(bcadd((string) $journal->lines->sum('debit_amount'), '0', 4))->toBe('0.0001')
        ->and(bcadd((string) $journal->lines->sum('credit_amount'), '0', 4))->toBe('0.0001')
        ->and(DB::table('journal_entry_lines')->where('journal_entry_id', $last->journal_entry_id)->orderBy('id')->get()->toArray())->toEqual($old);
    $count = JournalEntry::query()->where('company_id', $fixture['company']->id)->count();
    app(InventoryAccountingPostingService::class)->reverse($last, $date);
    expect(JournalEntry::query()->where('company_id', $fixture['company']->id)->count())->toBe($count);
    $raw = collect(app(InventoryGlReconciliationService::class)->reconcile($fixture['company']->id, $fixture['period']->id, $fixture['branch']->id))->firstWhere('key', 'raw_materials');
    expect($raw['subledger'])->toBe('19.0008')->and($raw['gl'])->toBe('19.0008')->and($raw['difference'])->toBe('0.0000');
});
