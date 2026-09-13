<?php

use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('inspection first procurement clears inventory grni supplier payable and payment through the complete document chain', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111097', 'Inspection lifecycle supplier payable');
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111097', 'Inspection lifecycle cash');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();

    $administrativeBranch = procurementAdministrativeBranch($fixture);
    $cashbox = Cashbox::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $administrativeBranch->getKey(),
        'account_id' => $cashAccount->getKey(),
        'name' => 'Inspection lifecycle cashbox',
        'status' => 'active',
    ]);
    CashboxCurrency::query()->create([
        'cashbox_id' => $cashbox->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'is_default' => true,
        'status' => 'active',
    ]);

    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);
    $reports = app(ProcurementCycleReport::class);

    $requisition = $sourcing->approveRequisition(
        $sourcing->submitRequisition(procurementManualRequisition($fixture, 100)),
    );

    procurementUseBranch($fixture, $administrativeBranch);
    $rfq = $sourcing->issueRequestForQuotation($sourcing->createRequestForQuotation($requisition, [
        'issue_date' => now()->toDateString(),
        'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num],
        'lines' => [[
            'requisition_line_public_id' => $requisition->lines->sole()->public_id,
            'quantity' => 100,
        ]],
    ]));
    $quotation = $sourcing->submitSupplierQuotation($sourcing->createSupplierQuotation($rfq, [
        'quotation_date' => now()->toDateString(),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'lines' => [[
            'rfq_line_public_id' => $rfq->lines->sole()->public_id,
            'offered_quantity' => 100,
            'unit_price' => 10,
            'tax_rate' => 0,
        ]],
    ]));
    $selection = $sourcing->createSupplierSelection($rfq, [
        'selection_date' => now()->toDateString(),
        'selection_reason' => 'Complete inspection-first acceptance path.',
        'lines' => [[
            'quotation_line_public_id' => $quotation->lines->sole()->public_id,
            'selected_quantity' => 100,
        ]],
    ]);
    $purchaseOrder = $sourcing->approveSelection($selection)->sole();
    $purchaseOrder = $orders->approve($orders->submit($purchaseOrder));

    expect(InventoryTransaction::query()->count())->toBe(0)
        ->and(DB::table('journal_entries')->count())->toBe(0);

    procurementUseBranch($fixture, $fixture['branch']);
    $inspection = $receiving->inspectPurchaseSource($purchaseOrder, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 100,
            'accepted_quantity' => 100,
            'rejected_quantity' => 0,
            'supplier_lot_number' => 'QA-FULL-100',
        ]],
    ]);
    $receipt = $receiving->createReceiptFromInspection($inspection, [
        'document_date' => now()->toDateString(),
        'supplier_delivery_note' => 'QA-DN-FULL-100',
        'lines' => [[
            'inspection_line_public_id' => $inspection->lines->sole()->public_id,
            'delivered_quantity' => 100,
        ]],
    ]);

    expect(InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->count())->toBe(0);

    $receipt = $receiving->postReceipt($receipt);
    $receiptLine = $receipt->lines->sole();

    expect($inspection->fresh()->result)->toBe('accepted')
        ->and($inspection->fresh()->hasReceiptableQuantity())->toBeFalse()
        ->and($purchaseOrder->fresh()->fulfillmentStatus())->toBe('fully_received')
        ->and((float) InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->sum('quantity_in'))->toBe(100.0)
        ->and((float) InventoryTransaction::query()->where('source_doc_num', $receipt->doc_num)->sum('total_cost'))->toBe(1000.0)
        ->and(fn () => $receiving->createReceiptFromInspection($inspection->fresh(), [
            'document_date' => now()->toDateString(),
            'lines' => [[
                'inspection_line_public_id' => $inspection->lines->sole()->public_id,
                'delivered_quantity' => 1,
            ]],
        ]))->toThrow(DomainException::class, __('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));

    procurementUseBranch($fixture, $administrativeBranch);
    $dueDate = now()->addWeek()->toDateString();
    $invoice = $invoices->create([
        'purchase_order_doc_num' => $purchaseOrder->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'supplier_invoice_number' => 'QA-FULL-INV-100',
        'payment_type' => PurchaseInvoice::PaymentTypePartial,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'receipt_line_public_id' => $receiptLine->public_id,
            'quantity' => 100,
            'unit_price' => 10,
        ]],
        'payment_schedules' => [[
            'due_date' => $dueDate,
            'amount' => 1000,
            'payment_source_type' => PurchaseInvoice::SourceCashbox,
            'cashbox_doc_num' => $cashbox->doc_num,
        ]],
    ])['record'];
    $schedule = $invoice->paymentSchedules()->with('cashVoucher')->sole();

    expect($schedule->due_date->toDateString())->toBe($dueDate)
        ->and($schedule->payment_date?->toDateString())->toBe($dueDate)
        ->and($schedule->payment_source_type)->toBe(PurchaseInvoice::SourceCashbox)
        ->and($schedule->cashVoucher)->not->toBeNull();

    $invoice = $invoices->approve($invoice);

    expect($invoice->matching_status)->toBe('matched')
        ->and((float) $receiptLine->fresh()->grni_cleared_quantity)->toBe(100.0)
        ->and($invoice->payment_status)->toBe(PurchaseInvoice::PaymentStatusUnpaid);

    $payment = SupplierPaymentContext::query()->where('cash_voucher_id', $schedule->cash_voucher_id)->sole();
    $payment = $settlement->approveSupplierPayment($payment);

    $companyReconciliation = $reports->grniReconciliation(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    );
    $factoryReconciliation = $reports->grniReconciliation(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $fixture['branch']->getKey(),
    );
    $administrativeReconciliation = $reports->grniReconciliation(
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
        $administrativeBranch->getKey(),
    );
    $supplierStatement = $reports->supplierStatement($fixture['company']->getKey(), $fixture['period']->getKey(), [
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_id' => $administrativeBranch->getKey(),
    ]);
    $chain = $reports->documentChain($requisition)->pluck('doc_num');

    expect($payment->status)->toBe(SupplierPaymentContext::StatusApproved)
        ->and($invoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid)
        ->and($invoice->fresh()->remaining_amount)->toBe('0.0000')
        ->and($companyReconciliation['difference'])->toBe('0.0000')
        ->and($factoryReconciliation['difference'])->toBe('0.0000')
        ->and($administrativeReconciliation['difference'])->toBe('0.0000')
        ->and($purchaseOrder->branch_id)->toBe($administrativeBranch->getKey())
        ->and($invoice->branch_id)->toBe($administrativeBranch->getKey())
        ->and($inspection->branch_id)->toBe($fixture['branch']->getKey())
        ->and($receipt->branch_id)->toBe($fixture['branch']->getKey())
        ->and((float) $supplierStatement->last()['balance'])->toBe(0.0)
        ->and((float) DB::table('journal_entry_lines')->sum('debit_amount'))->toBe((float) DB::table('journal_entry_lines')->sum('credit_amount'))
        ->and($chain)->toContain(
            $requisition->doc_num,
            $rfq->doc_num,
            $quotation->doc_num,
            $selection->doc_num,
            $purchaseOrder->doc_num,
            $inspection->doc_num,
            $receipt->doc_num,
            $invoice->doc_num,
            $payment->doc_num,
        );

    procurementUseBranch($fixture, $fixture['branch']);
    expect(fn () => $receiving->reverseReceipt($receipt->fresh(), 'Receipt cannot be reversed after billing.'))
        ->toThrow(DomainException::class, __('Resolve the related invoices and returns before reversing this receipt.'));

    procurementUseBranch($fixture, $administrativeBranch);
    foreach ([
        ['purchase-requisition', $requisition->doc_num],
        ['request-for-quotation', $rfq->doc_num],
        ['supplier-quotation', $quotation->doc_num],
        ['supplier-selection', $selection->doc_num],
        ['goods-receipt-inspection', $inspection->doc_num],
        ['goods-receipt', $receipt->doc_num],
        ['supplier-payment', $payment->doc_num],
    ] as [$type, $documentNumber]) {
        $response = $this->get(route('admin.purchases.procurement.print', [$type, $documentNumber]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        expect(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();
    }

    expect(str_starts_with($this->get(route('admin.purchases.purchase-orders.print', $purchaseOrder))->assertOk()->getContent(), '%PDF-'))->toBeTrue()
        ->and(str_starts_with($this->get(route('admin.purchases.purchase-invoices.print', $invoice))->assertOk()->getContent(), '%PDF-'))->toBeTrue();
});

test('quality rejection and incomplete warehouse intake preserve receipt and replacement capacity', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $reports = app(ProcurementCycleReport::class);

    $requisition = $sourcing->approveRequisition(
        $sourcing->submitRequisition(procurementManualRequisition($fixture, 100)),
    );
    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $purchaseOrder = $orders->create([
        'document_date' => now()->toDateString(),
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 100,
            'unit_price' => 7,
            'purchase_requisition_line_id' => $requisition->lines->sole()->getKey(),
        ]],
    ])['record'];
    $purchaseOrder = $orders->approve($orders->submit($purchaseOrder));

    procurementUseBranch($fixture, $fixture['branch']);
    expect(fn () => $receiving->inspectPurchaseSource($purchaseOrder, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 10,
            'accepted_quantity' => 8,
            'rejected_quantity' => 1,
            'reason' => 'Totals do not reconcile.',
        ]],
    ]))->toThrow(DomainException::class, __('Accepted plus rejected quantity must equal the delivered quantity.'));

    expect(fn () => $receiving->inspectPurchaseSource($purchaseOrder, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 1,
            'accepted_quantity' => 0,
            'rejected_quantity' => 1,
        ]],
    ]))->toThrow(DomainException::class, __('A rejection reason is required for rejected material.'));

    $partialInspection = $receiving->inspectPurchaseSource($purchaseOrder, [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 60,
            'accepted_quantity' => 40,
            'rejected_quantity' => 20,
            'disposition' => 'return_supplier',
            'reason' => 'Twenty units failed incoming quality inspection.',
        ]],
    ]);
    $firstReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($partialInspection, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $partialInspection->lines->sole()->public_id,
            'delivered_quantity' => 15,
        ]],
    ]));

    $pendingRow = $reports->rows(
        ProcurementCycleReport::IncomingQcPending,
        ['branch_id' => $fixture['branch']->getKey()],
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    )->firstWhere('document', $partialInspection->doc_num);

    expect($partialInspection->result)->toBe('partially_accepted')
        ->and((float) $partialInspection->lines->sole()->remainingReceiptQuantity())->toBe(25.0)
        ->and((float) $pendingRow['outstanding'])->toBe(25.0)
        ->and((float) InventoryTransaction::query()->sum('quantity_in'))->toBe(15.0)
        ->and(fn () => $receiving->inspectPurchaseSource($purchaseOrder->fresh(), [
            'inspection_at' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
                'delivered_quantity' => 61,
                'accepted_quantity' => 61,
                'rejected_quantity' => 0,
            ]],
        ]))->toThrow(DomainException::class, __('Inspected quantity exceeds the remaining purchase order quantity.'))
        ->and(fn () => $receiving->createReceiptFromInspection($partialInspection->fresh(), [
            'document_date' => now()->toDateString(),
            'lines' => [[
                'inspection_line_public_id' => $partialInspection->lines->sole()->public_id,
                'delivered_quantity' => 26,
            ]],
        ]))->toThrow(DomainException::class, __('procurement.messages.receipt_quantity_exceeds_inspection_remaining'));

    $secondReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($partialInspection->fresh(), [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $partialInspection->lines->sole()->public_id,
            'delivered_quantity' => 25,
        ]],
    ]));

    $rejectedInspection = $receiving->inspectPurchaseSource($purchaseOrder->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 10,
            'accepted_quantity' => 0,
            'rejected_quantity' => 10,
            'disposition' => 'return_supplier',
            'reason' => 'All ten replacement units failed inspection.',
        ]],
    ]);

    expect($rejectedInspection->result)->toBe('rejected')
        ->and($rejectedInspection->hasReceiptableQuantity())->toBeFalse()
        ->and(fn () => $receiving->createReceiptFromInspection($rejectedInspection, [
            'document_date' => now()->toDateString(),
            'lines' => [[
                'inspection_line_public_id' => $rejectedInspection->lines->sole()->public_id,
                'delivered_quantity' => 1,
            ]],
        ]))->toThrow(DomainException::class, __('procurement.messages.inspection_status_not_receiptable'));

    $acceptedInspection = $receiving->inspectPurchaseSource($purchaseOrder->fresh(), [
        'inspection_at' => now()->toDateString(),
        'lines' => [[
            'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
            'delivered_quantity' => 60,
            'accepted_quantity' => 60,
            'rejected_quantity' => 0,
        ]],
    ]);
    $thirdReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($acceptedInspection, [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $acceptedInspection->lines->sole()->public_id,
            'delivered_quantity' => 20,
        ]],
    ]));

    expect((float) $acceptedInspection->lines->sole()->remainingReceiptQuantity())->toBe(40.0)
        ->and($acceptedInspection->hasReceiptableQuantity())->toBeTrue()
        ->and($purchaseOrder->fresh()->fulfillmentStatus())->toBe('partially_received')
        ->and(fn () => $receiving->inspectPurchaseSource($purchaseOrder->fresh(), [
            'inspection_at' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_public_id' => $purchaseOrder->lines->sole()->public_id,
                'delivered_quantity' => 1,
                'accepted_quantity' => 1,
                'rejected_quantity' => 0,
            ]],
        ]))->toThrow(DomainException::class, __('Inspected quantity exceeds the remaining purchase order quantity.'));

    $fourthReceipt = $receiving->postReceipt($receiving->createReceiptFromInspection($acceptedInspection->fresh(), [
        'document_date' => now()->toDateString(),
        'lines' => [[
            'inspection_line_public_id' => $acceptedInspection->lines->sole()->public_id,
            'delivered_quantity' => 40,
        ]],
    ]));
    $rejectionRows = $reports->rows(
        ProcurementCycleReport::QcRejection,
        ['branch_id' => $fixture['branch']->getKey()],
        $fixture['company']->getKey(),
        $fixture['period']->getKey(),
    );

    expect($purchaseOrder->fresh()->fulfillmentStatus())->toBe('fully_received')
        ->and((float) InventoryTransaction::query()->sum('quantity_in'))->toBe(100.0)
        ->and($rejectionRows->sum(fn (array $row): float => (float) $row['rejected']))->toBe(30.0)
        ->and($reports->documentChain($purchaseOrder)->pluck('doc_num'))->toContain(
            $partialInspection->doc_num,
            $firstReceipt->doc_num,
            $secondReceipt->doc_num,
            $rejectedInspection->doc_num,
            $acceptedInspection->doc_num,
            $thirdReceipt->doc_num,
            $fourthReceipt->doc_num,
        );
});

test('bank payment schedule accepts one due date without creating a cash voucher', function (): void {
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());

    $administrativeBranch = procurementAdministrativeBranch($fixture);
    procurementUseBranch($fixture, $administrativeBranch);
    $bankGl = procurementPostingAccount($fixture['company'], '1112', '1112097', 'Inspection lifecycle bank');
    $bank = BankAccount::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $fixture['company']->getKey()),
        'company_id' => $fixture['company']->getKey(),
        'bank_id' => $bankGl->parent_id,
        'account_id' => $bankGl->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'account_name' => 'Inspection lifecycle bank',
        'account_number' => 'QA-100-200',
        'bank_branch_name' => 'Purchasing administration',
        'status' => 'active',
    ]);
    $dueDate = now()->addDays(10)->toDateString();
    $response = $this->postJson(route('admin.purchases.purchase-invoices.store'), [
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'exchange_rate' => 1,
        'invoice_date' => now()->toDateString(),
        'payment_type' => PurchaseInvoice::PaymentTypePartial,
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'quantity' => 2,
            'unit_price' => 10,
        ]],
        'payment_schedules' => [[
            'due_date' => $dueDate,
            'amount' => 20,
            'payment_source_type' => PurchaseInvoice::SourceBank,
            'bank_account_doc_num' => $bank->doc_num,
        ]],
    ])->assertOk()->assertJsonPath('success', true);

    $invoice = PurchaseInvoice::query()->where('doc_num', $response->json('data.doc_num'))->sole();
    $schedule = $invoice->paymentSchedules()->sole();

    expect($schedule->due_date->toDateString())->toBe($dueDate)
        ->and($schedule->payment_date)->toBeNull()
        ->and($schedule->payment_source_type)->toBe(PurchaseInvoice::SourceBank)
        ->and($schedule->bank_account_id)->toBe($bank->getKey())
        ->and($schedule->cash_voucher_id)->toBeNull()
        ->and(SupplierPaymentContext::query()->count())->toBe(0);
});
