<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Sales\Exports\SalesCycleReportExport;
use Modules\Sales\Http\Controllers\SalesCycleReportController;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\SalesCycleReadService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

function salesReportTotalsPermissions(array $fixture): array
{
    $permissions = collect(['invoices', 'products', 'financial', 'period', 'receivables', 'collections', 'returns', 'customers'])
        ->flatMap(fn (string $report): array => ["reports.sales.{$report}.view", "reports.sales.{$report}.print", "reports.sales.{$report}.export"])
        ->all();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['user']->givePermissionTo($permissions);

    return $permissions;
}

function salesReportTotalsInvoice(array $fixture, string $docNum, string $total, array $overrides = []): CustomerInvoice
{
    return CustomerInvoice::query()->create([
        'doc_number' => crc32($docNum),
        'doc_num' => $docNum,
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'document_type' => CustomerInvoice::TypeInvoice,
        'posting_status' => CustomerInvoice::StatusPosted,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addMonth()->toDateString(),
        'subtotal_amount' => $total,
        'discount_amount' => '0.0000',
        'tax_amount' => '0.0000',
        'total_amount' => $total,
        'paid_amount' => '0.0000',
        'credited_amount' => '0.0000',
        'remaining_amount' => $total,
        ...$overrides,
    ]);
}

function salesReportTotalsCredit(array $fixture, string $docNum, string $total, int $originalInvoiceId, array $overrides = []): CustomerInvoice
{
    return CustomerInvoice::query()->create([
        'doc_number' => crc32($docNum),
        'doc_num' => $docNum,
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'document_type' => CustomerInvoice::TypeCreditNote,
        'posting_status' => CustomerInvoice::StatusPosted,
        'original_invoice_id' => $originalInvoiceId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'subtotal_amount' => $total,
        'discount_amount' => '0.0000',
        'tax_amount' => '0.0000',
        'total_amount' => $total,
        'paid_amount' => '0.0000',
        'credited_amount' => '0.0000',
        'remaining_amount' => '0.0000',
        ...$overrides,
    ]);
}

function salesReportFooterValue(string $html, string $attr, string $key): ?string
{
    preg_match('/data-'.$attr.'="'.$key.'"[^>]*>\s*([^<]+)\s*</', $html, $matches);

    return isset($matches[1]) ? trim($matches[1]) : null;
}

function salesReportTotalsBc(?string $rendered, string $expected, int $scale = 4): bool
{
    return $rendered !== null && bccomp(str_replace(',', '', $rendered), $expected, $scale) === 0;
}

function salesReportLedgerTotals(string $html): array
{
    $totals = [];
    foreach (['net_sales', 'collected', 'outstanding'] as $key) {
        preg_match('/data-ledger-total="'.$key.'"[^>]*>\s*([^<]+)\s*</', $html, $matches);
        $totals[$key] = trim($matches[1] ?? '');
    }

    return $totals;
}

function salesReportControllerData(array $fixture, array $session, array $params = []): array
{
    $report = app()->make(SalesCycleReportController::class);
    $request = Request::create(
        route('admin.reports.sales.sales-orders.index'),
        'GET',
        ['report' => 'invoices', ...$params]
    );
    $request->setUserResolver(fn () => $fixture['user']);
    $request->setLaravelSession(session()->driver());
    foreach ($session as $key => $value) {
        session()->put($key, $value);
    }

    return $report->index($request)->getData();
}

test('sales ledger summary uses exact decimal strings and matches screen export parity', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    $first = salesPostedServiceInvoice($fixture, '100.0000');
    $second = salesPostedServiceInvoice($fixture, '200.5000');
    salesReportTotalsCredit($fixture, 'CN-SAL005-1', '50.0000', $first->getKey());

    $data = salesReportControllerData($fixture, $session);
    $ledger = $data['ledgerSummary'];

    /** Exact decimal strings, never float. */
    expect($ledger['gross_sales'])->toBe('300.5000')
        ->and($ledger['returns_amount'])->toBe('50.0000')
        ->and($ledger['net_sales'])->toBe('250.5000')
        ->and($ledger['collected'])->toBe('0.0000')
        ->and($ledger['outstanding'])->toBe('300.5000')
        ->and($ledger['invoice_count'])->toBe(2)
        ->and(bccomp($ledger['net_sales'], bcsub($ledger['gross_sales'], $ledger['returns_amount'], 4), 4))->toBe(0);

    /** Row-level withSum contribution matches the summary credit total. */
    $rows = $data['salesLedger'] instanceof Paginator
        ? $data['salesLedger']->items()
        : $data['salesLedger']->all();
    $rowReturns = '0.0000';
    foreach ($rows as $row) {
        $rowReturns = bcadd($rowReturns, (string) ($row->returns_amount ?? 0), 4);
    }
    expect(bccomp($rowReturns, $ledger['returns_amount'], 4))->toBe(0);

    $screen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices']))
        ->assertOk()->getContent();
    expect($screen)->toContain('data-ledger-total="net_sales"');
    expect(bccomp(str_replace(',', '', salesReportLedgerTotals($screen)['net_sales']), '250.5000', 4))->toBe(0);

    $pdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'invoices']))
        ->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');
    expect(salesPdfText($pdf->getContent()))->toContain('Totals', '250.5');

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.export', ['report' => 'invoices', 'format' => 'xlsx']))
        ->assertOk()->assertDownload();
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.export', ['report' => 'invoices', 'format' => 'csv']))
        ->assertOk()->assertDownload();

    $data['salesLedger'] = collect($rows);
    $sheets = (new SalesCycleReportExport($data))->sheets();
    $ledgerSheet = $sheets['invoices'][0] ?? $sheets[0];
    $last = collect($ledgerSheet->array())->last();
    expect($last[2])->toContain('TOTAL');
    expect(bccomp((string) $last[11], $ledger['net_sales'], 4))->toBe(0)
        ->and(bccomp((string) $last[12], $ledger['collected'], 4))->toBe(0)
        ->and(bccomp((string) $last[13], $ledger['outstanding'], 4))->toBe(0);
});

test('sales ledger totals are pagination independent beyond twenty five rows', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    for ($i = 1; $i <= 30; $i++) {
        salesReportTotalsInvoice($fixture, 'SAL005-PAGE-'.$i, '10.0000');
    }

    $data = salesReportControllerData($fixture, $session);
    expect($data['ledgerSummary']['gross_sales'])->toBe('300.0000')
        ->and($data['ledgerSummary']['invoice_count'])->toBe(30);

    $page1 = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices', 'ledger_page' => 1]))
        ->assertOk()->getContent();
    $page2 = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices', 'ledger_page' => 2]))
        ->assertOk()->getContent();

    expect(salesReportLedgerTotals($page1))->toEqual(salesReportLedgerTotals($page2));
    expect(bccomp(str_replace(',', '', salesReportLedgerTotals($page1)['net_sales']), '300.0000', 4))->toBe(0);
    expect($page1)->toContain('SAL005-PAGE-30')
        ->and($page2)->not->toContain('SAL005-PAGE-30');
    expect($page1)->toContain('(30)');
});

test('sales ledger respects inclusive date bounds customer product order status and isolation', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    $today = now()->toDateString();
    $serviceInvoice = salesPostedServiceInvoice($fixture, '77.2500');
    salesReportTotalsInvoice($fixture, 'SAL005-OLD', '500.0000', ['invoice_date' => now()->subYear()->toDateString()]);
    salesReportTotalsInvoice($fixture, 'SAL005-KEEP', '77.2500');
    $otherPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9501,
        'doc_num' => 'FP-SAL005-OTHER',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'SAL005 Other Period',
        'from_date' => now()->subYear()->toDateString(),
        'to_date' => now()->subYear()->addMonth()->toDateString(),
    ]);
    salesReportTotalsInvoice($fixture, 'SAL005-OTHER-PERIOD', '500.0000', ['financial_period_id' => $otherPeriod->getKey()]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 9501,
        'doc_num' => 'BR-SAL005-OTHER',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'SAL005 Other Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    salesReportTotalsInvoice($fixture, 'SAL005-OTHER-BRANCH', '500.0000', ['branch_id' => $otherBranch->getKey()]);
    $otherCurrency = Currency::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9501,
        'doc_num' => 'CUR-SAL005',
        'code' => 'S05',
        'name' => 'SAL005 Other',
        'status' => 'active',
        'is_main' => false,
    ]);
    salesReportTotalsInvoice($fixture, 'SAL005-FX', '500.0000', ['currency_id' => $otherCurrency->getKey()]);

    /** Inclusive bounds: an invoice dated exactly today is kept when from == to == today. */
    $bounded = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices', 'from' => $today, 'to' => $today,
        ]))->assertOk()->getContent();
    expect($bounded)->toContain('SAL005-KEEP')
        ->and($bounded)->not->toContain('SAL005-OLD');

    $filtered = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices',
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addMonth()->toDateString(),
        ]))->assertOk()->getContent();
    expect($filtered)->toContain('SAL005-KEEP')
        ->and($filtered)->not->toContain('SAL005-OLD')
        ->and($filtered)->not->toContain('SAL005-OTHER-PERIOD')
        ->and($filtered)->not->toContain('SAL005-OTHER-BRANCH')
        ->and($filtered)->not->toContain('SAL005-FX');

    /** Customer filter keeps only that customer's invoices. */
    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices', 'customer_doc_num' => $fixture['customer']->doc_num,
        ]))->assertOk()->assertSee('SAL005-KEEP');

    /** Product filter follows invoice lines: the service product matches service
        invoices only, while the finished product matches nothing here. */
    $productMatch = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'products', 'product_doc_num' => $fixture['service']->doc_num,
        ]))->assertOk()->getContent();
    expect($productMatch)->toContain($fixture['service']->doc_num);
    $productMiss = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices', 'product_doc_num' => $fixture['finished']->doc_num,
        ]))->assertOk()->getContent();
    expect($productMiss)->not->toContain('SAL005-KEEP')
        ->and($productMiss)->toContain('Totals');

    /** Order-status filter follows the parent order: approved keeps the service
        invoice, a non-matching status yields zero rows but still renders totals. */
    $statusMatch = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices', 'order_status' => 'approved',
        ]))->assertOk()->getContent();
    expect($statusMatch)->toContain($serviceInvoice->doc_num)
        ->and($statusMatch)->not->toContain('SAL005-KEEP');
    $statusMiss = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', [
            'report' => 'invoices', 'order_status' => 'cancelled',
        ]))->assertOk()->getContent();
    expect($statusMiss)->toContain('Totals');

    $guest = User::factory()->create();
    $this->actingAs($guest)->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices']))
        ->assertForbidden();
});

test('sales ledger excludes cross period and out of range credit notes', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    $invoice = salesReportTotalsInvoice($fixture, 'SAL005-XP-INV', '400.0000');
    $otherPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9502,
        'doc_num' => 'FP-SAL005-XP',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'SAL005 Cross Period',
        'from_date' => now()->subYear()->toDateString(),
        'to_date' => now()->subYear()->addMonth()->toDateString(),
    ]);
    /** Same-period credit note counts; other-period and out-of-range credit notes do not. */
    salesReportTotalsCredit($fixture, 'CN-SAL005-XP-OK', '100.0000', $invoice->getKey());
    salesReportTotalsCredit($fixture, 'CN-SAL005-XP-OTHER-PERIOD', '100.0000', $invoice->getKey(), [
        'financial_period_id' => $otherPeriod->getKey(),
    ]);
    salesReportTotalsCredit($fixture, 'CN-SAL005-XP-OLD-DATE', '100.0000', $invoice->getKey(), [
        'invoice_date' => now()->subYear()->toDateString(),
    ]);

    $data = salesReportControllerData($fixture, $session, [
        'from' => now()->subMonth()->toDateString(),
        'to' => now()->addMonth()->toDateString(),
    ]);
    expect($data['ledgerSummary']['gross_sales'])->toBe('400.0000')
        ->and($data['ledgerSummary']['returns_amount'])->toBe('100.0000')
        ->and($data['ledgerSummary']['net_sales'])->toBe('300.0000');
});

test('sales ledger return quantity excludes cancelled returns only', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $returns = app(SalesReturnService::class);

    $order = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Finished Crate',
            'quantity' => '2',
            'unit_price' => '10',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Full order value',
            'amount' => '20',
            'due_date' => now()->addMonth()->toDateString(),
        ]],
    ])));
    $orderLine = $order->lines->sole();
    $delivery = $fulfillment->deliver($order, [[
        'sales_order_line_id' => $orderLine->getKey(), 'quantity' => '2',
    ]]);
    $invoice = $invoices->post($invoices->createFromOrder($order->fresh(), [[
        'sales_order_line_id' => $orderLine->getKey(),
        'delivery_line_id' => $delivery->lines->sole()->getKey(),
        'quantity' => '2',
    ]], [[
        'due_date' => now()->addMonth()->toDateString(), 'amount' => '20',
    ]], $delivery));
    $invoiceLine = $invoice->lines->sole();

    $closed = $returns->create($invoice->fresh(), SalesReturn::ReasonExcess, null, [[
        'customer_invoice_line_id' => $invoiceLine->getKey(), 'quantity' => '1',
    ]]);
    $returns->authorize($closed);
    $closed = $returns->receive($closed);
    $returns->inspect($closed, [[
        'sales_return_line_id' => $closed->lines->sole()->getKey(), 'saleable_quantity' => '1',
    ]]);
    $closed = $returns->close($closed->fresh());
    expect($closed->status)->toBe(SalesReturn::StatusClosed);

    $cancelled = $returns->create($invoice->fresh(), SalesReturn::ReasonExcess, null, [[
        'customer_invoice_line_id' => $invoiceLine->getKey(), 'quantity' => '1',
    ]]);
    $cancelled = $returns->cancel($cancelled, 'SAL005 test cancellation');
    expect($cancelled->status)->toBe(SalesReturn::StatusCancelled);

    $ledgerRow = app(SalesCycleReadService::class)
        ->ledger($fixture['company']->getKey(), $fixture['branch']->getKey(), [
            'financial_period_id' => $fixture['period']->getKey(),
            'currency_id' => $fixture['currency']->getKey(),
            'invoice_id' => $invoice->getKey(),
        ])->firstOrFail();
    expect(bccomp((string) $ledgerRow->lines->sole()->returned_quantity, '1', 8))->toBe(0);

    $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices']))
        ->assertOk();
});

test('sales ledger renders zero row totals across screen pdf and exports', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    salesReportTotalsInvoice($fixture, 'SAL005-EMPTY-KEEP', '10.0000');

    $params = ['report' => 'invoices', 'customer_doc_num' => 'NO-SUCH-CUSTOMER-SAL005'];
    $screen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', $params))
        ->assertOk()->getContent();
    expect($screen)->toContain('Totals')
        ->and($screen)->toContain('(0)')
        ->and($screen)->not->toContain('SAL005-EMPTY-KEEP');

    $pdf = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', $params))
        ->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');
    expect(salesPdfText($pdf->getContent()))->toContain('Totals');

    foreach (['xlsx', 'csv'] as $format) {
        $this->actingAs($fixture['user'])->withSession($session)
            ->get(route('admin.reports.sales.sales-orders.export', [...$params, 'format' => $format]))
            ->assertOk()->assertDownload();
    }

    $data = salesReportControllerData($fixture, $session, $params);
    expect($data['ledgerSummary']['invoice_count'])->toBe(0)
        ->and($data['ledgerSummary']['net_sales'])->toBe('0.0000');
    $data['salesLedger'] = collect(
        $data['salesLedger'] instanceof Paginator
            ? $data['salesLedger']->items()
            : $data['salesLedger']->all()
    );
    $sheets = (new SalesCycleReportExport($data))->sheets();
    $ledgerSheet = $sheets['invoices'][0] ?? $sheets[0];
    $rows = $ledgerSheet->array();
    expect(count($rows))->toBe(1);
    expect($rows[0][2])->toContain('TOTAL (0)');
});

test('every non ledger perspective footer totals match summaries across screen pdf and export', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    $serviceInvoice = salesPostedServiceInvoice($fixture, '100.0000');
    salesReportTotalsInvoice($fixture, 'SAL005-GRP-DIRECT', '20.0000');
    app(CustomerReceiptService::class)->createAndApprove([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'customer_id' => $fixture['customer']->getKey(),
        'receipt_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'payment_method' => 'cash',
        'cashbox_id' => $fixture['cashbox']->getKey(),
        'amount' => '30',
        'receipt_type' => CustomerReceipt::TypeCollection,
    ], [[
        'customer_invoice_payment_schedule_id' => $serviceInvoice->paymentSchedules->sole()->getKey(),
        'amount' => '30',
    ]]);

    $orders = app(SalesOrderService::class);
    $fulfillment = app(SalesFulfillmentService::class);
    $invoices = app(CustomerInvoiceService::class);
    $returns = app(SalesReturnService::class);
    $goodsOrder = $orders->approve($orders->create(salesCycleOrderPayload($fixture, [
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'description' => 'Finished Crate',
            'quantity' => '2',
            'unit_price' => '10',
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]],
        'payment_schedules' => [[
            'title' => 'Goods value',
            'amount' => '20',
            'due_date' => now()->addMonth()->toDateString(),
        ]],
    ])));
    $goodsLine = $goodsOrder->lines->sole();
    $goodsDelivery = $fulfillment->deliver($goodsOrder, [[
        'sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '2',
    ]]);
    $goodsInvoice = $invoices->post($invoices->createFromOrder($goodsOrder->fresh(), [[
        'sales_order_line_id' => $goodsLine->getKey(),
        'delivery_line_id' => $goodsDelivery->lines->sole()->getKey(),
        'quantity' => '2',
    ]], [[
        'due_date' => now()->addMonth()->toDateString(), 'amount' => '20',
    ]], $goodsDelivery));
    $goodsReturn = $returns->create($goodsInvoice->fresh(), SalesReturn::ReasonExcess, null, [[
        'customer_invoice_line_id' => $goodsInvoice->lines->sole()->getKey(), 'quantity' => '1',
    ]]);
    $returns->authorize($goodsReturn);
    $goodsReturn = $returns->receive($goodsReturn);
    $returns->inspect($goodsReturn, [[
        'sales_return_line_id' => $goodsReturn->lines->sole()->getKey(), 'saleable_quantity' => '1',
    ]]);
    $returns->close($goodsReturn->fresh());

    /** Absolute anchors: 3 invoices (100 + 20 + 20), one 30.0000 receipt, one closed return of quantity 1.
        The return close posts a 10.0000 credit note against the goods invoice, so remaining
        drops to 100.0000 and net to 130.0000. */
    $financial = salesReportControllerData($fixture, $session, ['report' => 'financial']);
    expect($financial['financialSummary']['gross_sales'])->toBe('140.0000')
        ->and($financial['financialSummary']['collections'])->toBe('30.0000')
        ->and($financial['financialSummary']['credit_notes'])->toBe('10.0000')
        ->and($financial['financialSummary']['net_sales'])->toBe('130.0000')
        ->and($financial['financialSummary']['outstanding'])->toBe('100.0000')
        ->and($financial['customerSummary']['invoice_count'])->toBe(3)
        ->and($financial['customerSummary']['sales_value'])->toBe('140.0000')
        ->and($financial['outstandingSummary']['outstanding'])->toBe('100.0000')
        ->and($financial['collectionSummary']['amount'])->toBe('0.0000')
        ->and($financial['returnsSummary']['return_count'])->toBe(0);
    expect(bccomp($financial['financialSummary']['net_sales'], bcsub($financial['financialSummary']['gross_sales'], $financial['financialSummary']['credit_notes'], 4), 4))->toBe(0);

    $screen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'financial']))
        ->assertOk()->getContent();
    expect($screen)->toContain('data-sales-financial-summary')
        ->and(salesReportTotalsBc(salesReportFooterValue($screen, 'customer-total', 'sales_value'), $financial['customerSummary']['sales_value']))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($screen, 'customer-total', 'outstanding'), $financial['customerSummary']['outstanding']))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($screen, 'outstanding-total', 'outstanding'), $financial['outstandingSummary']['outstanding']))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'financial']))
        ->assertOk()->getContent()))->toContain('Totals');

    $period = salesReportControllerData($fixture, $session, ['report' => 'period']);
    expect($period['periodSummary']['invoice_count'])->toBe(3)
        ->and($period['periodSummary']['sales_value'])->toBe('140.0000');
    $periodScreen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'period']))
        ->assertOk()->getContent();
    expect(salesReportTotalsBc(salesReportFooterValue($periodScreen, 'period-total', 'sales_value'), '140.0000'))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'period']))
        ->assertOk()->getContent()))->toContain('Totals');

    $products = salesReportControllerData($fixture, $session, ['report' => 'products']);
    expect($products['productSummary']['product_count'])->toBe(2)
        ->and($products['productSummary']['sales_value'])->toBe('120.0000')
        ->and($products['productSummary']['sold_quantity'])->toBe('3.00000000')
        ->and($products['customerProductSummary']['sales_value'])->toBe('120.0000');
    $productsScreen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'products']))
        ->assertOk()->getContent();
    expect(salesReportTotalsBc(salesReportFooterValue($productsScreen, 'product-total', 'sales_value'), '120.0000'))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($productsScreen, 'customer-product-total', 'sales_value'), '120.0000'))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'products']))
        ->assertOk()->getContent()))->toContain('Totals');

    /** The return close also credits the goods-invoice payment schedule, so schedule-level
        outstanding is (100 - 30) + (20 - 10) = 80.0000. */
    $receivables = salesReportControllerData($fixture, $session, ['report' => 'receivables']);
    expect($receivables['outstandingSummary']['outstanding'])->toBe('100.0000')
        ->and($receivables['installmentSummary']['outstanding'])->toBe('80.0000')
        ->and($receivables['agingTotals']['current'])->toBe('80.0000');
    $receivablesScreen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'receivables']))
        ->assertOk()->getContent();
    expect(salesReportTotalsBc(salesReportFooterValue($receivablesScreen, 'outstanding-total', 'outstanding'), '100.0000'))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($receivablesScreen, 'aging-total', 'current'), '80.0000'))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'receivables']))
        ->assertOk()->getContent()))->toContain('Totals');

    $collections = salesReportControllerData($fixture, $session, ['report' => 'collections']);
    expect($collections['collectionSummary']['receipt_count'])->toBe(1)
        ->and($collections['collectionSummary']['amount'])->toBe('30.0000')
        ->and($collections['upcomingSummary']['outstanding'])->toBe('80.0000');
    $collectionsScreen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'collections']))
        ->assertOk()->getContent();
    expect(salesReportTotalsBc(salesReportFooterValue($collectionsScreen, 'collection-total', 'amount'), '30.0000'))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($collectionsScreen, 'upcoming-total', 'outstanding'), '80.0000'))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'collections']))
        ->assertOk()->getContent()))->toContain('Totals');

    $returnsData = salesReportControllerData($fixture, $session, ['report' => 'returns']);
    expect($returnsData['returnsSummary']['return_count'])->toBe(1)
        ->and($returnsData['returnsSummary']['returned_quantity'])->toBe('1.00000000')
        ->and($returnsData['returnsSummary']['saleable_quantity'])->toBe('1.00000000')
        ->and($returnsData['returnsSummary']['rejected_quantity'])->toBe('0.00000000')
        ->and($returnsData['returnAnalysisSummary']['line_count'])->toBe(1)
        ->and($returnsData['returnAnalysisSummary']['returned_quantity'])->toBe('1.00000000');
    $returnsScreen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'returns']))
        ->assertOk()->getContent();
    expect(salesReportTotalsBc(salesReportFooterValue($returnsScreen, 'returns-total', 'returned_quantity'), '1.00000000', 8))->toBeTrue()
        ->and(salesReportTotalsBc(salesReportFooterValue($returnsScreen, 'return-analysis-total', 'returned_quantity'), '1.00000000', 8))->toBeTrue();
    expect(salesPdfText($this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.print', ['report' => 'returns']))
        ->assertOk()->getContent()))->toContain('Totals');

    /** Exporter sheet TOTAL arrays reuse the same summary data (keyed by report-type sheet order). */
    $exportData = [
        'financial' => $financial,
        'customers' => salesReportControllerData($fixture, $session, ['report' => 'customers']),
        'period' => $period,
        'products' => $products,
        'receivables' => $receivables,
        'collections' => $collections,
        'returns' => $returnsData,
    ];
    $expectedSheetCounts = [
        'financial' => 4, 'customers' => 1, 'period' => 1, 'products' => 2,
        'receivables' => 3, 'collections' => 2, 'returns' => 2,
    ];
    foreach ($exportData as $type => $reportData) {
        $sheets = array_values((new SalesCycleReportExport($reportData))->sheets());
        expect(count($sheets))->toBe($expectedSheetCounts[$type]);
        foreach ($sheets as $index => $sheet) {
            $rows = $sheet->array();
            expect(count($rows))->toBeGreaterThan(0);
            /** The financial metrics summary sheet has no TOTAL row by design; every detail sheet does. */
            if ($type === 'financial' && $index === 0) {
                expect($sheet->headings())->toHaveCount(2);

                continue;
            }
            expect(implode(' ', array_map('strval', end($rows))))->toContain('TOTAL');
        }
    }
    $customersExport = array_values((new SalesCycleReportExport($exportData['customers']))->sheets());
    $customersRows = $customersExport[0]->array();
    $customersTotal = end($customersRows);
    expect(bccomp((string) $customersTotal[2], $exportData['customers']['customerSummary']['sales_value'], 4))->toBe(0);
    $returnsExport = array_values((new SalesCycleReportExport($exportData['returns']))->sheets());
    $returnsRows = $returnsExport[0]->array();
    $returnsTotal = end($returnsRows);
    expect(bccomp((string) $returnsTotal[2], '1.00000000', 8))->toBe(0);
});

test('serialized xlsx and csv exports carry total rows with exact values', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    salesReportTotalsInvoice($fixture, 'SAL005-SER-INV', '42.5000');

    $data = salesReportControllerData($fixture, $session);
    expect($data['ledgerSummary']['gross_sales'])->toBe('42.5000');
    $data['salesLedger'] = collect(
        $data['salesLedger'] instanceof Paginator
            ? $data['salesLedger']->items()
            : $data['salesLedger']->all()
    );

    $export = new SalesCycleReportExport($data);
    $xlsx = Excel::raw($export, ExcelWriter::XLSX);
    $path = tempnam(sys_get_temp_dir(), 'sal005').'.xlsx';
    file_put_contents($path, $xlsx);
    try {
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $strings = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/sheet') || $name === 'xl/sharedStrings.xml') {
                $strings .= (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        expect($strings)->toContain('TOTAL (1)');
        expect(preg_match('/<v>42\.5<\/v>/', $strings))->toBe(1);
    } finally {
        @unlink($path);
    }

    $csv = Excel::raw($export, ExcelWriter::CSV);
    expect($csv)->toContain('TOTAL (1)');
    expect($csv)->toContain('42.5');
});

test('sales ledger excludes invoices from another company', function () {
    $fixture = salesCycleFixture();
    salesReportTotalsPermissions($fixture);
    $session = salesCycleSession($fixture);

    salesReportTotalsInvoice($fixture, 'SAL005-CO-KEEP', '50.0000');
    $otherCompany = Company::query()->create([
        'doc_number' => 9601,
        'doc_num' => 'CO-SAL005-OTHER',
        'name' => 'SAL005 Other Company',
        'status' => 'active',
    ]);
    salesReportTotalsInvoice($fixture, 'SAL005-CO-OTHER', '999.0000', [
        'company_id' => $otherCompany->getKey(),
    ]);

    $data = salesReportControllerData($fixture, $session);
    expect($data['ledgerSummary']['invoice_count'])->toBe(1)
        ->and($data['ledgerSummary']['gross_sales'])->toBe('50.0000');

    $screen = $this->actingAs($fixture['user'])->withSession($session)
        ->get(route('admin.reports.sales.sales-orders.index', ['report' => 'invoices']))
        ->assertOk()->getContent();
    expect($screen)->toContain('SAL005-CO-KEEP')
        ->and($screen)->not->toContain('SAL005-CO-OTHER');
});
