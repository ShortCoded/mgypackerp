<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryValuationService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryValueAdjustmentSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
    expect(DB::transactionLevel())->toBe(0);
});

test('persist isolated synthetic receipt cost completion for browser approval acceptance', function (): void {
    if (getenv('MGYPACK_COMPLETION_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture creation only.');
    }
    $path = '/tmp/mgypack-receipt-completion-runtime-20261003.json';
    if (is_file($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $receipt = InventoryDocument::query()->where('company_id', $manifest['company_id'])->where('doc_num', $manifest['receipt_doc_num'])->sole();
        $manifest['receipt_id'] = $receipt->id;
        unset($manifest['receipt_public_id']);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($receipt->exists)->toBeTrue();

        return;
    }
    DB::transaction(function () use ($path): void {
        $fixture = receiptCompletionFixture(true);
        $sourceDay = $fixture['period']->from_date->copy()->addDay()->toDateString();
        $receipt = costTransitionMovement($fixture, $sourceDay, InventoryDocument::TypeReceipt, '10');
        costTransitionMovement($fixture, $sourceDay, InventoryDocument::TypeIssue, '4');
        foreach ([$fixture['preparer'], $fixture['approver']] as $user) {
            Permission::findOrCreate('journal_entries.view', 'web');
            $user->givePermissionTo('journal_entries.view');
            app(DefaultLoginContextService::class)->update($user, [
                'company_doc_num' => $fixture['company']->doc_num, 'branch_doc_num' => $fixture['branch']->doc_num,
                'financial_period_doc_num' => $fixture['period']->doc_num]);
        }
        $manifest = ['database' => 'mgypack_acceptance_closure_20261003', 'synthetic' => true,
            'preparer' => $fixture['preparer']->username, 'approver' => $fixture['approver']->username,
            'receipt_doc_num' => $receipt->doc_num, 'receipt_id' => $receipt->id,
            'line_id' => $receipt->lines->sole()->id, 'company_id' => $fixture['company']->id,
            'branch_id' => $fixture['branch']->id, 'store_id' => $fixture['store']->id,
            'product_id' => $fixture['product']->id, 'counterpart_id' => $fixture['counterpart']->id,
            'counterpart_code' => $fixture['counterpart']->account_code,
            'posting_date' => now()->toDateString(), 'source_unit_cost' => '7.12345678', 'source_total' => '71.23456780'];
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    });
});

test('persisted browser approved completion preserves quantities and reconciles exact stock expense and GL', function (): void {
    if (getenv('MGYPACK_COMPLETION_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture verification only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-receipt-completion-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $receipt = InventoryDocument::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['receipt_id']);
    $proposal = $receipt->costProposals()->where('status', InventoryReceiptCostProposal::StatusApproved)->sole();
    $adjustment = $proposal->valueAdjustment;
    expect($receipt->transactions->sole()->total_cost)->toBeNull()->and($receipt->lines->sole()->total_cost)->toBeNull()
        ->and($proposal->prepared_by)->not->toBe($proposal->approved_by)
        ->and($adjustment->source_snapshot['source_total'])->toBe($manifest['source_total']);
    $totals = InventoryTransaction::query()->where('branch_store_id', $manifest['store_id'])->where('product_id', $manifest['product_id'])
        ->selectRaw('sum(quantity_in-quantity_out) as quantity, sum('.InventoryTransaction::signedValueSql().') as value, sum('.InventoryTransaction::unvaluedQuantitySql().') as unvalued')->first();
    expect(bccomp((string) $totals->quantity, '6', 8))->toBe(0)->and(bccomp((string) $totals->value, '42.74074068', 8))->toBe(0)
        ->and(bccomp((string) $totals->unvalued, '0', 8))->toBe(0)
        ->and(app(InventoryValuationService::class)->bookUnitCostForPosition($manifest['company_id'], $manifest['store_id'], $manifest['product_id']))->toBe('7.12345678')
        ->and($adjustment->lines->where('effect', 'expense')->sole()->amount)->toBe('28.49382712');
    $journal = $adjustment->journalEntry;
    $stockAccount = $adjustment->lines->where('effect', 'stock')->first()->account_id;
    $expenseAccount = $adjustment->lines->where('effect', 'expense')->sole()->account_id;
    expect(bccomp((string) $journal->lines->where('account_id', $stockAccount)->sum('debit_amount'), '42.7407', 4))->toBe(0)
        ->and(bccomp((string) $journal->lines->where('account_id', $expenseAccount)->sum('debit_amount'), '28.4938', 4))->toBe(0)
        ->and(bccomp((string) $journal->lines->sum('debit_amount'), '71.2345', 4))->toBe(0)
        ->and(bccomp((string) $journal->lines->sum('credit_amount'), '71.2345', 4))->toBe(0)
        ->and($adjustment->lines->where('effect', 'counterpart')->sole()->source_snapshot['gl_rounding_difference'])->toBe('-0.00006780');
});

test('real PostgreSQL receipt cost completion approval is atomic under simultaneous independent approval submissions', function (): void {
    if (getenv('MGYPACK_COMPLETION_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed isolated synthetic concurrency acceptance only.');
    }
    $fixture = receiptCompletionFixture(true);
    $day = $fixture['period']->from_date->copy()->addDay()->toDateString();
    $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeReceipt, '10');
    costTransitionMovement($fixture, $day, InventoryDocument::TypeIssue, '4');
    $proposal = receiptCompletionPrepare($fixture, $receipt, '7', $day);
    $results = closurePostgresRace([
        ['operation' => 'receipt-cost-completion-approve', 'receipt' => $receipt->id, 'proposal' => $proposal->id, 'user' => $fixture['approver']->id],
        ['operation' => 'receipt-cost-completion-approve', 'receipt' => $receipt->id, 'proposal' => $proposal->id, 'user' => $fixture['approver']->id],
    ]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked'])
        ->and($proposal->fresh()->status)->toBe(InventoryReceiptCostProposal::StatusApproved)
        ->and(InventoryValueAdjustment::query()->where('source_id', $proposal->id)->where('source_type', InventoryReceiptCostProposal::class)->count())->toBe(1)
        ->and(InventoryTransaction::query()->where('source_type', InventoryValueAdjustment::class)->where('source_id', $proposal->valueAdjustment->id)->count())->toBe(2)
        ->and($proposal->valueAdjustment->journalEntry->lines->sum('debit_amount'))->toBe(70.0)
        ->and($proposal->valueAdjustment->journalEntry->lines->sum('credit_amount'))->toBe(70.0);
});
