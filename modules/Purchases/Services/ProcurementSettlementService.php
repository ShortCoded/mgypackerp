<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentAllocation;
use Modules\Purchases\Models\SupplierPaymentContext;

class ProcurementSettlementService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly CashVoucherService $cashVouchers,
        private readonly ChequeService $cheques,
        private readonly JournalEntryService $journalEntries,
        private readonly SupplierPaymentPostingService $supplierPaymentPostings,
        private readonly ProcurementAuditService $audit,
        private readonly InventoryAvailabilityService $availability,
        private readonly PurchaseOrderCalculationService $purchaseOrderCalculator,
    ) {}

    public function requestPurchaseOrderChange(PurchaseOrder $purchaseOrder, array $data): PurchaseOrderChangeRequest
    {
        return DB::transaction(function () use ($data, $purchaseOrder): PurchaseOrderChangeRequest {
            $context = $this->context();
            $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $this->assertOrderContext($order, $context);
            if (! $order->isApproved()) {
                throw new DomainException(__('Only an approved purchase order uses the controlled change workflow.'));
            }

            $requested = $this->normalizeChangeValues($order, $data['requested_values']);

            $changeRequest = PurchaseOrderChangeRequest::query()->create([
                ...$this->number('purchase_order_change_requests', PurchaseOrderChangeRequest::class, $context),
                ...$context,
                'purchase_order_id' => $order->getKey(),
                'request_date' => $data['request_date'],
                'original_values' => $this->currentChangeValues($order),
                'requested_values' => $requested,
                'reason' => $data['reason'],
                'status' => 'pending',
                'requested_by' => auth()->id(),
            ]);

            $this->audit->record($changeRequest, 'purchase_order.change_requested', [
                'purchase_order_doc_num' => $order->doc_num,
            ]);

            return $changeRequest;
        }, 3);
    }

    public function approvePurchaseOrderChange(PurchaseOrderChangeRequest $changeRequest): PurchaseOrderChangeRequest
    {
        return DB::transaction(function () use ($changeRequest): PurchaseOrderChangeRequest {
            $context = $this->context();
            $request = PurchaseOrderChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->getKey());
            $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($request->purchase_order_id);
            $this->assertOrderContext($order, $context);
            if ($request->status !== 'pending' || ! $order->isApproved() || $order->hasReceipts()) {
                throw new DomainException(__('This purchase order change can no longer be approved.'));
            }

            $values = $request->requested_values;
            $order->forceFill([
                'expected_delivery_date' => $values['expected_delivery_date'] ?? $order->expected_delivery_date,
                'payment_terms' => $values['payment_terms'] ?? $order->payment_terms,
                'notes' => $values['notes'] ?? $order->notes,
                'updated_by' => auth()->id(),
            ])->save();

            foreach ($values['lines'] ?? [] as $lineValues) {
                $line = PurchaseOrderLine::query()->lockForUpdate()
                    ->where('purchase_order_id', $order->getKey())
                    ->where('public_id', $lineValues['public_id'])
                    ->firstOrFail();
                $quantity = (float) ($lineValues['ordered_quantity'] ?? $line->ordered_quantity);
                $price = (float) ($lineValues['unit_price'] ?? $line->unit_price);
                if ($quantity <= 0 || $quantity < (float) $line->received_quantity || $price < 0) {
                    throw new DomainException(__('Requested purchase order line values are invalid.'));
                }
                $this->assertChangedQuantityWithinSource($line, $quantity);
                $calculated = $this->purchaseOrderCalculator->calculate([[
                    'ordered_quantity' => $quantity,
                    'received_quantity' => $line->received_quantity,
                    'unit_price' => $price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_rate' => $line->tax_rate,
                ]])['lines'][0];
                $line->forceFill([
                    'ordered_quantity' => $calculated['ordered_quantity'],
                    'remaining_quantity' => $calculated['remaining_quantity'],
                    'unit_price' => $calculated['unit_price'],
                    'discount_type' => $calculated['discount_type'],
                    'discount_value' => $calculated['discount_value'],
                    'discount_amount' => $calculated['discount_amount'],
                    'tax_rate' => $calculated['tax_rate'],
                    'tax_amount' => $calculated['tax_amount'],
                    'subtotal_amount' => $calculated['subtotal_amount'],
                    'total_before_tax' => $calculated['total_before_tax'],
                    'total_after_tax' => $calculated['total_after_tax'],
                    'line_total' => $calculated['line_total'],
                    'required_delivery_date' => $lineValues['required_delivery_date'] ?? $line->required_delivery_date,
                    'updated_by' => auth()->id(),
                ])->save();
            }

            $order->forceFill([
                'total_ordered_quantity' => $this->quantity($order->lines()->sum('ordered_quantity')),
                'total_remaining_quantity' => $this->quantity($order->lines()->sum('remaining_quantity')),
                'subtotal_amount' => $this->amount($order->lines()->sum('subtotal_amount')),
                'total_amount' => $this->amount((float) $order->lines()->sum('total_after_tax') + (float) $order->freight_amount),
            ])->save();
            $request->forceFill([
                'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(),
            ])->save();
            $this->audit->record($request, 'purchase_order.change_approved', [
                'purchase_order_doc_num' => $order->doc_num,
            ]);

            return $request->refresh()->load('purchaseOrder.lines');
        }, 3);
    }

    public function createPurchaseReturn(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $context = $this->context();
            $order = PurchaseOrder::query()->lockForUpdate()->where('company_id', $context['company_id'])
                ->where('doc_num', $data['purchase_order_doc_num'])->firstOrFail();
            $this->assertOrderContext($order, $context);
            $invoice = filled($data['purchase_invoice_doc_num'] ?? null)
                ? PurchaseInvoice::query()->where('company_id', $context['company_id'])->where('doc_num', $data['purchase_invoice_doc_num'])->firstOrFail()
                : null;
            if ($invoice instanceof PurchaseInvoice
                && ((int) $invoice->purchase_order_id !== (int) $order->getKey()
                    || (int) $invoice->supplier_id !== (int) $order->supplier_id
                    || ! in_array($invoice->status, [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed], true))) {
                throw new DomainException(__('The selected supplier invoice does not belong to this purchase order.'));
            }

            $return = PurchaseReturn::query()->create([
                ...$this->number('purchase_returns', PurchaseReturn::class, $context),
                ...$context,
                'branch_store_id' => $order->branch_store_id,
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->getKey(),
                'receipt_id' => null,
                'purchase_invoice_id' => $invoice?->getKey(),
                'return_date' => $data['return_date'],
                'reason_code' => $data['reason_code'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $totalQuantity = 0.0;
            $totalAmount = 0.0;
            foreach ($data['lines'] as $input) {
                $receiptLine = UnpricedInventoryReceiptLine::query()->with('receipt')->lockForUpdate()
                    ->where('public_id', $input['receipt_line_public_id'])->firstOrFail();
                if ((int) $receiptLine->receipt?->purchase_order_id !== (int) $order->getKey()) {
                    throw new DomainException(__('The return line does not belong to the selected purchase order.'));
                }
                $fromQuarantine = (bool) ($input['from_quarantine'] ?? false);
                $quantity = (float) $input['quantity'];
                $this->assertReturnable($receiptLine, $quantity, $fromQuarantine, null);
                $invoiceLine = $invoice instanceof PurchaseInvoice
                    ? PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->getKey())
                        ->where('receipt_line_id', $receiptLine->getKey())->first()
                    : null;
                if ($invoice instanceof PurchaseInvoice && ! $invoiceLine instanceof PurchaseInvoiceLine) {
                    throw new DomainException(__('The return line was not invoiced by the selected supplier invoice.'));
                }
                if ($invoiceLine instanceof PurchaseInvoiceLine) {
                    $previouslyCreditedQuantity = (float) DB::table('purchase_return_lines')
                        ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
                        ->where('purchase_return_lines.purchase_invoice_line_id', $invoiceLine->getKey())
                        ->whereNotIn('purchase_returns.status', ['cancelled'])
                        ->sum('purchase_return_lines.quantity');
                    if ($previouslyCreditedQuantity + $quantity > (float) $invoiceLine->quantity + 0.00000001) {
                        throw new DomainException(__('Return quantity exceeds the quantity billed on the selected supplier invoice.'));
                    }
                }
                $orderLine = PurchaseOrderLine::query()->findOrFail($receiptLine->purchase_order_line_id);
                $unitPrice = $invoiceLine instanceof PurchaseInvoiceLine && (float) $invoiceLine->quantity > 0
                    ? (float) $invoiceLine->total_before_tax / (float) $invoiceLine->quantity
                    : (float) $orderLine->total_before_tax / max((float) $orderLine->ordered_quantity, 0.00000001);
                $taxPerUnit = $invoiceLine instanceof PurchaseInvoiceLine && (float) $invoiceLine->quantity > 0
                    ? (float) $invoiceLine->tax_amount / (float) $invoiceLine->quantity
                    : 0.0;
                $tax = $taxPerUnit * $quantity;
                $lineTotal = $unitPrice * $quantity + $tax;

                $return->lines()->create([
                    'purchase_order_line_id' => $orderLine->getKey(),
                    'receipt_line_id' => $receiptLine->getKey(),
                    'purchase_invoice_line_id' => $invoiceLine?->getKey(),
                    'product_id' => $receiptLine->product_id,
                    'unit_id' => $receiptLine->unit_id,
                    'quantity' => $this->quantity($quantity),
                    'from_quarantine' => $fromQuarantine,
                    'unit_price' => $this->amount($unitPrice),
                    'tax_amount' => $this->amount($tax),
                    'line_total' => $this->amount($lineTotal),
                    'reason' => $input['reason'] ?? null,
                ]);
                $return->receipt_id ??= $receiptLine->receipt_id;
                $totalQuantity += $quantity;
                $totalAmount += $lineTotal;
            }

            $return->forceFill([
                'total_quantity' => $this->quantity($totalQuantity),
                'total_amount' => $this->amount($totalAmount),
            ])->save();
            $this->audit->record($return, 'purchase_return.created', [
                'purchase_order_doc_num' => $order->doc_num,
                'line_count' => $return->lines()->count(),
            ]);

            return $return->refresh()->load(['supplier', 'purchaseOrder', 'receipt', 'purchaseInvoice', 'lines.product']);
        }, 3);
    }

    public function approvePurchaseReturn(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn): PurchaseReturn {
            $context = $this->context();
            $return = PurchaseReturn::query()->with(['lines.receiptLine', 'purchaseInvoice'])->lockForUpdate()->findOrFail($purchaseReturn->getKey());
            if ((int) $return->company_id !== $context['company_id'] || (int) $return->financial_period_id !== $context['financial_period_id']) {
                throw new DomainException(__('The purchase return is outside the active operating context.'));
            }
            if ($return->status !== 'draft') {
                throw new DomainException(__('This purchase return is locked.'));
            }
            $this->assertOpenFinancialPeriod((int) $return->financial_period_id);

            BranchStore::query()->lockForUpdate()->findOrFail($return->branch_store_id);
            foreach ($return->lines as $line) {
                $receiptLine = UnpricedInventoryReceiptLine::query()->lockForUpdate()->findOrFail($line->receipt_line_id);
                $this->assertReturnable($receiptLine, (float) $line->quantity, (bool) $line->from_quarantine, $return);
                if (! $line->from_quarantine) {
                    Product::query()->lockForUpdate()->findOrFail($line->product_id);
                    $available = (float) $this->availability->forProduct(
                        (int) $return->company_id,
                        (int) $return->branch_store_id,
                        (int) $line->product_id,
                    )['available'];
                    if ((float) $line->quantity > $available + 0.00000001) {
                        throw new DomainException(__('Return quantity exceeds currently available unreserved stock.'));
                    }

                    InventoryTransaction::query()->firstOrCreate([
                        'posting_key' => "purchase-return:{$line->getKey()}",
                    ], [
                        'company_id' => $return->company_id,
                        'financial_period_id' => $return->financial_period_id,
                        'branch_id' => $return->branch_id,
                        'branch_store_id' => $return->branch_store_id,
                        'product_id' => $line->product_id,
                        'unit_id' => $line->unit_id,
                        'transaction_date' => $return->return_date,
                        'transaction_type' => 'purchase_return',
                        'quantity_in' => 0,
                        'quantity_out' => $line->quantity,
                        'source_type' => PurchaseReturn::class,
                        'source_id' => $return->getKey(),
                        'source_doc_num' => $return->doc_num,
                        'source_line_type' => $line::class,
                        'source_line_id' => $line->getKey(),
                        'supplier_id' => $return->supplier_id,
                        'unit_cost' => $line->unit_price,
                        'total_cost' => bcmul((string) $line->quantity, (string) $line->unit_price, 8),
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            $journalEntry = null;
            if ($return->purchaseInvoice instanceof PurchaseInvoice && $return->purchaseInvoice->journal_entry_id !== null && (float) $return->total_amount > 0) {
                $journalEntry = $this->journalEntries->createPostedFromPurchaseReturn($return);
            }
            $return->forceFill([
                'status' => 'posted',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'journal_entry_id' => $journalEntry?->getKey(),
                'updated_by' => auth()->id(),
            ])->save();
            $return->purchaseInvoice?->refreshPaymentTotals();
            $this->audit->record($return, 'purchase_return.posted', [
                'journal_entry_id' => $journalEntry?->getKey(),
            ]);

            return $return->refresh()->load(['lines.product', 'purchaseInvoice', 'purchaseOrder']);
        }, 3);
    }

    public function reversePurchaseReturn(PurchaseReturn $purchaseReturn, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $reason): PurchaseReturn {
            $context = $this->context();
            $return = PurchaseReturn::query()
                ->with(['lines', 'journalEntry.lines', 'purchaseInvoice'])
                ->lockForUpdate()
                ->findOrFail($purchaseReturn->getKey());
            if ((int) $return->company_id !== $context['company_id'] || $return->status !== PurchaseReturn::StatusPosted) {
                throw new DomainException(__('Only a posted Purchase Return in the active company can be reversed.'));
            }

            $reversalJournal = $return->journalEntry
                ? $this->journalEntries->createPostedReversalFromSource($return->journalEntry, [
                    'entry_date' => now()->toDateString(),
                    'company_id' => (int) $return->company_id,
                    'financial_period_id' => $context['financial_period_id'],
                    'branch_id' => $return->branch_id,
                    'currency_id' => $return->purchaseInvoice?->currency_id,
                    'exchange_rate' => $return->purchaseInvoice?->exchange_rate ?? 1,
                    'description' => __('Purchase return reversal :document', ['document' => $return->doc_num]),
                    'notes' => $reason,
                    'source_type' => 'purchase_return_reversal',
                    'source_id' => $return->getKey(),
                    'source_doc_num' => $return->doc_num,
                ])
                : null;

            foreach ($return->lines as $line) {
                if ($line->from_quarantine) {
                    continue;
                }

                InventoryTransaction::query()->firstOrCreate([
                    'posting_key' => "purchase-return-reversal:{$line->getKey()}",
                ], [
                    'company_id' => $return->company_id,
                    'financial_period_id' => $context['financial_period_id'],
                    'branch_id' => $return->branch_id,
                    'branch_store_id' => $return->branch_store_id,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => 'purchase_return_reversal',
                    'quantity_in' => $line->quantity,
                    'quantity_out' => 0,
                    'source_type' => PurchaseReturn::class,
                    'source_id' => $return->getKey(),
                    'source_doc_num' => $return->doc_num,
                    'source_line_type' => $line::class,
                    'source_line_id' => $line->getKey(),
                    'supplier_id' => $return->supplier_id,
                    'unit_cost' => $line->unit_price,
                    'total_cost' => bcmul((string) $line->quantity, (string) $line->unit_price, 8),
                    'created_by' => auth()->id(),
                ]);
            }

            $return->forceFill([
                'status' => PurchaseReturn::StatusReversed,
                'reversal_journal_entry_id' => $reversalJournal?->getKey(),
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();
            $return->purchaseInvoice?->refreshPaymentTotals();
            $this->audit->record($return, 'purchase_return.reversed', [
                'reversal_journal_entry_id' => $reversalJournal?->getKey(),
                'reason' => $reason,
            ]);

            return $return->refresh()->load(['lines.product', 'purchaseInvoice', 'purchaseOrder']);
        }, 3);
    }

    public function createSupplierPayment(array $data): SupplierPaymentContext
    {
        return DB::transaction(function () use ($data): SupplierPaymentContext {
            $context = $this->context();
            $supplier = Supplier::query()->with('account')->active()->forCompany($context['company_id'])
                ->where('doc_num', $data['supplier_doc_num'])->firstOrFail();
            if ($supplier->account === null) {
                throw new DomainException(__('The supplier requires a payable account before payment.'));
            }
            $amount = (float) $data['amount'];
            $allocationTotal = collect($data['allocations'] ?? [])->sum(fn (array $allocation): float => (float) $allocation['amount']);
            $isAdvance = (bool) ($data['is_advance'] ?? false);
            if ($amount <= 0 || $allocationTotal > $amount + 0.0001 || (! $isAdvance && abs($allocationTotal - $amount) > 0.0001)) {
                throw new DomainException(__('Payment allocations must reconcile to the payment amount.'));
            }
            $method = (string) ($data['payment_method'] ?? SupplierPaymentContext::MethodCash);
            $currency = Currency::query()->active()->forCompany($context['company_id'])
                ->where('doc_num', $data['currency_doc_num'])->firstOrFail();
            $bankAccount = in_array($method, [SupplierPaymentContext::MethodBank, SupplierPaymentContext::MethodCheque], true)
                ? BankAccount::query()->with(['account', 'bank', 'currency'])->active()->forCompany($context['company_id'])
                    ->where('doc_num', $data['bank_account_doc_num'])->firstOrFail()
                : null;
            if ($bankAccount instanceof BankAccount && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
                throw new DomainException(__('The payment currency must match the selected Bank Account currency.'));
            }

            $voucher = $method === SupplierPaymentContext::MethodCash
                ? $this->cashVouchers->create(CashVoucher::TypePayment, [
                    'voucher_date' => $data['payment_date'],
                    'cashbox_doc_num' => $data['cashbox_doc_num'],
                    'currency_doc_num' => $data['currency_doc_num'],
                    'exchange_rate' => $data['exchange_rate'] ?? 1,
                    'amount' => $this->amount($amount),
                    'person_name' => $supplier->name,
                    'person_national_id' => null,
                    'person_phone' => $supplier->mobile ?: $supplier->phone,
                    'reason' => $data['reason'] ?? __('Supplier payment'),
                    'description' => $data['notes'] ?? null,
                    'lines' => [[
                        'account_doc_num' => $supplier->account->doc_num,
                        'amount' => $this->amount($amount),
                        'description' => $data['reason'] ?? __('Supplier payment'),
                        'notes' => $data['notes'] ?? null,
                    ]],
                ])['record']
                : null;
            $cheque = $method === SupplierPaymentContext::MethodCheque
                ? $this->cheques->create([
                    'cheque_type' => Cheque::TypeIssued,
                    'cheque_number' => $data['cheque_number'],
                    'cheque_date' => $data['cheque_date'],
                    'due_date' => $data['cheque_due_date'],
                    'bank_account_doc_num' => $bankAccount?->doc_num,
                    'external_bank_name' => null,
                    'external_bank_branch' => null,
                    'party_type' => Supplier::class,
                    'party_id' => $supplier->getKey(),
                    'party_name' => $supplier->name,
                    'currency_doc_num' => $currency->doc_num,
                    'exchange_rate' => $data['exchange_rate'] ?? 1,
                    'amount' => $this->amount($amount),
                    'reason' => $data['reason'] ?? __('Supplier payment'),
                    'description' => $data['notes'] ?? null,
                    'lines' => [[
                        'account_doc_num' => $supplier->account->doc_num,
                        'amount' => $this->amount($amount),
                        'description' => $data['reason'] ?? __('Supplier payment'),
                        'notes' => $data['notes'] ?? null,
                    ]],
                ])['record']
                : null;
            $order = filled($data['purchase_order_doc_num'] ?? null)
                ? PurchaseOrder::query()->forCompany($context['company_id'])->where('doc_num', $data['purchase_order_doc_num'])->firstOrFail()
                : null;
            $payment = SupplierPaymentContext::query()->create([
                ...$this->number('supplier_payments', SupplierPaymentContext::class, $context),
                'cash_voucher_id' => $voucher?->getKey(),
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'supplier_id' => $supplier->getKey(),
                'purchase_order_id' => $order?->getKey(),
                'payment_method' => $method,
                'payment_date' => $data['payment_date'],
                'amount' => $this->amount($amount),
                'currency_id' => $currency->getKey(),
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'bank_account_id' => $bankAccount?->getKey(),
                'cheque_id' => $cheque?->getKey(),
                'status' => SupplierPaymentContext::StatusDraft,
                'is_advance' => $isAdvance,
                'allocated_amount' => 0,
                'reason' => $data['reason'] ?? __('Supplier payment'),
                'notes' => $data['notes'] ?? null,
            ]);
            $this->allocatePayment($payment, $data['allocations'] ?? []);
            $this->audit->record($payment, 'supplier_payment.created', [
                'payment_method' => $method,
                'cash_voucher_doc_num' => $voucher?->doc_num,
                'cheque_doc_num' => $cheque?->doc_num,
                'is_advance' => $isAdvance,
            ]);

            return $payment->refresh()->load(['cashVoucher', 'bankAccount.bank', 'cheque', 'currency', 'supplier', 'purchaseOrder', 'allocations.purchaseInvoice']);
        }, 3);
    }

    public function allocatePayment(SupplierPaymentContext $payment, array $allocations): SupplierPaymentContext
    {
        return DB::transaction(function () use ($allocations, $payment): SupplierPaymentContext {
            $locked = SupplierPaymentContext::query()->with(['cashVoucher', 'bankAccount', 'cheque'])->lockForUpdate()->findOrFail($payment->getKey());
            if ($locked->isCancelled()) {
                throw new DomainException(__('A cancelled supplier payment cannot be allocated.'));
            }

            foreach ($allocations as $input) {
                $invoice = PurchaseInvoice::query()->lockForUpdate()
                    ->where('company_id', $locked->company_id)
                    ->where('supplier_id', $locked->supplier_id)
                    ->where('doc_num', $input['purchase_invoice_doc_num'])
                    ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
                    ->firstOrFail();
                $schedule = filled($input['payment_schedule_public_id'] ?? null)
                    ? PurchaseInvoicePaymentSchedule::query()->lockForUpdate()
                        ->where('purchase_invoice_id', $invoice->getKey())
                        ->where('public_id', $input['payment_schedule_public_id'])->firstOrFail()
                    : null;
                if ($schedule instanceof PurchaseInvoicePaymentSchedule && $schedule->status === PurchaseInvoicePaymentSchedule::StatusCancelled) {
                    throw new DomainException(__('A cancelled payment installment cannot receive an allocation.'));
                }
                $amount = (float) $input['amount'];
                $invoiceAllocated = (float) SupplierPaymentAllocation::query()
                    ->where('purchase_invoice_id', $invoice->getKey())
                    ->whereHas('paymentContext', fn ($query) => $query->where('status', '<>', SupplierPaymentContext::StatusCancelled))
                    ->sum('amount');
                $invoiceCredits = (float) PurchaseReturn::query()
                    ->where('purchase_invoice_id', $invoice->getKey())
                    ->where('status', 'posted')
                    ->sum('total_amount');
                if ($amount <= 0 || $invoiceCredits + $invoiceAllocated + $amount > (float) $invoice->total_amount + 0.0001) {
                    throw new DomainException(__('Payment allocation exceeds the supplier invoice outstanding amount.'));
                }
                if ($schedule instanceof PurchaseInvoicePaymentSchedule) {
                    $scheduleAllocated = (float) SupplierPaymentAllocation::query()
                        ->where('payment_schedule_id', $schedule->getKey())
                        ->whereHas('paymentContext', fn ($query) => $query->where('status', '<>', SupplierPaymentContext::StatusCancelled))
                        ->sum('amount');
                    if ((float) $schedule->credited_amount + $scheduleAllocated + $amount > (float) $schedule->amount + 0.0001) {
                        throw new DomainException(__('Payment allocation exceeds the installment outstanding amount.'));
                    }
                }

                SupplierPaymentAllocation::query()->create([
                    'supplier_payment_context_id' => $locked->getKey(),
                    'purchase_invoice_id' => $invoice->getKey(),
                    'payment_schedule_id' => $schedule?->getKey(),
                    'amount' => $this->amount($amount),
                    'allocated_by' => auth()->id(),
                    'allocated_at' => now(),
                ]);
            }

            $allocated = (float) $locked->allocations()->sum('amount');
            if ($allocated > (float) $locked->amount + 0.0001) {
                throw new DomainException(__('Payment allocations exceed the voucher amount.'));
            }
            $locked->forceFill(['allocated_amount' => $this->amount($allocated)])->save();
            if ($locked->isApproved()) {
                $this->refreshPaymentAllocationTotals($locked);
            }
            if ($allocations !== []) {
                $this->audit->record($locked, 'supplier_payment.allocated', [
                    'allocation_count' => count($allocations),
                ]);
            }

            return $locked->refresh()->load(['cashVoucher', 'bankAccount.bank', 'cheque', 'allocations.purchaseInvoice', 'allocations.paymentSchedule']);
        }, 3);
    }

    public function approveSupplierPayment(SupplierPaymentContext $payment): SupplierPaymentContext
    {
        return DB::transaction(function () use ($payment): SupplierPaymentContext {
            $locked = SupplierPaymentContext::query()
                ->with(['cashVoucher', 'bankAccount.account', 'cheque', 'allocations.purchaseInvoice', 'allocations.paymentSchedule'])
                ->lockForUpdate()
                ->findOrFail($payment->getKey());
            if (! $locked->isDraft()) {
                throw new DomainException(__('A cancelled supplier payment cannot be approved.'));
            }
            $this->assertOpenFinancialPeriod((int) $locked->financial_period_id);
            if (! $locked->is_advance && abs((float) $locked->allocated_amount - (float) $locked->amount) > 0.0001) {
                throw new DomainException(__('A non-advance supplier payment must be fully allocated before approval.'));
            }

            match ($locked->payment_method) {
                SupplierPaymentContext::MethodCash => $this->approveCashPayment($locked),
                SupplierPaymentContext::MethodBank => $this->approveBankPayment($locked),
                SupplierPaymentContext::MethodCheque => $this->approveChequePayment($locked),
                default => throw new DomainException(__('Unsupported Supplier payment method.')),
            };
            $this->refreshPaymentAllocationTotals($locked);
            $this->audit->record($locked, 'supplier_payment.approved', [
                'payment_method' => $locked->payment_method,
                'cash_voucher_doc_num' => $locked->cashVoucher?->doc_num,
                'cheque_doc_num' => $locked->cheque?->doc_num,
            ]);

            return $locked->refresh()->load(['cashVoucher', 'bankAccount.bank', 'cheque', 'allocations.purchaseInvoice', 'allocations.paymentSchedule']);
        }, 3);
    }

    public function cancelSupplierPayment(SupplierPaymentContext $payment, string $reason): SupplierPaymentContext
    {
        return DB::transaction(function () use ($payment, $reason): SupplierPaymentContext {
            $locked = SupplierPaymentContext::query()
                ->with(['cashVoucher', 'bankAccount.account', 'cheque', 'journalEntry'])
                ->lockForUpdate()
                ->findOrFail($payment->getKey());

            if ($locked->isCancelled()) {
                return $locked;
            }

            if (! $locked->isApproved()) {
                throw new DomainException(__('Only an approved Supplier payment can be reversed.'));
            }

            match ($locked->payment_method) {
                SupplierPaymentContext::MethodCash => $this->cancelCashPayment($locked, $reason),
                SupplierPaymentContext::MethodBank => $this->supplierPaymentPostings->reverse($locked, $reason),
                SupplierPaymentContext::MethodCheque => $this->cancelChequePayment($locked, $reason),
                default => throw new DomainException(__('Unsupported Supplier payment method.')),
            };
            $this->refreshPaymentAllocationTotals($locked);
            $this->audit->record($locked, 'supplier_payment.reversed', ['reason' => $reason]);

            return $locked->refresh()->load(['cashVoucher', 'bankAccount.bank', 'cheque', 'allocations.purchaseInvoice', 'allocations.paymentSchedule']);
        }, 3);
    }

    private function approveCashPayment(SupplierPaymentContext $payment): void
    {
        if (! $payment->cashVoucher instanceof CashVoucher || $payment->cashVoucher->trashed()) {
            throw new DomainException(__('The canonical Cash Payment Voucher is missing.'));
        }

        $this->cashVouchers->approve(CashVoucher::TypePayment, $payment->cashVoucher);
    }

    private function assertOpenFinancialPeriod(int $financialPeriodId): void
    {
        $isOpen = FinancialPeriod::query()
            ->whereKey($financialPeriodId)
            ->where('is_closed', false)
            ->exists();

        if (! $isOpen) {
            throw new DomainException(__('purchase_invoices.messages.period_closed'));
        }
    }

    private function approveBankPayment(SupplierPaymentContext $payment): void
    {
        $bankAccount = $payment->bankAccount;
        $account = $bankAccount?->account;
        if (! $bankAccount instanceof BankAccount || ! $account) {
            throw new DomainException(__('The selected Bank Account requires a postable GL account.'));
        }

        $this->supplierPaymentPostings->post($payment, $account, (int) $bankAccount->getKey());
    }

    private function approveChequePayment(SupplierPaymentContext $payment): void
    {
        if (! $payment->cheque instanceof Cheque) {
            throw new DomainException(__('The canonical issued Cheque is missing.'));
        }

        $this->cheques->markIssued($payment->cheque);
    }

    private function cancelCashPayment(SupplierPaymentContext $payment, string $reason): void
    {
        if (! $payment->cashVoucher instanceof CashVoucher) {
            throw new DomainException(__('The canonical Cash Payment Voucher is missing.'));
        }

        $this->cashVouchers->cancel(CashVoucher::TypePayment, $payment->cashVoucher, $reason);
    }

    private function cancelChequePayment(SupplierPaymentContext $payment, string $reason): void
    {
        if (! $payment->cheque instanceof Cheque) {
            throw new DomainException(__('The canonical issued Cheque is missing.'));
        }

        $this->cheques->cancel($payment->cheque, $reason);
    }

    private function refreshPaymentAllocationTotals(SupplierPaymentContext $payment): void
    {
        $invoiceIds = $payment->allocations()->pluck('purchase_invoice_id')->unique();
        PurchaseInvoice::query()->whereKey($invoiceIds->all())->get()
            ->each(fn (PurchaseInvoice $invoice) => $invoice->refreshPaymentTotals());
    }

    private function assertReturnable(UnpricedInventoryReceiptLine $receiptLine, float $quantity, bool $fromQuarantine, ?PurchaseReturn $excluding): void
    {
        if ($quantity <= 0) {
            throw new DomainException(__('Return quantity must be greater than zero.'));
        }
        $previouslyReturned = (float) DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
            ->where('purchase_return_lines.receipt_line_id', $receiptLine->getKey())
            ->where('purchase_return_lines.from_quarantine', $fromQuarantine)
            ->whereNotIn('purchase_returns.status', ['cancelled', PurchaseReturn::StatusReversed])
            ->when($excluding, fn ($query) => $query->where('purchase_returns.id', '<>', $excluding->getKey()))
            ->sum('purchase_return_lines.quantity');
        $available = (float) ($fromQuarantine ? $receiptLine->rejected_quantity : $receiptLine->accepted_quantity) - $previouslyReturned;
        if ($quantity > $available + 0.00000001) {
            throw new DomainException(__('Return quantity exceeds the material received and still returnable.'));
        }
    }

    private function assertChangedQuantityWithinSource(PurchaseOrderLine $line, float $quantity): void
    {
        if ($line->supplier_selection_line_id !== null) {
            $selectedQuantity = (float) DB::table('supplier_selection_lines')
                ->where('id', $line->supplier_selection_line_id)
                ->value('selected_quantity');
            if ($quantity > $selectedQuantity + 0.00000001) {
                throw new DomainException(__('Changed quantity exceeds the approved supplier award.'));
            }
        }

        if ($line->purchase_requisition_line_id === null) {
            return;
        }

        $approvedQuantity = (float) DB::table('purchase_requisition_lines')
            ->where('id', $line->purchase_requisition_line_id)
            ->lockForUpdate()
            ->value('approved_quantity');
        $otherCommitted = (float) PurchaseOrderLine::query()
            ->where('purchase_requisition_line_id', $line->purchase_requisition_line_id)
            ->whereKeyNot($line->getKey())
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', [PurchaseOrder::StatusCancelled]))
            ->sum('ordered_quantity');

        if ($otherCommitted + $quantity > $approvedQuantity + 0.00000001) {
            throw new DomainException(__('Changed quantity exceeds the approved purchase requirement.'));
        }
    }

    private function normalizeChangeValues(PurchaseOrder $order, array $values): array
    {
        $allowed = [
            'expected_delivery_date' => $values['expected_delivery_date'] ?? $order->expected_delivery_date?->toDateString(),
            'payment_terms' => $values['payment_terms'] ?? $order->payment_terms,
            'notes' => $values['notes'] ?? $order->notes,
            'lines' => collect($values['lines'] ?? [])->map(fn (array $line): array => [
                'public_id' => $line['public_id'],
                'ordered_quantity' => $line['ordered_quantity'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'required_delivery_date' => $line['required_delivery_date'] ?? null,
            ])->values()->all(),
        ];

        return $allowed;
    }

    private function currentChangeValues(PurchaseOrder $order): array
    {
        return [
            'supplier_id' => $order->supplier_id,
            'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
            'payment_terms' => $order->payment_terms,
            'notes' => $order->notes,
            'lines' => $order->lines->map(fn (PurchaseOrderLine $line): array => [
                'public_id' => $line->public_id,
                'ordered_quantity' => $line->ordered_quantity,
                'unit_price' => $line->unit_price,
                'required_delivery_date' => $line->required_delivery_date?->toDateString(),
            ])->all(),
        ];
    }

    private function assertOrderContext(PurchaseOrder $order, array $context): void
    {
        if ((int) $order->company_id !== $context['company_id']
            || (int) $order->financial_period_id !== $context['financial_period_id']
            || (int) $order->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The purchase order is outside the active operating context.'));
        }
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function context(): array
    {
        $snapshot = $this->operatingContext->snapshot(request());
        if (! $snapshot['company_id'] || ! $snapshot['financial_period_id'] || ! $snapshot['branch_id']) {
            throw new DomainException(__('An operating company, branch, and financial period are required.'));
        }

        return [
            'company_id' => (int) $snapshot['company_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
            'branch_id' => (int) $snapshot['branch_id'],
        ];
    }

    /** @param class-string<Model> $model */
    private function number(string $key, string $model, array $context): array
    {
        return $this->documents->nextForCompany(
            $key,
            $model,
            $context['company_id'],
            fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
        );
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
