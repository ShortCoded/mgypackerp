<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturnLine;

class PurchaseInvoiceMatchingService
{
    public function __construct(
        private readonly ProcurementAuditService $audit,
        private readonly NumericFormatService $numbers,
    ) {}

    public function remainingForReceipt(UnpricedInventoryReceiptLine $line, ?int $exceptInvoiceId = null): float
    {
        return (float) $this->remainingForReceiptExact($line, $exceptInvoiceId);
    }

    public function remainingForReceiptExact(UnpricedInventoryReceiptLine $line, ?int $exceptInvoiceId = null): string
    {
        $line->loadMissing(['receipt', 'product']);
        if (! $line->receipt?->approved || $line->receipt->posting_status !== 'posted' || in_array($line->receipt->status, ['cancelled', 'reversed'], true)) {
            return '0.00000000';
        }
        $billed = $this->quantity(PurchaseInvoiceLine::query()->where('receipt_line_id', $line->getKey())
            ->when($exceptInvoiceId !== null, fn ($query) => $query->where('purchase_invoice_id', '<>', $exceptInvoiceId))
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))->sum('quantity'));
        $returnedBeforeInvoice = $this->quantity(PurchaseReturnLine::query()->where('receipt_line_id', $line->getKey())
            ->where('from_quarantine', false)->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted')->whereNull('purchase_invoice_id'))->sum('quantity'));
        $remaining = bcsub(bcsub($this->acceptedQuantity($line), $returnedBeforeInvoice, 8), $billed, 8);

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    public function matchForPosting(PurchaseInvoice $invoice): void
    {
        $invoice->loadMissing(['purchaseOrder', 'lines.product', 'lines.purchaseOrderLine', 'lines.receiptLine.receipt']);

        if (! $invoice->purchaseOrder instanceof PurchaseOrder) {
            $matchingStatus = $invoice->direct_procurement_override && filled($invoice->direct_procurement_reason)
                ? 'authorized_direct'
                : 'direct_invoice';
            $invoice->forceFill([
                'matching_status' => $matchingStatus,
                'matching_notes' => json_encode([
                    'direct_invoice' => true,
                    'reason' => $invoice->direct_procurement_reason,
                    'line_variances' => $invoice->lines->map(fn (PurchaseInvoiceLine $line): array => $this->unmatchedVariance($line))->all(),
                ], JSON_THROW_ON_ERROR),
            ])->save();
            $this->audit->record($invoice, $matchingStatus === 'authorized_direct'
                ? 'purchase_invoice.direct_procurement_authorized'
                : 'purchase_invoice.direct_recorded');

            return;
        }

        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($invoice->purchase_order_id);
        if ((int) $order->company_id !== (int) $invoice->company_id
            || (int) $order->supplier_id !== (int) $invoice->supplier_id
            || (int) $order->branch_id !== (int) $invoice->branch_id
            || (int) $order->currency_id !== (int) $invoice->currency_id
            || ! in_array($order->status, [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed], true)) {
            throw new DomainException(__('purchase_invoices.messages.purchase_order_context_mismatch'));
        }

        $variances = [];
        foreach ($invoice->lines as $invoiceLine) {
            $source = $invoiceLine->purchaseOrderLine;
            if (! $source instanceof PurchaseOrderLine
                || (int) $source->purchase_order_id !== (int) $order->getKey()
                || (int) $source->product_id !== (int) $invoiceLine->product_id
                || (int) $source->unit_id !== (int) $invoiceLine->unit_id) {
                $variances[] = $this->unmatchedVariance($invoiceLine);

                continue;
            }

            $this->matchLine($invoiceLine, $order);
            $invoiceLine->refresh()->load(['purchaseOrderLine', 'receiptLine']);
            $source = $invoiceLine->purchaseOrderLine;
            $baselineQuantity = $invoiceLine->receiptLine
                ? $this->acceptedQuantity($invoiceLine->receiptLine)
                : (string) $source->ordered_quantity;
            $variances[] = [
                'line' => $invoiceLine->public_id,
                'order_line' => $source->public_id,
                'ordered_quantity' => $this->quantity($source->ordered_quantity),
                'received_quantity' => $this->quantity(UnpricedInventoryReceiptLine::query()
                    ->where('purchase_order_line_id', $source->getKey())
                    ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                    ->sum('accepted_quantity')),
                'quantity_variance' => bcsub((string) $invoiceLine->quantity, $baselineQuantity, 8),
                'unit_price_variance' => bcsub((string) $invoiceLine->unit_price, (string) $source->unit_price, 8),
                'tax_rate_variance' => bcsub((string) $invoiceLine->tax_rate, (string) $source->tax_rate, 4),
                'match_type' => 'linked',
            ];
        }

        $freightMatch = $this->matchFreight($invoice, $order);

        $hasVariance = collect($variances)->contains(fn (array $variance): bool => ($variance['match_type'] ?? null) === 'unlinked'
            || bccomp((string) $variance['quantity_variance'], '0', 8) !== 0
            || bccomp((string) $variance['unit_price_variance'], '0', 8) !== 0
            || bccomp((string) $variance['tax_rate_variance'], '0', 4) !== 0)
            || bccomp((string) $freightMatch['variance'], '0', 4) !== 0;
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
        $approved = $this->amount($order->freight_amount);
        $previouslyInvoiced = $this->amount(PurchaseInvoice::query()
            ->where('purchase_order_id', $order->getKey())
            ->whereKeyNot($invoice->getKey())
            ->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed'])
            ->sum('freight_amount'));
        $current = $this->amount($invoice->freight_amount);
        $availableBeforeCurrent = $this->nonNegative(bcsub($approved, $previouslyInvoiced, 4), 4);

        $remaining = $this->nonNegative(bcsub($availableBeforeCurrent, $current, 4), 4);

        return [
            'approved' => $approved,
            'previously_invoiced' => $previouslyInvoiced,
            'current' => $current,
            'remaining' => $remaining,
            'variance' => bcsub($current, $availableBeforeCurrent, 4),
        ];
    }

    /** @return array{line: string, order_line: null, ordered_quantity: string, received_quantity: string, quantity_variance: string, unit_price_variance: string, tax_rate_variance: string, match_type: string} */
    private function unmatchedVariance(PurchaseInvoiceLine $invoiceLine): array
    {
        return [
            'line' => $invoiceLine->public_id,
            'order_line' => null,
            'ordered_quantity' => '0.00000000',
            'received_quantity' => '0.00000000',
            'quantity_variance' => $this->quantity($invoiceLine->quantity),
            'unit_price_variance' => bcadd((string) $invoiceLine->unit_price, '0', 8),
            'tax_rate_variance' => number_format((float) $invoiceLine->tax_rate, 4, '.', ''),
            'match_type' => 'unlinked',
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

        $invoicedForOrderLine = $this->quantity(PurchaseInvoiceLine::query()
            ->where('purchase_order_line_id', $orderLine->getKey())
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))
            ->sum('quantity'));

        if ($orderLine->product?->isService()) {
            $eligibleQuantity = (string) $orderLine->ordered_quantity;
        } else {
            $receiptLine = $this->resolveReceiptLine($invoiceLine, $orderLine);
            if (! $receiptLine instanceof UnpricedInventoryReceiptLine
                || (int) $receiptLine->product_id !== (int) $invoiceLine->product_id
                || ! $receiptLine->receipt?->approved
                || $receiptLine->receipt?->posting_status !== 'posted'
                || in_array($receiptLine->receipt?->status, ['cancelled', 'reversed'], true)) {
                throw new DomainException(__('A stock invoice line requires an accepted goods receipt line.'));
            }

            $invoicedForReceipt = $this->quantity(PurchaseInvoiceLine::query()
                ->where('receipt_line_id', $receiptLine->getKey())
                ->whereHas('purchaseInvoice', fn ($query) => $query->whereNotIn('status', [PurchaseInvoice::StatusCancelled, 'reversed']))
                ->sum('quantity'));
            $acceptedQuantity = $this->acceptedQuantity($receiptLine, $orderLine->product);
            if (bccomp($invoicedForReceipt, $acceptedQuantity, 8) > 0) {
                throw new DomainException(__('Invoice quantity exceeds quality-accepted receipt quantity.'));
            }
            $returned = $this->quantity(PurchaseReturnLine::query()->where('receipt_line_id', $receiptLine->getKey())
                ->where('from_quarantine', false)->whereHas('purchaseReturn', fn ($query) => $query->where('status', 'posted')->whereNull('purchase_invoice_id'))->sum('quantity'));
            if (bccomp($invoicedForReceipt, bcsub($acceptedQuantity, $returned, 8), 8) > 0) {
                throw new DomainException(__('Invoice quantity exceeds the accepted GRNI quantity remaining after returns.'));
            }
            $eligibleQuantity = $this->quantity(UnpricedInventoryReceiptLine::query()
                ->where('purchase_order_line_id', $orderLine->getKey())
                ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                ->sum('accepted_quantity'));
        }

        if (bccomp($invoicedForOrderLine, $eligibleQuantity, 8) > 0) {
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

        $requiredQuantity = (string) $invoiceLine->quantity;
        $receiptLine = UnpricedInventoryReceiptLine::query()
            ->where('purchase_order_line_id', $orderLine->getKey())
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
            ->with(['receipt', 'product'])
            ->orderBy('id')
            ->get()
            ->first(fn (UnpricedInventoryReceiptLine $line): bool => bccomp(
                $this->remainingForReceiptExact($line, $invoiceLine->purchase_invoice_id),
                $requiredQuantity,
                8,
            ) >= 0);

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

    private function acceptedQuantity(UnpricedInventoryReceiptLine $line, mixed $product = null): string
    {
        $product ??= $line->product;

        return $product && ! $product->cost_as_inventory
            ? (string) $line->accepted_quantity
            : (string) $line->inventory_posted_quantity;
    }

    private function quantity(mixed $value): string
    {
        return bcadd($this->numbers->normalizeScientificNotation((string) ($value ?? 0)) ?? '0', '0', 8);
    }

    private function amount(mixed $value): string
    {
        return bcadd($this->numbers->normalizeScientificNotation((string) ($value ?? 0)) ?? '0', '0', 4);
    }

    private function nonNegative(string $value, int $scale): string
    {
        return bccomp($value, '0', $scale) > 0 ? $value : bcadd('0', '0', $scale);
    }
}
