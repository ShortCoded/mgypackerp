<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ProductDataReport;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_customer_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
});

function customerCloneContext(object $test, Model $order): User
{
    $permissions = ['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen', 'sales_orders.view', 'sales_orders.edit', 'sales_orders.view_prices', 'inventory.documents.print'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create(['name' => 'SYNTHETIC local closure acceptance']);
    $actor->givePermissionTo($permissions);
    $context = [
        OperatingContextService::CompanyIdKey => $order->company_id,
        OperatingContextService::CompanyDocNumKey => $order->company->doc_num,
        OperatingContextService::BranchIdKey => $order->branch_id,
        OperatingContextService::BranchDocNumKey => Branch::findOrFail($order->branch_id)->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $order->financial_period_id,
        OperatingContextService::FinancialPeriodDocNumKey => FinancialPeriod::findOrFail($order->financial_period_id)->doc_num,
    ];
    $test->actingAs($actor)->withSession($context);

    return $actor;
}

test('restored customer order reopens and amends without changing its customer source or descendants', function (string $number): void {
    $order = SalesOrder::query()->where('doc_num', $number)->firstOrFail();
    customerCloneContext($this, $order);
    $source = $order->salesRequest->attributesToArray();
    $invoices = $order->invoices()->withTrashed()->get()->map->attributesToArray()->all();
    $productions = $order->productionOrders()->withTrashed()->get()->map(fn ($p) => [$p->attributesToArray(), $p->lines()->get()->map->attributesToArray()->all()])->all();
    $selection = ['document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number];
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->json();
    $this->postJson(route('admin.tools.open-documents.store'), [
        ...$selection, 'preview_token' => $preview['preview_token'], 'reason' => 'LOCAL SYNTHETIC acceptance; rolled back after verification.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    $order = $order->fresh();
    $this->get(route('admin.sales.sales-orders.edit', $order))->assertOk()->assertSee('amendment_token', false);
    $total = '0.0000';
    $lines = $order->lines()->orderBy('line_number')->get()->map(function ($line, int $index) use (&$total): array {
        $quantity = $index === 0 ? bcadd($line->quantity, '1', 8) : $line->quantity;
        $gross = bcmul($quantity, $line->unit_price, 12);
        $net = bcadd(bcsub($gross, $line->discount_amount, 12), $line->tax_amount, 12);
        $total = bcadd($total, bcadd($net, '0.00005', 4), 4);

        return ['public_id' => $line->public_id, 'product_doc_num' => $line->product->doc_num,
            'unit_doc_num' => $line->unit->doc_num, 'description' => $line->description,
            'quantity' => $quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount,
            'tax_amount' => $line->tax_amount];
    })->all();
    $payload = ['amendment_token' => $order->amendmentToken(),
        'customer_doc_num' => $order->customer->doc_num, 'currency_doc_num' => $order->currency->doc_num,
        'order_date' => $order->order_date->toDateString(), 'expected_delivery_date' => $order->expected_delivery_date->toDateString(),
        'lines' => $lines, 'payment_schedules' => [['title' => 'SYNTHETIC acceptance schedule', 'due_date' => $order->order_date->toDateString(), 'amount' => $total]],
    ];
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertOk();
    expect($order->fresh()->total_amount)->toBe($total)
        ->and($order->salesRequest->fresh()->attributesToArray())->toBe($source)
        ->and($order->invoices()->withTrashed()->get()->map->attributesToArray()->all())->toBe($invoices)
        ->and($order->productionOrders()->withTrashed()->get()->map(fn ($p) => [$p->attributesToArray(), $p->lines()->get()->map->attributesToArray()->all()])->all())->toBe($productions);
    $this->putJson(route('admin.sales.sales-orders.update', $order), $payload)->assertStatus(422);
    expect($order->fresh()->total_amount)->toBe($total);
})->with(['SO-00006', 'SO-00007', 'SO-00009', 'SO-00010']);

test('restored stock inquiry nets hall opening quantities and warehouse issues', function (): void {
    $report = app(InventoryReportService::class)->stockBalanceInquiry(1, [1, 2, 3], ['as_of' => '2026-10-03']);
    $row = $report['rows']->where('branch_store_id', 3)->where('product_id', 865)->sole();
    expect($row->on_hand)->toBe('539.00000000')->and($row->branch_hall_id)->toBeNull();
    $row = $report['rows']->where('branch_store_id', 3)->where('product_id', 922)->sole();
    expect($row->on_hand)->toBe('103.00000000')->and($row->branch_hall_id)->toBeNull();
});

test('restored closed purchase requisition reopens edits and reapproves from ordinary authorized routes', function (): void {
    $request = PurchaseRequisition::query()->where('doc_num', 'PR-00001')->firstOrFail();
    $actor = customerCloneContext($this, $request);
    $permissions = ['purchases.purchase_requisitions.reopen', 'purchases.purchase_requisitions.view',
        'purchases.purchase_requisitions.edit', 'purchases.purchase_requisitions.submit', 'purchases.purchase_requisition_approvals.approve'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo($permissions);
    expect($request->status)->toBe(PurchaseRequisition::StatusClosed);
    $original = $request->lines()->sole();
    $selection = ['document_type' => 'purchase_requisitions', 'from_number' => $request->doc_number, 'to_number' => $request->doc_number];
    $preview = $this->postJson(route('admin.tools.open-documents.preview'), $selection)->assertOk()->json();
    $this->postJson(route('admin.tools.open-documents.store'), [...$selection, 'preview_token' => $preview['preview_token'],
        'reason' => 'LOCAL SYNTHETIC purchase edit acceptance; transaction rolls back.'])->assertOk()->assertJsonPath('summary.opened', 1);
    $this->get(route('admin.purchases.purchase-requisitions.edit', $request))->assertOk();
    $this->putJson(route('admin.purchases.purchase-requisitions.update', $request), [
        'requester_employee_id' => $request->requester_employee_id, 'request_date' => $request->request_date->toDateString(),
        'branch_store_uuid' => $request->branchStore->public_uuid,
        'lines' => [['public_id' => $original->public_id, 'product_doc_num' => $original->product->doc_num,
            'unit_doc_num' => $original->unit->doc_num, 'requested_quantity' => '361', 'source_type' => 'manual']],
    ])->assertOk();
    expect($original->fresh()->requested_quantity)->toBe('361.00000000')->and($original->fresh()->approved_quantity)->toBe('0.00000000');
    $this->postJson(route('admin.purchases.purchase-requisitions.submit', $request))->assertOk();
    $this->postJson(route('admin.purchases.purchase-requisitions.approve', $request))->assertOk();
    expect($request->fresh()->isLockedForEditing())->toBeTrue()->and($original->fresh()->approved_quantity)->toBe('361.00000000');
});

test('restored sales delivery prints its own identity and readable product report pages', function (): void {
    $document = InventoryDocument::query()->where('doc_num', 'INV-MOV-00005')->firstOrFail();
    $actor = customerCloneContext($this, $document);
    $pdf = app(ReportPdfService::class);
    $artifactDirectory = getenv('MGYPACK_CAPTURE_PDF_DIR') ?: null;
    foreach (['ar', 'en'] as $locale) {
        $actor->update(['locale' => $locale]);
        $this->withSession(['locale' => $locale]);
        app()->setLocale($locale);
        expect($pdf->stockDocumentTitle($document))->toBe(__('inventory.movements.types.sales_delivery'));
        $response = $this->get(route('admin.inventory.documents.print', $document))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        if ($locale === 'en') {
            $temporaryPdf = tempnam(sys_get_temp_dir(), 'customer-pdf-');
            file_put_contents($temporaryPdf, $response->getContent());
            try {
                $text = (new Process(['pdftotext', '-layout', $temporaryPdf, '-']))->mustRun()->getOutput();
                expect($text)->toContain(__('inventory.movements.types.sales_delivery'));
            } finally {
                unlink($temporaryPdf);
            }
        }
        if ($artifactDirectory) {
            file_put_contents($artifactDirectory.'/customer-sales-delivery-'.$locale.'.pdf', $response->getContent());
        }
    }
    $report = app(ProductDataReport::class);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        foreach (['summary', 'detailed'] as $mode) {
            $filters = ['result_mode' => $mode, 'company_id' => 1];
            $rows = $report->orderedQuery($filters)->limit(15)->get()->map(fn ($row) => $report->map($row, $filters))->all();
            $headings = $report->headings($filters);
            $html = view('reports.partials.products-data-table', ['rows' => $rows, 'headings' => $headings, 'mode' => $mode])->render();
            foreach ($headings as $heading) {
                expect($html)->toContain(e($heading));
            }
            expect($html)->toContain('font-size: 12px', 'rowspan="2"', 'autosize="1"')->not->toContain('<pagebreak />');
            $output = $pdf->stream('reports.products-data', ['title' => 'Products / raw materials acceptance',
                'companyId' => 1, 'rows' => $rows, 'headings' => $headings, 'mode' => $mode, 'filters' => []], 'products-'.$locale.'-'.$mode.'.pdf');
            expect($output->headers->get('Content-Type'))->toBe('application/pdf');
            if ($artifactDirectory) {
                file_put_contents($artifactDirectory.'/customer-products-'.$locale.'-'.$mode.'.pdf', $output->getContent());
            }
        }
    }
});
