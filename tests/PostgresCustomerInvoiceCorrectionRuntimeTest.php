<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerInvoiceCorrectionSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('create synthetic connected invoice correction data for the ordinary browser', function (): void {
    if (getenv('MGYPACK_INVOICE_CORRECTION_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated ordinary browser fixture only.');
    }
    $path = '/tmp/mgypack-invoice-correction-runtime-20261003.json';
    expect(file_exists($path))->toBeFalse();
    $manifest = DB::transaction(function (): array {
        $f = invoiceCorrectionFixture();
        $f['period']->update(['to_date' => '2026-09-30', 'is_closed' => true]);
        $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id,
            'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-INVOICE-OCT-'.$f['company']->id, 'name' => 'SYNTHETIC invoice recovery October',
            'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
        $f['user']->revokePermissionTo('customer_invoices.correct_approve');
        $f['approver']->revokePermissionTo('customer_invoices.correct_prepare');
        foreach ([$f['user'], $f['approver']] as $actor) {
            app(DefaultLoginContextService::class)->update($actor, ['company_doc_num' => $f['company']->doc_num,
                'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['target']->doc_num]);
        }

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003', 'company_id' => $f['company']->id,
            'company_doc_num' => $f['company']->doc_num, 'branch_id' => $f['branch']->id, 'store_id' => $f['store']->id,
            'product_id' => $f['finished']->id, 'run_id' => $f['run']->id, 'run_public_id' => $f['run']->public_id,
            'source_period_id' => $f['period']->id, 'posting_period_id' => $f['target']->id,
            'invoice_id' => $f['invoice']->id, 'invoice_doc_num' => $f['invoice']->doc_num, 'delivery_id' => $f['delivery']->id,
            'preparer_id' => $f['user']->id, 'preparer' => $f['user']->username, 'reviewer_id' => $f['approver']->id, 'reviewer' => $f['approver']->username,
            'posting_date' => '2026-10-03', 'original_invoice_header' => $f['invoice']->only(['invoice_date', 'financial_period_id', 'total_amount', 'delivery_document_id', 'journal_entry_id']),
            'original_invoice_lines' => $f['invoice']->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            'original_delivery_transaction' => $f['delivery']->transactions()->sole()->getAttributes()];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('verify the independently approved ordinary browser invoice recovery', function (): void {
    if (getenv('MGYPACK_INVOICE_CORRECTION_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit ordinary browser read-only proof only.');
    }
    $m = json_decode(file_get_contents('/tmp/mgypack-invoice-correction-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($m['synthetic'])->toBeTrue()->and($m['company_doc_num'])->toStartWith('SYNTHETIC-');
    $invoice = CustomerInvoice::query()->where('company_id', $m['company_id'])->findOrFail($m['invoice_id']);
    $f = ['company' => Company::findOrFail($m['company_id']), 'branch' => Branch::findOrFail($m['branch_id']),
        'period' => FinancialPeriod::findOrFail($m['posting_period_id'])];
    invoiceCorrectionActor($f, User::findOrFail($m['reviewer_id']));
    $proposal = CustomerInvoiceCorrection::query()->where('customer_invoice_id', $invoice->id)->where('status', 'approved')->sole();
    app(CustomerInvoiceCorrectionService::class)->assertApproved($proposal);
    expect($proposal->prepared_by)->toBe($m['preparer_id'])->and($proposal->approved_by)->toBe($m['reviewer_id'])
        ->and($proposal->posting_financial_period_id)->toBe($m['posting_period_id'])
        ->and(json_decode(json_encode($invoice->only(array_keys($m['original_invoice_header'])), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($m['original_invoice_header'])
        ->and($invoice->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($m['original_invoice_lines'])
        ->and(InventoryTransaction::findOrFail($m['original_delivery_transaction']['id'])->getAttributes())->toBe($m['original_delivery_transaction'])
        ->and($invoice->creditNotes()->sole()->total_amount)->toBe('100.0000')
        ->and($invoice->creditNotes()->sole()->financial_period_id)->toBe($m['posting_period_id'])
        ->and($invoice->remaining_amount)->toBe('0.0000')->and($invoice->credited_amount)->toBe('100.0000');
    $delivery = InventoryDocument::findOrFail($m['delivery_id']);
    expect($delivery->status)->toBe('reversed')->and($delivery->transactions()->where('is_reversal', true)->sole()->total_cost)->toBe('38.00000000');
    foreach ([$m['source_period_id'], $m['posting_period_id']] as $period) {
        $rows = collect(app(InventoryGlReconciliationService::class)->reconcile($m['company_id'], $period))->keyBy('key');
        expect($rows['finished_goods']['difference'])->toBe('0.0000')->and($rows['wip']['difference'])->toBe('0.0000');
    }
});
