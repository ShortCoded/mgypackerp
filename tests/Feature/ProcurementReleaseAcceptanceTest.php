<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Services\ChequeService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\Reports\ProcurementCycleReport;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProcurementSupport.php';

test('five item two supplier release acceptance reconciles sourcing cross period receipts partial invoices three payment methods and reversed returns', function (): void {
    $this->travelTo(Carbon::parse('2026-09-20 12:00:00'));
    $fixture = procurementFixture();
    $this->seed(PermissionSeeder::class);
    $fixture['user']->givePermissionTo(Permission::query()->where('guard_name', 'web')->get());
    $september = $fixture['period'];
    $september->forceFill(['from_date' => '2026-09-01', 'to_date' => '2026-09-30'])->save();
    $october = FinancialPeriod::query()->create(['company_id' => $fixture['company']->id, 'doc_number' => 999, 'doc_num' => 'Period-RELEASE-OCT', 'name' => 'October', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'is_closed' => false]);
    $products = collect([$fixture['raw']]);
    foreach (range(2, 5) as $index) {
        $product = $fixture['raw']->replicate();
        $product->fill(['doc_number' => 9200 + $index, 'doc_num' => 'Product-RELEASE-'.$index, 'name' => 'Release material '.$index])->save();
        $products->push($product);
    }
    $supplierAccounts = [];
    foreach ([$fixture['firstSupplier'], $fixture['secondSupplier']] as $index => $supplier) {
        $account = procurementPostingAccount($fixture['company'], '2111', '211107'.($index + 1), 'Release Supplier '.($index + 1));
        $supplier->forceFill(['account_id' => $account->id])->save();
        $supplierAccounts[] = $account->id;
    }
    $cashAccount = procurementPostingAccount($fixture['company'], '1111', '1111071', 'Release Cash');
    $bankGl = procurementPostingAccount($fixture['company'], '1112', '1112071', 'Release Bank');
    $cashbox = Cashbox::query()->create(['doc_number' => 9701, 'doc_num' => 'CASH-RELEASE', 'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'account_id' => $cashAccount->id, 'name' => 'Release cashbox', 'status' => 'active']);
    CashboxCurrency::query()->create(['cashbox_id' => $cashbox->id, 'currency_id' => $fixture['currency']->id, 'is_default' => true, 'status' => 'active']);
    $bank = BankAccount::query()->create(['doc_number' => 9701, 'doc_num' => 'BANK-RELEASE', 'company_id' => $fixture['company']->id,
        'bank_id' => $bankGl->parent_id, 'account_id' => $bankGl->id, 'currency_id' => $fixture['currency']->id,
        'account_name' => 'Release Bank', 'account_number' => '001020', 'bank_branch_name' => 'Factory', 'status' => 'active']);
    $sourcing = app(ProcurementSourcingService::class);
    $orders = app(PurchaseOrderService::class);
    $receiving = app(ProcurementReceivingService::class);
    $invoices = app(PurchaseInvoiceService::class);
    $settlement = app(ProcurementSettlementService::class);
    $reports = app(ProcurementCycleReport::class);
    $request = $sourcing->approveRequisition($sourcing->submitRequisition($sourcing->createRequisition([
        'request_date' => '2026-09-20', 'branch_store_uuid' => $fixture['store']->public_uuid, 'priority' => 'high',
        'department' => 'Warehouse', 'lines' => $products->map(fn ($product) => ['product_doc_num' => $product->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num, 'requested_quantity' => 1000, 'source_type' => 'manual'])->all(),
    ])));
    $rfq = $sourcing->issueRequestForQuotation($sourcing->createRequestForQuotation($request, [
        'issue_date' => '2026-09-20', 'supplier_doc_nums' => [$fixture['firstSupplier']->doc_num, $fixture['secondSupplier']->doc_num],
        'lines' => $request->lines->map(fn ($line) => ['requisition_line_public_id' => $line->public_id, 'quantity' => 1000])->all(),
    ]));
    $quotes = collect([$fixture['firstSupplier'], $fixture['secondSupplier']])->map(fn ($supplier, $index) => $sourcing->submitSupplierQuotation($sourcing->createSupplierQuotation($rfq, [
        'quotation_date' => '2026-09-20', 'supplier_doc_num' => $supplier->doc_num, 'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
        'lines' => $rfq->lines->map(fn ($line) => ['rfq_line_public_id' => $line->public_id, 'offered_quantity' => 1000, 'unit_price' => 2 + $index, 'tax_rate' => 0])->all(),
    ])));
    $selection = $sourcing->createSupplierSelection($rfq, ['selection_date' => '2026-09-20', 'selection_reason' => 'Three materials to A and two to B',
        'lines' => $quotes->first()->lines->take(3)->concat($quotes->last()->lines->slice(3))->map(fn ($line) => ['quotation_line_public_id' => $line->public_id, 'selected_quantity' => 1000])->all()]);
    $purchaseOrders = $sourcing->approveSelection($selection)->map(fn ($order) => $orders->approve($orders->submit($order)));
    $orderA = $purchaseOrders->firstWhere('supplier_id', $fixture['firstSupplier']->id);
    $orderB = $purchaseOrders->firstWhere('supplier_id', $fixture['secondSupplier']->id);
    expect($orderA->lines)->toHaveCount(3)->and($orderB->lines)->toHaveCount(2)->and(DB::table('inventory_transactions')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
    $receive = function ($order, array $quantities, int $rejected = 0) use ($receiving) {
        $receipt = $receiving->receive($order, ['document_date' => now()->toDateString(), 'lines' => $order->lines->map(fn ($line, $index) => ['purchase_order_line_public_id' => $line->public_id, 'delivered_quantity' => $quantities[$index]])->all()]);
        $receiving->inspect($receipt, ['lines' => $receipt->lines->map(fn ($line, $index) => ['receipt_line_public_id' => $line->public_id,
            'accepted_quantity' => (float) $line->delivered_quantity - ($index === 0 ? $rejected : 0), 'rejected_quantity' => $index === 0 ? $rejected : 0,
            'disposition' => $index === 0 && $rejected > 0 ? 'quarantine' : 'accepted', 'reason' => $rejected ? 'QC defect' : null])->all()]);

        $receiving->postReceipt($receipt->fresh());

        return $receipt->fresh()->load('lines');
    };
    $first = $receive($orderA, [400, 400, 400], 5);
    $quarantineReturn = $settlement->approvePurchaseReturn($settlement->createPurchaseReturn(['purchase_order_doc_num' => $orderA->doc_num,
        'return_date' => now()->toDateString(), 'reason_code' => 'incoming_qc_rejection',
        'lines' => [['receipt_line_public_id' => $first->lines->first()->public_id, 'quantity' => 5, 'from_quarantine' => true]]]));
    expect($orderA->fresh()->fulfillmentStatus())->toBe('partially_received');
    $historicalOrderAudit = $orderA->fresh()->updated_at?->toISOString();
    $september->forceFill(['is_closed' => true])->save();
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
    $context = [OperatingContextService::FinancialPeriodIdKey => $october->id, OperatingContextService::FinancialPeriodDocNumKey => $october->doc_num];
    session($context);
    $this->withSession($context);
    $openOrders = app(ProcurementCycleReport::class)->rows(ProcurementCycleReport::OpenPurchaseOrders, ['branch_id' => $fixture['branch']->id], $fixture['company']->id, $october->id);
    expect($openOrders)->toHaveCount(5)->and($openOrders->sum('remaining'))->toBe(3805.0)
        ->and($openOrders->every(fn ($row) => filled($row['document_url'])))->toBeTrue();
    $second = $receive($orderA, [605, 600, 600]);
    $third = $receive($orderB, [1000, 1000]);
    expect($orderA->fresh()->updated_at?->toISOString())->toBe($historicalOrderAudit);
    expect($orderA->fresh()->fulfillmentStatus())->toBe('fully_received')->and($orderB->fresh()->fulfillmentStatus())->toBe('fully_received');
    $invoice = function ($order, $receipts, float $ratio, string $reference) use ($invoices, $fixture) {
        return $invoices->approve($invoices->create(['purchase_order_doc_num' => $order->doc_num, 'supplier_doc_num' => $order->supplier->doc_num,
            'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1, 'invoice_date' => now()->toDateString(),
            'supplier_invoice_number' => $reference, 'payment_type' => 'credit',
            'lines' => collect($receipts)->flatMap(fn ($receipt) => $receipt->lines)->map(fn ($line) => ['product_doc_num' => $line->product->doc_num,
                'unit_doc_num' => $fixture['unit']->doc_num, 'purchase_order_line_public_id' => $line->purchaseOrderLine->public_id,
                'receipt_line_public_id' => $line->public_id, 'quantity' => (float) $line->accepted_quantity * $ratio, 'unit_price' => $line->purchaseOrderLine->unit_price])->all(),
        ])['record']);
    };
    $invoiceA = $invoice($orderA, [$first, $second], 1, 'RELEASE-A');
    $invoiceB1 = $invoice($orderB, [$third], .5, 'RELEASE-B1');
    $invoiceB2 = $invoice($orderB, [$third], .5, 'RELEASE-B2');
    expect($invoiceA->total_amount)->toBe('6000.0000')->and($invoiceB1->total_amount)->toBe('3000.0000');
    $payments = collect();
    foreach ([[$invoiceA, 'cash', 1000], [$invoiceA, 'bank', 2000], [$invoiceA, 'cheque', 3000], [$invoiceB1, 'bank', 3000], [$invoiceB2, 'bank', 3000]] as [$invoiceRecord, $method, $amount]) {
        $payment = $settlement->approveSupplierPayment($settlement->createSupplierPayment(['supplier_doc_num' => $invoiceRecord->supplier->doc_num,
            'payment_method' => $method, 'payment_date' => '2026-10-05', 'currency_doc_num' => $fixture['currency']->doc_num, 'exchange_rate' => 1,
            'cashbox_doc_num' => $cashbox->doc_num, 'bank_account_doc_num' => $bank->doc_num, 'amount' => $amount,
            'cheque_number' => 'RELEASE-CHECK-1', 'cheque_date' => '2026-10-05', 'cheque_due_date' => '2026-10-10',
            'allocations' => [['purchase_invoice_doc_num' => $invoiceRecord->doc_num, 'amount' => $amount]]]));
        if ($method === 'cheque') {
            app(ChequeService::class)->markDelivered($payment->cheque);
            app(ChequeService::class)->markCleared($payment->cheque->fresh());
        }
        $payments->push($payment);
    }
    $return = $settlement->approvePurchaseReturn($settlement->createPurchaseReturn(['purchase_order_doc_num' => $orderA->doc_num,
        'purchase_invoice_doc_num' => $invoiceA->doc_num, 'return_date' => '2026-10-05', 'reason_code' => 'supplier_defect',
        'lines' => [['receipt_line_public_id' => $second->lines->first()->public_id, 'quantity' => 10]]]));
    $settlement->reversePurchaseReturn($return, 'Goods accepted after review');
    foreach ([$invoiceA, $invoiceB1, $invoiceB2] as $paidInvoice) {
        expect($paidInvoice->fresh()->payment_status)->toBe(PurchaseInvoice::PaymentStatusPaid);
    }
    foreach ($products as $product) {
        $quantity = DB::table('inventory_transactions')->where('product_id', $product->id)->selectRaw('sum(quantity_in - quantity_out) as net')->value('net');
        expect((float) $quantity)->toBe(1000.0);
    }
    foreach ([$fixture['firstSupplier'], $fixture['secondSupplier']] as $supplier) {
        $statement = $reports->supplierStatement($fixture['company']->id, $october->id, ['supplier_doc_num' => $supplier->doc_num, 'branch_id' => $fixture['branch']->id]);
        expect((float) $statement->last()['balance'])->toBe(0.0);
    }
    expect($reports->grniReconciliation($fixture['company']->id, $september->id, $fixture['branch']->id)['subledger'])->toBe('2390.0000')
        ->and($reports->grniReconciliation($fixture['company']->id, $october->id, $fixture['branch']->id)['difference'])->toBe('0.0000');
    $ledger = $reports->rows('purchase_ledger', ['branch_id' => $fixture['branch']->id], $fixture['company']->id, $october->id);
    $this->get(route('admin.purchases.procurement-cycle-report.index', ['report_type' => 'supplier_statement']))
        ->assertOk()->assertViewHas('metrics', fn (array $metrics): bool => isset($metrics['opening_balance'], $metrics['closing_balance'])
            && (float) $metrics['closing_balance'] === 0.0 && ! isset($metrics['amount'], $metrics['outstanding']));
    expect($ledger)->toHaveCount(3)->and((float) $ledger->sum('amount'))->toBe(12000.0)->and((float) $ledger->sum('paid'))->toBe(12000.0)->and((float) $ledger->sum('outstanding'))->toBe(0.0);
    $chain = $reports->documentChain($request)->pluck('doc_num');
    foreach ([$request, $rfq, ...$quotes, $orderA, $orderB, $first, $second, $third, $invoiceA, $invoiceB1, $invoiceB2, ...$payments, $return] as $document) {
        expect($chain)->toContain($document->doc_num);
    }
    expect((float) DB::table('journal_entry_lines')->sum('debit_amount'))->toBe((float) DB::table('journal_entry_lines')->sum('credit_amount'));
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        session(['locale' => $locale]);
        $this->withSession(['locale' => $locale]);
        foreach (['supplier_statement', 'purchase_ledger'] as $type) {
            $response = $this->get(route('admin.purchases.procurement-cycle-report.print', ['report_type' => $type]))
                ->assertOk()->assertHeader('content-type', 'application/pdf');
            expect(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();
            if ($directory = getenv('PROCUREMENT_PRINT_ARTIFACT_DIR')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/'.$type.'-'.$locale.'.pdf', $response->getContent());
            }
        }
    }
    $this->travelBack();
});
