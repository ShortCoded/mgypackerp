<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel;
use Modules\Core\Services\NumericFormatService;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\CustomerWithholdingSettlementService;
use Modules\Sales\Services\SalesBalanceProjectionService;
use Modules\Sales\Services\SalesCycleAuditService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/SalesCycleSupport.php';

require_once __DIR__.'/CustomerWithholdingCollectionSupport.php';

test('actual certified withholding settles receivables separately from native cash and reverses exactly once', function (): void {
    $fixture = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    expect($fixture['invoice']->withholding_amount)->toBe('10.0000')->and($fixture['invoice']->net_payable_amount)->toBe('1130.0000')
        ->and($fixture['invoice']->fresh()->paid_amount)->toBe('1130.0000')->and($fixture['invoice']->fresh()->remaining_amount)->toBe('10.0000')
        ->and($fixture['invoice']->fresh()->actual_withholding_amount)->toBe('0.0000');
    $cashBefore = [$fixture['receipt']->fresh()->getAttributes(), $fixture['receipt']->journalEntry->lines->map->getAttributes()->all()];
    $payload = withholdingPayload($fixture);
    $proposal = $service->prepare($fixture['invoice'], $payload);
    expect($service->prepare($fixture['invoice'], $payload)->id)->toBe($proposal->id);
    expect(fn () => $service->approve($fixture['invoice'], $proposal->id, 'Same actor'))->toThrow(DomainException::class);
    withholdingActor($fixture, $fixture['approver']);
    $approved = $service->approve($fixture['invoice'], $proposal->id, 'SYNTHETIC independent certificate approval');
    $count = DB::table('journal_entries')->count();
    expect($service->approve($fixture['invoice'], $proposal->id, 'Replay')->journal_entry_id)->toBe($approved->journal_entry_id)
        ->and(DB::table('journal_entries')->count())->toBe($count)
        ->and($fixture['invoice']->fresh()->paid_amount)->toBe('1130.0000')
        ->and($fixture['invoice']->fresh()->actual_withholding_amount)->toBe('10.0000')->and($fixture['invoice']->fresh()->remaining_amount)->toBe('0.0000')
        ->and($fixture['schedule']->fresh()->outstanding_amount)->toBe('0.0000');
    $journal = DB::table('journal_entry_lines')->where('journal_entry_id', $approved->journal_entry_id)->get();
    expect(bcadd((string) $journal->where('account_id', $fixture['account']->id)->sum('debit_amount'), '0', 4))->toBe('10.0000');
    $service->assertEvidence($fixture['company']->id);
    expect(fn () => app(CustomerReceiptSettlementService::class)->reverse($fixture['receipt'], 'Cannot erase certified cash'))->toThrow(DomainException::class)
        ->and(fn () => app(SalesReturnService::class)->create($fixture['invoice'], 'commercial', 'Tax recovery required', [['customer_invoice_line_id' => $fixture['invoice']->lines->sole()->id, 'quantity' => '1']]))->toThrow(DomainException::class);
    $fixture['account']->update(['status' => 'inactive']);
    $reversed = $service->reverse($fixture['invoice'], $proposal->id, ['posting_date' => now()->toDateString(),
        'reason' => 'SYNTHETIC corrected certificate', 'recovery_reference' => 'SYNTHETIC-RECOVERY-1', 'attachment_doc_nums' => [$fixture['recovery']->doc_num]]);
    $count = DB::table('journal_entries')->count();
    expect($service->reverse($fixture['invoice'], $proposal->id, [])->id)->toBe($reversed->id)
        ->and(DB::table('journal_entries')->count())->toBe($count)->and($fixture['invoice']->fresh()->remaining_amount)->toBe('10.0000')
        ->and($fixture['invoice']->fresh()->paid_amount)->toBe('1130.0000')->and($fixture['invoice']->fresh()->actual_withholding_amount)->toBe('0.0000')
        ->and([$fixture['receipt']->fresh()->getAttributes(), $fixture['receipt']->fresh()->journalEntry->lines->map->getAttributes()->all()])->toBe($cashBefore);
    $service->assertEvidence($fixture['company']->id);
    app(CustomerReceiptSettlementService::class)->reverse($fixture['receipt'], 'SYNTHETIC native recovery after tax reversal');
    expect($fixture['invoice']->fresh()->paid_amount)->toBe('0.0000')->and($fixture['invoice']->fresh()->remaining_amount)->toBe('1140.0000')
        ->and(fn () => app(CustomerInvoiceService::class)->reopen($fixture['invoice'], 'SYNTHETIC preserve certified history'))
        ->toThrow(DomainException::class, __('sales_ui.wht.preserve_invoice_history'));
    $service->assertEvidence($fixture['company']->id);
});

test('certificate preparation rejects over settlement missing evidence and changed native source atomically', function (): void {
    $fixture = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $payload = withholdingPayload($fixture);
    foreach ([['amount' => '10.0001'], ['amount' => '-1'], ['attachment_doc_nums' => ['missing']], ['source_fingerprint' => str_repeat('0', 64)]] as $change) {
        expect(fn () => $service->prepare($fixture['invoice'], [...$payload, ...$change]))->toThrow(DomainException::class);
    }
    expect(CustomerWithholdingSettlement::query()->count())->toBe(0);
    $proposal = $service->prepare($fixture['invoice'], $payload);
    DB::table('customer_receipts')->where('id', $fixture['receipt']->id)->update(['amount' => '1131']);
    withholdingActor($fixture, $fixture['approver']);
    $before = DB::table('journal_entries')->count();
    expect(fn () => $service->approve($fixture['invoice'], $proposal->id, 'Changed source'))->toThrow(DomainException::class)
        ->and(DB::table('journal_entries')->count())->toBe($before)->and($proposal->fresh()->status)->toBe('prepared')
        ->and($fixture['invoice']->fresh()->actual_withholding_amount)->toBe('0.0000');
});

test('withholding certificate HTTP workflow renders both locales and enforces permission scope and independent approval', function (string $locale): void {
    $f = withholdingFixture();
    Permission::findOrCreate('customer_withholding_settlements.print', 'web');
    $f['approver']->givePermissionTo('customer_withholding_settlements.print');
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $url = route('admin.sales.sales-invoices.withholding.index', $f['invoice']);
    $selection = ['payment_schedule_id' => $f['schedule']->id, 'customer_receipt_id' => $f['receipt']->id];
    $this->get($url.'?'.http_build_query($selection))->assertOk()->assertSee(__('sales_ui.wht.title'))
        ->assertSee('source_fingerprint', false)->assertSee('data-picker-max="1"', false)->assertDontSee('sales_ui.wht.');
    $payload = withholdingPayload($f);
    $store = route('admin.sales.sales-invoices.withholding.store', $f['invoice']);
    $this->postJson($store, [...$payload, '_submission_token' => (string) Str::uuid(), 'attachment_doc_nums' => [$f['certificate']->doc_num, $f['certificate']->doc_num]])->assertUnprocessable();
    $this->postJson($store, [...$payload, '_submission_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'prepared');
    $record = CustomerWithholdingSettlement::query()->sole();
    $approve = route('admin.sales.sales-invoices.withholding.approve', [$f['invoice'], $record->id]);
    $this->postJson($approve, ['_submission_token' => (string) Str::uuid(), 'approval_reason' => 'Same actor'])->assertUnprocessable();
    withholdingActor($f, $f['approver']);
    $this->postJson($approve, ['_submission_token' => (string) Str::uuid(), 'approval_reason' => 'SYNTHETIC independent verification'])->assertOk()->assertJsonPath('data.status', 'approved');
    $this->get($url)->assertOk()->assertSee(__('sales_ui.wht.actual'))->assertSee(app(NumericFormatService::class)->format('1130'), false)->assertDontSee('sales_ui.wht.');
    $pdf = $this->get(route('admin.sales.sales-invoices.withholding.print', [$f['invoice'], $record->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 5))->toBe('%PDF-');
    $this->get(route('admin.sales.customer-withholding-settlements.index'))->assertOk()->assertSee('SYNTHETIC-CERT-1');
    $f['approver']->revokePermissionTo('customer_withholding_settlements.view');
    $this->get($url)->assertForbidden();
    $this->withSession(['current_branch_id' => 999999])->postJson($approve, ['_submission_token' => (string) Str::uuid(), 'approval_reason' => 'Wrong branch'])->assertNotFound();
})->with(['ar', 'en']);

test('certified tax retains business date balances and separate CSV XLSX cash and tax totals', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $f = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $payload = withholdingPayload($f);
    $this->travelTo(Carbon::parse('2026-10-02 12:00:00'));
    $record = $service->prepare($f['invoice'], [...$payload, 'posting_date' => '2026-10-02']);
    withholdingActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $record->id, 'SYNTHETIC dated approval');
    $projection = app(SalesBalanceProjectionService::class);
    $before = $projection->invoicesAt($f['company']->id, '2026-10-01')->findOrFail($f['invoice']->id);
    $after = $projection->invoicesAt($f['company']->id, '2026-10-02')->findOrFail($f['invoice']->id);
    expect($before->actual_withholding_amount)->toBe('0.0000')->and($before->remaining_amount)->toBe('10.0000')
        ->and($after->actual_withholding_amount)->toBe('10.0000')->and($after->paid_amount)->toBe('1130.0000')->and($after->remaining_amount)->toBe('0.0000');
    $schedule = $projection->schedulesAt($f['company']->id, '2026-10-01')->where('id', $f['schedule']->id)->first();
    expect(bcadd((string) $schedule->actual_withholding_amount, '0', 4))->toBe('0.0000');
    foreach (['view', 'export', 'print'] as $action) {
        Permission::findOrCreate('reports.sales.invoices.'.$action, 'web');
        $f['approver']->givePermissionTo('reports.sales.invoices.'.$action);
    }
    $response = $this->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices', 'to' => '2026-10-02', 'currency_doc_num' => $f['currency']->doc_num]))->assertOk();
    $report = $response->viewData('ledgerSummary');
    expect($report['collected'])->toBe('1130.0000')->and($report['actual_withholding'])->toBe('10.0000')->and($report['outstanding'])->toBe('0.0000');
    $invoice = $after->load(['customer', 'currency', 'order', 'deliveries', 'lines.product.category']);
    $export = new SalesCycleReportExport(['reportType' => 'invoices', 'salesLedger' => collect([$invoice]), 'ledgerSummary' => $report]);
    $sheet = $export->sheets()[0];
    $rows = $sheet->array();
    expect((string) $rows[0][12])->toBe('1130.0000')->and((string) $rows[0][17])->toBe('10.0000');
    $csv = Maatwebsite\Excel\Facades\Excel::raw(new SalesCycleReportExport(['reportType' => 'invoices', 'salesLedger' => collect([$invoice]), 'ledgerSummary' => $report], true), Excel::CSV);
    expect($csv)->toContain(__('sales_ui.reports.export.headings.actual_withholding'));
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    $service->reverse($f['invoice'], $approved->id, ['posting_date' => '2026-10-03', 'reason' => 'SYNTHETIC later certificate recovery', 'recovery_reference' => 'RECOVERY-DATED', 'attachment_doc_nums' => [$f['recovery']->doc_num]]);
    expect($projection->invoicesAt($f['company']->id, '2026-10-02')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('0.0000')
        ->and($projection->invoicesAt($f['company']->id, '2026-10-03')->findOrFail($f['invoice']->id)->remaining_amount)->toBe('10.0000');
});

test('actual certificate amount may differ from preview and subsequent cash refresh preserves certified tax', function (): void {
    $f = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $proposal = $service->prepare($f['invoice'], [...withholdingPayload($f), 'amount' => '5', 'reason' => 'SYNTHETIC certificate proves only 5 despite expected 10']);
    withholdingActor($f, $f['approver']);
    $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC independently checked actual 5');
    expect($f['invoice']->fresh()->actual_withholding_amount)->toBe('5.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('5.0000');
    $receipt = app(CustomerReceiptService::class)->createAndApprove(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'customer_id' => $f['customer']->id, 'currency_id' => $f['currency']->id, 'exchange_rate' => '1',
        'receipt_date' => now()->toDateString(), 'receipt_type' => CustomerReceipt::TypeCollection, 'payment_method' => 'bank', 'bank_account_id' => $f['bankAccount']->id, 'amount' => '5'],
        [['customer_invoice_payment_schedule_id' => $f['schedule']->id, 'amount' => '5']]);
    expect($f['invoice']->fresh()->paid_amount)->toBe('1135.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('0.0000')
        ->and($f['schedule']->fresh()->outstanding_amount)->toBe('0.0000');
    app(CustomerReceiptSettlementService::class)->reverse($receipt, 'SYNTHETIC recover second cash only');
    expect($f['invoice']->fresh()->paid_amount)->toBe('1130.0000')->and($f['invoice']->fresh()->remaining_amount)->toBe('5.0000');
    $service->assertEvidence($f['company']->id);
});

test('withholding proof detects persisted tampering and accepts previous signing keys', function (): void {
    $f = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $proposal = $service->prepare($f['invoice'], withholdingPayload($f));
    withholdingActor($f, $f['approver']);
    $approved = $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC independent approval');
    $previousKey = config('app.key');
    config(['app.key' => 'SYNTHETIC-ROTATED-KEY', 'app.previous_keys' => [$previousKey]]);
    $service->assertEvidence($f['company']->id);
    foreach ([['customer_withholding_settlements', $approved->id, 'amount', '11.0000'],
        ['customer_withholding_settlements', $approved->id, 'doc_num', (string) Str::uuid()],
        ['customer_invoices', $f['invoice']->id, 'actual_withholding_amount', '9.0000'],
        ['customer_invoices', $f['invoice']->id, 'total_amount', '1141.0000'],
        ['customer_invoices', $f['invoice']->id, 'invoice_date', now()->subDay()->toDateString()],
        ['customer_receipts', $f['receipt']->id, 'receipt_date', now()->subDay()->toDateString()],
        ['customer_receipts', $f['receipt']->id, 'payment_method', 'cash'],
        ['journal_entries', $f['receipt']->journal_entry_id, 'reversed_entry_id', $approved->journal_entry_id],
        ['customer_invoices', $f['invoice']->id, 'remaining_amount', '1.0000'],
        ['customer_invoice_payment_schedules', $f['schedule']->id, 'actual_withholding_amount', '9.0000']] as [$table, $id, $column, $value]) {
        $original = DB::table($table)->where('id', $id)->value($column);
        DB::table($table)->where('id', $id)->update([$column => $value]);
        expect(fn () => $service->assertEvidence($f['company']->id))->toThrow(DomainException::class);
        DB::table($table)->where('id', $id)->update([$column => $original]);
    }
    $line = DB::table('journal_entry_lines')->where('journal_entry_id', $approved->journal_entry_id)->where('debit_amount', '>', 0)->first();
    DB::table('journal_entry_lines')->where('id', $line->id)->update(['debit_amount' => '9']);
    expect(fn () => $service->assertEvidence($f['company']->id))->toThrow(DomainException::class);
    DB::table('journal_entry_lines')->where('id', $line->id)->update(['debit_amount' => $line->debit_amount]);
    Storage::disk('local')->put($f['certificate']->path, 'tampered certificate bytes');
    expect(fn () => $service->assertEvidence($f['company']->id))->toThrow(DomainException::class);
});

test('closed period duplicate certificate and failed audit cannot create financial side effects', function (): void {
    $f = withholdingFixture();
    $service = app(CustomerWithholdingSettlementService::class);
    $payload = withholdingPayload($f);
    $proposal = $service->prepare($f['invoice'], $payload);
    expect(fn () => $service->prepare($f['invoice'], [...$payload, 'amount' => '9', 'certificate_reference' => '  synthetic-cert-1 ']))->toThrow(DomainException::class);
    withholdingActor($f, $f['approver']);
    $journalCount = DB::table('journal_entries')->count();
    $f['period']->update(['is_closed' => true]);
    expect(fn () => $service->approve($f['invoice'], $proposal->id, 'SYNTHETIC closed'))->toThrow(NotFoundHttpException::class);
    $f['period']->update(['is_closed' => false]);
    withholdingActor($f, $f['approver']);
    $audit = Mockery::mock(SalesCycleAuditService::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('SYNTHETIC audit failure'));
    app()->instance(SalesCycleAuditService::class, $audit);
    expect(fn () => app(CustomerWithholdingSettlementService::class)->approve($f['invoice'], $proposal->id, 'SYNTHETIC failed audit'))->toThrow(RuntimeException::class);
    expect(DB::table('journal_entries')->count())->toBe($journalCount)->and($f['invoice']->fresh()->remaining_amount)->toBe('10.0000')
        ->and($proposal->fresh()->status)->toBe('prepared')->and($f['schedule']->fresh()->actual_withholding_amount)->toBe('0.0000');
});
