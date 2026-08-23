<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;

class PurchaseInvoiceMatchingService
{
    public function __construct(private readonly ProcurementAuditService $audit) {}

    public function matchForPosting(PurchaseInvoice $invoice): void
    {
        $invoice->loadMissing(['purchaseOrder', 'lines.product', 'lines.purchaseOrderLine', 'lines.receiptLine.receipt']);

        if (! $invoice->purchaseOrder instanceof PurchaseOrder) {
            if (! $invoice->direct_procurement_override || blank($invoice->direct_procurement_reason)) {
                throw new DomainException(__('A purchase invoice requires a purchase order or an authorized direct-procurement reason.'));
            }
            $invoice->forceFill(['matching_status' => 'authorized_direct', 'matching_notes' => $invoice->direct_procurement_reason])->save();
            $this->audit->record($invoice, 'purchase_invoice.direct_procurement_authorized');

            return;
        }

        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($invoice->purchase_order_id);
        if ((int) $order->company_id !== (int) $invoice->company_id
            || (int) $order->supplier_id !== (int) $invoice->supplier_id
            || ! in_array($order->status, [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed], true)) {
            throw new DomainException(__('Purchase order, supplier, and invoice context do not match.'));
        }

        foreach ($invoice->lines as $invoiceLine) {
            $this->matchLine($invoiceLine, $order);
        }

        $freightMatch = $this->matchFreight($invoice, $order);

        $invoice->forceFill([
            'matching_status' => 'matched',
            'matching_notes' => json_encode($freightMatch, JSON_THROW_ON_ERROR),
        ])->save();
        $this->audit->record($invoice, 'purchase_invoice.matched', [
            'purchase_order_doc_num' => $order->doc_num,
            'line_count' => $invoice->lines->count(),
            'freight' => $freightMatch,
        ]);
    }

    /** @return array{approved: string, previously_invoiced: string, current: string, remaining: string, variance: string} */
    private function matchFreight(PurchaseInvoice $invoice, PurchaseOrder $order): array
    {
        $approved = (float) $order->freight_amount;
        $previouslyInvoiced = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->getKey())
            ->whereKeyNot($invoice->getKey())
            ->whereNotIn('status', [PurchaseInvoice::StatusCancelled])
            ->lockForUpdate()
            ->get(['freight_amount'])
            ->sum(fn (PurchaseInvoice $matchedInvoice): float => (float) $matchedInvoice->freight_amount);
        $current = (float) $invoice->freight_amount;
        $availableBeforeCurrent = max(0, $approved - $previouslyInvoiced);

        if ($current > $availableBeforeCurrent + 0.0001) {
            throw new DomainException(__('Invoice freight exceeds the remaining approved Purchase Order freight.'));
        }

        $remaining = max(0, $availableBeforeCurrent - $current);

        return [
            'approved' => number_format($approved, 4, '.', ''),
            'previously_invoiced' => number_format($previouslyInvoiced, 4, '.', ''),
            'current' => number_format($current, 4, '.', ''),
            'remaining' => number_format($remaining, 4, '.', ''),
            'variance' => number_format($current - $availableBeforeCurrent, 4, '.', ''),
        ];
    }

    private function matchLine(PurchaseInvoiceLine $invoiceLine, PurchaseOrder $order): void
    {
        $orderLine = PurchaseOrderLine::query()->with('product')->lockForUpdate()
            ->where('purchase_order_id', $order->getKey())
            ->find($invoiceLine->purchase_order_line_id);
        if (! $orderLine instanceof PurchaseOrderLine
            || (int) $orderLine->product_id !== (int) $invoiceLine->product_id
            || (int) $orderLine->unit_id !== (int) $invoiceLine->unit_id) {
            throw new DomainException(__('Every invoice line must match its purchase order line and unit.'));
        }

        if (abs((float) $invoiceLine->unit_price - (float) $orderLine->unit_price) > 0.0001) {
            throw new DomainException(__('Invoice price differs from the approved purchase order price.'));
        }
        $expectedDiscount = (float) $orderLine->ordered_quantity > 0
            ? (float) $orderLine->discount_amount * (float) $invoiceLine->quantity / (float) $orderLine->ordered_quantity
            : 0.0;
        if (abs((float) $invoiceLine->discount_amount - $expectedDiscount) > 0.0001
            || abs((float) $invoiceLine->tax_rate - (float) $orderLine->tax_rate) > 0.0001) {
            throw new DomainException(__('Invoice discount or tax differs from the approved purchase order terms.'));
        }

        $invoicedForOrderLine = (float) PurchaseInvoiceLine::query()
            ->where('purchase_order_line_id', $orderLine->getKey())
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled]))
            ->sum('quantity');

        if ($orderLine->product?->isService()) {
            $eligibleQuantity = (float) $orderLine->ordered_quantity;
        } else {
            $receiptLine = UnpricedInventoryReceiptLine::query()->lockForUpdate()
                ->where('purchase_order_line_id', $orderLine->getKey())
                ->find($invoiceLine->receipt_line_id);
            if (! $receiptLine instanceof UnpricedInventoryReceiptLine
                || (int) $receiptLine->product_id !== (int) $invoiceLine->product_id) {
                throw new DomainException(__('A stock invoice line requires an accepted goods receipt line.'));
            }

            $invoicedForReceipt = (float) PurchaseInvoiceLine::query()
                ->where('receipt_line_id', $receiptLine->getKey())
                ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled]))
                ->sum('quantity');
            if ($invoicedForReceipt > (float) $receiptLine->accepted_quantity + 0.0001) {
                throw new DomainException(__('Invoice quantity exceeds quality-accepted receipt quantity.'));
            }
            $eligibleQuantity = (float) $orderLine->received_quantity;
        }

        if ($invoicedForOrderLine > $eligibleQuantity + 0.0001) {
            throw new DomainException(__('Invoice quantity exceeds the eligible purchase quantity.'));
        }

        $invoiceLine->forceFill(['matched_quantity' => $invoiceLine->quantity, 'updated_by' => auth()->id()])->save();
    }
}
