<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;
use Modules\Inventory\Services\OpeningStockQuantityCorrectionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/OpeningStockQuantityCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @return array<string, mixed> */
function openingQuantityRuntimeFixture(): array
{
    $fixture = openingQuantityCorrectionFixture();
    $fixture['company']->update(['name' => 'SYNTHETIC opening quantity company '.$fixture['company']->id,
        'legal_name' => 'SYNTHETIC opening quantity company '.$fixture['company']->id]);
    $fixture['source_period'] = $fixture['period'];
    expect(($fixture['day'])(2))->toBeLessThan('2026-10-01');
    $fixture['issue'] = costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeIssue, '4');
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-OPENING-QUANTITY-OCT-'.$fixture['company']->id, 'name' => 'SYNTHETIC October quantity correction period',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    session(costTransitionSession($fixture));

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function assertOpeningQuantityRuntimeReconciliation(array $fixture, string $quantity, string $value): void
{
    $root = InventoryTransaction::query()->where('source_type', OpeningStock::class)->where('source_id', $fixture['opening']->id)->sole();
    expect($root->quantity_in)->toBe('10.00000000')->and($root->unit_cost)->toBe('5.00000000')->and($root->total_cost)->toBe('50.00000000')
        ->and($fixture['opening']->fresh()->financial_period_id)->toBe($fixture['source_period']->id)
        ->and($fixture['source_period']->fresh()->is_closed)->toBeTrue()
        ->and(OpeningStockLine::findOrFail($fixture['line']->id)->quantity)->toBe('10.0000');
    $rows = InventoryTransaction::query()->where('company_id', $fixture['company']->id)
        ->where('branch_store_id', $fixture['store']->id)->where('product_id', $fixture['product']->id)->get();
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0.00000000'))->toBe($quantity)
        ->and($rows->reduce(fn (string $sum, $row): string => bcadd($sum, $row->signedValue(), 8), '0.00000000'))->toBe($value)
        ->and(bcadd((string) InventoryReceiptLayer::query()->where('company_id', $fixture['company']->id)->sum('remaining_quantity'), '0', 8))->toBe($quantity);
    foreach (JournalEntry::query()->where('company_id', $fixture['company']->id)->with('lines')->get() as $journal) {
        expect(bcadd((string) $journal->lines->sum('debit_amount'), '0', 4))->toBe(bcadd((string) $journal->lines->sum('credit_amount'), '0', 4));
    }
}

test('prepare an explicitly synthetic closed opening source for ordinary quantity correction browser acceptance', function (): void {
    if (getenv('MGYPACK_OPENING_QUANTITY_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture only.');
    }
    $path = '/tmp/mgypack-opening-quantity-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = openingQuantityRuntimeFixture();
        Permission::findOrCreate('journal_entries.view', 'web');
        Permission::findOrCreate('inventory.documents.view', 'web');
        foreach ([$fixture['preparer'], $fixture['approver']] as $actor) {
            $actor->givePermissionTo(['journal_entries.view', 'inventory.documents.view']);
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        }

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id, 'product_id' => $fixture['product']->id,
            'source_period_id' => $fixture['source_period']->id, 'posting_period_id' => $fixture['period']->id,
            'opening_id' => $fixture['opening']->id, 'opening_doc_num' => $fixture['opening']->doc_num,
            'line_id' => $fixture['line']->id, 'issue_id' => $fixture['issue']->id,
            'preparer_id' => $fixture['preparer']->id, 'preparer' => $fixture['preparer']->username,
            'approver_id' => $fixture['approver']->id, 'approver' => $fixture['approver']->username,
            'posting_date' => '2026-10-03', 'context' => costTransitionSession($fixture)];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('persisted browser quantity correction preserves original history and reconciles exact stock layers and GL', function (): void {
    if (getenv('MGYPACK_OPENING_QUANTITY_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit persisted browser result verification only.');
    }
    $path = '/tmp/mgypack-opening-quantity-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    $approved = OpeningStockQuantityCorrection::query()->where('opening_stock_id', $manifest['opening_id'])
        ->where('status', OpeningStockQuantityCorrection::StatusApproved)->orderBy('id')->get();
    expect($approved)->toHaveCount(2);
    foreach ($approved as $proposal) {
        expect((int) $proposal->prepared_by)->toBe($manifest['preparer_id'])->and((int) $proposal->approved_by)->toBe($manifest['approver_id'])
            ->and((int) $proposal->posting_period_id)->toBe($manifest['posting_period_id'])
            ->and($proposal->document_links)->toHaveCount(1);
        $document = InventoryDocument::query()->with('transactions', 'journalEntry')->findOrFail($proposal->document_links[0]['id']);
        expect($document->transactions->sole()->total_cost)->toBe($proposal->plan['lines'][0]['value']);
    }
    expect($approved[0]->targets[(string) $manifest['line_id']]['target_quantity'])->toBe('8.0000')
        ->and($approved[1]->targets[(string) $manifest['line_id']]['target_quantity'])->toBe('11.0000')
        ->and($approved[1]->targets[(string) $manifest['line_id']]['unit_cost'])->toBe('7.12345678');
    $fixture = ['company' => Company::findOrFail($manifest['company_id']), 'source_period' => FinancialPeriod::findOrFail($manifest['source_period_id']),
        'opening' => OpeningStock::findOrFail($manifest['opening_id']), 'line' => OpeningStockLine::findOrFail($manifest['line_id']),
        'store' => BranchStore::findOrFail($manifest['store_id']), 'product' => Product::findOrFail($manifest['product_id'])];
    assertOpeningQuantityRuntimeReconciliation($fixture, '7.00000000', '41.37037034');
    $manifest['browser_verified'] = true;
    $manifest['correction_ids'] = $approved->modelKeys();
    $manifest['movement_doc_nums'] = $approved->flatMap(fn ($proposal) => collect($proposal->document_links)->pluck('doc_num'))->all();
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('simultaneous independent opening quantity approvals post exactly one stock movement and journal without retry', function (): void {
    if (getenv('MGYPACK_OPENING_QUANTITY_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated two-connection approval acceptance only.');
    }
    $fixture = openingQuantityRuntimeFixture();
    $secondApprover = closureSyntheticUser();
    $secondApprover->givePermissionTo('inventory.opening_stock_quantity_corrections.approve');
    $proposal = prepareOpeningQuantityCorrection($fixture, '12', '2026-10-03', '7.12345678');
    $results = closurePostgresRace([
        ['operation' => 'opening-quantity-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'opening-quantity-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $secondApprover->id],
    ]);
    expect(collect($results)->pluck('result')->all())->toBe(['applied', 'applied'])
        ->and(collect($results)->pluck('id')->unique()->all())->toBe([$proposal->id])
        ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusApproved)
        ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->where('source_document_id', $proposal->id)->count())->toBe(1);
    assertOpeningQuantityRuntimeReconciliation($fixture, '8.00000000', '44.24691356');
});

test('opening quantity decrease racing stock consumption never consumes the same available units twice', function (): void {
    if (getenv('MGYPACK_OPENING_QUANTITY_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated stock contention only.');
    }
    $fixture = openingQuantityRuntimeFixture();
    $proposal = prepareOpeningQuantityCorrection($fixture, '8', '2026-10-03');
    $results = closurePostgresRace([
        ['operation' => 'opening-quantity-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'issue', 'user' => $fixture['preparer']->id, 'header' => ['company_id' => $fixture['company']->id,
            'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
            'source_stock_status' => InventoryTransaction::StatusAvailable, 'document_type' => InventoryDocument::TypeIssue, 'document_date' => '2026-10-03'],
            'lines' => [['product_id' => $fixture['product']->id, 'quantity' => '5']]],
    ]);
    expect(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and(collect($results)->where('result', 'applied'))->toHaveCount(1)
        ->and(collect($results)->where('result', 'blocked'))->toHaveCount(1);
    if ($results[0]['result'] === 'applied') {
        expect($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusApproved);
        assertOpeningQuantityRuntimeReconciliation($fixture, '4.00000000', '20.00000000');
    } else {
        expect($proposal->fresh()->status)->toBe(OpeningStockQuantityCorrection::StatusPending)
            ->and(InventoryDocument::query()->where('source_document_type', OpeningStockQuantityCorrection::class)->where('source_document_id', $proposal->id)->count())->toBe(0);
        assertOpeningQuantityRuntimeReconciliation($fixture, '1.00000000', '5.00000000');
    }
});

/** @return array<string, mixed> */
function openingSerialQuantityRuntimeFixture(string $method = InventoryCostPolicy::PeriodicWeightedAverage): array
{
    $fixture = serializedOpeningQuantityCorrectionFixture($method);
    $fixture['company']->update(['name' => 'SYNTHETIC serialized quantity company '.$fixture['company']->id,
        'legal_name' => 'SYNTHETIC serialized quantity company '.$fixture['company']->id]);
    $fixture['mixed_receipt'] = costTransitionMovement($fixture, ($fixture['day'])(2), InventoryDocument::TypeAdjustmentIn, '2', '7',
        lineOverrides: ['serial_numbers' => ['SYNTHETIC-MIXED-NEW-A', 'SYNTHETIC-MIXED-NEW-B']]);
    $fixture['source_period'] = $fixture['period'];
    $fixture['source_period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
    $fixture['period'] = FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-SERIAL-QUANTITY-OCT-'.$fixture['company']->id, 'name' => 'SYNTHETIC October serial quantity correction',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false,
    ]);
    session(costTransitionSession($fixture));

    return $fixture;
}

test('prepare an explicitly synthetic serialized source for actual quantity correction browser acceptance', function (): void {
    if (getenv('MGYPACK_OPENING_SERIAL_QUANTITY_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated serialized browser fixture only.');
    }
    $path = '/tmp/mgypack-opening-serial-quantity-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = openingSerialQuantityRuntimeFixture();
        foreach (['journal_entries.view', 'inventory.documents.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach ([$fixture['preparer'], $fixture['approver']] as $actor) {
            $actor->givePermissionTo(['journal_entries.view', 'inventory.documents.view']);
            $actor->update(['locale' => 'ar']);
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        }

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id, 'product_id' => $fixture['product']->id,
            'product_code' => $fixture['product']->doc_num, 'source_period_id' => $fixture['source_period']->id,
            'posting_period_id' => $fixture['period']->id, 'opening_id' => $fixture['opening']->id,
            'opening_doc_num' => $fixture['opening']->doc_num, 'line_id' => $fixture['line']->id,
            'root_id' => $fixture['root']->id, 'method' => InventoryCostPolicy::PeriodicWeightedAverage,
            'serial_layers' => $fixture['serialLayers']->map(fn ($layer): int => $layer->id)->all(),
            'preparer_id' => $fixture['preparer']->id, 'preparer' => $fixture['preparer']->username,
            'approver_id' => $fixture['approver']->id, 'approver' => $fixture['approver']->username,
            'posting_date' => '2026-10-03', 'context' => costTransitionSession($fixture)];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('persisted serialized browser corrections preserve source identities and reconcile per-unit stock allocation and journal values', function (): void {
    if (getenv('MGYPACK_OPENING_SERIAL_QUANTITY_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit persisted serial browser result verification only.');
    }
    $path = '/tmp/mgypack-opening-serial-quantity-runtime-20261003.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and(Company::findOrFail($manifest['company_id'])->name)->toContain('SYNTHETIC');
    $proposals = OpeningStockQuantityCorrection::query()->where('opening_stock_id', $manifest['opening_id'])
        ->where('status', OpeningStockQuantityCorrection::StatusApproved)->orderBy('id')->get();
    expect($proposals)->toHaveCount(2);
    foreach ($proposals as $proposal) {
        expect((int) $proposal->prepared_by)->toBe($manifest['preparer_id'])
            ->and((int) $proposal->approved_by)->toBe($manifest['approver_id'])
            ->and((int) $proposal->posting_period_id)->toBe($manifest['posting_period_id']);
        $document = InventoryDocument::query()->with('transactions', 'journalEntry.lines')->findOrFail($proposal->document_links[0]['id']);
        expect($document->transactions)->toHaveCount(2)
            ->and($document->transactions->pluck('cost_method')->unique()->all())->toBe([$manifest['method']]);
        $expectedGl = '0.0000';
        foreach ($proposal->plan['lines'][0]['serial_units'] as $unit) {
            $transaction = $document->transactions->first(fn ($row): bool => $row->serialIdentity->serial_number === $unit['serial_number']);
            expect($transaction)->not->toBeNull()->and($transaction->total_cost)->toBe($unit['value']);
            $expectedGl = bcadd($expectedGl, $unit['accounting_contract']['booked_amount'], 4);
            if ($proposal->plan['lines'][0]['direction'] === 'decrease') {
                $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $transaction->id)->sole();
                expect($allocation->inventory_receipt_layer_id)->toBe($unit['selected_receipt_layer_id'])
                    ->and($allocation->cost_total_snapshot)->toBe('4.80000000');
            }
        }
        expect(bcadd((string) $document->journalEntry->lines->sum('debit_amount'), '0', 4))->toBe($expectedGl)
            ->and(bcadd((string) $document->journalEntry->lines->sum('credit_amount'), '0', 4))->toBe($expectedGl);
    }
    $root = InventoryTransaction::findOrFail($manifest['root_id']);
    expect($root->quantity_in)->toBe('3.00000000')->and($root->total_cost)->toBe('10.00000000')
        ->and(OpeningStockLine::findOrFail($manifest['line_id'])->quantity)->toBe('3.0000')
        ->and(FinancialPeriod::findOrFail($manifest['source_period_id'])->is_closed)->toBeTrue();
    $transactions = InventoryTransaction::query()->where('company_id', $manifest['company_id'])->get();
    expect($transactions->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0.00000000'))->toBe('5.00000000')
        ->and($transactions->reduce(fn (string $sum, $row): string => bcadd($sum, $row->signedValue(), 8), '0.00000000'))->toBe('28.64691356')
        ->and(InventorySerialIdentity::query()->where('company_id', $manifest['company_id'])->whereNotNull('current_receipt_layer_id')->count())->toBe(5);
    $manifest['browser_verified'] = true;
    $manifest['correction_ids'] = $proposals->modelKeys();
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

test('a serialized opening decrease racing an ordinary issue consumes the selected physical identity exactly once', function (): void {
    if (getenv('MGYPACK_OPENING_SERIAL_QUANTITY_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated serialized stock contention only.');
    }
    $fixture = openingSerialQuantityRuntimeFixture(InventoryCostPolicy::MovingAverage);
    $layer = $fixture['serialLayers']->last();
    $proposal = app(OpeningStockQuantityCorrectionService::class)->prepare(request(), $fixture['opening'], [
        'posting_date' => '2026-10-03', 'reason' => 'SYNTHETIC same physical identity contention',
        'source_reference' => 'SYNTHETIC competing issue evidence',
    ], [$fixture['line']->id => ['target_quantity' => '2', 'serial_receipt_layer_ids' => [$layer->id]]]);
    $results = closurePostgresRace([
        ['operation' => 'opening-quantity-correction-approve', 'opening' => $fixture['opening']->id, 'correction' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'issue', 'user' => $fixture['preparer']->id, 'header' => ['company_id' => $fixture['company']->id,
            'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id, 'branch_store_id' => $fixture['store']->id,
            'source_stock_status' => InventoryTransaction::StatusAvailable, 'document_type' => InventoryDocument::TypeIssue, 'document_date' => '2026-10-03'],
            'lines' => [['product_id' => $fixture['product']->id, 'quantity' => '1', 'selected_receipt_layer_id' => $layer->id]]],
    ]);
    expect(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
        ->and(collect($results)->where('result', 'applied'))->toHaveCount(1)
        ->and(collect($results)->where('result', 'blocked'))->toHaveCount(1)
        ->and($layer->fresh()->remaining_quantity)->toBe('0.00000000')
        ->and($layer->serialIdentity->fresh()->current_receipt_layer_id)->toBeNull()
        ->and(InventoryLayerAllocation::query()->where('inventory_receipt_layer_id', $layer->id)->count())->toBe(1);
    $rows = InventoryTransaction::query()->where('company_id', $fixture['company']->id)->get();
    expect($rows->reduce(fn (string $sum, $row): string => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0.00000000'))->toBe('4.00000000')
        ->and($rows->reduce(fn (string $sum, $row): string => bcadd($sum, $row->signedValue(), 8), '0.00000000'))->toBe('19.20000000');
});
