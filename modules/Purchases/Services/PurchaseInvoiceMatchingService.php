<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturnLine;

class PurchaseInvoiceMatchingService
{
    public function __construct(private readonly ProcurementAuditService $audit) {}

    public function remainingForReceipt(UnpricedInventoryReceiptLine $line, ?int $exceptInvoiceId = null): float
    {
        $line->loadMissing(['receipt', 'product']);
        if (! $line->receipt?->approved || $line->receipt->posting_status !== 'posted' || in_array($line->receipt->status, ['cancelled', 'reversed'], true)) {
            return 0.0;
        }
        $billed = (float) PurchaseInvoiceLine::query()->where('receipt_line_id', $line->getKey())
            ->when($exceptInvoiceId !== null, fn ($query) => $query->where('purchase_invoice_id', '<>', $exceptInvoiceId))
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))->sum('quantity');
        $returnedBeforeInvoice = (float) PurchaseReturnLine::query()->where('receipt_line_id', $line->getKey())
            ->where('from_quarantine', false)->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted')->whereNull('purchase_invoice_id'))->sum('quantity');

        return max(0, $this->acceptedQuantity($line) - $returnedBeforeInvoice - $billed);
    }

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
            || (int) $order->branch_id !== (int) $invoice->branch_id
            || (int) $order->currency_id !== (int) $invoice->currency_id
            || ! in_array($order->status, [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed], true)) {
            throw new DomainException(__('Purchase order, supplier, and invoice context do not match.'));
        }

        $variances = [];
        foreach ($invoice->lines as $invoiceLine) {
            $this->matchLine($invoiceLine, $order);
            $invoiceLine->refresh()->load(['purchaseOrderLine', 'receiptLine']);
            $source = $invoiceLine->purchaseOrderLine;
            $baselineQuantity = $invoiceLine->receiptLine
                ? $this->acceptedQuantity($invoiceLine->receiptLine)
                : (float) $source->ordered_quantity;
            $variances[] = [
                'line' => $invoiceLine->public_id,
                'order_line' => $source->public_id,
                'ordered_quantity' => number_format((float) $source->ordered_quantity, 8, '.', ''),
                'received_quantity' => number_format((float) $source->receivedQuantity(), 8, '.', ''),
                'quantity_variance' => number_format((float) $invoiceLine->quantity - $baselineQuantity, 8, '.', ''),
                'unit_price_variance' => bcsub((string) $invoiceLine->unit_price, (string) $source->unit_price, 4),
                'tax_rate_variance' => bcsub((string) $invoiceLine->tax_rate, (string) $source->tax_rate, 4),
            ];
        }

        $freightMatch = $this->matchFreight($invoice, $order);

        $hasVariance = collect($variances)->contains(fn (array $variance): bool => abs((float) $variance['quantity_variance']) > 0.00000001
            || abs((float) $variance['unit_price_variance']) > 0.0001
            || abs((float) $variance['tax_rate_variance']) > 0.0001);
        $invoice->forceFill([
            'matching_status' => $hasVariance ? 'approved_with_variance' : 'matched',
            'matching_notes' => json_encode([...$freightMatch, 'line_variances' => $variances], JSON_THROW_ON_ERROR),
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
            ->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed'])
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

        $invoicedForOrderLine = (float) PurchaseInvoiceLine::query()
            ->where('purchase_order_line_id', $orderLine->getKey())
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))
            ->sum('quantity');

        if ($orderLine->product?->isService()) {
            $eligibleQuantity = (float) $orderLine->ordered_quantity;
        } else {
            $receiptLine = $this->resolveReceiptLine($invoiceLine, $orderLine);
            if (! $receiptLine instanceof UnpricedInventoryReceiptLine
                || (int) $receiptLine->product_id !== (int) $invoiceLine->product_id
                || ! $receiptLine->receipt?->approved
                || $receiptLine->receipt?->posting_status !== 'posted'
                || in_array($receiptLine->receipt?->status, ['cancelled', 'reversed'], true)) {
                throw new DomainException(__('A stock invoice line requires an accepted goods receipt line.'));
            }

            $invoicedForReceipt = (float) PurchaseInvoiceLine::query()
                ->where('receipt_line_id', $receiptLine->getKey())
                ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))
                ->sum('quantity');
            $acceptedQuantity = $this->acceptedQuantity($receiptLine, $orderLine->product);
            if ($invoicedForReceipt > $acceptedQuantity + 0.00000001) {
                throw new DomainException(__('Invoice quantity exceeds quality-accepted receipt quantity.'));
            }
            $returned = (float) PurchaseReturnLine::query()->where('receipt_line_id', $receiptLine->getKey())
                ->where('from_quarantine', false)->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted')->whereNull('purchase_invoice_id'))->sum('quantity');
            if ($invoicedForReceipt > $acceptedQuantity - $returned + 0.00000001) {
                throw new DomainException(__('Invoice quantity exceeds the accepted GRNI quantity remaining after returns.'));
            }
            $eligibleQuantity = $orderLine->receivedQuantity();
        }

        if ($invoicedForOrderLine > $eligibleQuantity + 0.00000001) {
            throw new DomainException(__('Invoice quantity exceeds the eligible purchase quantity.'));
        }

        $invoiceLine->forceFill(['matched_quantity' => $invoiceLine->quantity, 'updated_by' => auth()->id()])->save();
    }

    private function resolveReceiptLine(PurchaseInvoiceLine $invoiceLine, PurchaseOrderLine $orderLine): ?UnpricedInventoryReceiptLine
    {
        if ($invoiceLine->receipt_line_id !== null) {
            return UnpricedInventoryReceiptLine::query()->lockForUpdate()
                ->where('purchase_order_line_id', $orderLine->getKey())
                ->find($invoiceLine->receipt_line_id);
        }

        $requiredQuantity = (float) $invoiceLine->quantity;
        $receiptLine = UnpricedInventoryReceiptLine::query()
            ->where('purchase_order_line_id', $orderLine->getKey())
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
            ->with(['receipt', 'product'])
            ->orderBy('id')
            ->get()
            ->first(fn (UnpricedInventoryReceiptLine $line): bool => $this->remainingForReceipt($line, $invoiceLine->purchase_invoice_id) + 0.00000001 >= $requiredQuantity);

        if (! $receiptLine instanceof UnpricedInventoryReceiptLine) {
            throw new DomainException(__('purchase_invoices.messages.receipt_capacity_required'));
        }

        $invoiceLine->forceFill([
            'receipt_line_id' => $receiptLine->getKey(),
            'updated_by' => auth()->id(),
        ])->save();
        $invoiceLine->setRelation('receiptLine', $receiptLine);

        return $receiptLine;
    }

    private function acceptedQuantity(UnpricedInventoryReceiptLine $line, mixed $product = null): float
    {
        $product ??= $line->product;

        return $product && ! $product->cost_as_inventory
            ? (float) $line->accepted_quantity
            : (float) $line->inventory_posted_quantity;
    }
}
