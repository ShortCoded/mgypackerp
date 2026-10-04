<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnCorrection;
use Modules\Sales\Services\SalesReturnCorrectionService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/SalesReturnLaterPeriodSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)
        ->and(DB::transactionLevel())->toBe(0);
});

test('actual PostgreSQL later-period correction duplicate approvals and both allocation and refund orders serialize once', function (): void {
    if (getenv('MGYPACK_SALES_RETURN_LATER_RACE') !== '1') {
        $this->markTestSkipped('Explicit committed synthetic contention acceptance.');
    }
    foreach (['duplicate', 'allocation-first', 'correction-before-allocation', 'refund-first', 'correction-before-refund'] as $order) {
        $f = laterReturnFixture();
        $targetInvoice = salesPostedServiceInvoice($f, '40');
        $f = laterReturnCloseSource($f);
        $proposal = app(SalesReturnCorrectionService::class)->prepare($f['return'], laterReturnPayload($f));
        $context = [...salesCycleSession($f), OperatingContextService::FinancialPeriodIdKey => $f['target']->id,
            OperatingContextService::FinancialPeriodDocNumKey => $f['target']->doc_num];
        $approval = ['operation' => 'sales-return-later-approve', 'user' => $f['reviewer']->id,
            'return' => $f['return']->id, 'correction' => $proposal->id, 'context' => $context, 'clock' => '2026-10-03 14:00:00'];
        $usage = str_contains($order, 'refund') ? ['operation' => 'sales-credit-refund', 'credit' => $f['credit']->id, 'user' => $f['reviewer']->id,
            'context' => $context, 'clock' => $approval['clock'], 'data' => ['company_id' => $f['company']->id, 'financial_period_id' => $f['target']->id,
                'branch_id' => $f['branch']->id, 'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'exchange_rate' => 1,
                'refund_date' => '2026-10-02', 'amount' => '5', 'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id]]
            : ['operation' => 'sales-credit-allocate', 'credit' => $f['credit']->id, 'invoice' => $targetInvoice->id,
                'user' => $f['reviewer']->id, 'context' => $context, 'clock' => $approval['clock']];
        $journalIds = JournalEntry::query()->where('company_id', $f['company']->id)->pluck('id');
        $sourceLines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->orderBy('id')->get()->toJson();
        $results = closurePostgresRace($order === 'duplicate' ? [$approval, $approval]
            : (str_ends_with($order, '-first') ? [$usage, $approval] : [$approval, $usage]), orderedCompanyLock: true);
        expect(collect($results)->pluck('result')->all())->toBe($order === 'duplicate' ? ['applied', 'applied'] : ['applied', 'blocked'])
            ->and(collect($results)->pluck('root_transaction_attempts')->all())->toBe([1, 1])
            ->and(DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->orderBy('id')->get()->toJson())->toBe($sourceLines);
        if (str_ends_with($order, '-first')) {
            expect($proposal->fresh()->status)->toBe('prepared')->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusClosed)
                ->and(SalesReturn::query()->where('company_id', $f['company']->id)->count())->toBe(1);
        } else {
            expect($proposal->fresh()->status)->toBe('approved')->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusCancelled)
                ->and(SalesReturn::query()->where('company_id', $f['company']->id)->count())->toBe(2)
                ->and($proposal->fresh()->replacementReturn->status)->toBe(SalesReturn::StatusPendingAuthorization);
        }
    }
});

test('prepare persistent synthetic later-period return correction for an ordinary browser', function (): void {
    if (getenv('MGYPACK_SALES_RETURN_LATER_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic browser fixture.');
    }
    $path = '/tmp/mgypack-sales-return-later-runtime-20261003.json';
    expect(file_exists($path))->toBeFalse();
    $f = laterReturnCloseSource(laterReturnFixture('closed'));
    $session = [...salesCycleSession($f), OperatingContextService::FinancialPeriodIdKey => $f['target']->id,
        OperatingContextService::FinancialPeriodDocNumKey => $f['target']->doc_num];
    $manifest = ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $f['company']->id,
        'company_doc_num' => $f['company']->doc_num, 'branch_id' => $f['branch']->id, 'branch_doc_num' => $f['branch']->doc_num,
        'source_period_id' => $f['period']->id, 'target_period_id' => $f['target']->id, 'target_period_doc_num' => $f['target']->doc_num,
        'invoice_id' => $f['invoice']->id, 'return_id' => $f['return']->id, 'return_doc_num' => $f['return']->doc_num,
        'preparer_id' => $f['user']->id, 'reviewer_id' => $f['reviewer']->id, 'preparer' => $f['user']->username, 'reviewer' => $f['reviewer']->username,
        'password' => 'password', 'session' => $session, 'phase' => 'prepared-fixture',
        'original_transactions' => InventoryTransaction::query()->where('company_id', $f['company']->id)->orderBy('id')->get()->map->getAttributes()->all(),
        'journal_ids' => JournalEntry::query()->where('company_id', $f['company']->id)->pluck('id')->all()];
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    expect($f['period']->fresh()->is_closed)->toBeTrue()->and($f['return']->fresh()->status)->toBe(SalesReturn::StatusClosed);
});

test('persisted browser later-period return correction preserves source evidence and links one pending replacement', function (): void {
    if (getenv('MGYPACK_SALES_RETURN_LATER_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit ordinary browser acceptance proof.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-sales-return-later-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $return = SalesReturn::query()->where('company_id', $manifest['company_id'])->findOrFail($manifest['return_id']);
    $proposal = SalesReturnCorrection::query()->where('sales_return_id', $return->id)->where('status', 'approved')->sole();
    $proof = app(SalesReturnCorrectionService::class);
    $proof->assertApproved($proposal);
    expect($proposal->prepared_by)->toBe($manifest['preparer_id'])->and($proposal->approved_by)->toBe($manifest['reviewer_id'])
        ->and($proposal->posting_financial_period_id)->toBe($manifest['target_period_id'])
        ->and($return->status)->toBe(SalesReturn::StatusCancelled)->and($return->return_date->toDateString())->toBe('2026-09-28')
        ->and($return->financial_period_id)->toBe($manifest['source_period_id'])
        ->and($proposal->replacementReturn->status)->toBe(SalesReturn::StatusPendingAuthorization)
        ->and($proposal->replacementReturn->financial_period_id)->toBe($manifest['target_period_id'])
        ->and($proposal->replacementReturn->lines->sole()->quantity)->toBe('3.00000000')
        ->and(InventoryTransaction::query()->whereIn('id', collect($manifest['original_transactions'])->pluck('id'))->orderBy('id')->get()->map->getAttributes()->all())->toBe($manifest['original_transactions']);
    foreach ([$return->quarantineJournalEntry, $return->dispositionJournalEntry, $return->creditNote->journalEntry] as $journal) {
        $inverse = JournalEntry::query()->findOrFail($journal->reversed_entry_id);
        $proof->assertInverse($journal, $inverse, $manifest['target_period_id'], $proposal->posting_date->toDateString());
    }
    expect(CustomerInvoice::query()->findOrFail($manifest['invoice_id'])->remaining_amount)->toBe('20.0000');
});

test('enable bounded report acceptance for the already approved synthetic browser correction', function (): void {
    if (getenv('MGYPACK_SALES_RETURN_LATER_REPORT_ACCESS') !== '1') {
        $this->markTestSkipped('Explicit synthetic report acceptance only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-sales-return-later-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue()->and($manifest['database'])->toBe('mgypack_acceptance_closure_20261003')
        ->and($manifest['company_doc_num'])->toStartWith('SYNTHETIC-');
    $user = User::query()->findOrFail($manifest['reviewer_id']);
    expect($user->username)->toBe($manifest['reviewer'])->toStartWith('synthetic-closure-');
    $journals = JournalEntry::query()->where('company_id', $manifest['company_id'])->orderBy('id')->get()->map->getAttributes()->all();
    foreach (['reports.sales.financial', 'reports.sales.invoices', 'reports.sales.receivables', 'reports.sales.returns', 'reports.finance'] as $report) {
        foreach (['view', 'print', 'export'] as $action) {
            $user->givePermissionTo(Permission::findOrCreate($report.'.'.$action, 'web'));
        }
    }
    expect(JournalEntry::query()->where('company_id', $manifest['company_id'])->orderBy('id')->get()->map->getAttributes()->all())->toBe($journals);
});
