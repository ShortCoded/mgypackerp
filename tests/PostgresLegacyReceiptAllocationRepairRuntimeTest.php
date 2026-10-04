<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/LegacyReceiptAllocationRepairCases.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('real PostgreSQL legacy allocation approvals serialize duplicate and both original source change orders', function (): void {
    if (getenv('MGYPACK_LEGACY_REPAIR_RACE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic two-connection acceptance only.');
    }
    foreach (['duplicate', 'source-first', 'repair-first'] as $order) {
        $f = legacyAllocationFixture();
        $service = app(InventoryMovementCorrectionService::class);
        $proposal = $service->prepare($f['legacy'], legacyAllocationPayload($f));
        $before = $proposal->source_snapshot['legacy_repair']['financial_proof'];
        $approval = ['operation' => 'manual-inventory-correction-approve', 'user' => $f['reviewer']->id,
            'document' => $f['legacy']->id, 'correction' => $proposal->id, 'clock' => '2026-09-28 12:00:00', 'context' => session()->all()];
        $receipt = ['operation' => 'movement', 'user' => $f['reviewer']->id, 'clock' => $approval['clock'], 'context' => $approval['context'],
            'header' => ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id,
                'branch_store_id' => $f['store']->id, 'document_type' => InventoryDocument::TypeReceipt, 'document_date' => '2026-09-28'],
            'lines' => [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '1', 'unit_cost' => '2']]];
        $operations = match ($order) {
            'duplicate' => [$approval, $approval], 'source-first' => [$receipt, $approval], default => [$approval, $receipt],
        };
        $results = closurePostgresRace($operations, orderedCompanyLock: true);
        expect(collect($results)->pluck('result')->all())->toBe($order === 'source-first' ? ['applied', 'blocked'] : ['applied', 'applied'])
            ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and($proposal->fresh()->status)->toBe($order === 'source-first' ? 'prepared' : 'approved');
        if ($order !== 'source-first') {
            $service->assertApproved($proposal->fresh());
            expect($proposal->fresh()->execution_snapshot['financial_proof'])->toBe($before)
                ->and($f['own']->fresh()->remaining_quantity)->toBe('0.00000000')
                ->and($f['older']->fresh()->remaining_quantity)->toBe('4.00000000');
        } else {
            expect($f['own']->fresh()->remaining_quantity)->toBe('1.00000000')->and($f['older']->fresh()->remaining_quantity)->toBe('3.00000000');
        }
        $quantity = InventoryTransaction::query()->where('company_id', $f['company']->id)->where('product_id', $f['finished']->id)->get()
            ->reduce(fn ($sum, $row) => bcadd($sum, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0');
        expect($quantity)->toBe($order === 'duplicate' ? '4.00000000' : '5.00000000');
    }
});
