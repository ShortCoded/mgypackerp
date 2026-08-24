<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
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
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Purchases\Exports\ProcurementCycleReportExport;
use Modules\Purchases\Http\Controllers\ProcurementWorkflowController;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceMatchingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/** @return array<string, mixed> */
function procurementFixture(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);

    $user = User::factory()->create();
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Raw Materials', 'position' => 1]);
    $unit = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9101, 'doc_num' => 'Unit-PROC', 'name' => 'Kilogram', 'status' => 'active']);
    $raw = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9101, 'doc_num' => 'Product-RESIN', 'name' => 'Polymer Resin', 'item_classification' => Product::ClassificationRawMaterial, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $service = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9102, 'doc_num' => 'Product-SERVICE-PROC', 'name' => 'Machine Calibration', 'item_classification' => Product::ClassificationService, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $finished = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9103, 'doc_num' => 'Product-FINISHED-PROC', 'name' => 'Finished Container', 'item_classification' => Product::ClassificationFinishedProduct, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $firstSupplier = Supplier::query()->create(['doc_number' => 9101, 'doc_num' => 'Supplier-PROC-1', 'company_id' => $company->getKey(), 'name' => 'Resin Supplier One', 'status' => 'active']);
    $secondSupplier = Supplier::query()->create(['doc_number' => 9102, 'doc_num' => 'Supplier-PROC-2', 'company_id' => $company->getKey(), 'name' => 'Resin Supplier Two', 'status' => 'active']);

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    session($context);
    test()->withSession($context);

    return compact('user', 'company', 'branch', 'period', 'currency', 'store', 'unit', 'raw', 'service', 'finished', 'firstSupplier', 'secondSupplier');
}

/** @param array<string, mixed> $fixture */
function procurementProductionSource(array $fixture): ProductionOrderLine
{
    $customer = Customer::query()->create(['doc_number' => 9101, 'doc_num' => 'Customer-PROC', 'company_id' => $fixture['company']->getKey(), 'name' => 'Production Customer', 'status' => 'active']);
    $salesOrder = SalesOrder::query()->create([
        'doc_number' => 9101, 'doc_num' => 'SO-PROC', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(), 'customer_id' => $customer->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(), 'status' => SalesOrder::StatusApproved,
    ]);
    $salesLine = SalesOrderLine::query()->create([
        'sales_order_id' => $salesOrder->getKey(), 'line_number' => 1, 'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(), 'description' => 'Finished production demand', 'quantity' => 10,
    ]);
    $productionOrder = ProductionOrder::query()->create([
        'doc_number' => 9101, 'doc_num' => 'PROD-PROC', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'sales_order_id' => $salesOrder->getKey(), 'customer_id' => $customer->getKey(),
        'production_order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => ProductionOrder::StatusReleased,
    ]);

    return ProductionOrderLine::query()->create([
        'production_order_id' => $productionOrder->getKey(), 'sales_order_line_id' => $salesLine->getKey(),
        'line_number' => 1, 'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'description' => 'Finished production demand', 'quantity' => 10,
    ]);
}

/** @param array<string, mixed> $fixture */
function procurementManualRequisition(array $fixture, float $quantity = 10): PurchaseRequisition
{
    return app(ProcurementSourcingService::class)->createRequisition([
        'request_date' => now()->toDateString(), 'required_by_date' => now()->addWeek()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid, 'priority' => 'high',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'requested_quantity' => $quantity, 'source_type' => 'manual',
        ]],
    ]);
}

function procurementPostingAccount(Company $company, string $parentCode, string $accountCode, string $name): Account
{
    $parent = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $parentCode)
        ->firstOrFail();

    return Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => $name,
        'name_en' => $name,
        'parent_id' => $parent->getKey(),
        'level' => ((int) $parent->level) + 1,
        'account_classification_id' => $parent->account_classification_id,
        'account_type' => $parent->account_type,
        'statement_type' => $parent->statement_type,
        'normal_balance' => $parent->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'is_system' => false,
        'status' => 'active',
    ]);
}

function procurementDocumentAttachment(Company $company): ArchiveFile
{
    $path = 'tests/procurement/supplier-quotation.pdf';
    Storage::disk('public')->put($path, 'procurement-document');

    return ArchiveFile::query()->create([
        'doc_number' => 9401,
        'doc_num' => 'File-PROC-1',
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'purchases',
        'record_type' => 'procurement_attachment',
        'hidden_from_picker' => false,
        'original_name' => 'supplier-quotation.pdf',
        'stored_name' => 'supplier-quotation.pdf',
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 20,
    ]);
}

test('unapproved requisitions cannot open the request for quotation creation screen', function () {
    $fixture = procurementFixture();
    $requisition = procurementManualRequisition($fixture);
    $requisition = app(ProcurementSourcingService::class)->submitRequisition($requisition);

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
        Product::ClassificationService,
        Product::ClassificationOther,
    ])->and($fixture['raw']->isPurchasable())->toBeTrue()
        ->and($fixture['service']->isPurchasable())->toBeTrue()
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
                'product_doc_num' => $fixture['service']->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num,
                'requested_quantity' => 1,
                'source_type' => 'work_order',
                'source_doc_num' => 'WO-EXTERNAL-1',
            ]],
        ]))->toThrow(DomainException::class, 'no canonical Work Order domain')
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
        ]))->toThrow(DomainException::class, 'already has an active purchase requirement');
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
    ]))->toThrow(DomainException::class, 'RFQ quantity exceeds');

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
        ->and($requisition->fresh()->status)->toBe('fully_converted')
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
        ]))->toThrow(DomainException::class, 'Delivered quantity exceeds');

    $receiptLine = $receipt->lines->first();
    $inspection = $receiving->inspect($receipt, [
        'inspection_at' => now()->toDateString(),
        'attachment_file_doc_nums' => [$attachment->doc_num],
        'lines' => [[
            'receipt_line_public_id' => $receiptLine->public_id, 'accepted_quantity' => 5,
            'rejected_quantity' => 1, 'disposition' => 'quarantine', 'reason' => 'Contaminated bag.',
        ]],
    ]);
    $receiptMovement = InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->firstOrFail();
    $unvaluedBalance = app(InventoryReportService::class)->balances($fixture['company']->getKey(), [
        'branch_store_id' => $fixture['store']->getKey(),
        'product_id' => $fixture['raw']->getKey(),
    ])->firstOrFail();

    expect($inspection->result)->toBe('partially_accepted')
        ->and($receiptMovement->quantity_in)->toBe('5.00000000')
        ->and($receiptMovement->unit_cost)->toBeNull()
        ->and($receiptMovement->total_cost)->toBeNull()
        ->and((float) $unvaluedBalance->on_hand)->toBe(5.0)
        ->and((float) $unvaluedBalance->inventory_value)->toBe(0.0)
        ->and((float) $unvaluedBalance->unvalued_receipt_quantity)->toBe(5.0)
        ->and($inspection->attachmentUsages()->where('archive_file_id', $attachment->getKey())->exists())->toBeTrue();

    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9201, 'doc_num' => 'PINV-PROC-1', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $firstOrder->supplier_id, 'purchase_order_id' => $firstOrder->getKey(),
        'invoice_date' => now()->toDateString(), 'currency_id' => $fixture['currency']->getKey(), 'exchange_rate' => 1.25,
        'subtotal_amount' => 10, 'line_discount_amount' => 1, 'taxable_amount' => 9,
        'tax_amount' => 0.9, 'total_amount' => 9.9, 'remaining_amount' => 9.9,
        'status' => PurchaseInvoice::StatusDraft,
    ]);
    $invoice->lines()->create([
        'company_id' => $fixture['company']->getKey(), 'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1, 'product_id' => $fixture['raw']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'purchase_order_line_id' => $orderLine->getKey(), 'receipt_line_id' => $receiptLine->getKey(),
        'quantity' => 5, 'unit_price' => 2, 'discount_type' => 'fixed', 'discount_value' => 1,
        'discount_amount' => 1, 'tax_rate' => 10, 'tax_amount' => 0.9, 'subtotal_amount' => 10,
        'total_before_tax' => 9, 'total_after_tax' => 9.9,
    ]);
    app(PurchaseInvoiceMatchingService::class)->matchForPosting($invoice);
    expect($invoice->fresh()->matching_status)->toBe('matched')
        ->and($invoice->lines->first()->fresh()->matched_quantity)->toBe('5.00000000');
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
    $excessLine->forceFill(['discount_amount' => 0, 'tax_rate' => 0])->save();
    expect(fn () => app(PurchaseInvoiceMatchingService::class)->matchForPosting($excessInvoice))
        ->toThrow(DomainException::class, 'discount or tax differs');
    $excessLine->forceFill(['discount_amount' => 0.2, 'tax_rate' => 10])->save();
    $excessInvoice->unsetRelation('lines');
    expect(fn () => app(PurchaseInvoiceMatchingService::class)->matchForPosting($excessInvoice))
        ->toThrow(DomainException::class, 'Invoice quantity exceeds quality-accepted');

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
        ->and($invoice->fresh()->remaining_amount)->toBe('5.9400')
        ->and(fn () => $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $firstOrder->doc_num, 'return_date' => now()->toDateString(),
            'reason_code' => 'latent_defect',
            'lines' => [['receipt_line_public_id' => $receiptLine->public_id, 'quantity' => 4, 'from_quarantine' => false]],
        ]))->toThrow(DomainException::class, 'Return quantity exceeds');

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
        ->and($invoice->fresh()->remaining_amount)->toBe('9.9000');

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

        return [$receipt, $receiptLine, $inspection];
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
        ->and((float) $beforeInvoiceBalance->inventory_value)->toBe(0.0)
        ->and((float) $beforeInvoiceBalance->unvalued_receipt_quantity)->toBe(9900.0)
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
        ->and((float) $finalBalance->inventory_value)->toBe(20622.45)
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
            'payment_schedule_public_id' => $firstSchedule->public_id,
            'amount' => 1,
        ], [
            'purchase_invoice_doc_num' => $invoice->doc_num,
            'payment_schedule_public_id' => $secondSchedule->public_id,
            'amount' => 5,
        ]],
    ]);
    $settlement->approveSupplierPayment($secondPayment);
    $journal = JournalEntry::query()->findOrFail($secondPayment->fresh()->journal_entry_id);

    expect($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid)
        ->and($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($firstSchedule->fresh()->status)->toBe('paid')
        ->and($secondSchedule->fresh()->status)->toBe('paid')
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
        ]))->toThrow(DomainException::class, 'outstanding amount');

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
    $debitsByCode = $journal->lines->filter(fn ($line) => (float) $line->debit_amount > 0)
        ->mapWithKeys(fn ($line): array => [$line->account->account_code => (float) $line->debit_amount]);

    expect($invoice->matching_status)->toBe('authorized_direct')
        ->and($debitsByCode->get('1131'))->toBe(18.0)
        ->and($debitsByCode->get('526'))->toBe(5.0)
        ->and($debitsByCode->get('2131'))->toBe(3.22)
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
            'product_doc_num' => $fixture['service']->doc_num,
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
        'open_requirements',
        'requested_vs_ordered',
        'rfq_quotation_status',
        'purchase_order_status',
        'ordered_vs_received',
        'overdue_po_deliveries',
        'delivery_schedule',
        'incoming_qc_pending',
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
        ->and($rows->first()['outstanding'])->toBe('125.0000')
        ->and($confidentialExport->headings())->not->toContain('Amount')
        ->and($confidentialExport->map($rows->first()))->not->toContain(125);

    $query = ['report_type' => ProcurementCycleReport::SupplierPayables];
    $this->actingAs($fixture['user'])
        ->get(route('admin.purchases.procurement-cycle-report.index', $query))
        ->assertOk()
        ->assertSee($invoice->doc_num)
        ->assertSee('name="production_order_doc_num"', false)
        ->assertSee('name="work_order_reference"', false)
        ->assertDontSee(__('Amount'));
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
        ->and(__('procurement.documents.types.goods-receipt-inspection'))->toBe('فحص الجودة الوارد');
});
