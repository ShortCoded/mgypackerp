<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Models\ChequeClearingEvent;
use Modules\Finance\Services\CashVoucherService;
use Modules\Finance\Services\ChequeCollectionCorrectionService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Services\ProductionCancellationOwnerService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplierSelectionLine;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerWithholdingSettlementService;
use Modules\Sales\Services\SalesRequestService;
use Modules\Sales\Services\SalesReturnService;

class DocumentOwnerEffectProofService
{
    public function cancelledRequisitionIsSettled(PurchaseRequisition $request): bool
    {
        return ! $request->trashed() && $request->status === PurchaseRequisition::StatusCancelled
            && $request->cancelled_at !== null && $request->cancelled_by !== null && filled($request->cancel_reason)
            && $this->hasAudit($request, 'purchase_requisition.cancelled')
            && ! $this->requisitionHasUnsettledEffects($request);
    }

    public function requisitionHasUnsettledEffects(PurchaseRequisition $request): bool
    {
        $lineIds = $request->lines()->select('id');
        $orders = PurchaseOrder::withTrashed()->where(function ($query) use ($request, $lineIds): void {
            $query->where('purchase_requisition_id', $request->id)->orWhereIn('id', DB::table('purchase_order_lines')
                ->whereIn('purchase_requisition_line_id', $lineIds)->select('purchase_order_id'));
        });
        foreach ($orders->lazyById() as $order) {
            if (! $this->sameOwner($request, $order) || ! $this->cancelledPurchaseOrderIsSettled($order)) {
                return true;
            }
        }
        foreach ([RequestForQuotation::class, SupplierQuotation::class] as $model) {
            foreach ($model::withTrashed()->where('purchase_requisition_id', $request->id)->lazyById() as $source) {
                if (! $this->sameOwner($request, $source) || ! $this->sourcingIsSettled($source)) {
                    return true;
                }
            }
        }
        foreach (ProductionMaterialRequest::withTrashed()->where('purchase_requisition_id', $request->id)->lazyById() as $source) {
            if (! $this->sameOwner($request, $source) || ! DB::table('activity_log')->where('company_id', $request->company_id)
                ->where('subject_type', $source::class)->where('subject_id', $source->id)
                ->where('event', 'production_material_request.purchase_requisition_created')
                ->where('properties->purchase_requisition', $request->doc_num)->exists()) {
                return true;
            }
        }
        foreach ($request->lines()->lazyById() as $line) {
            if (bccomp((string) $line->orderedQuantity(true), '0', 8) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function sourcingIsSettled(RequestForQuotation|SupplierQuotation|SupplierSelection $source): bool
    {
        if ($source->trashed() || $source->status !== 'cancelled' || ! $this->hasAudit($source, 'sourcing_document.cancelled')) {
            return false;
        }

        return ! $this->sourcingHasUnsettledEffects($source);
    }

    public function sourcingHasUnsettledEffects(RequestForQuotation|SupplierQuotation|SupplierSelection $source): bool
    {
        if ($source instanceof RequestForQuotation) {
            foreach ([$source->quotations()->withTrashed(), $source->supplierSelections()->withTrashed()] as $children) {
                foreach ($children->lazyById() as $child) {
                    if (! $this->sameOwner($source, $child) || ! $this->sourcingIsSettled($child)) {
                        return true;
                    }
                }
            }
        } elseif ($source instanceof SupplierQuotation) {
            $selectionIds = SupplierSelectionLine::query()
                ->whereHas('quotationLine', fn ($query) => $query->where('supplier_quotation_id', $source->id))->select('supplier_selection_id');
            foreach (SupplierSelection::withTrashed()->whereIn('id', $selectionIds)->lazyById() as $child) {
                if (! $this->sameOwner($source, $child) || ! $this->sourcingIsSettled($child)) {
                    return true;
                }
            }
        }
        if ($source instanceof SupplierSelection) {
            foreach ($source->lines()->whereNotNull('purchase_order_id')->lazyById() as $line) {
                $order = PurchaseOrder::withTrashed()->find($line->purchase_order_id);
                if ($order === null || (int) $order->supplier_selection_id !== (int) $source->id
                    || ! $this->sameOwner($source, $order) || ! $this->cancelledPurchaseOrderIsSettled($order)) {
                    return true;
                }
            }
        }
        $key = $source instanceof SupplierSelection ? 'supplier_selection_id' : 'supplier_quotation_id';
        if (! $source instanceof RequestForQuotation) {
            foreach (PurchaseOrder::withTrashed()->where($key, $source->id)->lazyById() as $order) {
                if (! $this->sameOwner($source, $order) || ! $this->cancelledPurchaseOrderIsSettled($order)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function supplyOrderHasUnsettledEffects(SupplyOrder $order): bool
    {
        foreach ($order->receipts()->withTrashed()->lazyById() as $receipt) {
            if (! $this->sameOwner($order, $receipt) || ! $this->receiptIsSettled($receipt)) {
                return true;
            }
        }

        return false;
    }

    public function cancelledPurchaseOrderIsSettled(PurchaseOrder $order): bool
    {
        return ! $order->trashed() && $order->isCancelled() && $order->cancelled_at !== null
            && $order->cancelled_by !== null && filled($order->cancel_reason)
            && $this->hasAudit($order, 'purchase_order.cancelled')
            && ! $this->purchaseOrderHasUnsettledEffects($order);
    }

    public function purchaseInvoiceIsSettled(PurchaseInvoice $invoice): bool
    {
        if ($invoice->trashed() || ! $invoice->isCancelled() || $invoice->cancelled_at === null
            || $invoice->cancelled_by === null || blank($invoice->cancel_reason)) {
            return false;
        }
        foreach (['paid_amount', 'credited_amount', 'remaining_amount'] as $field) {
            if (bccomp((string) $invoice->{$field}, '0', 4) !== 0) {
                return false;
            }
        }
        if ($invoice->lines()->where(function ($query): void {
            $query->where('grni_cleared_quantity', '!=', 0)->orWhere('grni_cleared_value', '!=', 0)->orWhere('purchase_price_variance', '!=', 0);
        })->exists()) {
            return false;
        }
        if (! $this->purchaseAssetEffectsAreSettled($invoice)) {
            return false;
        }
        foreach ($invoice->paymentAllocations()->with('paymentContext')->lazyById() as $allocation) {
            if ($allocation->paymentContext === null || ! $this->sameOwner($invoice, $allocation->paymentContext)
                || ! $this->supplierPaymentIsSettled($allocation->paymentContext)) {
                return false;
            }
        }
        foreach ($invoice->purchaseReturns()->withTrashed()->lazyById() as $return) {
            if (! $this->sameOwner($invoice, $return) || ! $this->purchaseReturnIsSettled($return)) {
                return false;
            }
        }
        if ($invoice->paymentSchedules()->where(function ($query): void {
            $query->where('paid_amount', '!=', 0)->orWhere('credited_amount', '!=', 0);
        })->exists()) {
            return false;
        }
        if ($invoice->journal_entry_id === null) {
            return $invoice->approved_at === null && $invoice->reversal_journal_entry_id === null
                && $this->hasAudit($invoice, 'purchase_invoice.cancelled')
                && ! JournalEntry::query()->where('company_id', $invoice->company_id)
                    ->where('source_type', 'purchase_invoice')->where('source_id', $invoice->id)->exists();
        }
        $source = JournalEntry::query()->find($invoice->journal_entry_id);

        return $invoice->reversed_at !== null && $invoice->reversed_by !== null && filled($invoice->reversal_reason)
            && $this->hasAudit($invoice, 'purchase_invoice.reversed') && $source !== null
            && (int) $source->branch_id === (int) $invoice->branch_id && (int) $source->financial_period_id === (int) $invoice->financial_period_id
            && (int) $source->currency_id === (int) $invoice->currency_id
            && (int) $source->reversed_entry_id === (int) $invoice->reversal_journal_entry_id
            && $this->journalHasExactInverse((int) $invoice->journal_entry_id, 'purchase_invoice', (int) $invoice->id, 'purchase_invoice_reversal', (int) $invoice->company_id);
    }

    public function supplierPaymentIsSettled(SupplierPaymentContext $payment): bool
    {
        if (! $payment->isCancelled() || $payment->cancelled_at === null
            || $payment->cancelled_by === null || blank($payment->cancel_reason)
            || ($payment->cheque_id !== null && ! $this->settledCheque($payment, Cheque::TypeIssued, 'supplier', (int) $payment->supplier_id))) {
            return false;
        }
        if ($payment->journal_entry_id === null) {
            return $payment->approved_at === null && $this->hasAudit($payment, 'supplier_payment.draft_cancelled');
        }
        if (! $this->hasAudit($payment, 'supplier_payment.reversed')) {
            return false;
        }
        $source = JournalEntry::query()->find($payment->journal_entry_id);
        if ($source === null || (int) $source->branch_id !== (int) $payment->branch_id
            || (int) $source->currency_id !== (int) $payment->currency_id) {
            return false;
        }
        if ($payment->cash_voucher_id !== null) {
            $voucher = CashVoucher::withTrashed()->find($payment->cash_voucher_id);
            if ($voucher === null || $voucher->trashed() || ! $voucher->isCancelled() || ! $this->sameOwner($payment, $voucher)
                || (int) $voucher->journal_entry_id !== (int) $source->id) {
                return false;
            }
        }
        $voucherSource = $source->source_type === CashVoucherService::SourcePayment;

        return $this->journalHasExactInverse((int) $source->id,
            $voucherSource ? CashVoucherService::SourcePayment : 'supplier_payment',
            $voucherSource ? (int) $payment->cash_voucher_id : (int) $payment->id,
            $voucherSource ? CashVoucherService::SourcePaymentReversal : 'supplier_payment_reversal', (int) $payment->company_id);
    }

    public function purchaseReturnIsSettled(PurchaseReturn $return): bool
    {
        if ($return->trashed() || ! in_array($return->status, ['cancelled', 'reversed'], true)) {
            return false;
        }
        $originals = InventoryTransaction::query()->where('source_type', $return::class)->where('source_id', $return->id)->where('is_reversal', false);
        if ($return->status === 'cancelled') {
            return $return->cancelled_at !== null && $return->cancelled_by !== null && filled($return->cancel_reason)
                && $this->hasAudit($return, 'purchase_return.cancelled') && ! $originals->exists()
                && $return->journal_entry_id === null && $return->grni_reversal_journal_entry_id === null && $return->posted_at === null;
        }
        if ($return->reversed_at === null || $return->reversed_by === null || blank($return->reversal_reason)
            || ! $this->hasAudit($return, 'purchase_return.reversed')) {
            return false;
        }
        foreach (['journal_entry_id' => ['purchase_return', 'purchase_return_reversal'],
            'grni_reversal_journal_entry_id' => ['grni_purchase_return', 'grni_purchase_return_reversal']] as $field => [$sourceType, $inverseType]) {
            if ($return->{$field} !== null && ! $this->journalHasExactInverse((int) $return->{$field}, $sourceType, (int) $return->id, $inverseType, (int) $return->company_id)) {
                return false;
            }
        }
        if ($return->journal_entry_id === null && $return->grni_reversal_journal_entry_id === null && $originals->exists()) {
            return false;
        }
        $expected = 0;
        foreach ($return->lines()->with('receiptLine.purchaseOrderLine')->lazyById() as $line) {
            if ($line->from_quarantine) {
                $orderLine = $line->receiptLine?->purchaseOrderLine;
                if ($orderLine === null || (int) $orderLine->purchase_order_id !== (int) $return->purchase_order_id
                    || (int) $line->receiptLine->product_id !== (int) $line->product_id
                    || (clone $originals)->where('source_line_type', $line::class)->where('source_line_id', $line->id)->exists()) {
                    return false;
                }

                continue;
            }
            $source = (clone $originals)->where('source_line_type', $line::class)->where('source_line_id', $line->id)->first();
            $orderLine = $line->receiptLine?->purchaseOrderLine;
            if ($source === null || $orderLine === null || (int) $orderLine->purchase_order_id !== (int) $return->purchase_order_id
                || (int) $source->company_id !== (int) $return->company_id || (int) $source->branch_id !== (int) $return->branch_id
                || (int) $source->product_id !== (int) $line->product_id
                || bccomp((string) $source->quantity_out, bcmul((string) $line->quantity, (string) $orderLine->stockConversionFactor(), 8), 8) !== 0
                || ! $this->stockHasExactInverse($source)) {
                return false;
            }
            $expected++;
        }

        return $expected === $originals->count();
    }

    public function purchaseOrderHasUnsettledEffects(PurchaseOrder $order): bool
    {
        if ($order->lines()->where(function ($query): void {
            $query->where('received_quantity', '!=', 0)->orWhereColumn('remaining_quantity', '<>', 'ordered_quantity');
        })->exists()
            || DB::table('purchase_order_change_requests')->where('purchase_order_id', $order->id)->where('status', 'pending')->exists()) {
            return true;
        }
        foreach ([PurchaseInvoice::class => 'purchaseInvoiceIsSettled',
            PurchaseReturn::class => 'purchaseReturnIsSettled',
            SupplierPaymentContext::class => 'supplierPaymentIsSettled'] as $model => $proof) {
            foreach ($this->ownerQuery($model)->where('purchase_order_id', $order->id)->lazyById() as $document) {
                if (! $this->sameOwner($order, $document) || ! $this->{$proof}($document)) {
                    return true;
                }
            }
        }
        foreach (SupplyOrder::withTrashed()->where('purchase_order_id', $order->id)->lazyById() as $supply) {
            if (! $this->sameOwner($order, $supply) || $supply->trashed() || $supply->status !== 'cancelled'
                || $supply->cancelled_at === null || $supply->cancelled_by === null || blank($supply->cancel_reason)
                || ! $this->hasAudit($supply, 'supply_order.cancelled') || $this->supplyOrderHasUnsettledEffects($supply)) {
                return true;
            }
        }
        foreach (UnpricedInventoryReceipt::withTrashed()->where('purchase_order_id', $order->id)->lazyById() as $receipt) {
            if (! $this->sameOwner($order, $receipt) || ! $this->receiptIsSettled($receipt)) {
                return true;
            }
        }
        foreach (GoodsReceiptInspection::withTrashed()->where('purchase_order_id', $order->id)->lazyById() as $inspection) {
            $receipt = UnpricedInventoryReceipt::withTrashed()->find($inspection->receipt_id);
            if (! $this->sameOwner($order, $inspection) || $inspection->trashed() || $receipt === null
                || ! $this->sameOwner($order, $receipt) || (int) $receipt->purchase_order_id !== (int) $order->id || ! $this->receiptIsSettled($receipt)) {
                return true;
            }
        }
        foreach (PurchaseOrderDeliverySchedule::withTrashed()->where('purchase_order_id', $order->id)->lazyById() as $schedule) {
            if ($schedule->trashed() || ! $this->sameOwner($order, $schedule) || bccomp((string) $schedule->received_quantity, '0', 8) !== 0) {
                return true;
            }
        }

        return false;
    }

    public function receiptIsSettled(UnpricedInventoryReceipt $receipt): bool
    {
        $originals = InventoryTransaction::query()->where('source_type', $receipt::class)->where('source_id', $receipt->id)->where('is_reversal', false);
        if ($receipt->trashed() || $receipt->approved || ! in_array($receipt->status, ['cancelled', 'reversed'], true)
            || $receipt->posting_status !== $receipt->status || $receipt->lines()->withTrashed()->whereNotNull('deleted_at')->exists()) {
            return false;
        }
        $lineIds = $receipt->lines()->select('id');
        foreach ([PurchaseInvoice::class => ['purchase_invoice_lines', 'purchase_invoice_id', 'purchaseInvoiceIsSettled'],
            PurchaseReturn::class => ['purchase_return_lines', 'purchase_return_id', 'purchaseReturnIsSettled']] as $model => [$table, $key, $proof]) {
            foreach ($model::withTrashed()->whereIn('id', DB::table($table)->whereIn('receipt_line_id', $lineIds)->select($key))->lazyById() as $document) {
                if (! $this->sameOwner($receipt, $document) || ! $this->{$proof}($document)) {
                    return false;
                }
            }
        }
        if ($receipt->lines()->where(function ($query): void {
            foreach (['grni_cleared_quantity', 'grni_cleared_value', 'grni_returned_quantity', 'grni_returned_value'] as $field) {
                $query->orWhere($field, '!=', 0);
            }
        })->exists()) {
            return false;
        }
        if ($receipt->status === 'cancelled') {
            return $receipt->cancelled_at !== null && $receipt->cancelled_by !== null && filled($receipt->cancel_reason)
                && $this->hasAudit($receipt, 'goods_receipt.cancelled_before_quality') && $receipt->grni_journal_entry_id === null
                && ! $receipt->inspection()->withTrashed()->exists() && ! $originals->exists()
                && ! $receipt->lines()->where(function ($query): void {
                    $query->whereNotNull('grni_journal_entry_id')->orWhere('inventory_posted_quantity', '!=', 0);
                })->exists();
        }
        if ($receipt->reversed_at === null || $receipt->reversed_by === null || blank($receipt->reversal_reason)
            || ! $this->hasAudit($receipt, 'goods_receipt.reversed')) {
            return false;
        }
        $expectedCount = 0;
        foreach ($receipt->lines()->with(['product', 'purchaseOrderLine'])->lazyById() as $line) {
            $source = $line->purchaseOrderLine;
            if ($source === null || (int) $source->purchase_order_id !== (int) $receipt->purchase_order_id
                || (int) $source->company_id !== (int) $receipt->company_id || $line->product === null
                || (int) $source->product_id !== (int) $line->product_id || (int) $source->unit_id !== (int) $line->unit_id) {
                return false;
            }
            if (! $line->product->cost_as_inventory || bccomp((string) $line->accepted_quantity, '0', 8) === 0) {
                if ($line->grni_journal_entry_id !== null) {
                    return false;
                }

                continue;
            }
            $expectedCount++;
            $movement = (clone $originals)->where('posting_key', 'purchase-receipt:'.$line->id)->first();
            $quantity = bcmul((string) $line->accepted_quantity, (string) $source->stockConversionFactor(), 8);
            $journal = JournalEntry::query()->find($line->grni_journal_entry_id);
            $order = $source->purchaseOrder;
            if ($journal === null || $order === null || (int) $journal->branch_id !== (int) $receipt->branch_id
                || (int) $journal->financial_period_id !== (int) $receipt->financial_period_id
                || (int) $journal->currency_id !== (int) $order->currency_id
                || bccomp((string) $journal->exchange_rate, (string) $order->exchange_rate, 6) !== 0
                || bccomp(bcmul((string) $journal->lines()->sum('debit_amount'), (string) $journal->exchange_rate, 4), (string) $line->provisional_total_value, 4) !== 0) {
                return false;
            }
            if ($movement === null || $movement->source_line_type !== $line::class || (int) $movement->source_line_id !== (int) $line->id
                || (int) $movement->company_id !== (int) $receipt->company_id || (int) $movement->branch_id !== (int) $receipt->branch_id
                || (int) $movement->branch_store_id !== (int) $receipt->branch_store_id
                || (int) $movement->financial_period_id !== (int) $receipt->financial_period_id
                || (int) $movement->product_id !== (int) $line->product_id || $movement->transaction_type !== 'purchase_receipt'
                || bccomp((string) $movement->quantity_in, $quantity, 8) !== 0 || bccomp((string) $movement->quantity_out, '0', 8) !== 0
                || bccomp((string) $line->inventory_posted_quantity, (string) $line->accepted_quantity, 8) !== 0
                || bccomp((string) $movement->total_cost, (string) $line->provisional_total_value, 8) !== 0
                || bccomp((string) $movement->unit_cost, bcdiv((string) $line->provisional_total_value, $quantity, 8), 8) !== 0
                || ! $this->stockHasExactInverse($movement)
                || ! $this->journalHasExactInverse((int) $line->grni_journal_entry_id, 'grni_receipt', (int) $line->id, 'grni_receipt_reversal', (int) $receipt->company_id)) {
                return false;
            }
        }

        return $expectedCount === $originals->count();
    }

    public function stockHasExactInverse(InventoryTransaction $source): bool
    {
        $inverse = InventoryTransaction::query()->where('reversal_of_id', $source->id)->get();
        if ($source->is_reversal || $inverse->count() !== 1 || ! $inverse->first()->is_reversal) {
            return false;
        }
        $inverse = $inverse->first();
        foreach (['company_id', 'branch_id', 'branch_store_id', 'warehouse_location_id', 'product_id', 'unit_id',
            'stock_status', 'batch_lot', 'source_type', 'source_id', 'source_line_type', 'source_line_id', 'supplier_id',
            'inventory_serial_identity_id', 'serial_numbers', 'manufacture_date', 'expiry_date'] as $field) {
            if ($source->getRawOriginal($field) !== $inverse->getRawOriginal($field)) {
                return false;
            }
        }
        foreach (['unit_cost', 'total_cost'] as $field) {
            if (($source->{$field} === null) !== ($inverse->{$field} === null)
                || ($source->{$field} !== null && bccomp((string) $source->{$field}, (string) $inverse->{$field}, 8) !== 0)) {
                return false;
            }
        }

        return bccomp((string) $source->quantity_in, (string) $inverse->quantity_out, 8) === 0
            && bccomp((string) $source->quantity_out, (string) $inverse->quantity_in, 8) === 0
            && bccomp(bcadd($source->signedValue(), $inverse->signedValue(), 8), '0', 8) === 0
            && bccomp(bcadd($this->unvaluedQuantity($source), $this->unvaluedQuantity($inverse), 8), '0', 8) === 0;
    }

    private function unvaluedQuantity(InventoryTransaction $movement): string
    {
        $quantity = $movement->unit_cost === null || $movement->total_cost === null
            ? bcsub((string) $movement->quantity_in, (string) $movement->quantity_out, 8) : '0';

        return bcadd($quantity, (string) ($movement->unvalued_quantity_delta ?? '0'), 8);
    }

    private function journalHasExactInverse(int $sourceId, string $sourceType, int $ownerId, string $inverseType, int $companyId): bool
    {
        $source = JournalEntry::query()->where('company_id', $companyId)
            ->where('source_type', $sourceType)->where('source_id', $ownerId)->find($sourceId);
        $inverse = $source === null ? null : JournalEntry::query()->where('company_id', $companyId)
            ->where('source_type', $inverseType)->where('source_id', $ownerId)->find($source->reversed_entry_id);
        if ($source === null || $inverse === null) {
            return false;
        }
        try {
            app(JournalEntryService::class)->assertPostedReversal($source, $inverse);
        } catch (\DomainException) {
            return false;
        }

        return true;
    }

    public function cancelledQuotationIsSettled(Quotation $quotation): bool
    {
        return ! $quotation->trashed() && $quotation->status === 'cancelled'
            && $this->hasAudit($quotation, 'quotation.cancelled') && ! $this->quotationHasUnsettledEffects($quotation)
            && ($quotation->sales_request_id === null || DB::table('activity_log')->where('company_id', $quotation->company_id)
                ->where('subject_type', SalesRequest::class)->where('subject_id', $quotation->sales_request_id)
                ->where('event', 'sales_request.conversion_reversed')->where('properties->quotation', $quotation->doc_num)->exists());
    }

    public function quotationHasUnsettledEffects(Quotation $quotation): bool
    {
        foreach ($quotation->salesOrders()->withTrashed()->lazyById() as $order) {
            if (! $this->sameOwner($quotation, $order) || ! $this->cancelledSalesOrderIsSettled($order)) {
                return true;
            }
        }

        return ! app(SalesRequestService::class)->hasQuotationConversionProof($quotation);
    }

    public function salesInvoiceIsSettled(CustomerInvoice $invoice): bool
    {
        if ($invoice->document_type === CustomerInvoice::TypeInvoice && $this->cancelledDraftInvoiceIsSettled($invoice)) {
            return true;
        }
        if ($invoice->trashed() || $invoice->source_type === 'fixed_asset_disposal') {
            return false;
        }
        $credit = $invoice->document_type === CustomerInvoice::TypeCreditNote ? $invoice : $invoice->creditNotes()
            ->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')->first();
        if ($credit === null || $credit->source_type !== CustomerInvoiceCorrection::class
            || $credit->posting_status !== 'posted' || $credit->status !== CustomerInvoice::StatusPosted) {
            return false;
        }
        $proposal = CustomerInvoiceCorrection::query()->where('company_id', $invoice->company_id)->find($credit->source_id);
        if ($proposal === null || ! in_array((int) $credit->id, array_column($proposal->execution_snapshot['credits'] ?? [], 'id'), true)
            || ! in_array((int) ($invoice->document_type === CustomerInvoice::TypeCreditNote ? $invoice->original_invoice_id : $invoice->id),
                array_column($proposal->source_snapshot['invoices'] ?? [], 'id'), true)) {
            return false;
        }
        try {
            app(CustomerInvoiceCorrectionService::class)->assertApproved($proposal);
        } catch (\DomainException|ModelNotFoundException) {
            return false;
        }

        return true;
    }

    public function reopenedInvoicePostingIsReversed(CustomerInvoice $invoice): bool
    {
        $revision = (int) $invoice->posting_revision;
        if ($revision < 1 || $invoice->journal_entry_id === null || $invoice->reversal_journal_entry_id === null
            || $invoice->reopened_at === null || $invoice->reopened_by === null || blank($invoice->reopen_reason)
            || ! $this->hasAudit($invoice, 'customer_invoice.reopened')) {
            return false;
        }
        $source = JournalEntry::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
            ->where('financial_period_id', $invoice->financial_period_id)->where('source_id', $invoice->id)
            ->where('source_type', $revision === 1 ? 'customer_invoice' : 'customer_invoice_post_'.($revision - 1))
            ->find($invoice->journal_entry_id);
        $inverse = JournalEntry::query()->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
            ->where('financial_period_id', $invoice->financial_period_id)->where('source_id', $invoice->id)
            ->where('source_type', 'customer_invoice_reversal_'.$revision)->find($invoice->reversal_journal_entry_id);
        if ($source === null || $inverse === null || ! $source->is_system_generated || ! $inverse->is_system_generated) {
            return false;
        }
        try {
            app(JournalEntryService::class)->assertPostedReversal($source, $inverse);
        } catch (\DomainException) {
            return false;
        }

        return true;
    }

    private function salesDeliveryIsSettled(InventoryDocument $delivery, SalesOrder $order): bool
    {
        if ($delivery->trashed() || $delivery->status !== InventoryDocument::StatusReversed) {
            return false;
        }
        $proposalIds = $order->invoices()->where('source_type', CustomerInvoiceCorrection::class)->select('source_id');
        foreach (CustomerInvoiceCorrection::query()->where('company_id', $order->company_id)->whereIn('id', $proposalIds)->lazyById() as $proposal) {
            if (! in_array((int) $delivery->id, array_column($proposal->source_snapshot['documents'] ?? [], 'id'), true)) {
                continue;
            }
            try {
                app(CustomerInvoiceCorrectionService::class)->assertApproved($proposal);
            } catch (\DomainException|ModelNotFoundException) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function customerReceiptIsSettled(CustomerReceipt $receipt): bool
    {
        if ($receipt->trashed() || $receipt->status !== 'cancelled' || $receipt->cancelled_at === null
            || $receipt->cancelled_by === null || blank($receipt->cancel_reason)
            || ($receipt->cheque_id !== null && ! $this->settledCheque($receipt, Cheque::TypeReceived, 'customer', (int) $receipt->customer_id))
            || bccomp((string) $receipt->unallocated_amount, '0', 4) !== 0
            || ! $this->hasAudit($receipt, 'customer_receipt.reversed')) {
            return false;
        }
        if ($receipt->journal_entry_id === null) {
            return $receipt->cheque_id !== null && $receipt->reversal_journal_entry_id === null
                && ! $receipt->allocations()->whereNotNull('applied_at')->exists()
                && ! CustomerWithholdingSettlement::query()->where('customer_receipt_id', $receipt->id)->exists()
                && ! JournalEntry::query()->where('company_id', $receipt->company_id)->where('source_id', $receipt->id)
                    ->whereIn('source_type', ['customer_receipt', 'customer_receipt_reversal'])->exists();
        }
        foreach (CustomerWithholdingSettlement::query()->where('customer_receipt_id', $receipt->id)->lazyById() as $settlement) {
            if ($settlement->status !== 'reversed') {
                return false;
            }
            try {
                app(CustomerWithholdingSettlementService::class)->assertApproved($settlement);
            } catch (\DomainException) {
                return false;
            }
        }
        $source = JournalEntry::query()->find($receipt->journal_entry_id);
        if ($source === null || (int) $source->branch_id !== (int) $receipt->branch_id
            || (int) $source->currency_id !== (int) $receipt->currency_id || (int) $source->reversed_entry_id !== (int) $receipt->reversal_journal_entry_id) {
            return false;
        }
        if ($receipt->cash_voucher_id !== null) {
            $voucher = CashVoucher::withTrashed()->find($receipt->cash_voucher_id);
            if ($voucher === null || $voucher->trashed() || ! $voucher->isCancelled() || ! $this->sameOwner($receipt, $voucher)
                || (int) $voucher->journal_entry_id !== (int) $source->id) {
                return false;
            }
        }
        $voucherSource = $source->source_type === CashVoucherService::SourceReceipt;

        return $this->journalHasExactInverse((int) $source->id,
            $voucherSource ? CashVoucherService::SourceReceipt : $source->source_type,
            $voucherSource ? (int) $receipt->cash_voucher_id : (int) $receipt->id,
            $voucherSource ? CashVoucherService::SourceReceiptReversal : 'customer_receipt_reversal', (int) $receipt->company_id);
    }

    private function salesReturnIsSettled(SalesReturn $return): bool
    {
        if ($return->trashed() || $return->status !== 'cancelled') {
            return false;
        }
        try {
            app(SalesReturnService::class)->assertCancelledRecovery($return);
        } catch (\DomainException|ModelNotFoundException) {
            return false;
        }
        foreach (InventoryDocument::query()->where('source_document_type', $return::class)->where('source_document_id', $return->id)->lazyById() as $document) {
            foreach ($document->transactions()->where('is_reversal', false)->lazyById() as $source) {
                if (! $this->stockHasExactInverse($source)) {
                    return false;
                }
            }
        }

        return collect(['sales_return.cancelled', 'sales_return.closed_corrected', 'sales_return.disposition_corrected', 'sales_return.receipt_corrected'])
            ->contains(fn (string $event): bool => $this->hasAudit($return, $event));
    }

    public function salesOrderHasUnsettledEffects(SalesOrder $order): bool
    {
        if ($order->remainderClosures()->exists()
            || $order->lines()->where(function ($query): void {
                foreach (['production_requested', 'produced', 'delivered', 'invoiced', 'returned', 'declined', 'remainder_credited'] as $effect) {
                    $query->orWhere($effect.'_quantity', '!=', 0)->orWhere($effect.'_base_quantity', '!=', 0);
                }
            })->exists()) {
            return true;
        }
        foreach ($order->deliveries()->withTrashed()->lazyById() as $delivery) {
            if (! $this->sameOwner($order, $delivery) || ! $this->salesDeliveryIsSettled($delivery, $order)) {
                return true;
            }
        }
        foreach ($order->receipts()->withTrashed()->lazyById() as $receipt) {
            if (! $this->sameOwner($order, $receipt) || ! $this->customerReceiptIsSettled($receipt)) {
                return true;
            }
        }
        foreach ($order->returns()->withTrashed()->lazyById() as $return) {
            if (! $this->sameOwner($order, $return) || ! $this->salesReturnIsSettled($return)) {
                return true;
            }
        }
        foreach ($order->invoices()->withTrashed()->lazyById() as $invoice) {
            if (! $this->sameOwner($order, $invoice) || ! $this->salesInvoiceIsSettled($invoice)) {
                return true;
            }
        }
        foreach ($order->productionOrders()->withTrashed()->lazyById() as $production) {
            if (! $this->sameOwner($order, $production) || ! $this->cancelledUnexecutedProductionIsSettled($production)) {
                return true;
            }
        }

        return false;
    }

    public function cancelledSalesOrderIsSettled(SalesOrder $order, bool $allowArchived = false): bool
    {
        return ($allowArchived || ! $order->trashed()) && $order->status === SalesOrder::StatusCancelled
            && $order->cancelled_at !== null && $order->cancelled_by !== null && filled($order->cancel_reason)
            && $this->hasAudit($order, 'sales_order.cancelled')
            && ! $order->lines()->where(function ($query): void {
                $query->where('reserved_quantity', '!=', 0)->orWhere('reserved_base_quantity', '!=', 0);
            })->exists()
            && ! InventoryReservation::query()->where('sales_order_id', $order->id)->where('status', InventoryReservation::StatusActive)->exists()
            && ! $this->salesOrderHasUnsettledEffects($order);
    }

    private function cancelledDraftInvoiceIsSettled(CustomerInvoice $invoice): bool
    {
        $reopened = (int) $invoice->posting_revision > 0 && $this->reopenedInvoicePostingIsReversed($invoice);
        if (($invoice->trashed() && ! $reopened) || $invoice->document_type !== CustomerInvoice::TypeInvoice
            || $invoice->electronic_invoice_uuid !== null || $invoice->electronic_invoice_submitted_at !== null
            || ! in_array($invoice->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)
            || $invoice->status !== CustomerInvoice::StatusCancelled
            || $invoice->posting_status !== 'cancelled'
            || (! $reopened && ((int) $invoice->posting_revision !== 0 || $invoice->journal_entry_id !== null || $invoice->reversal_journal_entry_id !== null))
            || $invoice->cancelled_at === null || $invoice->cancelled_by === null || blank($invoice->cancel_reason)
            || $invoice->issueOrder()->exists() || $invoice->deliveries()->withTrashed()->exists()
            || $invoice->deliveryReceipts()->exists() || $invoice->returns()->withTrashed()->exists()
            || $invoice->creditNotes()->withTrashed()->exists() || $invoice->allocations()->exists()
            || $invoice->appliedCredits()->exists() || $invoice->withholdingSettlements()->exists()
            || $invoice->electronicInvoiceSubmissions()->exists()
            || InventoryTransaction::query()->where('source_type', CustomerInvoice::class)->where('source_id', $invoice->id)->exists()) {
            return false;
        }
        foreach (['paid_amount', 'credited_amount', 'applied_advance_amount', 'remaining_amount'] as $field) {
            if (bccomp((string) $invoice->{$field}, '0', 4) !== 0) {
                return false;
            }
        }
        $audit = DB::table('activity_log')->where('company_id', $invoice->company_id)
            ->where('subject_type', CustomerInvoice::class)->where('subject_id', $invoice->id)
            ->where('event', $reopened ? 'customer_invoice.reopened_cancelled' : 'customer_invoice.draft_cancelled')->latest('id')->first();
        try {
            $proof = $audit === null ? [] : json_decode($audit->properties, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (($proof['source_quantities_released'] ?? false) !== true
            || ($proof['scope'] ?? null) !== ($reopened ? 'reopened_original_reversed' : 'unused_draft')) {
            return false;
        }
        $released = collect($proof['released_order_lines'] ?? []);
        if ($released->pluck('invoice_line_id')->unique()->count() !== $released->count()
            || $released->count() !== $invoice->lines()->whereNotNull('sales_order_line_id')->count()) {
            return false;
        }
        foreach ($invoice->lines()->whereNotNull('sales_order_line_id')->lazyById() as $line) {
            $item = $released->firstWhere('invoice_line_id', $line->id);
            $source = SalesOrderLine::query()->where('sales_order_id', $invoice->sales_order_id)->find($line->sales_order_line_id);
            if ($source === null || $item === null || (int) ($item['order_line_id'] ?? 0) !== (int) $source->id
                || (int) $source->product_id !== (int) $line->product_id || (int) $source->unit_id !== (int) $line->unit_id
                || bccomp((string) ($item['quantity'] ?? '-1'), (string) $line->quantity, 8) !== 0
                || bccomp((string) ($item['base_quantity'] ?? '-1'), (string) $line->base_quantity, 8) !== 0
                || bccomp(bcsub((string) ($item['quantity_before'] ?? '-1'), (string) ($item['quantity_after'] ?? '-1'), 8), (string) $line->quantity, 8) !== 0
                || bccomp(bcsub((string) ($item['base_quantity_before'] ?? '-1'), (string) ($item['base_quantity_after'] ?? '-1'), 8), (string) $line->base_quantity, 8) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function cancelledUnexecutedProductionIsSettled(ProductionOrder $order): bool
    {
        if (Schema::hasTable('production_cancellation_owners')) {
            $owners = DB::table('production_cancellation_owners')->where('company_id', $order->company_id)->where('production_order_id', $order->id)
                ->whereNull('production_run_id')->whereNull('inventory_document_id')->where('treatment', 'document_error')->where('status', 'approved')->get();
            if ($owners->count() === 1) {
                try {
                    app(ProductionCancellationOwnerService::class)->assertApproved($order, (int) $owners->sole()->id);

                    return true;
                } catch (\DomainException|ModelNotFoundException) {
                    return false;
                }
            }
        }
        if ($order->trashed() || $order->status !== ProductionOrder::StatusCancelled
            || ! $this->hasAudit($order, 'production.order.cancelled')
            || $order->lines()->where('received_base_quantity', '!=', 0)->exists()) {
            return false;
        }
        foreach (['production_runs', 'production_run_batches', 'production_material_requirements', 'production_material_requests',
            'production_expense_requests', 'inventory_reservations', 'inventory_documents', 'inventory_document_lines',
            'inventory_transactions', 'quality_inspections'] as $table) {
            if (DB::table($table)->where('production_order_id', $order->id)->exists()) {
                return false;
            }
        }

        return ! $order->stageSnapshots()->where(function ($query): void {
            $query->where('status', '<>', ProductionOrderStageSnapshot::StatusPending)->orWhereHas('events');
        })->exists();
    }

    /** @param class-string<Model> $model */
    private function ownerQuery(string $model): Builder
    {
        $query = $model::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return $query;
    }

    private function purchaseAssetEffectsAreSettled(PurchaseInvoice $invoice): bool
    {
        foreach ($invoice->lines()->with(['fixedAssets' => fn ($query) => $query->withTrashed()->with('costMovements'), 'assetImprovementMovement.asset'])->lazyById() as $line) {
            foreach ($line->fixedAssets as $asset) {
                if ($asset->trashed() || (int) $asset->company_id !== (int) $invoice->company_id
                    || $asset->source_type !== FixedAssetPurchaseIntegrationService::SourceType || (int) $asset->source_id !== (int) $line->id
                    || $asset->status !== FixedAsset::StatusDraft || $asset->capitalized_at !== null || $asset->locked_at !== null
                    || $asset->postedDepreciations()->exists() || $asset->disposals()->exists()
                    || $asset->movements()->where('status', FixedAssetMovement::StatusPosted)->exists()) {
                    return false;
                }
                $recognitions = $asset->costMovements->where('movement_type', FixedAssetMovement::TypeCapitalization);
                if (($invoice->journal_entry_id !== null && $recognitions->count() !== 1)
                    || ($invoice->journal_entry_id === null && $recognitions->isNotEmpty())) {
                    return false;
                }
                foreach ($recognitions as $movement) {
                    if (! $this->purchaseAssetMovementIsReversed($invoice, $movement)) {
                        return false;
                    }
                }
            }
            if ($line->asset_treatment === FixedAssetPurchaseIntegrationService::TreatmentNewAsset
                && $invoice->journal_entry_id !== null && $line->fixedAssets->isEmpty()) {
                return false;
            }
            if ($line->asset_treatment === FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement || $line->target_fixed_asset_id !== null) {
                $movement = $line->assetImprovementMovement;
                if ($invoice->journal_entry_id === null) {
                    if ($movement !== null) {
                        return false;
                    }

                    continue;
                }
                if ($movement === null || $movement->movement_type !== FixedAssetMovement::TypeAddition
                    || $movement->source_type !== FixedAssetPurchaseIntegrationService::ImprovementSourceType
                    || (int) $movement->source_id !== (int) $line->id || (int) $movement->fixed_asset_id !== (int) $line->target_fixed_asset_id
                    || $movement->asset === null || $movement->asset->trashed() || (int) $movement->asset->company_id !== (int) $invoice->company_id
                    || ! data_get($movement->snapshot, 'external_journal', false)
                    || ! $this->purchaseAssetMovementIsReversed($invoice, $movement)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function purchaseAssetMovementIsReversed(PurchaseInvoice $invoice, FixedAssetMovement $movement): bool
    {
        return (int) $movement->company_id === (int) $invoice->company_id
            && $movement->status === FixedAssetMovement::StatusReversed && $movement->reversed_at !== null
            && $movement->reversed_by !== null && filled($movement->reversal_reason)
            && (int) $movement->journal_entry_id === (int) $invoice->journal_entry_id
            && (int) $movement->reversal_journal_entry_id === (int) $invoice->reversal_journal_entry_id
            && $this->journalHasExactInverse((int) $invoice->journal_entry_id, 'purchase_invoice', (int) $invoice->id,
                'purchase_invoice_reversal', (int) $invoice->company_id);
    }

    private function settledCheque(SupplierPaymentContext|CustomerReceipt $owner, string $type, string $partyType, int $partyId): bool
    {
        $cheque = Cheque::withTrashed()->with('clearingEvents')->find($owner->cheque_id);
        if ($cheque === null || $cheque->trashed() || (int) $cheque->company_id !== (int) $owner->company_id
            || $cheque->cheque_type !== $type || $cheque->party_type !== $partyType || (int) $cheque->party_id !== $partyId
            || (int) $cheque->currency_id !== (int) $owner->currency_id || bccomp((string) $cheque->amount, (string) $owner->amount, 4) !== 0
            || bccomp((string) $cheque->exchange_rate, (string) $owner->exchange_rate, 6) !== 0
            || ! in_array($cheque->status, $type === Cheque::TypeReceived ? [Cheque::StatusCancelled, Cheque::StatusReturned, Cheque::StatusCollectionReversed] : [Cheque::StatusCancelled, Cheque::StatusReturned], true)) {
            return false;
        }
        if (($cheque->status === Cheque::StatusCancelled && ($cheque->cancelled_at === null || $cheque->cancelled_by === null || blank($cheque->cancel_reason)))
            || ($cheque->status === Cheque::StatusReturned && $cheque->returned_at === null)
            || ($type === Cheque::TypeReceived && $cheque->clearingEvents->isNotEmpty())) {
            return false;
        }
        if ($type === Cheque::TypeReceived && $cheque->collected_at !== null) {
            try {
                $correction = app(ChequeCollectionCorrectionService::class)->assertApproved($cheque);
                if ((int) $correction->customer_receipt_id !== (int) $owner->id) {
                    return false;
                }
            } catch (\DomainException|ModelNotFoundException) {
                return false;
            }
        }
        $linked = $owner::query()->where('cheque_id', $cheque->id)->get();
        if ($linked->count() !== 1 || (int) $linked->sole()->id !== (int) $owner->id) {
            return false;
        }
        if ($type === Cheque::TypeIssued) {
            $legacySingleClearing = (int) $cheque->clearing_revision === 0 && $cheque->clearingEvents->count() === 1
                && (int) $cheque->clearingEvents->sole()->sequence === 1 && $cheque->clearing_reversed_at !== null
                && $cheque->clearing_reversed_by !== null && filled($cheque->clearing_reversal_reason);
            if ((! $legacySingleClearing && (int) $cheque->clearing_revision !== $cheque->clearingEvents->count())
                || ($cheque->cleared_at !== null && $cheque->clearingEvents->isEmpty())) {
                return false;
            }
            foreach ($cheque->clearingEvents->sortBy('sequence')->values() as $index => $event) {
                $sourceType = (int) $event->sequence === 1 ? 'supplier_cheque_clearing' : 'supplier_cheque_clearing_'.$event->sequence;
                $journal = JournalEntry::query()->with('lines')->find($event->clearing_journal_entry_id);
                if ((int) $event->sequence !== $index + 1 || $event->status !== ChequeClearingEvent::StatusReversed
                    || $event->reversed_at === null || $event->reversed_by === null || blank($event->reversal_reason)
                    || $journal === null || (int) $journal->branch_id !== (int) $owner->branch_id
                    || (int) $journal->currency_id !== (int) $owner->currency_id
                    || bccomp((string) $journal->exchange_rate, (string) $owner->exchange_rate, 6) !== 0
                    || $journal->lines->count() !== 2
                    || $journal->lines->contains(fn ($line): bool => (int) $line->supplier_id !== (int) $owner->supplier_id
                        || (int) ($line->branch_id ?? $journal->branch_id) !== (int) $owner->branch_id)
                    || bccomp($journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->debit_amount, 4), '0.0000'), (string) $owner->amount, 4) !== 0
                    || bccomp($journal->lines->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->credit_amount, 4), '0.0000'), (string) $owner->amount, 4) !== 0
                    || (int) $journal->reversed_entry_id !== (int) $event->reversal_journal_entry_id
                    || ! $this->journalHasExactInverse((int) $journal->id, $sourceType, (int) $owner->id,
                        'supplier_cheque_clearing_reversal_'.$event->sequence, (int) $owner->company_id)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function sameOwner(Model $parent, Model $child): bool
    {
        return (int) $parent->company_id === (int) $child->company_id
            && (! array_key_exists('branch_id', $child->getAttributes())
                || Branch::query()->where('company_id', $parent->company_id)->whereKey($child->branch_id)->exists())
            && FinancialPeriod::query()->where('company_id', $parent->company_id)->whereKey($child->financial_period_id)->exists();
    }

    private function hasAudit(Model $document, string $event): bool
    {
        return DB::table('activity_log')->where('company_id', $document->company_id)
            ->where('subject_type', $document::class)->where('subject_id', $document->getKey())->where('event', $event)->exists();
    }
}
