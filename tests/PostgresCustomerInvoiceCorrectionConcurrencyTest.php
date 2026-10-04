<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerInvoiceCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')
        ->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('actual invoice correction approvals serialize on two PostgreSQL connections with one original inverse', function (): void {
    $f = invoiceCorrectionFixture();
    $service = app(CustomerInvoiceCorrectionService::class);
    $proposal = $service->prepare($f['invoice'], invoiceCorrectionPayload($f));
    $reviewer = closureSyntheticUser();
    $reviewer->givePermissionTo(['customer_invoices.correct_approve']);
    $results = closurePostgresRace([
        ['operation' => 'customer-invoice-correction', 'invoice' => $f['invoice']->id, 'correction' => $proposal->id,
            'user' => $f['approver']->id, 'clock' => '2026-09-30 12:00:00'],
        ['operation' => 'customer-invoice-correction', 'invoice' => $f['invoice']->id, 'correction' => $proposal->id,
            'user' => $reviewer->id, 'clock' => '2026-09-30 12:00:00'],
    ], orderedCompanyLock: true);
    expect(array_column($results, 'result'))->toBe(['applied', 'applied'])
        ->and(array_column($results, 'root_transaction_attempts'))->toBe([1, 1])
        ->and(array_column($results, 'id'))->toBe([$proposal->id, $proposal->id])
        ->and($proposal->fresh()->status)->toBe('approved')
        ->and($f['invoice']->creditNotes()->count())->toBe(1)
        ->and($f['delivery']->transactions()->where('is_reversal', true)->count())->toBe(1)
        ->and($f['invoice']->journalEntry->fresh()->reversed_entry_id)->toBe($f['invoice']->creditNotes()->sole()->journal_entry_id);
    invoiceCorrectionActor($f, $f['approver']);
    $service->assertApproved($proposal->fresh());
    foreach ([null, $f['branch']->id] as $branch) {
        $position = collect(app(InventoryGlReconciliationService::class)
            ->reconcile($f['company']->id, $f['period']->id, $branch))->keyBy('key');
        expect($position['finished_goods']['difference'])->toBe('0.0000');
    }

});
