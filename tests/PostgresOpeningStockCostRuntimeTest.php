<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostBasis;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Inventory\Services\OpeningStockCostCorrectionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/OpeningStockCostCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @return array<string, mixed> */
function openingCostRuntimeFixture(): array
{
    $fixture = openingCorrectionFixture();
    $fixture['company']->update(['name' => 'SYNTHETIC opening cost correction company '.$fixture['company']->id,
        'legal_name' => 'SYNTHETIC opening cost correction company '.$fixture['company']->id]);
    $fixture['source_period'] = $fixture['period'];
    expect(($fixture['day'])(2))->toBeLessThan('2026-10-01');
    $fixture['issue'] = costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '4');
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-OPENING-OCTOBER-'.$fixture['company']->id, 'name' => 'SYNTHETIC October opening correction period',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    session(costTransitionSession($fixture));

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function assertOpeningCostRuntimeReconciliation(array $fixture, string $quantity, string $value): void
{
    $root = InventoryTransaction::query()->where('source_type', OpeningStock::class)->where('source_id', $fixture['opening']->id)->sole();
    expect($root->quantity_in)->toBe('10.00000000')->and($root->unit_cost)->toBe('5.00000000')
        ->and($root->total_cost)->toBe('50.00000000')->and($root->completedTotalCost())->toBe('81.23456780')
        ->and($fixture['opening']->fresh()->lines->sole()->quantity)->toBe('10.0000')
        ->and($fixture['pricingLine']->fresh()->unit_price)->toBe('5.00000000')
        ->and($fixture['issue']->transactions->sole()->fresh()->total_cost)->toBe('20.00000000')
        ->and($fixture['issue']->transactions->sole()->fresh()->completedTotalCost())->toBe('32.49382712')
        ->and($fixture['source_period']->fresh()->is_closed)->toBeTrue();
    $totals = InventoryTransaction::query()->where('company_id', $fixture['company']->id)
        ->where('branch_store_id', $fixture['store']->id)->where('product_id', $fixture['product']->id)
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value')->sole();
    expect(bcadd((string) $totals->quantity, '0', 8))->toBe($quantity)
        ->and(bcadd((string) $totals->value, '0', 8))->toBe($value)
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($fixture['company']->id, $fixture['store']->id, $fixture['product']->id))->toBe('8.12345678');
    $adjustment = InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->sole();
    expect((int) $adjustment->financial_period_id)->toBe((int) $fixture['period']->id)
        ->and((int) $adjustment->journalEntry->financial_period_id)->toBe((int) $fixture['period']->id)
        ->and($adjustment->journalEntry->lines->sum('debit_amount'))->toBe($adjustment->journalEntry->lines->sum('credit_amount'))
        ->and(JournalEntry::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $adjustment->id)->count())->toBe(1);
    $basis = InventoryReceiptCostBasis::query()->where('inventory_value_adjustment_id', $adjustment->id)->get();
    expect($basis->pluck('inventory_receipt_layer_id')->unique()->count())->toBe($basis->count())
        ->and($basis)->toHaveCount(count($adjustment->source_snapshot['layers'] ?? $adjustment->source_snapshot['plan']['layers'] ?? []));
    expect(InventoryTransaction::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $adjustment->id)->get()
        ->every(fn (InventoryTransaction $row): bool => bccomp((string) $row->quantity_in, '0', 8) === 0
        && bccomp((string) $row->quantity_out, '0', 8) === 0))->toBeTrue();
}

test('prepare explicitly synthetic opening cost correction source for ordinary browser review and approval', function (): void {
    if (getenv('MGYPACK_OPENING_COST_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser preparation only.');
    }
    $path = '/tmp/mgypack-opening-cost-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = openingCostRuntimeFixture();
        Permission::findOrCreate('journal_entries.view', 'web');
        foreach ([$fixture['preparer'], $fixture['approver']] as $actor) {
            $actor->givePermissionTo('journal_entries.view');
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        }

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id, 'product_id' => $fixture['product']->id,
            'source_period_id' => $fixture['source_period']->id, 'posting_period_id' => $fixture['period']->id,
            'opening_id' => $fixture['opening']->id, 'opening_doc_num' => $fixture['opening']->doc_num,
            'line_id' => $fixture['line']->id, 'pricing_line_id' => $fixture['pricingLine']->id,
            'pricing_id' => $fixture['pricing']->id, 'issue_id' => $fixture['issue']->id,
            'counterpart_id' => $fixture['counterpart']->id, 'counterpart_code' => $fixture['counterpart']->account_code,
            'preparer_id' => $fixture['preparer']->id, 'preparer' => $fixture['preparer']->username,
            'approver_id' => $fixture['approver']->id, 'approver' => $fixture['approver']->username,
            'target_cost' => '8.12345678', 'posting_date' => '2026-10-03', 'context' => costTransitionSession($fixture)];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('persisted browser opening cost correction preserves source evidence and reconciles stock expense and GL', function (): void {
    if (getenv('MGYPACK_OPENING_COST_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit persisted synthetic browser result verification only.');
    }
    $path = '/tmp/mgypack-opening-cost-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    $proposal = OpeningStockCostCorrection::query()->where('opening_stock_id', $manifest['opening_id'])->where('status', OpeningStockCostCorrection::StatusApproved)->sole();
    expect((int) $proposal->prepared_by)->toBe($manifest['preparer_id'])->and((int) $proposal->approved_by)->toBe($manifest['approver_id'])
        ->and($proposal->unit_costs[(string) $manifest['line_id']])->toBe($manifest['target_cost']);
    $fixture = ['company' => Company::findOrFail($manifest['company_id']), 'period' => FinancialPeriod::findOrFail($manifest['posting_period_id']),
        'source_period' => FinancialPeriod::findOrFail($manifest['source_period_id']), 'opening' => OpeningStock::findOrFail($manifest['opening_id']),
        'store' => BranchStore::findOrFail($manifest['store_id']), 'product' => Product::findOrFail($manifest['product_id']),
        'pricingLine' => OpeningStockPricingLine::findOrFail($manifest['pricing_line_id']), 'issue' => InventoryDocument::findOrFail($manifest['issue_id'])];
    session($manifest['context']);
    assertOpeningCostRuntimeReconciliation($fixture, '6.00000000', '48.74074068');
    expect($proposal->valueAdjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('12.49382712')
        ->and($proposal->valueAdjustment->lines->where('effect', 'stock')->pluck('amount')->all())->toBe(['31.23456780', '-12.49382712'])
        ->and($proposal->valueAdjustment->lines->where('effect', 'stock')->reduce(fn (string $total, $line): string => bcadd($total, $line->amount, 8), '0.00000000'))->toBe('18.74074068');
    $manifest['browser_verified'] = true;
    $manifest['correction_id'] = $proposal->id;
    $manifest['adjustment_id'] = $proposal->inventory_value_adjustment_id;
    $manifest['journal_doc_num'] = $proposal->valueAdjustment->journalEntry->doc_num;
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('simultaneous independent opening cost approvals commit exactly one adjustment journal and basis set without retry', function (): void {
    if (getenv('MGYPACK_OPENING_COST_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated two-connection approval acceptance only.');
    }
    $fixture = openingCostRuntimeFixture();
    $secondApprover = closureSyntheticUser();
    $secondApprover->givePermissionTo('inventory.opening_stock_cost_corrections.approve');
    $proposal = prepareOpeningCorrection($fixture, '8.12345678', '2026-10-03');
    $results = closurePostgresRace([
        ['operation' => 'opening-cost-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'opening-cost-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $secondApprover->id],
    ]);
    expect(collect($results)->pluck('result')->all())->toBe(['applied', 'applied'])
        ->and(collect($results)->pluck('id')->unique()->all())->toBe([$proposal->id])
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and($proposal->fresh()->status)->toBe(OpeningStockCostCorrection::StatusApproved);
    assertOpeningCostRuntimeReconciliation($fixture, '6.00000000', '48.74074068');
});

test('opening approval racing a new downstream issue either posts first or refuses its stale preview before an audited reprepare', function (): void {
    if (getenv('MGYPACK_OPENING_COST_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated approval and movement contention only.');
    }
    $fixture = openingCostRuntimeFixture();
    $proposal = prepareOpeningCorrection($fixture, '8.12345678', '2026-10-03');
    $results = closurePostgresRace([
        ['operation' => 'opening-cost-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'issue', 'user' => $fixture['preparer']->id, 'header' => ['company_id' => $fixture['company']->id,
            'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
            'source_stock_status' => InventoryTransaction::StatusAvailable, 'document_type' => InventoryDocument::TypeIssue, 'document_date' => '2026-10-02'],
            'lines' => [['product_id' => $fixture['product']->id, 'quantity' => '1']]],
    ]);
    expect(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and($results[1]['result'])->toBe('applied')->and($results[0]['result'])->toBeIn(['applied', 'blocked']);
    if ($results[0]['result'] === 'blocked') {
        expect(InventoryValueAdjustment::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
            ->and($proposal->fresh()->status)->toBe(OpeningStockCostCorrection::StatusPending);
        $this->actingAs($fixture['approver']);
        request()->setUserResolver(fn (): User => $fixture['approver']);
        app(OpeningStockCostCorrectionService::class)->reject(request(), $fixture['opening'], $proposal, 'SYNTHETIC stale downstream preview rejected');
        $this->actingAs($fixture['preparer']);
        request()->setUserResolver(fn (): User => $fixture['preparer']);
        $proposal = prepareOpeningCorrection($fixture, '8.12345678', '2026-10-03');
        $this->actingAs($fixture['approver']);
        request()->setUserResolver(fn (): User => $fixture['approver']);
        app(OpeningStockCostCorrectionService::class)->approve(request(), $fixture['opening'], $proposal, 'SYNTHETIC new lineage independently reviewed');
    }
    assertOpeningCostRuntimeReconciliation($fixture, '5.00000000', '40.61728390');
    $newIssue = InventoryDocument::findOrFail($results[1]['id']);
    expect($newIssue->transactions->sole()->completedTotalCost())->toBe('8.12345678');
});
