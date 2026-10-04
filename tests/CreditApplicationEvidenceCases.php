<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\CustomerCreditApplicationEvidence;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerCreditApplicationEvidenceService;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesReturnService;

require_once __DIR__.'/CreditApplicationEvidenceSupport.php';

test('historical application requires independent approval and then executes the actual closed return correction', function (): void {
    $f = historicalCreditEvidenceFixture();
    $url = route('admin.sales.sales-invoices.application-evidence.index', $f['credit']);
    $this->get($url)->assertOk()->assertSee('Credit Application Evidence')->assertSee('novalidate', false);
    $invoiceBefore = $f['invoice']->fresh()->getAttributes();
    $creditBefore = $f['credit']->fresh()->getAttributes();
    $schedulesBefore = $f['invoice']->paymentSchedules()->get()->map->getAttributes()->all();
    $journalsBefore = JournalEntry::query()->count();
    $prepared = $this->postJson(route('admin.sales.sales-invoices.application-evidence.store', $f['credit']), $f['payload'])
        ->assertOk()->assertJsonPath('data.status', 'pending')->json('data.evidence_id');
    $approveUrl = route('admin.sales.sales-invoices.application-evidence.approve', [$f['credit'], $prepared]);
    $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC original records reviewed'])->assertUnprocessable();
    expect($f['credit']->fresh()->credit_application_snapshot)->toBeNull();
    $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
    $this->get($url)->assertOk()->assertSee('Approve details');
    $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC independent original records review'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson($approveUrl, ['approval_reason' => 'SYNTHETIC duplicate click'])->assertOk();
    $creditAfter = $f['credit']->fresh()->getAttributes();
    unset($creditBefore['updated_at'], $creditBefore['credit_application_snapshot'], $creditAfter['updated_at'], $creditAfter['credit_application_snapshot']);
    expect($creditAfter)->toBe($creditBefore)->and($f['invoice']->fresh()->getAttributes())->toBe($invoiceBefore)
        ->and($f['invoice']->paymentSchedules()->get()->map->getAttributes()->all())->toBe($schedulesBefore)
        ->and(JournalEntry::query()->count())->toBe($journalsBefore)
        ->and(CustomerCreditApplicationEvidence::query()->where('company_id', $f['company']->id)->count())->toBe(1);
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC replace original return quantity'])
        ->assertOk();
    expect($f['credit']->fresh()->posting_status)->toBe('reversed')
        ->and($f['invoice']->fresh()->credited_amount)->toBe('0.0000')
        ->and($f['invoice']->fresh()->remaining_amount)->toBe('100.0000')
        ->and($f['invoice']->paymentSchedules()->sole()->credited_amount)->toBe('0.0000')
        ->and(JournalEntry::query()->count())->toBe($journalsBefore + 1)
        ->and(CustomerCreditApplicationEvidence::query()->where('company_id', $f['company']->id)->sole()->status)->toBe('approved');
    $this->get($url)->assertOk()->assertSee('SYNTHETIC independent original records review')
        ->assertDontSee('Submit details for approval')->assertDontSee('Approve details');
});

test('historical credit evidence rejects stale sources, mismatched installments, malformed amounts and bypassed provenance', function (): void {
    $f = historicalCreditEvidenceFixture();
    $service = app(CustomerCreditApplicationEvidenceService::class);
    $storeUrl = route('admin.sales.sales-invoices.application-evidence.store', $f['credit']);
    foreach ([['schedules' => [['schedule_id' => 999999, 'amount' => '50']]], ['schedules' => [['schedule_id' => $f['invoice']->paymentSchedules->sole()->id, 'amount' => '49']]],
        ['schedules' => [['schedule_id' => $f['invoice']->paymentSchedules->sole()->id, 'amount' => '-50']]], ['schedules' => ['bad']],
        ['schedules' => [['schedule_id' => $f['invoice']->paymentSchedules->sole()->id, 'amount' => ['50']]]], ['source_reference' => ' ']] as $change) {
        $this->postJson($storeUrl, [...$f['payload'], ...$change])->assertUnprocessable();
    }
    expect(CustomerCreditApplicationEvidence::query()->where('company_id', $f['company']->id)->count())->toBe(0);
    $prepared = $service->prepare($f['credit'], $f['payload']);
    $f['invoice']->forceFill(['notes' => 'SYNTHETIC source changed after preparation'])->save();
    $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
    $this->postJson(route('admin.sales.sales-invoices.application-evidence.approve', [$f['credit'], $prepared]), ['approval_reason' => 'SYNTHETIC review'])
        ->assertUnprocessable();
    expect($prepared->fresh()->status)->toBe('pending')->and($f['credit']->fresh()->credit_application_snapshot)->toBeNull();
    $this->actingAs($f['user'])->withSession(salesCycleSession($f));
    $f['payload']['source_fingerprint'] = $service->preview($f['credit'])['fingerprint'];
    $second = $service->prepare($f['credit'], $f['payload']);
    $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
    $service->approve($f['credit'], $second->id, 'SYNTHETIC corrected baseline reviewed');
    $application = $f['credit']->fresh()->credit_application_snapshot;
    unset($application['evidence']);
    $f['credit']->forceFill(['credit_application_snapshot' => $application])->save();
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC attempt without approved provenance'])->assertUnprocessable();
    expect($f['credit']->fresh()->posting_status)->toBe('posted')->and($f['invoice']->fresh()->credited_amount)->toBe('50.0000');
});

test('historical credit evidence enforces permissions and source company and branch while allowing closed source records', function (): void {
    $f = historicalCreditEvidenceFixture();
    $url = route('admin.sales.sales-invoices.application-evidence.index', $f['credit']);
    $storeUrl = route('admin.sales.sales-invoices.application-evidence.store', $f['credit']);
    $f['user']->revokePermissionTo(['customer_credits.prepare_application_evidence', 'customer_credits.approve_application_evidence']);
    $this->get($url)->assertForbidden();
    $this->postJson($storeUrl, $f['payload'])->assertForbidden();
    $f['user']->givePermissionTo('customer_credits.prepare_application_evidence');
    $branch = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => 99275, 'doc_num' => 'SYNTHETIC-OTHER-EVIDENCE-BRANCH', 'name' => 'SYNTHETIC other evidence branch', 'type' => Branch::TypeAdministrative, 'status' => 'active']);
    $this->withSession([...salesCycleSession($f), OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num]);
    $this->postJson($storeUrl, $f['payload'])->assertNotFound();
    $this->withSession(salesCycleSession($f));
    $f['period']->update(['is_closed' => true]);
    $this->get($url)->assertOk();
    $f['payload']['source_fingerprint'] = app(CustomerCreditApplicationEvidenceService::class)->preview($f['credit'])['fingerprint'];
    $this->postJson($storeUrl, $f['payload'])->assertOk();
    expect($f['period']->fresh()->is_closed)->toBeTrue()->and($f['credit']->fresh()->credit_application_snapshot)->toBeNull();
});

test('application evidence seals prepared and approved records against later modification', function (): void {
    $f = historicalCreditEvidenceFixture();
    $service = app(CustomerCreditApplicationEvidenceService::class);
    $proposal = $service->prepare($f['credit'], $f['payload']);
    expect(fn () => $proposal->update(['reason' => 'SYNTHETIC unauthorized proposal edit']))->toThrow(DomainException::class);
    DB::table('customer_credit_application_evidences')->where('id', $proposal->id)->update(['reason' => 'SYNTHETIC altered stored reason']);
    $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
    expect(fn () => $service->approve($f['credit'], $proposal->id, 'SYNTHETIC review'))->toThrow(DomainException::class);
    DB::table('customer_credit_application_evidences')->where('id', $proposal->id)->update(['reason' => $f['payload']['reason']]);
    $approved = $service->approve($f['credit'], $proposal->id, 'SYNTHETIC original evidence reviewed');
    expect(fn () => $approved->update(['approval_reason' => 'SYNTHETIC changed review']))->toThrow(DomainException::class);
    DB::table('customer_credit_application_evidences')->where('id', $proposal->id)->update(['approval_reason' => 'SYNTHETIC altered stored approval']);
    expect(fn () => $service->assertApprovedApplication($f['credit']->fresh()))->toThrow(DomainException::class);
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC altered approval rejection'])->assertUnprocessable();
    expect($f['credit']->fresh()->posting_status)->toBe('posted')->and($f['invoice']->fresh()->credited_amount)->toBe('50.0000');
});

test('historical application cannot claim installment credit already attributed to another credit or allocation', function (string $kind): void {
    $f = historicalCreditEvidenceFixture();
    $invoice = $f['invoice'];
    $first = $invoice->paymentSchedules()->sole();
    $first->update(['amount' => '50', 'credited_amount' => '0']);
    $second = $invoice->paymentSchedules()->create(['sequence' => 2, 'due_date' => now()->addDay()->toDateString(), 'amount' => '50', 'collected_amount' => '0', 'credited_amount' => '50']);
    if ($kind === 'credit') {
        $returns = app(SalesReturnService::class);
        $returns->close($returns->authorize($returns->create($invoice, SalesReturn::ReasonOrderEntry, 'SYNTHETIC second recorded credit',
            [['customer_invoice_line_id' => $invoice->lines->sole()->id, 'quantity' => '1']])));
    } else {
        $otherInvoice = salesPostedServiceInvoice($f, '50');
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
            'customer_id' => $f['customer']->id, 'receipt_date' => now()->toDateString(), 'currency_id' => $f['currency']->id,
            'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id, 'amount' => '50', 'receipt_type' => CustomerReceipt::TypeCollection,
        ], [['customer_invoice_payment_schedule_id' => $otherInvoice->paymentSchedules->sole()->id, 'amount' => '50']]);
        $returns = app(SalesReturnService::class);
        $otherReturn = $returns->close($returns->authorize($returns->create($otherInvoice, SalesReturn::ReasonOrderEntry, 'SYNTHETIC available credit for allocation',
            [['customer_invoice_line_id' => $otherInvoice->lines->sole()->id, 'quantity' => '1']])));
        app(CustomerCreditService::class)->allocate($otherReturn->creditNote, $invoice->fresh(), '50', now()->toDateString(), $first->fresh());
    }
    $service = app(CustomerCreditApplicationEvidenceService::class);
    $f['payload']['source_fingerprint'] = $service->preview($f['credit'])['fingerprint'];
    $f['payload']['schedules'] = [['schedule_id' => $first->id, 'amount' => '50']];
    $this->postJson(route('admin.sales.sales-invoices.application-evidence.store', $f['credit']), $f['payload'])->assertUnprocessable();
    $f['payload']['schedules'] = [['schedule_id' => $second->id, 'amount' => '50']];
    $this->postJson(route('admin.sales.sales-invoices.application-evidence.store', $f['credit']), $f['payload'])->assertOk();
    expect(CustomerCreditApplicationEvidence::query()->where('company_id', $f['company']->id)->sole()->application_snapshot['schedules'])->toBe([['schedule_id' => $second->id, 'amount' => '50.0000']])
        ->and($first->fresh()->credited_amount)->toBe('50.0000')->and($second->fresh()->credited_amount)->toBe('50.0000');
})->with(['credit', 'allocation']);

test('approved original credit application retains active allocations and refunds until each real recovery is executed', function (): void {
    $f = historicalCreditEvidenceFixture('80');
    $credits = app(CustomerCreditService::class);
    $target = salesPostedServiceInvoice($f, '40');
    $allocation = $credits->allocate($f['credit'], $target, '10', now()->toDateString(), $target->paymentSchedules->sole());
    $refund = $credits->refund($f['credit']->fresh(), [
        'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id, 'refund_date' => now()->toDateString(),
        'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id, 'currency_id' => $f['currency']->id,
        'exchange_rate' => 1, 'amount' => '5', 'notes' => 'SYNTHETIC partial refund',
    ]);
    $f['payload']['source_fingerprint'] = app(CustomerCreditApplicationEvidenceService::class)->preview($f['credit'])['fingerprint'];
    $proposal = app(CustomerCreditApplicationEvidenceService::class)->prepare($f['credit'], $f['payload']);
    $this->actingAs($f['reviewer'])->withSession(salesCycleSession($f));
    app(CustomerCreditApplicationEvidenceService::class)->approve($f['credit'], $proposal->id, 'SYNTHETIC review includes active allocation and refund');
    expect($f['credit']->fresh()->credit_application_snapshot['applied_to_original'])->toBe('20.0000')
        ->and($f['credit']->fresh()->credit_available_amount)->toBe('15.0000')
        ->and($allocation->fresh()->status)->toBe('applied')->and($refund->fresh()->status)->toBe('posted');
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC ordered credit recovery'])->assertUnprocessable();
    $credits->reverseAllocation($allocation, 'SYNTHETIC release target installment');
    $credits->reverseRefund($refund, 'SYNTHETIC funds actually recovered', 'SYNTHETIC recovery receipt');
    $this->postJson(route('admin.sales.sales-returns.correct-closed', $f['return']), ['reason' => 'SYNTHETIC after explicit recoveries'])->assertOk();
    expect($f['invoice']->fresh()->paid_amount)->toBe('80.0000')->and($f['invoice']->fresh()->credited_amount)->toBe('0.0000')
        ->and($f['invoice']->fresh()->remaining_amount)->toBe('20.0000')->and($target->fresh()->remaining_amount)->toBe('40.0000')
        ->and($allocation->fresh()->status)->toBe('reversed')->and($refund->fresh()->status)->toBe('reversed');
});
