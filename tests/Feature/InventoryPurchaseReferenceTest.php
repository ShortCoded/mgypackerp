<?php

use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Exports\InventoryValuationComparisonExport;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../ProcurementSupport.php';

test('approved purchase reference excludes other inbound and uses the approved net invoice in base currency', function (): void {
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111098', 'Valuation supplier payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $orderService = app(PurchaseOrderService::class);
    $order = $orderService->approve($orderService->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Synthetic purchase reference acceptance fixture.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10,
            'unit_price' => 2,
        ]],
    ])['record']);
    $orderLine = $order->lines->firstOrFail();
    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->createReceipt($order, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 10,
        ]],
    ]);
    $receiptLine = $receipt->lines->firstOrFail();
    $receiving->inspect($receipt, [
        'lines' => [[
            'receipt_line_public_id' => $receiptLine->public_id,
            'accepted_quantity' => 10,
            'rejected_quantity' => 0,
        ]],
    ]);
    $receipt = $receiving->postReceipt($receipt->fresh());

    InventoryTransaction::query()->create([
        'posting_key' => 'synthetic-production-inbound-purchase-reference',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'transaction_date' => now()->toDateString(),
        'transaction_type' => 'production_receipt',
        'quantity_in' => 5,
        'quantity_out' => 0,
        'unit_cost' => 9,
        'total_cost' => 45,
        'source_type' => 'synthetic_test',
        'source_id' => 1,
        'source_doc_num' => 'SYNTHETIC-PROD-1',
        'stock_status' => InventoryTransaction::StatusAvailable,
    ]);

    $compare = fn (): array => app(InventoryValuationService::class)->comparisonForStockPosition(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $fixture['branch']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
        now()->toDateString(),
        'last_purchase_reference',
    );
    $receiptReference = $compare();
    expect($receiptReference['methods']['last_inbound_reference']['ending_value'])->toBe('135.00000000')
        ->and($receiptReference['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000')
        ->and($receiptReference['methods']['last_purchase_reference']['sources'][0]['document'])->toBe($receipt->doc_num);

    $invoices = app(PurchaseInvoiceService::class);
    $invoice = $invoices->create([
        'invoice_date' => now()->toDateString(),
        'purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'supplier_invoice_number' => 'SYNTHETIC-PRICE-REF-1',
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => '1.5',
        'payment_type' => 'credit',
        'header_discount_type' => 'fixed',
        'header_discount_value' => 2,
        'freight_amount' => 7,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $orderLine->public_id,
            'receipt_line_public_id' => $receiptLine->public_id,
            'quantity' => 10,
            'unit_price' => 3,
            'discount_type' => 'fixed',
            'discount_value' => 5,
        ]],
    ])['record'];
    expect($compare()['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000');

    $invoice = $invoices->approve($invoice);
    $approvedReference = $compare();
    expect($approvedReference['methods']['last_inbound_reference']['ending_value'])->toBe('135.00000000')
        ->and($approvedReference['methods']['last_purchase_reference']['ending_value'])->toBe('51.75000000')
        ->and($approvedReference['methods']['last_purchase_reference']['sources'][0]['document'])->toBe($invoice->doc_num)
        ->and($approvedReference['methods']['last_purchase_reference']['sources'][0]['currency'])->toBe($fixture['currency']->code)
        ->and($approvedReference['methods']['last_purchase_reference']['sources'][0]['basis'])->toBe('net_line_excluding_freight_tax');

    $invoice->forceFill(['invoice_date' => now()->subDay()->toDateString()])->save();
    expect($compare()['methods']['last_purchase_reference']['sources'][0]['document'])->toBe($invoice->doc_num);
    $invoice->forceFill(['invoice_date' => now()->toDateString()])->save();

    $otherCompany = Company::query()->create([
        ...app(DocumentNumberService::class)->next('companies', Company::class),
        'name' => 'Synthetic reference isolation',
        'status' => 'active',
        'country' => 'Egypt',
    ]);
    $invoice->forceFill(['company_id' => $otherCompany->getKey()])->save();
    expect($compare()['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000');
    $invoice->forceFill(['company_id' => $fixture['company']->getKey()])->save();

    $invoiceLine = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->getKey())->firstOrFail();
    $invoiceLine->forceFill(['company_id' => $otherCompany->getKey()])->save();
    expect($compare()['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000');
    $invoiceLine->forceFill(['company_id' => $fixture['company']->getKey(), 'product_id' => $fixture['finished']->getKey()])->save();
    expect($compare()['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000');
    $invoiceLine->forceFill(['product_id' => $fixture['raw']->getKey()])->save();

    $export = new InventoryValuationComparisonExport($approvedReference);
    $purchaseRow = collect($export->array())->first(fn (array $row): bool => $row[0] === __('inventory_accounting.valuation_methods.last_purchase_reference'));
    expect($purchaseRow[2])->toBe('51.75000000')
        ->and($purchaseRow[6])->toContain($invoice->doc_num)
        ->and($purchaseRow[6])->toContain($fixture['currency']->code);

    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(['inventory.reports.valuation.view', 'inventory.reports.valuation.export']);
    $query = [
        'as_of' => now()->toDateString(),
        'product_id' => $fixture['raw']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'reference_method' => 'last_purchase_reference',
    ];
    $this->actingAs($fixture['user'])->get(route('admin.inventory.reports.valuation', $query))
        ->assertOk()
        ->assertSee($invoice->doc_num)
        ->assertSee(__('inventory_accounting.valuation_methods.last_purchase_reference'));
    $csv = $this->actingAs($fixture['user'])->get(route('admin.inventory.reports.valuation.export.csv', [...$query, 'export_view' => 'comparison']))->assertOk();
    expect($csv->streamedContent())->toContain($invoice->doc_num);
    $pdf = $this->actingAs($fixture['user'])->get(route('admin.inventory.reports.valuation.export.pdf', [...$query, 'export_view' => 'comparison']))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF-'))->toBeTrue();
    if (is_executable('/usr/bin/pdftotext')) {
        $pdfPath = tempnam(sys_get_temp_dir(), 'mgypack-valuation-');
        try {
            file_put_contents($pdfPath, $pdf->getContent());
            $extraction = new Process(['/usr/bin/pdftotext', '-layout', $pdfPath, '-']);
            $extraction->mustRun();
            expect($extraction->getOutput())->toContain($invoice->doc_num)
                ->toContain($fixture['currency']->code);
        } finally {
            unlink($pdfPath);
        }
    }

    $invoices->reverse($invoice, 'Synthetic valuation reference reversal');
    $reversedReference = $compare();
    expect($reversedReference['methods']['last_purchase_reference']['ending_value'])->toBe('30.00000000')
        ->and($reversedReference['methods']['last_purchase_reference']['sources'][0]['document'])->toBe($receipt->doc_num);

    $receipt->forceFill(['approved_at' => null])->save();
    expect($compare()['methods']['last_purchase_reference']['ending_value'])->toBeNull();
});

test('a later receipt on the same date outranks an earlier purchases approved invoice', function (): void {
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111097', 'Reference order payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);

    $purchase = function (string $price) use ($fixture, $orders, $receiving): array {
        $order = $orders->approve($orders->create([
            'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
            'branch_store_uuid' => $fixture['store']->public_uuid,
            'currency_doc_num' => $fixture['currency']->doc_num,
            'document_date' => now()->toDateString(),
            'exchange_rate' => 1,
            'direct_procurement_override' => true,
            'direct_procurement_reason' => 'Synthetic reference ordering fixture.',
            'lines' => [[
                'product_doc_num' => $fixture['raw']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'ordered_quantity' => 10,
                'unit_price' => $price,
            ]],
        ])['record']);
        $orderLine = $order->lines->sole();
        $receipt = $receiving->createReceipt($order, [
            'document_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'delivered_quantity' => 10,
            ]],
        ]);
        $receiptLine = $receipt->lines->sole();
        $receiving->inspect($receipt, [
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id,
                'accepted_quantity' => 10,
                'rejected_quantity' => 0,
            ]],
        ]);

        return [$order, $orderLine, $receiptLine, $receiving->postReceipt($receipt->fresh())];
    };

    [$firstOrder, $firstOrderLine, $firstReceiptLine] = $purchase('2');
    $invoices = app(PurchaseInvoiceService::class);
    $firstInvoice = $invoices->create([
        'invoice_date' => now()->toDateString(),
        'purchase_order_doc_num' => $firstOrder->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'supplier_invoice_number' => 'SYNTHETIC-ORDERED-REFERENCE',
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'payment_type' => 'credit',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $firstOrderLine->public_id,
            'receipt_line_public_id' => $firstReceiptLine->public_id,
            'quantity' => 10,
            'unit_price' => 3,
        ]],
    ])['record'];
    $invoices->approve($firstInvoice);
    [, , , $latestReceipt] = $purchase('7');

    $comparison = app(InventoryValuationService::class)->comparisonForStockPosition(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $fixture['branch']->getKey(),
        $fixture['store']->getKey(),
        $fixture['raw']->getKey(),
        now()->toDateString(),
        'last_purchase_reference',
    );

    expect($comparison['ending_quantity'])->toBe('20.00000000')
        ->and($comparison['methods']['last_purchase_reference']['ending_value'])->toBe('140.00000000')
        ->and($comparison['methods']['last_purchase_reference']['sources'][0]['document'])->toBe($latestReceipt->doc_num);
});
