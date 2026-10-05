<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Services\ChequeService;
use Modules\Finance\Services\FinanceReportService;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Http\Controllers\SalesCycleReportController;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerCreditService;
use Modules\Sales\Services\CustomerReceiptApplicationHistoryService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerReceiptSettlementService;
use Modules\Sales\Services\SalesBalanceProjectionService;
use Modules\Sales\Services\SalesReturnCorrectionService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesReturnLaterPeriodSupport.php';

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** @param array<string,mixed> $f @return array<string,mixed> */
function datedSalesReport(array $f, string $type, string $date, bool $target = false, array $filters = []): array
{
    foreach (['view', 'print', 'export'] as $action) {
        $permission = Permission::findOrCreate("reports.sales.$type.$action", 'web');
        $f['user']->givePermissionTo($permission);
    }
    $session = salesCycleSession($f);
    if ($target) {
        $session[OperatingContextService::FinancialPeriodIdKey] = $f['target']->id;
        $session[OperatingContextService::FinancialPeriodDocNumKey] = $f['target']->doc_num;
    }
    test()->actingAs($f['user'])->withSession($session);
    $request = Request::create(route('admin.reports.sales.sales-orders.index'), 'GET', ['report' => $type, 'to' => $date, ...$filters]);
    $request->setUserResolver(fn () => $f['user']);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put($session);

    return app(SalesCycleReportController::class)->index($request)->getData();
}

test('dated invoice and installment balances preserve the source period after a later return correction', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $projection = app(SalesBalanceProjectionService::class);
    $before = $projection->invoicesAt($f['company']->id, '2026-09-30')->findOrFail($f['invoice']->id);
    expect($before->paid_amount)->toBe('80.0000')->and($before->credited_amount)->toBe('20.0000')->and($before->remaining_amount)->toBe('0.0000');
    $proposal = app(SalesReturnCorrectionService::class)->prepare($f['return'], laterReturnPayload($f));
    laterReturnActor($f, $f['reviewer']);
    app(SalesReturnCorrectionService::class)->approve($f['return'], $proposal->id, 'SYNTHETIC dated report approval');
    $projection->assertCorrectionEvidence($f['company']->id);
    foreach (['2026-09-30', '2026-10-01'] as $date) {
        $invoice = $projection->invoicesAt($f['company']->id, $date)->findOrFail($f['invoice']->id);
        $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $invoice->id)->sole();
        expect($invoice->paid_amount)->toBe($before->paid_amount)->and($invoice->credited_amount)->toBe($before->credited_amount)
            ->and($invoice->remaining_amount)->toBe($before->remaining_amount)
            ->and(bccomp((string) $schedule->credited_amount, '20', 4))->toBe(0)
            ->and($projection->activeCreditDocumentIdsAt($f['company']->id, $date)->count())->toBe(1);
    }
    $onDate = $projection->invoicesAt($f['company']->id, '2026-10-02')->findOrFail($f['invoice']->id);
    expect($onDate->remaining_amount)->toBe('20.0000')->and($onDate->credited_amount)->toBe('0.0000')
        ->and($projection->activeCreditDocumentIdsAt($f['company']->id, '2026-10-02')->count())->toBe(0);
});

test('dated external credit allocation remains applied until its independently approved reversal business date', function (): void {
    $f = laterReturnFixture();
    $target = salesPostedServiceInvoice($f, '40');
    $allocation = app(CustomerCreditService::class)->allocate($f['credit'], $target, '10', '2026-09-28', $target->paymentSchedules->sole());
    $f = laterReturnCloseSource($f);
    $service = app(SalesReturnCorrectionService::class);
    $proposal = $service->prepare($f['return'], laterReturnPayload($f, ['operation' => 'allocation', 'source_id' => $allocation->id]));
    laterReturnActor($f, $f['reviewer']);
    $service->approve($f['return'], $proposal->id, 'SYNTHETIC independently approved allocation');
    $projection = app(SalesBalanceProjectionService::class);
    foreach (['2026-09-30' => '30', '2026-10-01' => '30', '2026-10-02' => '40'] as $date => $remaining) {
        $invoice = $projection->invoicesAt($f['company']->id, $date)->findOrFail($target->id);
        $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $target->id)->sole();
        expect(bccomp($invoice->remaining_amount, $remaining, 4))->toBe(0)
            ->and(bccomp(bcsub((string) $schedule->amount, (string) $schedule->credited_amount, 4), $remaining, 4))->toBe(0);
    }
});

test('dated receipts exclude future posted cash collections from invoice and schedule history', function (): void {
    $f = laterReturnFixture();
    $invoice = salesPostedServiceInvoice($f, '40');
    $f = laterReturnCloseSource($f);
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $f['company']->id, 'financial_period_id' => $f['target']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'receipt_date' => '2026-10-02', 'currency_id' => $f['currency']->id,
        'exchange_rate' => 1, 'payment_method' => 'cash', 'cashbox_id' => $f['cashbox']->id,
        'amount' => '10', 'receipt_type' => CustomerReceipt::TypeCollection,
    ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->id, 'amount' => '10']]);
    $projection = app(SalesBalanceProjectionService::class);
    expect($invoice->fresh()->remaining_amount)->toBe('30.0000');
    foreach (['2026-09-30' => '40', '2026-10-01' => '40', '2026-10-02' => '30'] as $date => $remaining) {
        $row = $projection->invoicesAt($f['company']->id, $date)->findOrFail($invoice->id);
        $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $invoice->id)->sole();
        expect(bccomp($row->remaining_amount, $remaining, 4))->toBe(0)
            ->and(bccomp(bcsub((string) $schedule->amount, (string) $schedule->collected_amount, 4), $remaining, 4))->toBe(0);
    }
});

test('closed source sales reports and later credit reversal outputs use the same business cutoff', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $before = datedSalesReport($f, 'financial', '2026-09-30');
    $beforeLedger = datedSalesReport($f, 'invoices', '2026-09-30');
    $beforeReturns = datedSalesReport($f, 'returns', '2026-09-30');
    laterReturnActor($f, $f['user']);
    $proposal = app(SalesReturnCorrectionService::class)->prepare($f['return'], laterReturnPayload($f));
    laterReturnActor($f, $f['reviewer']);
    app(SalesReturnCorrectionService::class)->approve($f['return'], $proposal->id, 'SYNTHETIC dated outputs');
    $after = datedSalesReport($f, 'financial', '2026-09-30');
    $afterLedger = datedSalesReport($f, 'invoices', '2026-09-30');
    $afterReturns = datedSalesReport($f, 'returns', '2026-09-30');
    expect($after['financialSummary'])->toBe($before['financialSummary'])
        ->and($afterLedger['ledgerSummary'])->toBe($beforeLedger['ledgerSummary'])
        ->and($afterReturns['returnsSummary'])->toBe($beforeReturns['returnsSummary'])
        ->and(bccomp((string) $afterLedger['salesLedger']->first()->lines->sole()->returned_quantity, '1', 8))->toBe(0)
        ->and($after['creditMovements']->sole()['signed_amount'])->toBe('-50.0000');
    $octoberOne = datedSalesReport($f, 'financial', '2026-10-01', target: true);
    $october = datedSalesReport($f, 'financial', '2026-10-02', target: true);
    expect($octoberOne['creditMovements'])->toBeEmpty()->and($octoberOne['financialSummary']['net_sales'])->toBe('0.0000')
        ->and($october['financialSummary']['net_sales'])->toBe('50.0000')
        ->and($october['creditMovements']->sole()['kind'])->toBe('credit_note_reversal')
        ->and($october['creditMovements']->sole()['posting_date'])->toBe('2026-10-02');
    $params = ['report' => 'financial', 'to' => '2026-10-02'];
    $screen = test()->get(route('admin.reports.sales.sales-orders.index', $params))->assertOk()->getContent();
    expect($screen)->toContain('data-credit-movement-total="net_sales"')->toContain('Credit note reversal');
    $pdf = test()->get(route('admin.reports.sales.sales-orders.print', $params))->assertOk()->getContent();
    file_put_contents('/tmp/mgypack-dated-sales-correction-'.DB::getDriverName().'-20261003.pdf', $pdf);
    expect(substr($pdf, 0, 4))->toBe('%PDF')->and(salesPdfText($pdf))->toMatch('/Credit note reversal\s+50(?:\.0+)?\b/');
    foreach ([ExcelWriter::XLSX, ExcelWriter::CSV] as $format) {
        $bytes = Excel::raw(new SalesCycleReportExport($october, $format === ExcelWriter::CSV), $format);
        $path = tempnam(sys_get_temp_dir(), 'dated-sales-');
        file_put_contents($path, $bytes);
        try {
            $workbook = IOFactory::load($path);
            $rows = collect($workbook->getAllSheets())->flatMap(fn ($sheet) => $sheet->toArray());
            $movements = $rows->filter(fn (array $row) => in_array('Credit note reversal', $row, true));
            expect($movements)->toHaveCount(1)->and(in_array('50.0000', $movements->sole(), true))->toBeTrue()
                ->and(in_array(app(DateFormatService::class)->formatDate('2026-10-02'), $movements->sole(), true))->toBeTrue();
        } finally {
            unlink($path);
        }
    }
});

test('finance customer aging uses dated invoice and installment balances after later return correction', function (): void {
    $f = laterReturnCloseSource(laterReturnFixture());
    $proposal = app(SalesReturnCorrectionService::class)->prepare($f['return'], laterReturnPayload($f));
    laterReturnActor($f, $f['reviewer']);
    app(SalesReturnCorrectionService::class)->approve($f['return'], $proposal->id, 'SYNTHETIC finance aging');
    $base = ['type' => FinanceReportService::CustomerAging, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id];
    $finance = app(FinanceReportService::class);
    expect($finance->report([...$base, 'as_of_date' => '2026-09-30'])['rows'])->toBeEmpty();
    $onDate = $finance->report([...$base, 'as_of_date' => '2026-10-02']);
    expect($onDate['rows'])->toHaveCount(1)->and($onDate['rows']->sole()['outstanding'])->toBe('20.0000')
        ->and($onDate['rows']->sole()['settled_amount'])->toBe('80.0000');
});

test('legacy cheque deferral and recollection retain immutable per installment application amounts and dates', function (): void {
    $f = laterReturnFixture();
    $invoice = salesPostedServiceInvoice($f, '40');
    $receipt = app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'customer_id' => $f['customer']->id, 'receipt_date' => '2026-09-28', 'currency_id' => $f['currency']->id,
        'exchange_rate' => 1, 'payment_method' => 'cheque', 'bank_account_id' => $f['bankAccount']->id,
        'reference_no' => 'SYNTHETIC-LEGACY-PENDING', 'external_bank_name' => 'SYNTHETIC Bank',
        'amount' => '35', 'receipt_type' => CustomerReceipt::TypeCollection,
    ], [['customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->sole()->id, 'amount' => '35']]);
    $cheques = app(ChequeService::class);
    $cheques->markCollected($cheques->markDeposited($receipt->cheque));
    $receipt = $receipt->fresh();
    $receipt->cheque->update(['status' => 'received']);
    DB::table('customer_receipt_application_events')->where('receipt_id', $receipt->id)->delete();
    $receipt->allocations()->update(['settlement_evidence' => null]);
    $f = laterReturnCloseSource($f);
    Carbon::setTestNow('2026-10-02 12:00:00');
    CarbonImmutable::setTestNow('2026-10-02 12:00:00');
    app(CustomerReceiptSettlementService::class)->deferLegacyCheque($receipt);
    $projection = app(SalesBalanceProjectionService::class);
    foreach (['2026-09-30' => '35', '2026-10-01' => '35', '2026-10-02' => '0'] as $date => $paid) {
        $row = $projection->invoicesAt($f['company']->id, $date)->findOrFail($invoice->id);
        $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $invoice->id)->sole();
        expect(bccomp($row->paid_amount, $paid, 4))->toBe(0)->and(bccomp((string) $schedule->collected_amount, $paid, 4))->toBe(0);
    }
    app(CustomerCreditService::class)->allocate($f['credit'], $invoice->fresh(), '10', '2026-10-02', $invoice->paymentSchedules->sole());
    Carbon::setTestNow('2026-10-03 12:00:00');
    CarbonImmutable::setTestNow('2026-10-03 12:00:00');
    $cheques->markCollected($cheques->markDeposited($receipt->cheque->fresh()));
    $projection->assertCorrectionEvidence($f['company']->id);
    foreach (['2026-09-30' => '35', '2026-10-02' => '0', '2026-10-03' => '30'] as $date => $paid) {
        $row = $projection->invoicesAt($f['company']->id, $date)->findOrFail($invoice->id);
        $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $invoice->id)->sole();
        expect(bccomp($row->paid_amount, $paid, 4))->toBe(0)->and(bccomp((string) $schedule->collected_amount, $paid, 4))->toBe(0);
    }
    expect(DB::table('customer_receipt_application_events')->where('receipt_id', $receipt->id)->orderBy('id')->pluck('amount')->map(fn ($amount) => bcadd((string) $amount, '0', 4))->all())
        ->toBe(['35.0000', '-35.0000', '30.0000']);
    $originalEvents = DB::table('customer_receipt_application_events')->where('receipt_id', $receipt->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    foreach ([[$originalEvents[1]['id']], [$originalEvents[0]['id'], $originalEvents[1]['id']], array_column($originalEvents, 'id')] as $missing) {
        DB::table('customer_receipt_application_events')->whereIn('id', $missing)->delete();
        expect(fn () => $projection->assertCorrectionEvidence($f['company']->id))->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
        DB::table('customer_receipt_application_events')->insert(array_values(array_filter($originalEvents, fn ($row) => in_array($row['id'], $missing, true))));
    }
    $projection->assertCorrectionEvidence($f['company']->id);
});

test('dated collection evidence rejects an unposted inverse and credit allocation rejects currency mismatch atomically', function (): void {
    $f = laterReturnFixture();
    $target = salesPostedServiceInvoice($f, '40');
    $currency = Currency::query()->create(['company_id' => $f['company']->id, 'doc_number' => 9821, 'doc_num' => 'SYNTHETIC-USD-9821',
        'name' => 'SYNTHETIC USD', 'name_en' => 'SYNTHETIC USD', 'code' => 'USD', 'status' => 'active', 'is_main' => false,
        'minor_unit_name' => 'Cent', 'minor_unit_factor' => 100]);
    $target->update(['currency_id' => $currency->id]);
    expect(fn () => app(CustomerCreditService::class)->allocate($f['credit'], $target, '10', '2026-09-28', $target->paymentSchedules->sole()))
        ->toThrow(DomainException::class, __('sales_balance_report.credit_currency_mismatch'));
    expect($target->fresh()->credited_amount)->toBe('0.0000')->and($f['credit']->fresh()->credit_available_amount)->toBe('30.0000');
    $receipt = CustomerReceipt::query()->where('company_id', $f['company']->id)->sole();
    $f = laterReturnCloseSource($f);
    app(CustomerReceiptSettlementService::class)->reverse($receipt, 'SYNTHETIC valid inverse');
    app(SalesBalanceProjectionService::class)->assertCorrectionEvidence($f['company']->id);
    JournalEntry::query()->findOrFail($receipt->fresh()->reversal_journal_entry_id)->update(['is_posted' => false]);
    expect(fn () => app(SalesBalanceProjectionService::class)->assertCorrectionEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
});

test('receipt history follows retained signing keys and refuses scope drift or destructive evidence rollback', function (): void {
    $f = laterReturnFixture();
    $history = app(CustomerReceiptApplicationHistoryService::class);
    $key = config('app.key');
    $previous = config('app.previous_keys');
    try {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('N', 32)), 'app.previous_keys' => [$key]]);
        $history->assertEvidence($f['company']->id);
        config(['app.previous_keys' => []]);
        expect(fn () => $history->assertEvidence($f['company']->id))->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
    } finally {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
    }
    foreach (['2026_10_03_195742_create_customer_receipt_application_events_table.php', '2026_10_03_200656_add_settlement_evidence_to_customer_receipt_allocations_table.php'] as $file) {
        $migration = require database_path('migrations/'.$file);
        expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    }
    $target = salesPostedServiceInvoice($f, '40');
    $schedule = $f['invoice']->paymentSchedules->sole();
    $schedule->update(['customer_invoice_id' => $target->id, 'sequence' => 9822]);
    expect(fn () => $history->assertEvidence($f['company']->id))->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
    $schedule->update(['customer_invoice_id' => $f['invoice']->id, 'sequence' => 1]);
    $otherCustomer = $f['customer']->replicate()->forceFill(['doc_number' => 9822, 'account_id' => null,
        'doc_num' => 'SYNTHETIC-HISTORY-CUSTOMER-9822', 'name' => 'SYNTHETIC reassigned customer']);
    $otherCustomer->save();
    $f['invoice']->update(['customer_id' => $otherCustomer->id]);
    expect(fn () => $history->assertEvidence($f['company']->id))->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
});

test('receipt event seals bind signing keys and authenticate legacy history through rotation and dated reversal', function (): void {
    $f = laterReturnFixture();
    $receipt = CustomerReceipt::query()->where('company_id', $f['company']->id)->sole();
    $allocation = $receipt->allocations()->sole();
    $history = app(CustomerReceiptApplicationHistoryService::class);
    $event = (array) DB::table('customer_receipt_application_events')->where('allocation_id', $allocation->id)->sole();
    $eventSeal = new ReflectionMethod($history, 'seal');
    $manifestSeal = new ReflectionMethod($history, 'manifestSeal');
    $key = config('app.key');
    $previous = config('app.previous_keys');
    expect($eventSeal->invoke($history, $event, 'SYNTHETIC-first-signing-key'))
        ->not->toBe($eventSeal->invoke($history, $event, 'SYNTHETIC-second-signing-key'))
        ->and($event['evidence_seal'])->toBe($eventSeal->invoke($history, $event, $key));

    $event['evidence_seal'] = $eventSeal->invoke($history, $event, 'created_by');
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update(['evidence_seal' => $event['evidence_seal']]);
    $manifest = ['count' => 1, 'digest' => hash('sha256', $event['id'].'|'.$event['evidence_seal'])];
    $manifest['seal'] = $manifestSeal->invoke($history, $allocation, $manifest, $key);
    $allocation->update(['settlement_evidence' => $manifest]);
    $history->assertEvidence($f['company']->id);

    try {
        $newKey = 'base64:'.base64_encode(str_repeat('R', 32));
        config(['app.key' => $newKey, 'app.previous_keys' => [$key]]);
        $history->assertEvidence($f['company']->id);
        config(['app.previous_keys' => []]);
        expect(fn () => $history->assertEvidence($f['company']->id))
            ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
        config(['app.previous_keys' => [$key]]);

        $journal = $receipt->journalEntry;
        $sourceType = $journal->source_type;
        $journal->update(['source_type' => 'SYNTHETIC-untrusted-receipt-source']);
        expect(fn () => $history->assertEvidence($f['company']->id))
            ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
        $journal->update(['source_type' => $sourceType]);

        $f = laterReturnCloseSource($f);
        app(CustomerReceiptSettlementService::class)->reverse($receipt, 'SYNTHETIC authenticated legacy reversal');
        expect((array) DB::table('customer_receipt_application_events')->where('id', $event['id'])->sole())->toBe($event);
        $inverse = (array) DB::table('customer_receipt_application_events')->where('allocation_id', $allocation->id)->orderByDesc('id')->first();
        expect($inverse['evidence_seal'])->toBe($eventSeal->invoke($history, $inverse, $newKey))
            ->not->toBe($eventSeal->invoke($history, $inverse, 'created_by'));
        config(['app.previous_keys' => []]);
        $history->assertEvidence($f['company']->id);
        $projection = app(SalesBalanceProjectionService::class);
        $projection->assertCorrectionEvidence($f['company']->id);
        foreach (['2026-09-30' => '80', '2026-10-02' => '80', '2026-10-03' => '0'] as $date => $paid) {
            $invoice = $projection->invoicesAt($f['company']->id, $date)->findOrFail($f['invoice']->id);
            $schedule = $projection->schedulesAt($f['company']->id, $date)->where('customer_invoice_id', $invoice->id)->sole();
            expect(bccomp($invoice->paid_amount, $paid, 4))->toBe(0)
                ->and(bccomp((string) $schedule->collected_amount, $paid, 4))->toBe(0);
        }
    } finally {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
    }
});

test('legacy receipt event evidence rejects company drift public resealing and missing manifests', function (): void {
    $f = laterReturnFixture();
    $receipt = CustomerReceipt::query()->where('company_id', $f['company']->id)->sole();
    $allocation = $receipt->allocations()->sole();
    $history = app(CustomerReceiptApplicationHistoryService::class);
    $event = (array) DB::table('customer_receipt_application_events')->where('allocation_id', $allocation->id)->sole();
    $otherCompanyId = DB::table('companies')->where('id', '!=', $f['company']->id)->value('id');
    expect($otherCompanyId)->not->toBeNull();
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update(['company_id' => $otherCompanyId]);
    expect(fn () => $history->assertEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update(['company_id' => $event['company_id']]);

    $eventSeal = new ReflectionMethod($history, 'seal');
    $manifestSeal = new ReflectionMethod($history, 'manifestSeal');
    $event['evidence_seal'] = $eventSeal->invoke($history, $event, 'created_by');
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update(['evidence_seal' => $event['evidence_seal']]);
    $manifest = ['count' => 1, 'digest' => hash('sha256', $event['id'].'|'.$event['evidence_seal'])];
    $manifest['seal'] = $manifestSeal->invoke($history, $allocation, $manifest, config('app.key'));
    $allocation->update(['settlement_evidence' => $manifest]);
    $history->assertEvidence($f['company']->id);

    $forgedEvent = [...$event, 'created_by' => $f['reviewer']->id];
    $forgedEvent['evidence_seal'] = $eventSeal->invoke($history, $forgedEvent, 'created_by');
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update([
        'created_by' => $forgedEvent['created_by'], 'evidence_seal' => $forgedEvent['evidence_seal'],
    ]);
    expect(fn () => $history->assertEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
    $forgedManifest = ['count' => 1, 'digest' => hash('sha256', $event['id'].'|'.$forgedEvent['evidence_seal'])];
    $forgedManifest['seal'] = $manifestSeal->invoke($history, $allocation, $forgedManifest, 'created_by');
    $allocation->update(['settlement_evidence' => $forgedManifest]);
    expect(fn () => $history->assertEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
    $allocation->update(['settlement_evidence' => null]);
    expect(fn () => $history->assertEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));

    DB::table('customer_receipt_application_events')->where('id', $event['id'])->update([
        'created_by' => $event['created_by'], 'evidence_seal' => $event['evidence_seal'],
    ]);
    $allocation->update(['settlement_evidence' => $manifest]);
    $history->assertEvidence($f['company']->id);
    DB::table('customer_receipt_application_events')->where('id', $event['id'])->delete();
    expect(fn () => $history->assertEvidence($f['company']->id))
        ->toThrow(DomainException::class, __('sales_balance_report.invalid_receipt_evidence'));
});
