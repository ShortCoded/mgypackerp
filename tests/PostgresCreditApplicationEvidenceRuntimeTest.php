<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Sales\Models\CustomerCreditApplicationEvidence;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerCreditApplicationEvidenceService;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CreditApplicationEvidenceSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

test('actual PostgreSQL credit evidence approvals and both closed correction orders serialize once without partial financial effects', function (): void {
    if (getenv('MGYPACK_CREDIT_EVIDENCE_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed synthetic contention acceptance.');
    }
    foreach (['duplicate', 'approval-first', 'correction-first'] as $order) {
        $f = historicalCreditEvidenceFixture();
        $proposal = app(CustomerCreditApplicationEvidenceService::class)->prepare($f['credit'], $f['payload']);
        $journalIds = JournalEntry::query()->where('company_id', $f['company']->id)->pluck('id');
        $journalLines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->orderBy('id')->get()->toJson();
        $approval = ['operation' => 'credit-application-approve', 'user' => $f['reviewer']->id,
            'credit' => $f['credit']->id, 'evidence' => $proposal->id, 'context' => salesCycleSession($f)];
        $correction = ['operation' => 'sales-return-closed-correction', 'user' => $f['reviewer']->id,
            'return' => $f['return']->id, 'context' => salesCycleSession($f)];
        $results = closurePostgresRace(match ($order) {
            'duplicate' => [$approval, $approval], 'approval-first' => [$approval, $correction], default => [$correction, $approval],
        }, orderedCompanyLock: true);
        expect(collect($results)->pluck('result')->all())->toBe($order === 'correction-first' ? ['blocked', 'applied'] : ['applied', 'applied'])
            ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and(CustomerCreditApplicationEvidence::query()->where('credit_note_id', $f['credit']->id)->where('status', 'approved')->count())->toBe(1)
            ->and(DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->orderBy('id')->get()->toJson())->toBe($journalLines);
        if ($order !== 'approval-first') {
            expect($f['invoice']->fresh()->credited_amount)->toBe('50.0000')->and($f['credit']->fresh()->posting_status)->toBe('posted')
                ->and(JournalEntry::query()->where('company_id', $f['company']->id)->count())->toBe($journalIds->count());
            $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
            app(SalesReturnService::class)->correctClosed($f['return']->fresh(), 'SYNTHETIC explicit retry after reviewed metadata');
        }
        expect($f['invoice']->fresh()->credited_amount)->toBe('0.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('100.0000')
            ->and($f['credit']->fresh()->posting_status)->toBe('reversed')
            ->and(JournalEntry::query()->where('company_id', $f['company']->id)->count())->toBe($journalIds->count() + 1);
    }
});

test('prepare persistent synthetic credit application data for an ordinary browser without financial approval', function (): void {
    if (getenv('MGYPACK_CREDIT_EVIDENCE_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture.');
    }
    $path = '/tmp/mgypack-credit-application-evidence-runtime-20261003.json';
    expect(file_exists($path))->toBeFalse();
    $f = historicalCreditEvidenceFixture('80');
    $manifest = ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $f['company']->id,
        'company_doc_num' => $f['company']->doc_num, 'branch_id' => $f['branch']->id, 'branch_doc_num' => $f['branch']->doc_num,
        'period_id' => $f['period']->id, 'period_doc_num' => $f['period']->doc_num, 'credit_id' => $f['credit']->id, 'credit_doc_num' => $f['credit']->doc_num,
        'invoice_id' => $f['invoice']->id, 'invoice_doc_num' => $f['invoice']->doc_num, 'return_id' => $f['return']->id, 'return_doc_num' => $f['return']->doc_num,
        'preparer_id' => $f['user']->id, 'reviewer_id' => $f['reviewer']->id, 'preparer' => $f['user']->username, 'reviewer' => $f['reviewer']->username,
        'password' => 'password', 'session' => salesCycleSession($f), 'original_application' => $f['payload']['schedules'],
        'phase' => 'prepared-fixture', 'journal_ids' => JournalEntry::query()->where('company_id', $f['company']->id)->pluck('id')->all()];
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    expect($f['credit']->fresh()->credit_application_snapshot)->toBeNull()->and($f['invoice']->fresh()->credited_amount)->toBe('20.0000');
});

test('persisted browser credit application approval reconciles unchanged source balances and journals', function (): void {
    if (getenv('MGYPACK_CREDIT_EVIDENCE_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit persisted browser acceptance.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-credit-application-evidence-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $credit = CustomerInvoice::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['credit_id']);
    $invoice = CustomerInvoice::query()->findOrFail($manifest['invoice_id']);
    $proof = CustomerCreditApplicationEvidence::query()->where('credit_note_id', $credit->id)->where('status', 'approved')->sole();
    app(CustomerCreditApplicationEvidenceService::class)->assertApprovedApplication($credit);
    expect($proof->prepared_by)->toBe($manifest['preparer_id'])->and($proof->approved_by)->toBe($manifest['reviewer_id'])
        ->and($credit->posting_status)->toBe('posted')->and($credit->credit_available_amount)->toBe('30.0000')
        ->and($credit->credit_allocated_amount)->toBe('0.0000')->and($credit->credit_refunded_amount)->toBe('0.0000')
        ->and($credit->credit_application_snapshot['applied_to_original'])->toBe('20.0000')
        ->and($credit->credit_application_snapshot['schedules'])->toBe($manifest['original_application'])
        ->and($invoice->paid_amount)->toBe('80.0000')->and($invoice->credited_amount)->toBe('20.0000')->and($invoice->remaining_amount)->toBe('0.0000')
        ->and(JournalEntry::query()->where('company_id', $manifest['company_id'])->pluck('id')->all())->toBe($manifest['journal_ids'])
        ->and(SalesReturn::query()->findOrFail($manifest['return_id'])->status)->toBe(SalesReturn::StatusClosed);
});
