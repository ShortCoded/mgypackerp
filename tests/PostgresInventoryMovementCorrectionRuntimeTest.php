<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryMovementCorrectionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('manual inventory duplicate approvals and both stock issue orders serialize with exact history', function (): void {
    if (getenv('MGYPACK_MANUAL_CORRECTION_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed synthetic two-connection acceptance only.');
    }
    foreach (['duplicate', 'issue-first', 'correction-first'] as $order) {
        $f = manualCorrectionClose(manualCorrectionFixture());
        $proposal = app(InventoryMovementCorrectionService::class)->prepare($f['receipt'], manualCorrectionPayload($f, $f['receipt']));
        $source = $f['receipt']->transactions()->sole()->getAttributes();
        $journalLines = $f['receipt']->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all();
        $approval = ['operation' => 'manual-inventory-correction-approve', 'user' => $f['reviewer']->id,
            'document' => $f['receipt']->id, 'correction' => $proposal->id, 'clock' => '2026-10-03 12:00:00', 'context' => session()->all()];
        $issue = ['operation' => 'issue', 'user' => $f['reviewer']->id, 'clock' => $approval['clock'], 'context' => $approval['context'],
            'header' => ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['target']->id,
                'branch_store_id' => $f['store']->id, 'document_type' => InventoryDocument::TypeIssue, 'document_date' => '2026-10-02'],
            'lines' => [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id, 'quantity' => '10']]];
        $operations = match ($order) {
            'duplicate' => [$approval, $approval], 'issue-first' => [$issue, $approval], default => [$approval, $issue]
        };
        $results = closurePostgresRace($operations, orderedCompanyLock: true);
        expect(collect($results)->pluck('result')->all())->toBe($order === 'duplicate' ? ['applied', 'applied'] : ['applied', 'blocked'])
            ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and(InventoryTransaction::findOrFail($source['id'])->getAttributes())->toBe($source)
            ->and($f['receipt']->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($journalLines);
        $rows = InventoryTransaction::query()->where('company_id', $f['company']->id)->where('product_id', $f['finished']->id)->get();
        expect($rows->reduce(fn (string $total, $row): string => bcadd($total, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))
            ->toBe($order === 'issue-first' ? '0.00000000' : '6.00000000');
        expect($proposal->fresh()->status)->toBe($order === 'issue-first' ? 'prepared' : 'approved')
            ->and($f['receipt']->fresh()->status)->toBe($order === 'issue-first' ? 'posted' : 'reversed');
        if ($order === 'duplicate') {
            expect(collect($results)->pluck('id')->unique()->all())->toBe([$proposal->id])
                ->and($f['receipt']->transactions()->where('is_reversal', true)->count())->toBe(1)
                ->and(InventoryDocument::query()->where('company_id', $f['company']->id)->count())->toBe(2);
        }
    }
});

test('prepare an explicitly synthetic manual inventory correction for the ordinary browser', function (): void {
    if (getenv('MGYPACK_MANUAL_CORRECTION_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated browser fixture only.');
    }
    $path = '/tmp/mgypack-manual-correction-runtime-20261003.json';
    expect(file_exists($path))->toBeFalse();
    $manifest = DB::transaction(function (): array {
        $f = manualCorrectionClose(manualCorrectionFixture());
        $f['user']->revokePermissionTo('inventory.documents.correct_approve');
        $f['reviewer']->revokePermissionTo('inventory.documents.correct_prepare');
        foreach ([$f['user'], $f['reviewer']] as $actor) {
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $f['company']->doc_num,
                'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['target']->doc_num]);
        }

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $f['company']->id,
            'company_doc_num' => $f['company']->doc_num, 'branch_id' => $f['branch']->id, 'store_id' => $f['store']->id,
            'product_id' => $f['finished']->id, 'source_period_id' => $f['period']->id, 'posting_period_id' => $f['target']->id,
            'document_id' => $f['receipt']->id, 'document_doc_num' => $f['receipt']->doc_num,
            'preparer_id' => $f['user']->id, 'preparer' => $f['user']->username, 'reviewer_id' => $f['reviewer']->id, 'reviewer' => $f['reviewer']->username,
            'posting_date' => '2026-10-03', 'original_transaction' => $f['receipt']->transactions()->sole()->getAttributes(),
            'original_journal_id' => $f['receipt']->journal_entry_id,
            'original_journal_lines' => $f['receipt']->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all()];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('persisted ordinary browser manual correction matches exact source stock value and accounting', function (): void {
    if (getenv('MGYPACK_MANUAL_CORRECTION_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit read-only ordinary browser proof only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-manual-correction-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and($manifest['company_doc_num'])->toStartWith('SYNTHETIC-');
    $source = InventoryDocument::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['document_id']);
    $proposal = InventoryMovementCorrection::query()->where('inventory_document_id', $source->id)->where('status', 'approved')->sole();
    $replacement = $proposal->replacementDocument;
    expect($proposal->prepared_by)->toBe($manifest['preparer_id'])->and($proposal->approved_by)->toBe($manifest['reviewer_id'])
        ->and($proposal->posting_financial_period_id)->toBe($manifest['posting_period_id'])->and($source->status)->toBe('reversed')
        ->and($source->document_date->toDateString())->toBe('2026-09-28')->and($source->financial_period_id)->toBe($manifest['source_period_id'])
        ->and(FinancialPeriod::findOrFail($source->financial_period_id)->is_closed)->toBeTrue()->and($replacement->status)->toBe('posted')->and($replacement->is_closed)->toBeTrue()
        ->and($replacement->lines->sole()->quantity)->toBe('6.00000000')->and($replacement->lines->sole()->unit_cost)->toBe('3.12345678')
        ->and(InventoryTransaction::findOrFail($manifest['original_transaction']['id'])->getAttributes())->toBe($manifest['original_transaction'])
        ->and($source->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($manifest['original_journal_lines']);
    $rows = InventoryTransaction::query()->where('company_id', $manifest['company_id'])->where('product_id', $manifest['product_id'])->get();
    expect($rows->reduce(fn (string $total, $row): string => bcadd($total, bcsub($row->quantity_in, $row->quantity_out, 8), 8), '0'))->toBe('6.00000000')
        ->and($rows->reduce(fn (string $total, $row): string => bcadd($total, $row->signedValue(), 8), '0'))->toBe('18.74074068')
        ->and(InventoryReceiptLayer::query()->where('receipt_transaction_id', $replacement->transactions()->sole()->id)->sole()->remaining_quantity)->toBe('6.00000000')
        ->and($replacement->journalEntry->lines()->where('debit_amount', '>', 0)->sum('debit_amount'))->toBe('18.7407');
    foreach ([$source->reversalJournalEntry, $replacement->journalEntry] as $journal) {
        expect($journal->financial_period_id)->toBe($manifest['posting_period_id'])->and($journal->entry_date->toDateString())->toBe($manifest['posting_date'])
            ->and($journal->lines()->sum('debit_amount'))->toBe($journal->lines()->sum('credit_amount'));
    }
    $original = JournalEntry::findOrFail($manifest['original_journal_id']);
    foreach ($original->lines as $line) {
        $inverse = $source->reversalJournalEntry->lines->firstWhere('account_id', $line->account_id);
        expect($inverse->debit_amount)->toBe($line->credit_amount)->and($inverse->credit_amount)->toBe($line->debit_amount);
    }
});
