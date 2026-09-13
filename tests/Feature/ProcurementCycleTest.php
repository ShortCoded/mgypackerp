<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Purchases\Exports\ProcurementCycleReportExport;
use Modules\Purchases\Http\Controllers\ProcurementWorkflowController;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementAttachmentService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceMatchingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../ProcurementSupport.php';

function procurementAccountByClassification(Company $company, string $classificationCode): Account
{
    return Account::query()
        ->where('company_id', $company->getKey())
        ->whereHas('classification', fn ($query) => $query->where('code', $classificationCode))
        ->firstOrFail();
}

test('unapproved requisitions cannot open the request for quotation creation screen', function () {
    $fixture = procurementFixture();
    $requisition = procurementManualRequisition($fixture);
    $requisition = app(ProcurementSourcingService::class)->submitRequisition($requisition);
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));

    $response = app(ProcurementWorkflowController::class)->createRfq($requisition);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe(route('admin.purchases.purchase-requisitions.show', $requisition));
});

test('procurement migrations expose the reconciled purchase schema', function () {
    expect(Schema::hasColumns('purchase_orders', [
        'purchase_type',
        'payment_terms',
        'internal_reference',
        'purchase_requisition_id',
        'request_for_quotation_id',
        'supplier_quotation_id',
        'supplier_selection_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_order_lines', [
            'description',
            'discount_type',
            'discount_value',
            'discount_amount',
            'tax_rate',
            'tax_amount',
            'purchase_requisition_line_id',
            'request_for_quotation_line_id',
            'supplier_quotation_line_id',
            'supplier_selection_line_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_invoices', [
            'purchase_order_id',
            'purchase_type',
            'matching_status',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_invoice_lines', [
            'purchase_order_line_id',
            'description',
            'receipt_line_id',
            'matched_quantity',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_returns', [
            'purchase_order_id',
            'receipt_id',
            'purchase_invoice_id',
            'reason_code',
            'total_quantity',
            'total_amount',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('purchase_return_lines', [
            'purchase_order_line_id',
            'receipt_line_id',
            'purchase_invoice_line_id',
            'from_quarantine',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('purchase_invoices', ['company_id', 'purchase_order_id', 'status']))->toBeTrue()
        ->and(Schema::hasIndex('purchase_invoice_lines', ['purchase_order_line_id', 'receipt_line_id']))->toBeTrue()
        ->and(Schema::hasIndex('purchase_returns', ['company_id', 'status']))->toBeTrue()
        ->and(Schema::hasIndex('purchase_return_lines', ['receipt_line_id', 'product_id']))->toBeTrue();
});

test('purchasable classifications and production demand lineage are explicit', function () {
    $fixture = procurementFixture();
    $productionLine = procurementProductionSource($fixture);

    expect(Product::purchasableItemClassifications())->toBe([
        Product::ClassificationRawMaterial,
        Product::ClassificationSemiFinished,
        Product::ClassificationPackaging,
        Product::ClassificationOther,
    ])->and($fixture['raw']->isPurchasable())->toBeTrue()
        ->and($fixture['service']->isPurchasable())->toBeFalse()
        ->and($fixture['finished']->isPurchasable())->toBeFalse();

    $requisition = app(ProcurementSourcingService::class)->createRequisition([
        'request_date' => now()->toDateString(), 'branch_store_uuid' => $fixture['store']->public_uuid,
        'priority' => 'normal', 'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'requested_quantity' => 4, 'source_type' => 'production_order',
            'source_doc_num' => $productionLine->order->doc_num, 'source_line_reference' => $productionLine->public_id,
        ]],
    ]);

    expect($requisition->lines->first()->production_order_id)->toBe($productionLine->production_order_id)
        ->and($requisition->lines->first()->production_order_line_id)->toBe($productionLine->getKey())
        ->and(fn () => app(ProcurementSourcingService::class)->createRequisition([
            'request_date' => now()->toDateString(),
            'branch_store_uuid' => $fixture['store']->public_uuid,
            'priority' => 'normal',
            'lines' => [[
                'product_doc_num' => $fixture['finished']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'requested_quantity' => 1,
                'source_type' => 'work_order',
                'source_doc_num' => 'WO-EXTERNAL-1',
            ]],
        ]))->toThrow(DomainException::class, __('procurement.messages.purchase_product_type_invalid'))
        ->and(fn () => app(ProcurementSourcingService::class)->createRequisition([
            'request_date' => now()->toDateString(),
            'branch_store_uuid' => $fixture['store']->public_uuid,
            'priority' => 'normal',
            'lines' => [[
                'product_doc_num' => $fixture['raw']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'requested_quantity' => 1,
                'source_type' => 'production_order',
                'source_doc_num' => $productionLine->order->doc_num,
                'source_line_reference' => $productionLine->public_id,
            ]],
        ]))->toThrow(DomainException::class, __('This operational demand and item already has an active purchase requirement.'));
});

test('split sourcing, receiving, quality, matching, and returns preserve line capacity', function () {
    Storage::fake('public');
    $fixture = procurementFixture();
    $attachment = procurementDocumentAttachment($fixture['company']);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Procurement Supplier Payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $sourcing = app(ProcurementSourcingService::class);
    $receiving = app(ProcurementReceivingService::class);
    $settlement = app(ProcurementSettlementService::class);
    $requisition = procurementManualRequisition($fixture);
    $sourcing->submitRequisition($requisition);
    $requisition = $sourcing->approveRequisition($requisition->fresh());
    $requirementLine = $requisition->lines->first();

    expect(fn () => $sourcing->createRequestForQuotation($requisition, [
        'issue_date' => now()->toDateString(), 'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $requirementLine->public_id, 'quantity' => 11]],
    ]))->toThrow(DomainException::class, __('RFQ quantity exceeds the remaining approved requirement.'));

    $rfq = $sourcing->createRequestForQuotation($requisition->fresh(), [
        'issue_date' => now()->toDateString(),
        'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num, $fixture['secondSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $requirementLine->public_id, 'quantity' => 10]],
    ]);
    $rfq = $sourcing->issueRequestForQuotation($rfq);
    $rfqLine = $rfq->lines->first();
    $quotations = collect([
        [$fixture['firstSupplier'], 2],
        [$fixture['secondSupplier'], 2.5],
    ])->map(function (array $offer) use ($attachment, $fixture, $rfq, $rfqLine, $sourcing) {
        $quotation = $sourcing->createSupplierQuotation($rfq, [
            'supplier_doc_num' => $offer[0]->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
            'quotation_date' => now()->toDateString(), 'exchange_rate' => 1.25, 'freight_amount' => 3,
            'attachment_file_doc_nums' => [$attachment->doc_num],
            'lines' => [['rfq_line_public_id' => $rfqLine->public_id, 'offered_quantity' => 10, 'unit_price' => $offer[1], 'discount_amount' => 2, 'tax_rate' => 10]],
        ]);

        return $sourcing->submitSupplierQuotation($quotation);
    });
    $selection = $sourcing->createSupplierSelection($rfq->fresh(), [
        'selection_date' => now()->toDateString(), 'selection_reason' => 'Split award for supply continuity.',
        'lines' => [
            ['quotation_line_public_id' => $quotations->first()->lines->first()->public_id, 'selected_quantity' => 6],
            ['quotation_line_public_id' => $quotations->last()->lines->first()->public_id, 'selected_quantity' => 4],
        ],
    ]);
    $orders = $sourcing->approveSelection($selection);

    expect($orders)->toHaveCount(2)
        ->and($requisition->fresh()->status)->toBe('approved')
        ->and($orders->sum(fn (PurchaseOrder $order): float => (float) $order->total_ordered_quantity))->toBe(10.0)
        ->and($orders->every(fn (PurchaseOrder $order): bool => $order->exchange_rate === '1.250000'))->toBeTrue()
        ->and($quotations->first()->attachmentUsages()->where('archive_file_id', $attachment->getKey())->exists())->toBeTrue();

    $firstOrder = $orders->firstWhere('supplier_id', $fixture['firstSupplier']->getKey());
    expect($firstOrder->lines->first()->discount_amount)->toBe('1.2000')
        ->and($firstOrder->lines->first()->tax_amount)->toBe('1.0800')
        ->and($firstOrder->lines->first()->total_after_tax)->toBe('11.8800')
        ->and($firstOrder->freight_amount)->toBe('3.0000')
        ->and($firstOrder->total_amount)->toBe('14.8800');
    $firstOrder = app(PurchaseOrderService::class)->approve($firstOrder);
    $changeRequest = $settlement->requestPurchaseOrderChange($firstOrder, [
        'request_date' => now()->toDateString(),
        'requested_values' => ['notes' => 'Approved delivery coordination note.'],
        'reason' => 'Verify controlled Purchase Order change output.',
    ]);
    $changeRequest = $settlement->approvePurchaseOrderChange($changeRequest);
    $orderLine = $firstOrder->lines->first();
    $scheduledOrder = $receiving->createDeliverySchedules($firstOrder, [
        'schedules' => [['purchase_order_line_public_id' => $orderLine->public_id, 'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_quantity' => 6]],
    ]);
    $schedule = $scheduledOrder->lines->first()->deliverySchedules->first();
    $receipt = $receiving->receive($firstOrder->fresh(), [
        'document_date' => now()->toDateString(), 'supplier_delivery_note' => 'DN-100',
        'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivery_schedule_public_id' => $schedule->public_id, 'delivered_quantity' => 6]],
    ]);

    expect($receipt->qc_status)->toBe('pending_inspection')
        ->and(InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->count())->toBe(0)
        ->and(fn () => $receiving->receive($firstOrder->fresh(), [
            'document_date' => now()->toDateString(),
            'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => 1]],
        ]))->toThrow(DomainException::class, __('Delivered quantity exceeds the remaining purchase order quantity.'));

    $receiptLine = $receipt->lines->first();
    $inspection = $receiving->inspect($receipt, [
        'inspection_at' => now()->toDateString(),
        'attachment_file_doc_nums' => [$attachment->doc_num],
        'lines' => [[
            'receipt_line_public_id' => $receiptLine->public_id, 'accepted_quantity' => 5,
            'rejected_quantity' => 1, 'disposition' => 'quarantine', 'reason' => 'Contaminated bag.',
        ]],
    ]);
    expect(InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->count())->toBe(0);
    $receipt = $receiving->postReceipt($receipt->fresh());
    $receiptMovement = InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->firstOrFail();
    $unvaluedBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();

    expect($inspection->result)->toBe('partially_accepted')
        ->and($receiptMovement->quantity_in)->toBe('5.00000000')
        ->and($receiptMovement->unit_cost)->toBe('2.25000000')
        ->and($receiptMovement->total_cost)->toBe('11.25000000')
        ->and((float) $unvaluedBalance->on_hand)->toBe(5.0)
        ->and((float) $unvaluedBalance->inventory_value)->toBe(11.25)
        ->and((float) $unvaluedBalance->unvalued_receipt_quantity)->toBe(0.0)
        ->and($inspection->attachmentUsages()->where('archive_file_id', $attachment->getKey())->exists())->toBeTrue();

    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9201, 'doc_num' => 'PINV-PROC-1', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $firstOrder->supplier_id, 'purchase_order_id' => $firstOrder->getKey(),
        'invoice_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1.25,
        'subtotal_amount' => 10, 'line_discount_amount' => 1, 'taxable_amount' => 12,
        'freight_amount' => 3, 'tax_amount' => 0.9, 'total_amount' => 12.9, 'remaining_amount' => 12.9,
        'status' => PurchaseInvoice::StatusDraft,
    ]);
    $invoice->lines()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'purchase_order_line_id' => $orderLine->getKey(), 'receipt_line_id' => null,
        'quantity' => 5, 'unit_price' => 2, 'discount_type' => 'fixed', 'discount_value' => 1,
        'discount_amount' => 1, 'tax_rate' => 10, 'tax_amount' => 0.9, 'subtotal_amount' => 10,
        'total_before_tax' => 9, 'total_after_tax' => 9.9,
    ]);
    app(PurchaseInvoiceMatchingService::class)->matchForPosting($invoice);
    expect($invoice->fresh()->matching_status)->toBe('matched')
        ->and($invoice->lines->first()->fresh()->matched_quantity)->toBe('5.00000000')
        ->and($invoice->lines->first()->fresh()->receipt_line_id)->toBe($receiptLine->getKey());
    $invoice->lines->first()->forceFill(['tax_rate' => 0])->save();
    $invoice->unsetRelation('lines');
    app(PurchaseInvoiceMatchingService::class)->matchForPosting($invoice);
    expect($invoice->fresh()->matching_status)->toBe('approved_with_variance');
    $invoice->lines->first()->forceFill(['tax_rate' => 10])->save();
    $invoice->unsetRelation('lines');
    $invoice = app(PurchaseInvoiceService::class)->approve($invoice);
    $valuedBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();

    expect($receiptMovement->fresh()->unit_cost)->toBe('2.25000000')
        ->and($receiptMovement->fresh()->total_cost)->toBe('11.25000000')
        ->and(InventoryTransaction::query()->where('posting_key', "purchase-receipt:{$receiptLine->getKey()}")->count())->toBe(1)
        ->and((float) $valuedBalance->on_hand)->toBe(5.0)
        ->and((float) $valuedBalance->inventory_value)->toBe(11.25)
        ->and((float) $valuedBalance->unvalued_receipt_quantity)->toBe(0.0);

    $excessInvoice = PurchaseInvoice::query()->create([
        'doc_number' => 9202, 'doc_num' => 'PINV-PROC-2', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $firstOrder->supplier_id, 'purchase_order_id' => $firstOrder->getKey(),
        'invoice_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(),
        'total_amount' => 1.98, 'remaining_amount' => 1.98, 'status' => PurchaseInvoice::StatusDraft,
    ]);
    $excessLine = $excessInvoice->lines()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'purchase_order_line_id' => $orderLine->getKey(), 'receipt_line_id' => $receiptLine->getKey(),
        'quantity' => 1, 'unit_price' => 2, 'discount_type' => 'fixed', 'discount_value' => 0.2,
        'discount_amount' => 0.2, 'tax_rate' => 10, 'tax_amount' => 0.18, 'subtotal_amount' => 2,
        'total_before_tax' => 1.8, 'total_after_tax' => 1.98,
    ]);
    $excessLine->forceFill(['discount_amount' => 0.2, 'tax_rate' => 10])->save();
    $excessInvoice->unsetRelation('lines');
    expect(fn () => app(PurchaseInvoiceMatchingService::class)->matchForPosting($excessInvoice))
        ->toThrow(DomainException::class, __('Invoice quantity exceeds quality-accepted receipt quantity.'));

    $return = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $firstOrder->doc_num, 'return_date' => now()->toDateString(),
        'purchase_invoice_doc_num' => $invoice->doc_num, 'reason_code' => 'latent_defect',
        'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'quantity' => 2, 'from_quarantine' => false]],
    ]);
    $return = $settlement->approvePurchaseReturn($return);
    $returnedBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();
    expect(InventoryTransaction::query()->where('transaction_type', 'purchase_return')->value('quantity_out'))->toBe('2.00000000')
        ->and(InventoryTransaction::query()->where('transaction_type', 'purchase_return')->value('unit_cost'))->toBe('2.25000000')
        ->and((float) $returnedBalance->on_hand)->toBe(3.0)
        ->and((float) $returnedBalance->inventory_value)->toBe(6.75)
        ->and($return->journal_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->credited_amount)->toBe('3.9600')
        ->and($invoice->fresh()->remaining_amount)->toBe('8.9400')
        ->and(fn () => $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $firstOrder->doc_num, 'return_date' => now()->toDateString(),
            'reason_code' => 'latent_defect',
            'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'quantity' => 4, 'from_quarantine' => false]],
        ]))->toThrow(DomainException::class, __('Return quantity exceeds the material received and still returnable.'));

    $return = $settlement->reversePurchaseReturn($return, 'Return entered against the wrong batch.');
    $restoredBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();
    expect($return->status)->toBe('reversed')
        ->and($return->reversal_journal_entry_id)->not->toBeNull()
        ->and(InventoryTransaction::query()->where('transaction_type', 'purchase_return_reversal')->value('quantity_in'))->toBe('2.00000000')
        ->and(InventoryTransaction::query()->where('transaction_type', 'purchase_return_reversal')->value('unit_cost'))->toBe('2.25000000')
        ->and((float) $restoredBalance->on_hand)->toBe(5.0)
        ->and((float) $restoredBalance->inventory_value)->toBe(11.25)
        ->and($invoice->fresh()->credited_amount)->toBe('0.0000')
        ->and($invoice->fresh()->remaining_amount)->toBe('12.9000');

    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo([
        'purchases.goods_receipt_notes.view',
        'purchases.goods_receipt_notes.print',
        'purchases.goods_receipt_inspection.view',
        'purchase_orders.view',
        'purchase_orders.print',
        'purchase_invoices.print',
        'purchases.prices.view',
        'purchases.purchase_requisitions.print',
        'purchases.request_for_quotations.print',
        'purchases.supplier_quotation_comparison.print',
        'purchases.supplier_quotation_entry.print',
        'purchases.supplier_selection.print',
        'purchases.purchase_order_change_requests.print',
        'purchases.purchase_order_delivery_schedule.print',
        'purchases.goods_receipt_inspection.print',
        'purchases.purchase_returns.print',
    ]);

    $this->actingAs($fixture['user'])
        ->get(route('admin.purchases.goods-receipt-notes.show', $receipt->doc_num))
        ->assertOk()
        ->assertSee($receipt->doc_num)
        ->assertDontSee(__('Unit price'));
    $goodsReceiptPdf = $this->get(route('admin.purchases.procurement.print', ['goods-receipt', $receipt->doc_num]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition');
    expect(str_starts_with($goodsReceiptPdf->getContent(), '%PDF-'))->toBeTrue()
        ->and($goodsReceiptPdf->headers->get('content-disposition'))->toStartWith('inline;');

    $purchaseOrderPdf = $this->get(route('admin.purchases.purchase-orders.print', $firstOrder->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $purchaseInvoicePdf = $this->get(route('admin.purchases.purchase-invoices.print', $invoice->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect(str_starts_with($purchaseOrderPdf->getContent(), '%PDF-'))->toBeTrue()
        ->and(str_starts_with($purchaseInvoicePdf->getContent(), '%PDF-'))->toBeTrue();

    $genericPrintDocuments = [
        ['purchase-requisition', $requisition->doc_num],
        ['request-for-quotation', $rfq->doc_num],
        ['quotation-comparison', $rfq->doc_num],
        ['supplier-quotation', $quotations->first()->doc_num],
        ['supplier-selection', $selection->doc_num],
        ['purchase-order-change-request', $changeRequest->doc_num],
        ['purchase-order-delivery-schedule', $firstOrder->doc_num],
        ['goods-receipt-inspection', $inspection->doc_num],
        ['purchase-return', $return->doc_num],
    ];
    foreach ($genericPrintDocuments as [$type, $documentNumber]) {
        $documentPdf = $this->get(route('admin.purchases.procurement.print', [$type, $documentNumber]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
        expect(str_starts_with($documentPdf->getContent(), '%PDF-'))->toBeTrue()
            ->and($documentPdf->headers->get('content-disposition'))->toStartWith('inline;');
    }
    $this->get(route('admin.purchases.goods-receipt-inspection.show', $inspection->doc_num))
        ->assertOk()
        ->assertSee(__('Accepted'))
        ->assertSee(__('Rejected'))
        ->assertSee('5')
        ->assertSee('1');
    $fixture['user']->revokePermissionTo('purchases.prices.view');
    $this->get(route('admin.purchases.purchase-orders.show', $firstOrder->doc_num))
        ->assertForbidden();
});

test('procurement-specific confidentiality and report permissions are discoverable', function () {
    $permissions = app(PermissionRegistryService::class)->all();

    expect($permissions)->toContain('purchases.prices.view')
        ->and($permissions)->toContain('purchases.direct_procurement.override')
        ->and($permissions)->toContain('reports.purchases.view');
});

test('the ten thousand kilogram split award closes supplier B and reconciles quantity value and supplier balances', function () {
    $fixture = procurementFixture();
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(PermissionSeeder::class);

    $supplierAAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Supplier A Payable');
    $supplierBAccount = procurementPostingAccount($fixture['company'], '2111', '2111002', 'Supplier B Payable');
    $bankAccountGl = procurementPostingAccount($fixture['company'], '1112', '1112001', 'Procurement Settlement Bank');
    $bankParent = Account::query()->where('company_id', $fixture['company']->getKey())->where('account_code', '1112')->firstOrFail();
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAAccount->getKey()])->save();
    $fixture['secondSupplier']->forceFill(['account_id' => $supplierBAccount->getKey()])->save();
    $bankAccount = BankAccount::query()->create([
        'doc_number' => 9701,
        'doc_num' => 'BANK-SPLIT-SETTLEMENT',
        'company_id' => $fixture['company']->getKey(),
        'bank_id' => $bankParent->getKey(),
        'account_id' => $bankAccountGl->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'account_name' => 'Split Award Settlement Account',
        'account_number' => '0099004400',
        'bank_branch_name' => 'Factory Branch',
        'status' => 'active',
    ]);

    $sourcing = app(ProcurementSourcingService::class);
    $receiving = app(ProcurementReceivingService::class);
    $settlement = app(ProcurementSettlementService::class);
    $requisition = procurementManualRequisition($fixture, 10000);
    $sourcing->submitRequisition($requisition);
    $requisition = $sourcing->approveRequisition($requisition->fresh());
    $requirementLine = $requisition->lines->firstOrFail();
    $rfq = $sourcing->createRequestForQuotation($requisition, [
        'issue_date' => now()->toDateString(),
        'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num, $fixture['secondSupplier']->doc_num],
        'lines' => [['requisition_line_public_id' => $requirementLine->public_id, 'quantity' => 10000]],
    ]);
    $rfq = $sourcing->issueRequestForQuotation($rfq);
    $rfqLine = $rfq->lines->firstOrFail();
    $quotations = collect([
        [$fixture['firstSupplier'], 2, 100],
        [$fixture['secondSupplier'], 2.5, 80],
    ])->map(function (array $offer) use ($fixture, $rfq, $rfqLine, $sourcing) {
        $quotation = $sourcing->createSupplierQuotation($rfq, [
            'supplier_doc_num' => $offer[0]->doc_num,
            'currency_doc_num' => $fixture['currency']->doc_num,
            'quotation_date' => now()->toDateString(),
            'exchange_rate' => 1,
            'freight_amount' => 0,
            'lines' => [[
                'rfq_line_public_id' => $rfqLine->public_id,
                'offered_quantity' => 10000,
                'unit_price' => $offer[1],
                'discount_amount' => $offer[2],
                'tax_rate' => 14,
            ]],
        ]);

        return $sourcing->submitSupplierQuotation($quotation);
    });
    $selection = $sourcing->createSupplierSelection($rfq->fresh(), [
        'selection_date' => now()->toDateString(),
        'selection_reason' => 'Canonical 60/40 split award.',
        'lines' => [
            ['quotation_line_public_id' => $quotations->first()->lines->first()->public_id, 'selected_quantity' => 6000],
            ['quotation_line_public_id' => $quotations->last()->lines->first()->public_id, 'selected_quantity' => 4000],
        ],
    ]);
    $orders = $sourcing->approveSelection($selection);
    $supplierAOrder = app(PurchaseOrderService::class)->approve($orders->firstWhere('supplier_id', $fixture['firstSupplier']->getKey()));
    $supplierBOrder = app(PurchaseOrderService::class)->approve($orders->firstWhere('supplier_id', $fixture['secondSupplier']->getKey()));

    $receiveAndInspect = function (PurchaseOrder $order, float $delivered, float $accepted, float $rejected) use ($receiving): array {
        $orderLine = $order->lines->firstOrFail();
        $receipt = $receiving->receive($order, [
            'document_date' => now()->toDateString(),
            'supplier_delivery_note' => 'DN-'.$order->doc_num,
            'lines' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'delivered_quantity' => $delivered,
            ]],
        ]);
        $receiptLine = $receipt->lines->firstOrFail();
        $inspection = $receiving->inspect($receipt, [
            'inspection_at' => now()->toDateString(),
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id,
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'disposition' => $rejected > 0 ? 'quarantine' : 'accepted',
                'reason' => $rejected > 0 ? 'Rejected during incoming QC.' : null,
            ]],
        ]);

        $receipt = $receiving->postReceipt($receipt->fresh());

        return [$receipt, $receiptLine->fresh(), $inspection];
    };

    [$supplierAReceipt, $supplierAReceiptLine] = $receiveAndInspect($supplierAOrder, 6000, 5900, 100);
    [$supplierBReceipt, $supplierBReceiptLine] = $receiveAndInspect($supplierBOrder, 4000, 4000, 0);

    $preInvoiceReturn = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $supplierAOrder->doc_num,
        'return_date' => now()->toDateString(),
        'reason_code' => 'incoming_qc_rejection',
        'lines' => [[
            'receipt_line_public_id' => $supplierAReceiptLine->public_id,
            'quantity' => 100,
            'from_quarantine' => true,
        ]],
    ]);
    $preInvoiceReturn = $settlement->approvePurchaseReturn($preInvoiceReturn);

    $beforeInvoiceBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();
    expect((float) $beforeInvoiceBalance->on_hand)->toBe(9900.0)
        ->and((float) $beforeInvoiceBalance->inventory_value)->toBe(21709.0)
        ->and((float) $beforeInvoiceBalance->unvalued_receipt_quantity)->toBe(0.0)
        ->and($preInvoiceReturn->journal_entry_id)->toBeNull();

    $createInvoice = function (
        int $docNumber,
        PurchaseOrder $order,
        object $receiptLine,
        float $quantity,
        float $unitPrice,
        float $discount,
        float $headerDiscount = 0,
    ) use ($fixture): PurchaseInvoice {
        $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([[
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_type' => 'fixed',
            'discount_value' => $discount,
            'tax_rate' => 14,
        ]], $headerDiscount > 0 ? 'fixed' : null, $headerDiscount);
        $invoice = PurchaseInvoice::query()->create([
            'doc_number' => $docNumber,
            'doc_num' => 'PINV-SPLIT-'.$docNumber,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->getKey(),
            'invoice_date' => now()->toDateString(),
            'currency_id' => $fixture['currency']->getKey(),
            'exchange_rate' => 1,
            ...$calculation['invoice'],
            'remaining_amount' => $calculation['invoice']['total_amount'],
            'status' => PurchaseInvoice::StatusDraft,
        ]);
        $invoice->lines()->create([
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'line_number' => 1,
            'product_id' => $fixture['raw']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'purchase_order_line_id' => $order->lines->firstOrFail()->getKey(),
            'receipt_line_id' => $receiptLine->getKey(),
            ...$calculation['lines'][0],
        ]);
        $invoice->paymentSchedules()->create([
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'line_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => $calculation['invoice']['total_amount'],
            'paid_amount' => 0,
            'status' => 'scheduled',
        ]);
        app(PurchaseInvoiceMatchingService::class)->matchForPosting($invoice);

        return app(PurchaseInvoiceService::class)->approve($invoice);
    };

    $supplierAInvoice = $createInvoice(9801, $supplierAOrder, $supplierAReceiptLine, 5900, 2, 59, 100);
    $supplierBInvoice = $createInvoice(9802, $supplierBOrder, $supplierBReceiptLine, 4000, 2.5, 32);
    $postInvoiceReturn = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $supplierAOrder->doc_num,
        'purchase_invoice_doc_num' => $supplierAInvoice->doc_num,
        'return_date' => now()->toDateString(),
        'reason_code' => 'latent_defect',
        'lines' => [[
            'receipt_line_public_id' => $supplierAReceiptLine->public_id,
            'quantity' => 500,
            'from_quarantine' => false,
        ]],
    ]);
    $postInvoiceReturn = $settlement->approvePurchaseReturn($postInvoiceReturn);

    $payInvoice = function (PurchaseInvoice $invoice, Supplier $supplier) use ($bankAccount, $fixture, $settlement): SupplierPaymentContext {
        $invoice->refreshPaymentTotals();
        $payment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $supplier->doc_num,
            'payment_method' => SupplierPaymentContext::MethodBank,
            'payment_date' => now()->toDateString(),
            'bank_account_doc_num' => $bankAccount->doc_num,
            'currency_doc_num' => $fixture['currency']->doc_num,
            'exchange_rate' => 1,
            'amount' => $invoice->remaining_amount,
            'reason' => 'Close canonical split-award Supplier balance.',
            'allocations' => [[
                'purchase_invoice_doc_num' => $invoice->doc_num,
                'payment_schedule_public_id' => $invoice->paymentSchedules()->firstOrFail()->public_id,
                'amount' => $invoice->remaining_amount,
            ]],
        ]);

        return $settlement->approveSupplierPayment($payment);
    };

    $supplierAPayment = $payInvoice($supplierAInvoice, $fixture['firstSupplier']);
    $supplierBPayment = $payInvoice($supplierBInvoice, $fixture['secondSupplier']);
    $finalBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();
    $ledgerFilters = [
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'from_date' => $fixture['period']->from_date->toDateString(),
        'to_date' => $fixture['period']->to_date->toDateString(),
    ];
    $supplierAStatement = app(LedgerQueryService::class)->accountLedger([...$ledgerFilters, 'account_id' => $supplierAAccount->getKey()]);
    $supplierBStatement = app(LedgerQueryService::class)->accountLedger([...$ledgerFilters, 'account_id' => $supplierBAccount->getKey()]);

    expect((float) $requisition->lines()->sum('requested_quantity'))->toBe(10000.0)
        ->and((float) $orders->sum(fn (PurchaseOrder $order): float => (float) $order->total_ordered_quantity))->toBe(10000.0)
        ->and((float) $supplierAReceipt->lines()->sum('delivered_quantity') + (float) $supplierBReceipt->lines()->sum('delivered_quantity'))->toBe(10000.0)
        ->and((float) $supplierAReceipt->lines()->sum('accepted_quantity') + (float) $supplierBReceipt->lines()->sum('accepted_quantity'))->toBe(9900.0)
        ->and((float) $supplierAReceipt->lines()->sum('rejected_quantity') + (float) $supplierBReceipt->lines()->sum('rejected_quantity'))->toBe(100.0)
        ->and((float) $preInvoiceReturn->total_quantity)->toBe(100.0)
        ->and((float) $supplierAInvoice->lines()->sum('quantity') + (float) $supplierBInvoice->lines()->sum('quantity'))->toBe(9900.0)
        ->and((float) $postInvoiceReturn->total_quantity)->toBe(500.0)
        ->and((float) $finalBalance->on_hand)->toBe(9400.0)
        ->and((float) $finalBalance->inventory_value)->toBe(20714.0)
        ->and((float) $finalBalance->unvalued_receipt_quantity)->toBe(0.0)
        ->and(InventoryTransaction::query()->where('transaction_type', 'purchase_receipt')->count())->toBe(2)
        ->and($supplierAInvoice->fresh()->total_amount)->toBe('13270.7400')
        ->and($supplierAInvoice->fresh()->credited_amount)->toBe('1124.6636')
        ->and($supplierAPayment->amount)->toBe('12146.0764')
        ->and($supplierAInvoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($supplierAStatement['period']['credit'])->toBe('13270.7400')
        ->and($supplierAStatement['period']['debit'])->toBe('13270.7400')
        ->and($supplierAStatement['ending']['credit'])->toBe('0.0000')
        ->and($supplierBInvoice->fresh()->total_amount)->toBe('11363.5200')
        ->and($supplierBInvoice->fresh()->credited_amount)->toBe('0.0000')
        ->and($supplierBPayment->amount)->toBe('11363.5200')
        ->and($supplierBInvoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($supplierBStatement['period']['credit'])->toBe('11363.5200')
        ->and($supplierBStatement['period']['debit'])->toBe('11363.5200')
        ->and($supplierBStatement['ending']['credit'])->toBe('0.0000');

    $fixture['user']->givePermissionTo('purchases.purchase_returns.print');
    foreach ([$preInvoiceReturn, $postInvoiceReturn] as $returnDocument) {
        $returnPdf = $this->actingAs($fixture['user'])
            ->get(route('admin.purchases.procurement.print', ['purchase-return', $returnDocument->doc_num]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
        expect(str_starts_with($returnPdf->getContent(), '%PDF-'))->toBeTrue();
    }
});

test('supplier installments, partial payments, advances, and cancellation accounting reconcile', function () {
    $fixture = procurementFixture();
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(PermissionSeeder::class);

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Procurement Supplier Payable');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111001', 'Procurement Cash');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Procurement Cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true,
        'status' => 'active',
    ]);

    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9301,
        'doc_num' => 'PINV-PAYMENT-PROC',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'total_amount' => 10,
        'paid_amount' => 0,
        'remaining_amount' => 10,
        'status' => PurchaseInvoice::StatusApproved,
    ]);
    $firstSchedule = $invoice->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1,
        'due_date' => now()->toDateString(),
        'amount' => 5,
        'paid_amount' => 0,
        'status' => 'scheduled',
    ]);
    $secondSchedule = $invoice->paymentSchedules()->create([
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 2,
        'due_date' => now()->addMonth()->toDateString(),
        'amount' => 5,
        'paid_amount' => 0,
        'status' => 'scheduled',
    ]);
    $settlement = app(ProcurementSettlementService::class);
    $paymentPayload = [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'payment_date' => now()->toDateString(),
        'cashbox_doc_num' => $cashbox->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'reason' => 'Supplier installment',
    ];

    $firstPayment = $settlement->createSupplierPayment([
        ...$paymentPayload,
        'amount' => 4,
        'allocations' => [[
            'purchase_invoice_doc_num' => $invoice->doc_num,
            'payment_schedule_public_id' => $firstSchedule->public_id,
            'amount' => 4,
        ]],
    ]);
    $settlement->approveSupplierPayment($firstPayment);

    expect($invoice->fresh()->paid_amount)->toBe('4.0000')
        ->and($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPartiallyPaid)
        ->and($firstSchedule->fresh()->status)->toBe('partially_paid')
        ->and($firstPayment->fresh()->journal_entry_id)->not->toBeNull();

    $secondPayment = $settlement->createSupplierPayment([
        ...$paymentPayload,
        'amount' => 6,
        'allocations' => [[
            'purchase_invoice_doc_num' => $invoice->doc_num,
            'amount' => 6,
        ]],
    ]);
    $settlement->approveSupplierPayment($secondPayment);
    $journal = JournalEntry::query()->findOrFail($secondPayment->fresh()->journal_entry_id);
    $installmentRows = app(ProcurementCycleReport::class)->rows(
        ProcurementCycleReport::DueSupplierInstallments,
        [],
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    )->whereIn('document', [
        $invoice->doc_num.' / '.$firstSchedule->line_number,
        $invoice->doc_num.' / '.$secondSchedule->line_number,
    ])->values();

    expect($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid)
        ->and($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($firstSchedule->fresh()->status)->toBe('paid')
        ->and($firstSchedule->fresh()->paid_amount)->toBe('5.0000')
        ->and($firstSchedule->fresh()->outstanding_amount)->toBe('0.0000')
        ->and($secondSchedule->fresh()->status)->toBe('paid')
        ->and($secondSchedule->fresh()->paid_amount)->toBe('5.0000')
        ->and($secondSchedule->fresh()->outstanding_amount)->toBe('0.0000')
        ->and($installmentRows)->toHaveCount(2)
        ->and($installmentRows->pluck('outstanding')->all())->toBe(['0.0000', '0.0000'])
        ->and($installmentRows->pluck('overdue')->all())->toBe([false, false])
        ->and((float) $journal->lines()->sum('debit_amount'))->toBe(6.0)
        ->and((float) $journal->lines()->sum('credit_amount'))->toBe(6.0);

    app(CashVoucherService::class)->cancel(CashVoucher::TypePayment, $secondPayment->cashVoucher, 'Payment cancelled');

    expect($journal->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('4.0000')
        ->and($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPartiallyPaid)
        ->and($firstSchedule->fresh()->status)->toBe('partially_paid')
        ->and($secondSchedule->fresh()->status)->toBe('scheduled')
        ->and(fn () => $settlement->createSupplierPayment([
            ...$paymentPayload,
            'amount' => 7,
            'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => 7]],
        ]))->toThrow(DomainException::class, __('Payment allocation exceeds the supplier invoice outstanding amount.'));

    $advance = $settlement->createSupplierPayment([
        ...$paymentPayload,
        'amount' => 3,
        'is_advance' => true,
        'allocations' => [],
    ]);
    $settlement->approveSupplierPayment($advance);

    expect($advance->fresh()->journal_entry_id)->not->toBeNull()
        ->and($advance->fresh()->is_advance)->toBeTrue()
        ->and($invoice->fresh()->paid_amount)->toBe('4.0000');

    $settlement->allocatePayment($advance, [[
        'purchase_invoice_doc_num' => $invoice->doc_num,
        'payment_schedule_public_id' => $firstSchedule->public_id,
        'amount' => 1,
    ], [
        'purchase_invoice_doc_num' => $invoice->doc_num,
        'payment_schedule_public_id' => $secondSchedule->public_id,
        'amount' => 1,
    ]]);

    expect($advance->fresh()->allocated_amount)->toBe('2.0000')
        ->and($invoice->fresh()->paid_amount)->toBe('6.0000')
        ->and($firstSchedule->fresh()->status)->toBe('paid')
        ->and($secondSchedule->fresh()->status)->toBe('partially_paid');

    $fixture['user']->givePermissionTo(['supplier_payments.print', 'purchases.prices.view']);
    $cashPaymentPdf = $this->actingAs($fixture['user'])
        ->get(route('admin.purchases.procurement.print', ['supplier-payment', $firstPayment->doc_num]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition');
    expect(str_starts_with($cashPaymentPdf->getContent(), '%PDF-'))->toBeTrue();
});

test('bank and issued cheque supplier payments use canonical finance records and reversible journals', function () {
    $fixture = procurementFixture();
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(PermissionSeeder::class);

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Procurement Supplier Payable');
    $bankAccountGl = procurementPostingAccount($fixture['company'], '1112', '1112001', 'Procurement Bank Current Account');
    $paymentPaperAccount = Account::query()->where('company_id', $fixture['company']->getKey())->where('account_code', '2112')->firstOrFail();
    $bankParent = Account::query()->where('company_id', $fixture['company']->getKey())->where('account_code', '1112')->firstOrFail();
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $bankAccount = BankAccount::query()->create([
        'doc_number' => 9501,
        'doc_num' => 'BANK-PROC-1',
        'company_id' => $fixture['company']->getKey(),
        'bank_id' => $bankParent->getKey(),
        'account_id' => $bankAccountGl->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'account_name' => 'Procurement Operating Account',
        'account_number' => '0011223344',
        'bank_branch_name' => 'Factory Branch',
        'status' => 'active',
    ]);
    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9501,
        'doc_num' => 'PINV-PAYMENT-METHODS',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'total_amount' => 10,
        'remaining_amount' => 10,
        'status' => PurchaseInvoice::StatusApproved,
    ]);
    $settlement = app(ProcurementSettlementService::class);
    $common = [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'payment_date' => now()->toDateString(),
        'currency_doc_num' => $fixture['currency']->doc_num,
        'bank_account_doc_num' => $bankAccount->doc_num,
        'exchange_rate' => 1,
        'reason' => 'Supplier settlement',
    ];

    $bankPayment = $settlement->createSupplierPayment([
        ...$common,
        'payment_method' => SupplierPaymentContext::MethodBank,
        'amount' => 4,
        'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => 4]],
    ]);
    $bankPayment = $settlement->approveSupplierPayment($bankPayment);
    $bankJournal = $bankPayment->journalEntry()->with('lines')->firstOrFail();

    expect($bankPayment->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and($bankPayment->cash_voucher_id)->toBeNull()
        ->and($bankPayment->bank_account_id)->toBe($bankAccount->getKey())
        ->and((float) $bankJournal->lines->sum('debit_amount'))->toBe(4.0)
        ->and((float) $bankJournal->lines->sum('credit_amount'))->toBe(4.0)
        ->and($bankJournal->lines->firstWhere('account_id', $bankAccountGl->getKey())?->bank_account_id)->toBe($bankAccount->getKey())
        ->and($invoice->fresh()->paid_amount)->toBe('4.0000');

    $settlement->cancelSupplierPayment($bankPayment, 'Bank payment recalled.');
    expect($bankPayment->fresh()->status)->toBe(SupplierPaymentContext::StatusCancelled)
        ->and($bankJournal->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('0.0000');

    $chequePayment = $settlement->createSupplierPayment([
        ...$common,
        'payment_method' => SupplierPaymentContext::MethodCheque,
        'amount' => 5,
        'cheque_number' => 'CHK-PROC-100',
        'cheque_date' => now()->toDateString(),
        'cheque_due_date' => now()->addWeek()->toDateString(),
        'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => 5]],
    ]);
    $chequePayment = $settlement->approveSupplierPayment($chequePayment);
    $issuedCheque = $chequePayment->cheque;
    $chequeJournal = $chequePayment->journalEntry()->with('lines')->firstOrFail();

    expect($issuedCheque)->toBeInstanceOf(Cheque::class)
        ->and($issuedCheque->doc_num)->toStartWith('OCH-')
        ->and($issuedCheque->cheque_type)->toBe(Cheque::TypeIssued)
        ->and($issuedCheque->status)->toBe(Cheque::StatusIssued)
        ->and($issuedCheque->party_type)->toBe(Supplier::class)
        ->and($issuedCheque->party_id)->toBe($fixture['firstSupplier']->getKey())
        ->and($issuedCheque->bank_account_id)->toBe($bankAccount->getKey())
        ->and($issuedCheque->cheque_date->toDateString())->toBe(now()->toDateString())
        ->and($issuedCheque->due_date->toDateString())->toBe(now()->addWeek()->toDateString())
        ->and($chequePayment->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and($chequePayment->journal_entry_id)->not->toBeNull()
        ->and((float) $chequeJournal->lines->firstWhere('account_id', $supplierAccount->getKey())?->debit_amount)->toBe(5.0)
        ->and((float) $chequeJournal->lines->firstWhere('account_id', $paymentPaperAccount->getKey())?->credit_amount)->toBe(5.0)
        ->and($chequeJournal->lines->firstWhere('account_id', $bankAccountGl->getKey()))->toBeNull()
        ->and($invoice->fresh()->paid_amount)->toBe('5.0000');

    $settlement->cancelSupplierPayment($chequePayment, 'Cheque voided before delivery.');
    expect($chequePayment->fresh()->status)->toBe(SupplierPaymentContext::StatusCancelled)
        ->and($issuedCheque->fresh()->status)->toBe(Cheque::StatusCancelled)
        ->and($chequeJournal->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->remaining_amount)->toBe('10.0000');

    $clearedPayment = $settlement->createSupplierPayment([
        ...$common,
        'payment_method' => SupplierPaymentContext::MethodCheque,
        'amount' => 5,
        'cheque_number' => 'CHK-PROC-101',
        'cheque_date' => now()->toDateString(),
        'cheque_due_date' => now()->addWeek()->toDateString(),
        'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => 5]],
    ]);
    $clearedPayment = $settlement->approveSupplierPayment($clearedPayment);
    $clearedCheque = app(ChequeService::class)->markCleared($clearedPayment->cheque);
    $clearingJournal = JournalEntry::query()
        ->with('lines')
        ->where('source_type', 'supplier_cheque_clearing')
        ->where('source_id', $clearedPayment->getKey())
        ->firstOrFail();

    expect($clearedCheque->status)->toBe(Cheque::StatusCleared)
        ->and((float) $clearingJournal->lines->firstWhere('account_id', $paymentPaperAccount->getKey())?->debit_amount)->toBe(5.0)
        ->and((float) $clearingJournal->lines->firstWhere('account_id', $bankAccountGl->getKey())?->credit_amount)->toBe(5.0)
        ->and($clearingJournal->lines->firstWhere('account_id', $bankAccountGl->getKey())?->bank_account_id)->toBe($bankAccount->getKey())
        ->and($invoice->fresh()->remaining_amount)->toBe('5.0000')
        ->and(fn () => app(ChequeService::class)->cancel($clearedCheque, 'Cannot void cleared cheque.'))
        ->toThrow(DomainException::class, __('cheques.messages.status_transition_forbidden'));

    $clearedCheque = app(ChequeService::class)->reverseClearing($clearedCheque, 'Bank rejected the clearing file.');
    $clearingEvent = $clearedCheque->clearingEvents->sole();
    $clearingReversal = $clearingEvent->reversalJournalEntry()->with('lines')->firstOrFail();
    expect($clearedCheque->status)->toBe(Cheque::StatusClearingReversed)
        ->and($clearingEvent->status)->toBe('reversed')
        ->and($clearingEvent->clearing_journal_entry_id)->toBe($clearingJournal->getKey())
        ->and($clearingEvent->reversal_journal_entry_id)->toBe($clearingReversal->getKey())
        ->and((float) $clearingReversal->lines->firstWhere('account_id', $paymentPaperAccount->getKey())?->credit_amount)->toBe(5.0)
        ->and((float) $clearingReversal->lines->firstWhere('account_id', $bankAccountGl->getKey())?->debit_amount)->toBe(5.0)
        ->and($clearedPayment->fresh()->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and($invoice->fresh()->remaining_amount)->toBe('5.0000');

    $clearedCheque = app(ChequeService::class)->cancel($clearedCheque, 'Cancelled after clearing reversal.');
    expect($clearedCheque->status)->toBe(Cheque::StatusCancelled)
        ->and($clearedPayment->fresh()->status)->toBe(SupplierPaymentContext::StatusCancelled)
        ->and($clearedPayment->journalEntry->fresh()->reversed_entry_id)->not->toBeNull()
        ->and($invoice->fresh()->remaining_amount)->toBe('10.0000');

    $fixture['user']->givePermissionTo(['cheques.print', 'supplier_payments.print', 'purchases.prices.view']);
    foreach ([$bankPayment, $clearedPayment] as $paymentDocument) {
        $paymentPdf = $this->actingAs($fixture['user'])
            ->get(route('admin.purchases.procurement.print', ['supplier-payment', $paymentDocument->doc_num]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
        expect(str_starts_with($paymentPdf->getContent(), '%PDF-'))->toBeTrue();
    }
    $chequePdf = $this->actingAs($fixture['user'])
        ->get(route('admin.finance.cheques.print', $clearedCheque->doc_num))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition');
    expect(str_starts_with($chequePdf->getContent(), '%PDF-'))->toBeTrue();
});

test('freight discount tax posting, invoice reversal, and period locks are exact', function () {
    $fixture = procurementFixture();
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111001', 'Procurement Supplier Payable');
    $fixture['firstSupplier']->forceFill([
        'account_id' => $supplierAccount->getKey(),
        'payment_terms_days' => 30,
    ])->save();

    $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([[
        'quantity' => 2,
        'unit_price' => 10,
        'tax_rate' => 14,
    ]], 'fixed', 2, 5, 14);

    expect($calculation['lines'][0]['tax_amount'])->toBe('2.5200')
        ->and($calculation['lines'][0]['total_after_tax'])->toBe('20.5200')
        ->and($calculation['invoice']['freight_tax_amount'])->toBe('0.7000')
        ->and($calculation['invoice']['tax_amount'])->toBe('3.2200')
        ->and($calculation['invoice']['total_amount'])->toBe('26.2200');

    $invoice = app(PurchaseInvoiceService::class)->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'purchase_type' => 'direct',
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Emergency resin supply approved outside the PO flow.',
        'header_discount_type' => 'fixed',
        'header_discount_value' => 2,
        'freight_amount' => 5,
        'freight_tax_rate' => 14,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 10,
            'tax_rate' => 14,
        ]],
    ])['record'];

    expect($invoice->paymentSchedules)->toHaveCount(1)
        ->and($invoice->paymentSchedules->first()->due_date->toDateString())->toBe(now()->addDays(30)->toDateString());

    $invoice = app(PurchaseInvoiceService::class)->approve($invoice);
    $journal = $invoice->journalEntry()->with('lines.account')->firstOrFail();
    $debitsByAccount = $journal->lines->filter(fn ($line) => (float) $line->debit_amount > 0)
        ->mapWithKeys(fn ($line): array => [$line->account_id => (float) $line->debit_amount]);
    $rawInventoryAccount = procurementAccountByClassification($fixture['company'], 'raw_material_inventory');
    $freightAccount = procurementAccountByClassification($fixture['company'], 'freight_in');
    $recoverableVatAccount = procurementAccountByClassification($fixture['company'], 'recoverable_vat');

    expect($invoice->matching_status)->toBe('authorized_direct')
        ->and($debitsByAccount->get($rawInventoryAccount->getKey()))->toBe(18.0)
        ->and($debitsByAccount->get($freightAccount->getKey()))->toBe(5.0)
        ->and($debitsByAccount->get($recoverableVatAccount->getKey()))->toBe(3.22)
        ->and((float) $journal->lines->sum('debit_amount'))->toBe(26.22)
        ->and((float) $journal->lines->sum('credit_amount'))->toBe(26.22);

    $reversed = app(PurchaseInvoiceService::class)->reverse($invoice, 'Supplier invoice reference was duplicated.');
    $reversal = JournalEntry::query()->with('lines')->findOrFail($reversed->reversal_journal_entry_id);
    expect($reversed->status)->toBe(PurchaseInvoice::StatusCancelled)
        ->and($journal->fresh()->reversed_entry_id)->toBe($reversal->getKey())
        ->and((float) $reversal->lines->sum('debit_amount'))->toBe(26.22)
        ->and((float) $reversal->lines->sum('credit_amount'))->toBe(26.22);

    $lockedInvoice = app(PurchaseInvoiceService::class)->create([
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'purchase_type' => 'direct',
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Approved exception.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 1,
            'unit_price' => 10,
        ]],
    ])['record'];
    $fixture['period']->forceFill(['is_closed' => true])->save();

    $lockedPayment = SupplierPaymentContext::query()->create([
        'doc_number' => 9401,
        'doc_num' => 'SPAY-LOCKED-PROC',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'payment_method' => SupplierPaymentContext::MethodCash,
        'payment_date' => now()->toDateString(),
        'amount' => 10,
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => 1,
        'status' => SupplierPaymentContext::StatusDraft,
        'is_advance' => true,
        'allocated_amount' => 0,
    ]);

    expect(fn () => app(PurchaseInvoiceService::class)->approve($lockedInvoice))
        ->toThrow(DomainException::class, __('purchase_invoices.messages.period_closed'))
        ->and($lockedInvoice->fresh()->journal_entry_id)->toBeNull()
        ->and(fn () => app(ProcurementSettlementService::class)->approveSupplierPayment($lockedPayment))
        ->toThrow(DomainException::class, __('purchase_invoices.messages.period_closed'))
        ->and($lockedPayment->fresh()->status)->toBe(SupplierPaymentContext::StatusDraft)
        ->and($lockedPayment->fresh()->journal_entry_id)->toBeNull();
});

test('accepted returns and multiple partial invoices clear grni exactly with purchase price variance', function () {
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111099', 'GRNI Supplier Payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $purchaseOrders = app(PurchaseOrderService::class);
    $order = $purchaseOrders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Focused partial GRNI lifecycle verification.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 1000,
            'unit_price' => 10,
            'tax_rate' => 0,
        ]],
    ])['record'];
    $order = $purchaseOrders->approve($order);
    $orderLine = $order->lines->firstOrFail();

    $receiving = app(ProcurementReceivingService::class);
    $receipt = $receiving->receive($order, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'DN-GRNI-PARTIAL',
        'lines' => [[
            'purchase_order_line_public_id' => $orderLine->public_id,
            'delivered_quantity' => 1000,
        ]],
    ]);
    $receiptLine = $receipt->lines->firstOrFail();
    $receiving->inspect($receipt, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'receipt_line_public_id' => $receiptLine->public_id,
            'accepted_quantity' => 1000,
            'rejected_quantity' => 0,
            'disposition' => 'accepted',
        ]],
    ]);
    $receipt = $receiving->postReceipt($receipt->fresh());

    $receiptLine->refresh();
    expect($receiptLine->provisional_unit_value)->toBe('10.00000000')
        ->and($receiptLine->provisional_total_value)->toBe('10000.0000')
        ->and($receiptLine->grni_journal_entry_id)->not->toBeNull();

    $reports = app(ProcurementCycleReport::class);
    $grniRows = $reports->rows(
        ProcurementCycleReport::GoodsReceivedNotInvoiced,
        [],
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    );
    expect($grniRows)->toHaveCount(1)
        ->and($grniRows->first()['returned_quantity'])->toBe('0.00000000')
        ->and($grniRows->first()['remaining_quantity'])->toBe('1000.00000000')
        ->and($grniRows->first()['remaining_grni_value'])->toBe('10000.0000')
        ->and($reports->grniReconciliation($fixture['company']->getKey(), $fixture['period']->getKey()))
        ->toMatchArray(['subledger' => '10000.0000', 'gl' => '10000.0000', 'difference' => '0.0000', 'status' => 'reconciled']);
    $grniExport = new ProcurementCycleReportExport(
        $reports,
        $grniRows,
        true,
        ProcurementCycleReport::GoodsReceivedNotInvoiced,
    );
    expect($grniExport->headings())->toContain('Received Qty', 'Remaining Qty', 'Remaining GRNI Value', 'Days Outstanding')
        ->and($grniExport->collection())->toHaveCount(1)
        ->and($grniExport->map($grniRows->first()))->toContain('1000.00000000', '10000.0000');

    $createMatchedInvoice = function (int $docNumber, float $quantity, float $unitPrice) use ($fixture, $order, $orderLine, $receiptLine): PurchaseInvoice {
        $calculation = app(PurchaseInvoiceCalculationService::class)->calculate([[
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
        ]], null, 0);
        $invoice = PurchaseInvoice::query()->create([
            'doc_number' => $docNumber,
            'doc_num' => 'PINV-GRNI-'.$docNumber,
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'supplier_id' => $fixture['firstSupplier']->getKey(),
            'purchase_order_id' => $order->getKey(),
            'invoice_date' => now()->toDateString(),
            'currency_id' => $fixture['currency']->getKey(),
            'exchange_rate' => 1,
            ...$calculation['invoice'],
            'remaining_amount' => $calculation['invoice']['total_amount'],
            'status' => PurchaseInvoice::StatusDraft,
        ]);
        $invoice->lines()->create([
            'company_id' => $fixture['company']->getKey(),
            'financial_period_id' => $fixture['period']->getKey(),
            'line_number' => 1,
            'product_id' => $fixture['raw']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'purchase_order_line_id' => $orderLine->getKey(),
            'receipt_line_id' => $receiptLine->getKey(),
            ...$calculation['lines'][0],
        ]);

        app(PurchaseInvoiceMatchingService::class)->matchForPosting($invoice);

        return app(PurchaseInvoiceService::class)->approve($invoice);
    };

    $firstInvoice = $createMatchedInvoice(9851, 400, 12);
    $receiptLine->refresh();
    $grniRows = $reports->rows(ProcurementCycleReport::GoodsReceivedNotInvoiced, [], $fixture['company']->getKey(), $fixture['period']->getKey());
    expect((float) $firstInvoice->lines->first()->purchase_price_variance)->toBe(800.0)
        ->and($receiptLine->grni_cleared_quantity)->toBe('400.00000000')
        ->and($grniRows->first()['remaining_quantity'])->toBe('600.00000000')
        ->and($grniRows->first()['remaining_grni_value'])->toBe('6000.0000');

    $secondInvoice = $createMatchedInvoice(9852, 600, 9);
    $receiptLine->refresh();
    $grniRows = $reports->rows(ProcurementCycleReport::GoodsReceivedNotInvoiced, [], $fixture['company']->getKey(), $fixture['period']->getKey());
    expect((float) $secondInvoice->lines->first()->purchase_price_variance)->toBe(-600.0)
        ->and($receiptLine->grni_cleared_quantity)->toBe('1000.00000000')
        ->and($receiptLine->grni_cleared_value)->toBe('10000.0000')
        ->and($grniRows->first()['remaining_quantity'])->toBe('0.00000000')
        ->and($grniRows->first()['remaining_grni_value'])->toBe('0.0000')
        ->and($reports->grniReconciliation($fixture['company']->getKey(), $fixture['period']->getKey()))
        ->toMatchArray(['subledger' => '0.0000', 'gl' => '0.0000', 'difference' => '0.0000', 'status' => 'reconciled']);

    expect(fn () => $createMatchedInvoice(9853, 1, 10))
        ->toThrow(DomainException::class, __('Invoice quantity exceeds quality-accepted receipt quantity.'));

    foreach ([$firstInvoice, $secondInvoice] as $billedInvoice) {
        $settlement = app(ProcurementSettlementService::class);
        $billedReturn = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $order->doc_num,
            'purchase_invoice_doc_num' => $billedInvoice->doc_num,
            'return_date' => now()->toDateString(), 'reason_code' => 'price_variance_return',
            'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'quantity' => 10]],
        ]);
        $billedReturn = $settlement->approvePurchaseReturn($billedReturn);
        $movement = InventoryTransaction::query()->where('posting_key', 'purchase-return:'.$billedReturn->lines->sole()->getKey())->firstOrFail();
        $entry = $billedReturn->journalEntry()->with('lines.account')->firstOrFail();
        $rawInventoryAccount = procurementAccountByClassification($fixture['company'], 'raw_material_inventory');
        $purchasePriceVarianceAccount = procurementAccountByClassification($fixture['company'], 'purchase_price_variance');
        expect((float) $movement->total_cost)->toBe(100.0)
            ->and((float) $entry->lines->where('account_id', $rawInventoryAccount->getKey())->sum('credit_amount'))->toBe(100.0)
            ->and((float) $entry->lines->sum('debit_amount'))->toBe((float) $entry->lines->sum('credit_amount'));
        $varianceLines = $entry->lines->where('account_id', $purchasePriceVarianceAccount->getKey());
        expect((float) $varianceLines->sum('credit_amount') - (float) $varianceLines->sum('debit_amount'))
            ->toBe($billedInvoice->is($firstInvoice) ? 20.0 : -10.0);
        $settlement->reversePurchaseReturn($billedReturn, 'Restore the source receipt at its original cost');
        $reversal = InventoryTransaction::query()->where('reversal_of_id', $movement->getKey())->firstOrFail();
        expect($reversal->unit_cost)->toBe($movement->unit_cost)->and($reversal->total_cost)->toBe($movement->total_cost);
    }

    $returnOrder = $purchaseOrders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Accepted pre-invoice return verification.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10,
            'unit_price' => 10,
            'tax_rate' => 0,
        ]],
    ])['record'];
    $returnOrder = $purchaseOrders->approve($returnOrder);
    $returnReceipt = $receiving->receive($returnOrder, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'DN-GRNI-ACCEPTED-RETURN',
        'lines' => [[
            'purchase_order_line_public_id' => $returnOrder->lines->sole()->public_id,
            'delivered_quantity' => 10,
        ]],
    ]);
    $returnReceiptLine = $returnReceipt->lines->sole();
    $receiving->inspect($returnReceipt, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'receipt_line_public_id' => $returnReceiptLine->public_id,
            'accepted_quantity' => 10,
            'rejected_quantity' => 0,
            'disposition' => 'accepted',
        ]],
    ]);
    $returnReceipt = $receiving->postReceipt($returnReceipt->fresh());
    $settlement = app(ProcurementSettlementService::class);
    $return = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $returnOrder->doc_num,
        'return_date' => now()->toDateString(),
        'reason_code' => 'accepted_material_return',
        'lines' => [[
            'receipt_line_public_id' => $returnReceiptLine->public_id,
            'quantity' => 2,
            'from_quarantine' => false,
        ]],
    ]);
    $return = $settlement->approvePurchaseReturn($return);
    $returnReceiptLine->refresh();
    $returnGrniRow = $reports->rows(ProcurementCycleReport::GoodsReceivedNotInvoiced, [], $fixture['company']->getKey(), $fixture['period']->getKey())
        ->firstWhere('document', $returnReceipt->doc_num);

    expect($return->journal_entry_id)->toBeNull()
        ->and($return->grni_reversal_journal_entry_id)->not->toBeNull()
        ->and($returnReceiptLine->grni_returned_quantity)->toBe('2.00000000')
        ->and($returnReceiptLine->grni_returned_value)->toBe('20.0000')
        ->and($returnGrniRow['remaining_quantity'])->toBe('8.00000000')
        ->and($returnGrniRow['remaining_grni_value'])->toBe('80.0000')
        ->and($reports->grniReconciliation($fixture['company']->getKey(), $fixture['period']->getKey()))
        ->toMatchArray(['subledger' => '80.0000', 'gl' => '80.0000', 'difference' => '0.0000', 'status' => 'reconciled']);

    foreach ([$return->grni_reversal_journal_entry_id, $firstInvoice->journal_entry_id, $secondInvoice->journal_entry_id] as $journalEntryId) {
        $journal = JournalEntry::query()->with('lines')->findOrFail($journalEntryId);
        expect((float) $journal->lines->sum('debit_amount'))->toBe((float) $journal->lines->sum('credit_amount'));
    }
    expect($returnOrder->fresh()->fulfillmentStatus())->toBe('partially_received')
        ->and($returnOrder->lines->sole()->quantityProgress()['returned'])->toBe(2.0)
        ->and($returnOrder->lines->sole()->quantityProgress()['remaining'])->toBe(2.0);
    $replacement = $receiving->receive($returnOrder, [
        'document_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_public_id' => $returnOrder->lines->sole()->public_id, 'delivered_quantity' => 2]],
    ]);
    $receiving->inspect($replacement, ['lines' => [['receipt_line_public_id' => $replacement->lines->sole()->public_id,
        'accepted_quantity' => 2, 'rejected_quantity' => 0]]]);
    $replacement = $receiving->postReceipt($replacement->fresh());
    expect($returnOrder->fresh()->fulfillmentStatus())->toBe('fully_received');
    expect(fn () => $settlement->reversePurchaseReturn($return, 'Would exceed the source order'))
        ->toThrow(DomainException::class);
    $receiving->reverseReceipt($replacement, 'Undo replacement first');
    $settlement->reversePurchaseReturn($return, 'Restore original receipt');
    expect($returnOrder->fresh()->fulfillmentStatus())->toBe('fully_received');

});

test('purchase order stores span active company branches while remaining company scoped', function (): void {
    $fixture = procurementFixture();
    $fixture['branch']->forceFill([
        'name' => 'Administrative Headquarters',
        'type' => Branch::TypeAdministrative,
    ])->save();

    $factoryBranch = Branch::query()->create([
        'doc_number' => 9901,
        'doc_num' => 'Branch-PO-09901',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Main Factory',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $factoryStore = BranchStore::query()->create([
        'branch_id' => $factoryBranch->getKey(),
        'name' => 'Raw Materials Warehouse',
        'classification' => Product::ClassificationRawMaterial,
        'position' => 1,
    ]);
    $finishedStore = BranchStore::query()->create([
        'branch_id' => $factoryBranch->getKey(),
        'name' => 'Finished Goods Warehouse',
        'classification' => Product::ClassificationFinishedProduct,
        'position' => 2,
    ]);
    $serviceStore = BranchStore::query()->create([
        'branch_id' => $factoryBranch->getKey(),
        'name' => 'Service Warehouse',
        'classification' => Product::ClassificationService,
        'position' => 3,
    ]);
    $inactiveBranch = Branch::query()->create([
        'doc_number' => 9902,
        'doc_num' => 'Branch-PO-09902',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Inactive Branch',
        'type' => Branch::TypeWarehouse,
        'status' => 'inactive',
    ]);
    $inactiveStore = BranchStore::query()->create([
        'branch_id' => $inactiveBranch->getKey(),
        'name' => 'Inactive Warehouse',
        'position' => 1,
    ]);
    $otherCompany = Company::query()->create([
        'doc_number' => 9901,
        'doc_num' => 'Company-PO-09901',
        'name' => 'Other Purchase Company',
        'status' => 'active',
    ]);
    $otherBranch = Branch::query()->create([
        'doc_number' => 9903,
        'doc_num' => 'Branch-PO-09903',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Company Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $otherStore = BranchStore::query()->create([
        'branch_id' => $otherBranch->getKey(),
        'name' => 'Other Company Warehouse',
        'position' => 1,
    ]);

    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo([
        'purchase_orders.view',
        'purchase_orders.create',
        'purchase_orders.edit',
        'purchases.prices.view',
        'purchases.direct_procurement.override',
    ]);
    $this->actingAs($fixture['user']);

    $this->withSession(['locale' => 'ar'])
        ->get(route('admin.purchases.purchase-orders.create'))
        ->assertOk()
        ->assertSee('شروط الدفع المحفوظة')
        ->assertSee('تُستخدم شروط المورد الافتراضية عند ترك الحقل فارغًا')
        ->assertDontSee('سبب الشراء المباشر')
        ->assertDontSee('سماح معتمد بالشراء المباشر')
        ->assertSee('نوع الخصم')
        ->assertDontSee('Payment Terms Snapshot')
        ->assertDontSee('Authorized Direct Procurement Override');
    $this->withSession(['locale' => 'en']);

    $storeResults = $this->getJson(route('admin.purchases.select2.branch-stores'))
        ->assertOk()
        ->json('results');

    expect(collect($storeResults)->pluck('id')->all())
        ->toContain($fixture['store']->public_uuid)
        ->not->toContain($factoryStore->public_uuid, $finishedStore->public_uuid, $serviceStore->public_uuid, $inactiveStore->public_uuid, $otherStore->public_uuid);

    $productResults = $this->getJson(route('admin.purchases.select2.products'))->assertOk()->json('results');
    $historicalService = $this->getJson(route('admin.purchases.select2.products', [
        'selected_doc_num' => $fixture['service']->doc_num,
    ]))->assertOk()->json('results');

    expect(collect($productResults)->pluck('id'))
        ->toContain($fixture['raw']->doc_num)
        ->not->toContain($fixture['service']->doc_num, $fixture['finished']->doc_num)
        ->and(collect($historicalService)->pluck('id'))->toContain($fixture['service']->doc_num);

    $payload = [
        'branch_store_uuid' => $factoryStore->public_uuid,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => '1',
        'freight_amount' => '0',
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Administrative branch requires the factory warehouse.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '2',
            'unit_price' => '10',
            'discount_type' => 'fixed',
            'discount_value' => '0',
            'tax_rate' => '0',
        ]],
    ];

    $this->postJson(route('admin.purchases.purchase-orders.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    $order = PurchaseOrder::query()->latest('id')->firstOrFail();

    expect($order->branch_id)->toBe($fixture['branch']->getKey())
        ->and($order->branch_store_id)->toBe($factoryStore->getKey())
        ->and($order->status)->toBe(PurchaseOrder::StatusDraft);

    $this->get(route('admin.purchases.purchase-orders.edit', $order->doc_num))
        ->assertOk()
        ->assertSee(__('purchase_orders.attributes.branch_store'))
        ->assertSee('value="'.$factoryStore->public_uuid.'" selected', false)
        ->assertSee('Raw Materials Warehouse — Main Factory');

    $this->postJson(route('admin.purchases.purchase-orders.store'), [
        ...$payload,
        'branch_store_uuid' => $inactiveStore->public_uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors(['branch_store_uuid']);

    $this->postJson(route('admin.purchases.purchase-orders.store'), [
        ...$payload,
        'branch_store_uuid' => $otherStore->public_uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors(['branch_store_uuid']);

    $this->postJson(route('admin.purchases.purchase-orders.store'), [
        ...$payload,
        'branch_store_uuid' => $finishedStore->public_uuid,
    ])->assertUnprocessable()->assertJsonValidationErrors(['branch_store_uuid']);

    $this->postJson(route('admin.purchases.purchase-orders.store'), [
        ...$payload,
        'lines' => [[
            ...$payload['lines'][0],
            'product_doc_num' => $fixture['service']->doc_num,
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.cost_center_doc_num']);

    expect(fn () => app(PurchaseOrderService::class)->create([
        ...$payload,
        'branch_store_uuid' => $otherStore->public_uuid,
    ]))->toThrow(DomainException::class, __('purchase_orders.messages.store_unavailable'));

    $factoryStore->delete();

    $this->getJson(route('admin.purchases.select2.branch-stores'))
        ->assertOk()
        ->assertJsonMissing(['id' => $factoryStore->public_uuid]);

    $this->get(route('admin.purchases.purchase-orders.edit', $order->doc_num))
        ->assertOk()
        ->assertSee('value="'.$factoryStore->public_uuid.'" selected', false)
        ->assertSee('Raw Materials Warehouse — Main Factory');

    $this->get(route('admin.purchases.purchase-orders.show', $order->doc_num))
        ->assertOk()
        ->assertSee('Raw Materials Warehouse — Main Factory');
});

test('procurement reports filter, print, and export without leaking confidential prices', function () {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo('reports.purchases.view');
    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9601,
        'doc_num' => 'PINV-REPORT-PROC',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'invoice_date' => now()->toDateString(),
        'currency_id' => $fixture['currency']->getKey(),
        'total_amount' => 125,
        'remaining_amount' => 125,
        'status' => PurchaseInvoice::StatusApproved,
    ]);
    $report = app(ProcurementCycleReport::class);
    $filters = [
        'report_type' => ProcurementCycleReport::SupplierPayables,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'outstanding' => '1',
    ];
    $rows = $report->rows(
        ProcurementCycleReport::SupplierPayables,
        $filters,
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    );
    $confidentialExport = new ProcurementCycleReportExport($report, $rows, false);

    expect(ProcurementCycleReport::types())->toBe([
        'supplier_statement', 'purchase_ledger',
        'purchase_requests', 'pending_purchase_requests', 'open_purchase_orders', 'partially_received_orders',
        'supply_orders',
        'supplier_deliveries', 'purchase_receipts', 'purchase_invoices', 'received_vs_invoiced',
        'purchases_by_category', 'purchases_by_warehouse', 'price_history', 'receipt_quality_status',
        'open_requirements',
        'requested_vs_ordered',
        'rfq_quotation_status',
        'purchase_order_status',
        'ordered_vs_received',
        'overdue_po_deliveries',
        'delivery_schedule',
        'incoming_qc_pending',
        'goods_received_not_invoiced',
        'qc_rejection',
        'purchases_by_supplier',
        'purchases_by_product',
        'purchases_by_period',
        'outstanding_supplier_invoices',
        'due_supplier_installments',
        'supplier_aging',
        'upcoming_supplier_payments',
        'returns',
        'production_analysis',
    ])->and($rows)->toHaveCount(1)
        ->and($rows->first()['document'])->toBe($invoice->doc_num)
        ->and((float) $rows->first()['outstanding'])->toBe(125.0)
        ->and($confidentialExport->headings())->not->toContain('Amount')
        ->and($confidentialExport->map($rows->first()))->not->toContain(125);

    $query = ['report_type' => ProcurementCycleReport::SupplierPayables];
    $this->get(route('admin.purchases.procurement-cycle-report.index', $query))->assertForbidden();
    $fixture['user']->givePermissionTo('purchases.prices.view');
    $this->actingAs($fixture['user'])
        ->get(route('admin.purchases.procurement-cycle-report.index', $query))
        ->assertOk()
        ->assertSee($invoice->doc_num)
        ->assertSee('name="production_order_doc_num"', false)
        ->assertSee('name="work_order_reference"', false)
        ->assertDontSee(route('admin.purchases.procurement-cycle-report.export.excel', $query), false);

    $this->get(route('admin.purchases.procurement-cycle-report.print', $query))->assertForbidden();
    $this->get(route('admin.purchases.procurement-cycle-report.export.excel', $query))->assertForbidden();

    $fixture['user']->givePermissionTo('reports.purchases.export');
    $reportPdf = $this->get(route('admin.purchases.procurement-cycle-report.print', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition');
    expect(str_starts_with($reportPdf->getContent(), '%PDF-'))->toBeTrue()
        ->and($reportPdf->headers->get('content-disposition'))->toStartWith('inline;');
    $this->get(route('admin.purchases.procurement-cycle-report.export.excel', $query))
        ->assertOk()
        ->assertHeader('content-disposition');

    app()->setLocale('ar');
    expect(__('procurement.reports.types.open_requirements'))->toBe('احتياجات الشراء المفتوحة')
        ->and(__('procurement.documents.types.goods-receipt-inspection'))->toBe('فحص المشتريات');
});

test('warehouse purchase requests retain approval audit and enforce split order capacity', function (): void {
    $fixture = procurementFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $request = procurementManualRequisition($fixture, 10000);
    $line = $request->lines->first();
    $payload = [
        'request_date' => now()->toDateString(), 'required_by_date' => now()->addWeek()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid, 'priority' => 'high',
        'lead_time_days' => 7, 'suggested_supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'department' => 'Warehouse', 'lines' => [[
            'public_id' => $line->public_id, 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => 10000, 'source_type' => 'manual',
        ]],
    ];
    $request = $sourcing->updateRequisition($request, $payload);
    $updatedAt = $request->updated_at->toDateTimeString();
    $this->travel(1)->minutes();
    $request = $sourcing->updateRequisition($request, $payload);
    expect($request->updated_at->toDateTimeString())->toBe($updatedAt)
        ->and($request->lines()->first()->public_id)->toBe($line->public_id)
        ->and($request->suggested_supplier_id)->toBe($fixture['firstSupplier']->getKey());
    $orderPayload = [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString(),
        'exchange_rate' => 1, 'lines' => [[
            'purchase_requisition_line_id' => $line->getKey(), 'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 4000, 'unit_price' => 2,
        ]],
    ];
    expect(fn () => $orders->create($orderPayload))->toThrow(DomainException::class);
    $request = $sourcing->submitRequisition($request);
    $submittedAt = $request->submitted_at->toDateTimeString();
    $this->travel(1)->minutes();
    expect($sourcing->submitRequisition($request)->submitted_at->toDateTimeString())->toBe($submittedAt);
    $request = $sourcing->approveRequisition($request);
    expect($request->submitted_by)->toBe($fixture['user']->getKey())
        ->and($request->approved_by)->toBe($fixture['user']->getKey());
    $first = $orders->approve($orders->create($orderPayload)['record']);
    $line->refresh();
    expect($line->remainingToOrder())->toBe(6000.0);
    $orderPayload['supplier_doc_num'] = $fixture['secondSupplier']->doc_num;
    $orderPayload['lines'][0]['ordered_quantity'] = 6001;
    expect(fn () => $orders->create($orderPayload))->toThrow(DomainException::class);
    $orderPayload['lines'][0]['ordered_quantity'] = 6000;
    $second = $orders->approve($orders->create($orderPayload)['record']);
    expect($request->fresh()->status)->toBe(PurchaseRequisition::StatusFullyConverted)
        ->and($line->orderedQuantity())->toBe(10000.0);
    expect(fn () => $sourcing->finishRequisition($request, PurchaseRequisition::StatusCancelled, 'Cancelled'))
        ->toThrow(DomainException::class);
    $orders->cancel($second, 'Supplier unavailable');
    expect($line->remainingToOrder())->toBe(6000.0)
        ->and($request->fresh()->status)->toBe(PurchaseRequisition::StatusPartiallyConverted)
        ->and(InventoryTransaction::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
    $rejected = $sourcing->submitRequisition(procurementManualRequisition($fixture));
    $rejected = $sourcing->rejectRequisition($rejected, 'Not required');
    expect($rejected->status)->toBe(PurchaseRequisition::StatusRejected)
        ->and($rejected->rejected_by)->toBe($fixture['user']->getKey())
        ->and($rejected->rejection_reason)->toBe('Not required');
    expect(fn () => $sourcing->approveRequisition($rejected))->toThrow(DomainException::class);
    $this->travelBack();
});

test('goods receipt drafts post once and reverse quantities and grni without trusting cached order totals', function (): void {
    $fixture = procurementFixture();
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $order = $orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num, 'ordered_quantity' => 10000, 'unit_price' => 2]],
    ])['record'];
    $order = $orders->approve($order);
    $line = $order->lines->first();
    $payload = ['document_date' => now()->toDateString(), 'supplier_delivery_note' => 'DELIVERY-1',
        'lines' => [['purchase_order_line_public_id' => $line->public_id, 'delivered_quantity' => 4000]],
    ];
    $receipt = $receiving->createReceipt($order, $payload);
    expect($receipt->status)->toBe('draft')->and($receipt->posting_status)->toBe('unposted')
        ->and(InventoryTransaction::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0)
        ->and($line->receivedQuantity())->toBe(0.0);
    if ($receipt->qc_status === 'pending_inspection') {
        $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->first()->public_id, 'accepted_quantity' => 4000, 'rejected_quantity' => 0]]]);
    }
    $receipt = $receiving->postReceipt($receipt->fresh());
    expect($line->receivedQuantity())->toBe(4000.0)
        ->and((float) $order->fresh()->total_remaining_quantity)->toBe(6000.0)
        ->and(InventoryTransaction::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1);
    $line->forceFill(['received_quantity' => 0, 'remaining_quantity' => 10000])->save();
    $payload['lines'][0]['delivered_quantity'] = 6001;
    expect(fn () => $receiving->receive($order, $payload))->toThrow(DomainException::class);
    $payload['lines'][0]['delivered_quantity'] = 6000;
    $second = $receiving->receive($order, $payload);
    if ($second->qc_status === 'pending_inspection') {
        $receiving->inspect($second, ['lines' => [['receipt_line_public_id' => $second->lines->first()->public_id, 'accepted_quantity' => 6000, 'rejected_quantity' => 0]]]);
    }
    $second = $receiving->postReceipt($second->fresh());
    expect($line->receivedQuantity())->toBe(10000.0)->and((float) $order->fresh()->total_remaining_quantity)->toBe(0.0);
    $receiving->reverseReceipt($second->fresh(), 'Incorrect delivery');
    $receiving->reverseReceipt($second->fresh(), 'Repeated reversal');
    expect($line->receivedQuantity())->toBe(4000.0)
        ->and((float) $order->fresh()->total_remaining_quantity)->toBe(6000.0)
        ->and((float) InventoryTransaction::query()->selectRaw('sum(quantity_in - quantity_out) as balance')->value('balance'))->toBe(4000.0)
        ->and(InventoryTransaction::query()->where('is_reversal', true)->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(3);
});

test('warehouse procurement acceptance completes ten thousand units through receipts invoice payments return and reversal', function (): void {
    $this->withoutExceptionHandling();
    Storage::fake('public');
    $fixture = procurementFixture();
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111091', 'Acceptance Supplier');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111091', 'Acceptance Cash');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $administrativeBranch->getKey(),
        'account_id' => $cashAccount->getKey(), 'name' => 'Acceptance cashbox', 'status' => 'active',
    ]);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->getKey(), 'currency_id' => $fixture['currency']->getKey(), 'is_default' => true, 'status' => 'active']);
    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);
    $attachment = procurementDocumentAttachment($fixture['company']);
    $attachments = app(ProcurementAttachmentService::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10000)));
    procurementUseBranch($fixture, $administrativeBranch);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['purchase_requisition_line_id' => $request->lines->first()->getKey(),
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10000, 'unit_price' => 2, 'attachment_file_doc_nums' => [$attachment->doc_num]]],
    ])['record']);
    expect(InventoryTransaction::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0);
    $orderLine = $order->lines->first();
    expect($attachments->documents($orderLine, ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    procurementUseBranch($fixture, $fixture['branch']);
    $receipts = collect();
    foreach ([4000, 6000] as $quantity) {
        $receipt = $receiving->createReceipt($order, ['document_date' => now()->toDateString(),
            'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => $quantity,
                'attachment_file_doc_nums' => [$attachment->doc_num]]],
        ]);
        expect($attachments->documents($receipt->lines->first(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
        if ($receipt->qc_status === 'pending_inspection') {
            $inspection = $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->first()->public_id,
                'accepted_quantity' => $quantity, 'rejected_quantity' => 0,
                'attachment_file_doc_nums' => [$attachment->doc_num]]]]);
            expect($attachments->documents($inspection->lines->first(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
        }
        $receipt = $receiving->postReceipt($receipt->fresh());
        $receipts->push($receipt->fresh()->load('lines'));
        expect($order->fresh()->fulfillmentStatus())->toBe($quantity === 4000 ? 'partially_received' : 'fully_received')
            ->and((float) $orderLine->quantityProgress()['remaining'])->toBe($quantity === 4000 ? 6000.0 : 0.0);
    }
    procurementUseBranch($fixture, $administrativeBranch);
    $invoicePayload = [
        'purchase_order_doc_num' => $order->doc_num, 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'invoice_date' => now()->toDateString(),
        'supplier_invoice_number' => 'SUPPLIER-ACCEPTANCE-1', 'payment_type' => 'credit',
        'lines' => $receipts->map(fn ($receipt) => [
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $orderLine->public_id, 'receipt_line_public_id' => $receipt->lines->first()->public_id,
            'quantity' => $receipt->lines->first()->accepted_quantity, 'unit_price' => 2,
            'attachment_file_doc_nums' => [$attachment->doc_num],
        ])->all(),
    ];
    $this->get(route('admin.purchases.purchase-invoices.create', ['purchase_order' => $order->doc_num]))->assertOk()
        ->assertSee($receipts->first()->lines->first()->public_id)->assertSee($receipts->last()->lines->first()->public_id);
    $invoice = $invoices->approve($invoices->create($invoicePayload)['record']);
    foreach ($invoice->lines as $invoiceLine) {
        expect($attachments->documents($invoiceLine, ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    }
    $invoices->approve($invoice->fresh());
    expect((float) $invoice->total_amount)->toBe(20000.0)
        ->and(InventoryTransaction::query()->count())->toBe(2)
        ->and($orderLine->quantityProgress()['invoiced'])->toBe(10000.0);
    expect(fn () => $receiving->reverseReceipt($receipts->first(), 'Blocked by invoice'))->toThrow(DomainException::class);
    $payments = collect();
    foreach ([8000, 12000] as $amount) {
        $payment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'payment_date' => now()->toDateString(),
            'cashbox_doc_num' => $cashbox->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
            'amount' => $amount, 'reason' => 'Acceptance settlement',
            'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => $amount]],
        ]);
        $payments->push($settlement->approveSupplierPayment($payment));
        $settlement->approveSupplierPayment($payment->fresh());
        expect($invoice->fresh()->payment_status)->toBe($amount === 8000 ? PurchaseInvoice::PaymentStatusPartiallyPaid : PurchaseInvoice::PaymentStatusPaid);
    }
    expect((float) $invoice->fresh()->remaining_amount)->toBe(0.0);
    expect(fn () => $invoices->reverse($invoice, 'Payments must be reversed first'))->toThrow(DomainException::class);
    procurementUseBranch($fixture, $fixture['branch']);
    $this->get(route('admin.purchases.purchase-invoices.show', $invoice->doc_num))
        ->assertOk()
        ->assertDontSee(route('admin.purchases.purchase-invoices.edit', $invoice->doc_num), false);
    $return = $settlement->createPurchaseReturn([
        'purchase_order_doc_num' => $order->doc_num, 'purchase_invoice_doc_num' => $invoice->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'latent_defect',
        'lines' => [['receipt_line_public_id' => $receipts->first()->lines->first()->public_id, 'quantity' => 500,
            'from_quarantine' => false, 'attachment_file_doc_nums' => [$attachment->doc_num]]],
    ]);
    expect($attachments->documents($return->lines->first(), ProcurementAttachmentService::LineCollection, $fixture['company']->id))->toHaveCount(1);
    $return = $settlement->approvePurchaseReturn($return);
    $settlement->approvePurchaseReturn($return);
    expect((float) InventoryTransaction::query()->sum('quantity_in') - (float) InventoryTransaction::query()->sum('quantity_out'))->toBe(9500.0)
        ->and($orderLine->quantityProgress()['returned'])->toBe(500.0);
    $return = $settlement->reversePurchaseReturn($return, 'Accepted after reinspection');
    $settlement->reversePurchaseReturn($return, 'Repeated reversal');
    expect((float) InventoryTransaction::query()->sum('quantity_in') - (float) InventoryTransaction::query()->sum('quantity_out'))->toBe(10000.0)
        ->and($orderLine->quantityProgress()['net_received'])->toBe(10000.0)
        ->and($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid);
    $ledgerBalance = fn (int $accountId): float => (float) JournalEntryLine::query()
        ->where('account_id', $accountId)->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))
        ->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) AS balance')->value('balance');
    expect($ledgerBalance($supplierAccount->getKey()))->toBe(0.0)
        ->and($ledgerBalance(procurementAccountByClassification($fixture['company'], 'goods_received_not_invoiced')->getKey()))->toBe(0.0)
        ->and($ledgerBalance(procurementAccountByClassification($fixture['company'], 'raw_material_inventory')->getKey()))->toBe(20000.0);
    foreach (JournalEntry::query()->with('lines')->get() as $journal) {
        expect(round((float) $journal->lines->sum('debit_amount') - (float) $journal->lines->sum('credit_amount'), 4))->toBe(0.0);
    }
    $expectedDocs = [$request->doc_num, $order->doc_num, ...$receipts->pluck('doc_num')->all(), $invoice->doc_num,
        ...$payments->pluck('doc_num')->all(), $return->doc_num];
    foreach ([$request, $order, ...$receipts, $invoice, ...$payments, $return] as $document) {
        $chain = app(ProcurementCycleReport::class)->documentChain($document);
        foreach ($expectedDocs as $number) {
            expect($chain->pluck('doc_num')->all())->toContain($number);
        }
    }
    procurementUseBranch($fixture, $administrativeBranch);
    $printRoutes = [
        route('admin.purchases.procurement.print', ['purchase-requisition', $request->doc_num]),
        route('admin.purchases.purchase-orders.print', $order->doc_num),
        route('admin.purchases.procurement.print', ['goods-receipt', $receipts->first()->doc_num]),
        route('admin.purchases.purchase-invoices.print', $invoice->doc_num),
        route('admin.purchases.procurement.print', ['purchase-return', $return->doc_num]),
    ];
    Storage::disk('public')->put('tests/procurement/identity.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAKAAAAAyCAIAAABUA0cyAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABR0lEQVR4nO3bUY6CMBgA4XWz91hvocfYPSnX4BgcxYcmTfNTaolFzTjfk8GChBGoJJ5+L39f4vp+9Q7oWAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAxnYDgDwxkYzsBwBob76Rm0zFN1+fn6H8aUS6rrpgF3N1gOqC6sbrBnfz5NV+CkcbDyoV/mqXGUl3lKA0KzsOVyYV6luhvrdxUMuETnHuHsXMfrKRHWap/xYcuNj/5Yjwbe2+O4g16e8dbNdlyiq/fF56ve1PNr6wZj7sEv8W778552BG64e48caGvypaoxv4PTDKucHm8Z9VXonHzpwAcd6wY9PfrnwzbuMeYSvSXNevbOzsJaXocfcfLPZ2w+i4YzMJyB4QwMZ2A4A8MZGM7AcAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAx3A4Npkgj1aQnLAAAAAElFTkSuQmCC'));
    $fixture['company']->forceFill(['legal_name' => 'FACTORY PRINT IDENTITY', 'email' => 'factory-identity@example.test', 'logo' => 'tests/procurement/identity.png'])->save();
    foreach ([false, true] as $showIdentity) {
        $fixture['company']->forceFill(['show_company_identity_on_prints' => $showIdentity])->save();
        foreach ($printRoutes as $index => $url) {
            $pdf = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
            expect(str_starts_with($pdf, '%PDF-'))->toBeTrue();
            $path = tempnam(sys_get_temp_dir(), 'procurement-print-');
            file_put_contents($path, $pdf);
            try {
                $process = new Process(['pdftotext', '-layout', $path, '-']);
                $process->mustRun();
                if ($index === 3) {
                    expect($process->getOutput())->toContain($receipts->first()->doc_num)->toContain($receipts->last()->doc_num);
                }

                if ($showIdentity) {
                    expect($process->getOutput())->toContain('FACTORY PRINT IDENTITY')->toContain('factory-identity@example.test')
                        ->and(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThan(0);
                } else {
                    expect($process->getOutput())->not->toContain('FACTORY PRINT IDENTITY')->not->toContain('factory-identity@example.test')
                        ->and(substr_count($pdf, '/Subtype /Image'))->toBe(0);
                }
            } finally {
                unlink($path);
            }
            if ($directory = getenv('PROCUREMENT_PRINT_SAMPLES')) {
                file_put_contents($directory.'/procurement-'.($index + 1).'-'.($showIdentity ? 'on' : 'off').'.pdf', $pdf);
            }
        }
    }
    $reports = app(ProcurementCycleReport::class);
    foreach ([ProcurementCycleReport::PurchaseRequests, ProcurementCycleReport::RequestedVsOrdered, ProcurementCycleReport::PurchaseReceipts,
        ProcurementCycleReport::ReceivedVsInvoiced, ProcurementCycleReport::PurchaseInvoices, ProcurementCycleReport::PurchasesBySupplier,
        ProcurementCycleReport::PurchasesByProduct, ProcurementCycleReport::PurchasesByCategory, ProcurementCycleReport::PurchasesByWarehouse, ProcurementCycleReport::PriceHistory] as $reportType) {
        $reportBranchId = in_array($reportType, [ProcurementCycleReport::PurchaseRequests, ProcurementCycleReport::RequestedVsOrdered, ProcurementCycleReport::PurchaseReceipts], true)
            ? $fixture['branch']->getKey()
            : $administrativeBranch->getKey();
        $rows = $reports->rows($reportType, ['branch_id' => $reportBranchId], $fixture['company']->getKey(), $fixture['period']->getKey());
        expect($rows)->not->toBeEmpty();
        $this->get(route('admin.purchases.procurement-cycle-report.index', ['report_type' => $reportType]))->assertOk();
        expect($reports->rows($reportType, ['date_from' => now()->addDay()->toDateString()], $fixture['company']->getKey(), $fixture['period']->getKey()))->toBeEmpty();
    }
    expect($reports->rows(ProcurementCycleReport::OpenPurchaseOrders, [], $fixture['company']->getKey(), $fixture['period']->getKey()))->toBeEmpty();

    $fixture['company']->forceFill(['show_company_identity_on_prints' => false])->save();
    $legalCopy = $this->get(route('admin.purchases.purchase-invoices.print', [$invoice->doc_num, 'copy' => 'legal']))
        ->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
    $path = tempnam(sys_get_temp_dir(), 'procurement-legal-print-');
    file_put_contents($path, $legalCopy);
    try {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->mustRun();
        expect($process->getOutput())->toContain('FACTORY PRINT IDENTITY')->toContain('factory-identity@example.test');
    } finally {
        unlink($path);
    }

});

test('alternate purchase units keep stock lineage and draft edits preserve identifiers and audit', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(['purchases.goods_receipt_notes.view', 'purchases.goods_receipt_notes.edit', 'purchases.purchase_returns.view', 'purchases.purchase_returns.edit']);
    $ton = ItemUnit::query()->create(['company_id' => $fixture['company']->getKey(), 'doc_number' => 9901, 'doc_num' => 'UNIT-TON', 'name' => 'Ton', 'status' => 'active']);
    $fixture['raw']->forceFill(['equivalent_unit_id' => $ton->getKey(), 'equivalent_value' => '0.001', 'tracks_expiry' => true])->save();
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $settlement = app(ProcurementSettlementService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid, 'document_date' => now()->toDateString(), 'exchange_rate' => 1,
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $ton->doc_num, 'ordered_quantity' => 10, 'unit_price' => 2000]],
    ])['record']);
    $fixture['raw']->forceFill(['equivalent_value' => '0.01'])->save();
    $payload = ['document_date' => now()->toDateString(), 'supplier_delivery_note' => 'TON-DELIVERY',
        'lines' => [['purchase_order_line_public_id' => $order->lines->first()->public_id, 'delivered_quantity' => 4,
            'supplier_lot_number' => 'LOT-TON', 'manufacture_date' => now()->toDateString(), 'expiry_date' => now()->addYear()->toDateString()]],
    ];
    $draft = $receiving->createReceipt($order, $payload);
    $lineId = $draft->lines->first()->public_id;
    $this->get(route('admin.purchases.goods-receipt-notes.edit', $draft->doc_num))->assertOk()->assertSee('TON-DELIVERY');
    $this->post(route('admin.purchases.goods-receipt-notes.post', $draft->doc_num))->assertForbidden();
    $payload['lines'][0]['delivered_quantity'] = 3;
    $draft = $receiving->updateReceipt($draft, $payload);
    $this->travel(1)->minutes();
    $updatedAt = $draft->updated_at->toDateTimeString();
    $lineUpdatedAt = $draft->lines->first()->updated_at->toDateTimeString();
    $draft = $receiving->updateReceipt($draft, $payload);
    expect($draft->updated_at->toDateTimeString())->toBe($updatedAt)->and($draft->lines->first()->public_id)->toBe($lineId)
        ->and($draft->lines->first()->updated_at->toDateTimeString())->toBe($lineUpdatedAt);
    $payload['lines'][0]['delivered_quantity'] = 4;
    $receipt = $receiving->updateReceipt($draft, $payload);
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $lineId, 'accepted_quantity' => 4, 'rejected_quantity' => 0]]]);
    $receipt = $receiving->postReceipt($receipt->fresh());
    $receipt = $receipt->fresh()->load('lines');
    $movement = InventoryTransaction::query()->where('source_id', $receipt->getKey())->where('transaction_type', 'purchase_receipt')->sole();
    expect($movement->quantity_in)->toBe('4000.00000000')->and($movement->unit_id)->toBe($fixture['unit']->getKey())
        ->and((float) $movement->unit_cost)->toBe(2.0)->and((float) $movement->total_cost)->toBe(8000.0);
    expect(fn () => $receiving->updateReceipt($receipt, $payload))->toThrow(DomainException::class);
    expect(fn () => $receiving->deleteReceipt($receipt))->toThrow(DomainException::class);
    $returnPayload = ['purchase_order_doc_num' => $order->doc_num, 'return_date' => now()->toDateString(), 'reason_code' => 'damaged',
        'lines' => [['receipt_line_public_id' => $lineId, 'quantity' => 0.2, 'from_quarantine' => false]],
    ];
    $return = $settlement->createPurchaseReturn($returnPayload);
    $returnLineId = $return->lines->first()->public_id;
    $returnPayload['lines'][0]['quantity'] = 0.5;
    $return = $settlement->updatePurchaseReturn($return, $returnPayload);
    $returnUpdated = $return->updated_at->toDateTimeString();
    $this->travel(1)->minutes();
    $return = $settlement->updatePurchaseReturn($return, $returnPayload);
    expect($return->updated_at->toDateTimeString())->toBe($returnUpdated)->and($return->lines->first()->public_id)->toBe($returnLineId);
    $this->get(route('admin.purchases.purchase-returns.edit', $return))->assertOk()->assertSee($lineId);
    $return = $settlement->approvePurchaseReturn($return);
    expect((float) InventoryTransaction::query()->sum('quantity_out'))->toBe(500.0);
    expect(fn () => $settlement->deletePurchaseReturn($return))->toThrow(DomainException::class);
    $settlement->reversePurchaseReturn($return, 'Return withdrawn');
    $reversal = InventoryTransaction::query()->where('transaction_type', 'purchase_return_reversal')->sole();
    expect($reversal->batch_lot)->toBe('LOT-TON')->and($reversal->expiry_date?->toDateString())->toBe($movement->expiry_date?->toDateString());
    $receiving->reverseReceipt($receipt, 'All dependent returns reversed');
    expect((float) InventoryTransaction::query()->sum('quantity_in') - (float) InventoryTransaction::query()->sum('quantity_out'))->toBe(0.0)
        ->and(app(ProcurementCycleReport::class)->grniReconciliation($fixture['company']->getKey(), $fixture['period']->getKey())['difference'])->toBe('0.0000');
    expect(fn () => $receiving->inspect($receipt->fresh(), ['lines' => []]))->toThrow(DomainException::class);
    $this->travelBack();
});

test('supplier invoice preserves receipt precision and rejects duplicate supplier references', function (): void {
    $fixture = procurementFixture();
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
        'document_date' => now()->toDateString(), 'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Precision regression',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => '0.12345678', 'unit_price' => 100]],
    ])['record']);
    $orderLine = $order->lines->sole();
    $receipt = $receiving->receive($order, ['document_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_public_id' => $orderLine->public_id, 'delivered_quantity' => '0.12345678']]]);
    $receiptLine = $receipt->lines->sole();
    $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receiptLine->public_id,
        'accepted_quantity' => '0.12345678', 'rejected_quantity' => 0]]]);
    $receipt = $receiving->postReceipt($receipt->fresh());
    $receiptLine = $receipt->lines()->where('public_id', $receiptLine->public_id)->firstOrFail();
    $payload = [
        'purchase_order_doc_num' => $order->doc_num, 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'PRECISION-INV-1',
        'lines' => [['product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $orderLine->public_id, 'receipt_line_public_id' => $receiptLine->public_id,
            'quantity' => '0.12345678', 'unit_price' => 100]],
    ];
    $invoice = $invoices->create($payload)['record'];
    expect($invoice->lines->sole()->quantity)->toBe('0.12345678')
        ->and(app(PurchaseInvoiceMatchingService::class)->remainingForReceipt($receiptLine))->toBe(0.0);
    expect(fn () => $invoices->create($payload))->toThrow(DomainException::class);
    expect(PurchaseInvoice::query()->count())->toBe(1);
});

test('one purchase order combines approved requests and retains every source line', function (): void {
    $fixture = procurementFixture();
    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $requests = collect([10, 20])->map(fn ($quantity) => $sourcing->approveRequisition(
        $sourcing->submitRequisition(procurementManualRequisition($fixture, $quantity))));
    procurementUseBranch($fixture, procurementAdministrativeBranch($fixture));
    foreach (['purchase_orders.create', 'purchases.prices.view', 'purchases.purchase_requisitions.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->get(route('admin.purchases.purchase-orders.create', [
        'purchase_requisition_doc_nums' => $requests->pluck('doc_num')->all(),
    ]))->assertOk()->assertSee($requests[0]->doc_num)->assertSee($requests[1]->doc_num);
    $order = $orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
        'document_date' => now()->toDateString(),
        'lines' => $requests->map(fn ($request) => [
            'purchase_requisition_line_id' => $request->lines->sole()->getKey(),
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => $request->lines->sole()->approved_quantity, 'unit_price' => 2,
        ])->all(),
    ])['record'];
    expect($order->lines->pluck('purchase_requisition_line_id')->all())->toBe($requests->map(fn ($request) => $request->lines->sole()->getKey())->all())
        ->and($requests->first()->lines->sole()->quantityProgress()['ordered'])->toBe(0.0);
    expect(fn () => $sourcing->finishRequisition($requests->first(), PurchaseRequisition::StatusClosed))->toThrow(DomainException::class);
    $orders->approve($order);
    expect($requests->map(fn ($request) => $request->fresh()->status)->unique()->all())->toBe([PurchaseRequisition::StatusFullyConverted])
        ->and(InventoryTransaction::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0);
});

test('procurement workflow and shared print labels have Arabic translations without fallback', function (): void {
    $paths = [
        'modules/Purchases/Services/ProcurementReceivingService.php',
        'modules/Purchases/Services/ProcurementSourcingService.php',
        'modules/Purchases/Services/ProcurementSettlementService.php',
        'modules/Purchases/Services/SupplyOrderService.php',
        'modules/Purchases/Services/PurchaseInvoiceService.php',
        'modules/Purchases/Services/PurchaseOrderService.php',
        'modules/Purchases/Services/Reports/ProcurementCycleReport.php',
        'resources/views/modules/purchases/suppliers/procurement-overview.blade.php',
        'resources/views/modules/purchases/procurement/attachments.blade.php',
        'resources/views/components/forms/line-item-cards.blade.php',
        'app/Http/Middleware/IdempotentDocumentSubmission.php',
        'modules/Core/Services/Reports/ReportPdfService.php',
        'resources/views/modules/purchases/procurement/document-cycle.blade.php',
        'resources/views/modules/purchases/procurement/line-progress.blade.php',
        'resources/views/modules/purchases/procurement/print.blade.php',
        'resources/views/modules/purchases/procurement/supply-order-form.blade.php',
        'resources/views/modules/purchases/procurement/supply-order-source.blade.php',
        'resources/views/modules/purchases/procurement/supply-receipt-form.blade.php',
        'resources/views/reports/partials/document-signatures.blade.php',
        'resources/views/reports/sales/quotation.blade.php',
    ];
    $missing = [];
    foreach ($paths as $path) {
        preg_match_all('/__\(\s*[\'"]([^\'"\n]+)[\'"]\s*[,\)]/', file_get_contents(base_path($path)), $matches);
        foreach (array_unique($matches[1]) as $key) {
            if (! str_contains($key, '$') && ! Lang::hasForLocale($key, 'ar')) {
                $missing[] = $key;
            }
        }
    }
    expect(array_values(array_unique($missing)))->toBe([]);
});

test('procurement crosses closed source periods while each child posts in its own open period', function (): void {
    $this->travelTo(Carbon::parse('2026-08-29 12:00:00'));
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $august = $fixture['period'];
    $august->forceFill(['from_date' => '2026-08-01', 'to_date' => '2026-08-31'])->save();
    $periods = collect([9, 10, 11])->mapWithKeys(fn (int $month): array => [$month => FinancialPeriod::query()->create([
        'company_id' => $fixture['company']->id, 'doc_number' => 9200 + $month, 'doc_num' => 'Period-PROC-'.$month,
        'name' => 'Procurement '.$month, 'from_date' => sprintf('2026-%02d-01', $month),
        'to_date' => Carbon::create(2026, $month, 1)->endOfMonth()->toDateString(), 'is_closed' => false,
    ])]);
    $usePeriod = function (FinancialPeriod $period, string $date): void {
        $this->travelTo(Carbon::parse($date.' 12:00:00'));
        $values = [OperatingContextService::FinancialPeriodIdKey => $period->id, OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num];
        session($values);
        $this->withSession($values);
    };
    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);
    $requisition = $sourcing->approveRequisition($sourcing->submitRequisition(procurementManualRequisition($fixture, 10000)));
    $august->forceFill(['is_closed' => true])->save();
    $usePeriod($periods[9], '2026-09-01');
    $order = $orders->approve($orders->create([
        'document_date' => '2026-09-01', 'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [['purchase_requisition_line_id' => $requisition->lines->first()->id,
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 10000, 'unit_price' => 2]],
    ])['record']);
    $receipts = collect();
    foreach ([[9, 4000, '2026-09-03'], [10, 6000, '2026-10-03']] as [$month, $quantity, $date]) {
        $usePeriod($periods[$month], $date);
        $receipt = $receiving->createReceipt($order, ['document_date' => $date,
            'lines' => [['purchase_order_line_public_id' => $order->lines->first()->public_id, 'delivered_quantity' => $quantity]],
        ]);
        $receiving->inspect($receipt, ['lines' => [['receipt_line_public_id' => $receipt->lines->first()->public_id,
            'accepted_quantity' => $quantity, 'rejected_quantity' => 0]]]);
        $receipt = $receiving->postReceipt($receipt->fresh());
        $receipts->push($receipt->fresh()->load('lines'));
        if ($month === 9) {
            $periods[9]->forceFill(['is_closed' => true])->save();
        }
    }
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111088', 'Cross-period Supplier');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->id])->save();
    $invoiceData = ['invoice_date' => '2026-10-10', 'purchase_order_doc_num' => $order->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'supplier_invoice_number' => 'CROSS-PERIOD-1',
        'currency_doc_num' => $fixture['currency']->doc_num, 'payment_type' => 'credit',
        'lines' => $receipts->map(fn ($receipt): array => ['product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'purchase_order_line_public_id' => $order->lines->first()->public_id,
            'receipt_line_public_id' => $receipt->lines->first()->public_id, 'quantity' => $receipt->lines->first()->accepted_quantity, 'unit_price' => 2])->all()];
    $invoice = $invoices->approve($invoices->create($invoiceData)['record']);
    expect($invoice->financial_period_id)->toBe($periods[10]->id)
        ->and($order->fresh()->fulfillmentStatus())->toBe('fully_received')
        ->and((float) InventoryTransaction::sum('quantity_in'))->toBe(10000.0);
    $this->get(route('admin.purchases.purchase-orders.show', $order->doc_num))->assertOk();
    $this->get(route('admin.purchases.goods-receipt-notes.show', $receipts->first()->doc_num))->assertOk();
    expect(app(ProcurementCycleReport::class)->grniReconciliation($fixture['company']->id, $periods[10]->id)['difference'])->toBe('0.0000');
    $periods[10]->forceFill(['is_closed' => true])->save();
    $usePeriod($periods[11], '2026-11-01');
    expect(fn () => $invoices->create([...$invoiceData, 'invoice_date' => '2026-11-01']))->toThrow(DomainException::class);
    expect(fn () => $receiving->reverseReceipt($receipts->first(), 'Historical receipt'))->toThrow(DomainException::class);
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111088', 'Cross-period Cash');
    $cashbox = Cashbox::query()->create([...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->id),
        'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'account_id' => $cashAccount->id, 'name' => 'Cross-period cash', 'status' => 'active']);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->id, 'currency_id' => $fixture['currency']->id, 'is_default' => true, 'status' => 'active']);
    foreach ([8000, 12000] as $amount) {
        $settlement->approveSupplierPayment($settlement->createSupplierPayment(['payment_date' => '2026-11-01',
            'supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num,
            'cashbox_doc_num' => $cashbox->doc_num, 'amount' => $amount,
            'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => $amount]]]));
    }
    expect($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid);
    $historicalLedger = app(ProcurementCycleReport::class)->rows(ProcurementCycleReport::PurchaseLedger, ['branch_id' => $fixture['branch']->id], $fixture['company']->id, $periods[10]->id);
    expect((float) $historicalLedger->sole()['paid'])->toBe(0.0)->and((float) $historicalLedger->sole()['outstanding'])->toBe(20000.0);
    $statement = app(ProcurementCycleReport::class)->supplierStatement($fixture['company']->id, $periods[11]->id, ['supplier_doc_num' => $fixture['firstSupplier']->doc_num, 'branch_id' => $fixture['branch']->id]);
    expect((float) $statement->first()['balance'])->toBe(20000.0)->and((float) $statement->last()['balance'])->toBe(0.0);
    $chain = app(ProcurementCycleReport::class)->documentChain($invoice)->pluck('doc_num');
    foreach ([$requisition, $order, ...$receipts] as $source) {
        expect($chain)->toContain($source->doc_num);
    }
    expect((float) JournalEntryLine::sum('debit_amount'))->toBe((float) JournalEntryLine::sum('credit_amount'));
    $this->travelBack();
});
