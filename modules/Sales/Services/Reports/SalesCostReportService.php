<?php

namespace Modules\Sales\Services\Reports;

use App\Services\PostingAccountResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

/**
 * Read-only service for the Cost of Sales report.
 *
 * Produces delivery (positive COGS) and return (negative COGS) rows from
 * canonical posted inventory documents and their linked COGS journals.
 * Never uses selling price or recomputes BOM; always reads persisted
 * inventory cost from posted inventory_document_lines and returns.
 *
 * Reconciliation: for each canonical source (delivery document or return),
 * the COGS-account net in the linked journal must exactly equal the
 * FULL source document aggregate total cost at scale 4. A product filter
 * only narrows the emitted rows; reconciliation always runs against the
 * complete unfiltered source. Any null unit_cost or total_cost anywhere
 * in the full source forces the entire source to unreconciled.
 */
class SalesCostReportService
{
    public function __construct(
        private readonly PostingAccountResolver $accounts,
    ) {}

    /** @param array<string, mixed> $filters */
    public function report(int $companyId, int $branchId, array $filters): SalesCostReportResult
    {
        $periodId = $filters['financial_period_id'] ?? null;
        $customerId = $filters['customer_id'] ?? null;
        $productId = $filters['product_id'] ?? null;
        $orderId = $filters['order_id'] ?? null;
        $invoiceId = $filters['invoice_id'] ?? null;
        // Normalise to date-only strings: the controller may pass Carbon
        // instances which coerce to datetime strings via __toString();
        // SQLite DATE() returns short dates so 'YYYY-MM-DD' >= 'YYYY-MM-DD HH:MM:SS'
        // would fail as a text comparison.
        $from = $filters['from'] instanceof CarbonInterface
            ? $filters['from']->toDateString()
            : ($filters['from'] !== null ? (string) $filters['from'] : null);
        $to = $filters['to'] instanceof CarbonInterface
            ? $filters['to']->toDateString()
            : ($filters['to'] !== null ? (string) $filters['to'] : null);

        $cogsAccountId = $this->accounts->resolveFirst(
            $companyId,
            PostingAccountResolver::CostOfGoodsSold,
            'Cost of sales report',
        )->getKey();

        $rows = collect();
        $deliveryCount = 0;
        $returnCount = 0;
        $deliveryCost = '0.0000';
        $returnCost = '0.0000';

        // ── Delivery rows ────────────────────────────────────────────
        $deliveries = $this->deliveryQuery($companyId, $branchId, $periodId, $customerId, $productId, $orderId, $invoiceId, $from, $to)
            ->with([
                'lines.product', 'lines.unit',
                'customer',
                'journalEntry.lines',
                'customerInvoices.order',
            ])
            ->get();

        foreach ($deliveries as $document) {
            $allLines = $document->lines;

            // Reconcile against FULL unfiltered source aggregate
            $fullAggregateCost = '0.0000';
            $fullSourceHasNull = false;
            foreach ($allLines as $line) {
                if ($line->total_cost === null || $line->unit_cost === null) {
                    $fullSourceHasNull = true;
                }
                $fullAggregateCost = bcadd($fullAggregateCost, $this->toDecimal($line->total_cost, 4), 4);
            }

            $journalEntry = $document->journalEntry;
            $journalReconciled = ! $fullSourceHasNull
                && $this->reconcileDeliveryJournal($journalEntry, $cogsAccountId, $fullAggregateCost, $document);

            // Typed order reference: source_document_type is SalesOrder, or
            // fall back to a linked invoice's sales_order relation
            $orderDocNum = null;
            if ($document->source_document_type === SalesOrder::class && $document->salesOrder) {
                $orderDocNum = $document->salesOrder->doc_num;
            } else {
                $linkedOrder = $document->customerInvoices
                    ->map->order
                    ->filter()
                    ->first();
                if ($linkedOrder) {
                    $orderDocNum = $linkedOrder->doc_num;
                }
            }

            // Invoice references from pivot
            $invoiceDocNums = $this->resolveDeliveryInvoiceReferences($document);

            // Filter emitted lines to selected product (if any)
            $emittedLines = $productId
                ? $allLines->where('product_id', $productId)
                : $allLines;

            if ($emittedLines->isEmpty()) {
                continue;
            }

            foreach ($emittedLines as $line) {
                $unitCost = $this->toNullableDecimal($line->unit_cost, 8);
                $totalCost = $this->toNullableDecimal($line->total_cost, 4);

                $rows->push([
                    'movement_kind' => 'delivery',
                    'document' => $document->doc_num,
                    'return_document' => null,
                    'order' => $orderDocNum,
                    'invoice' => $invoiceDocNums !== '' ? $invoiceDocNums : null,
                    'customer' => $document->customer?->name,
                    'product' => $line->product?->name,
                    'unit' => $line->unit?->name,
                    'quantity' => $this->toDecimal($line->quantity, 8),
                    'unit_cost' => $unitCost,
                    'signed_total_cost' => $totalCost,
                    'posting_date' => $document->document_date,
                    'branch_id' => $document->branch_id,
                    'financial_period_id' => $document->financial_period_id,
                    'journal_entry' => $journalEntry?->doc_num,
                    'reconciliation_status' => ($unitCost !== null && $totalCost !== null && $journalReconciled)
                        ? 'reconciled'
                        : 'unreconciled',
                ]);

                if ($totalCost !== null) {
                    $deliveryCost = bcadd($deliveryCost, $totalCost, 4);
                }
            }

            $deliveryCount++;
        }

        // ── Return rows ──────────────────────────────────────────────
        $returns = $this->returnQuery($companyId, $branchId, $periodId, $customerId, $productId, $orderId, $invoiceId, $from, $to)
            ->with([
                'returnInventoryDocument.lines.product', 'returnInventoryDocument.lines.unit',
                'customer',
                'lines',
                'invoice',
                'order',
                'quarantineJournalEntry.lines',
            ])
            ->get();

        foreach ($returns as $return) {
            $inventoryDoc = $return->returnInventoryDocument;
            if (! $inventoryDoc || $inventoryDoc->status !== InventoryDocument::StatusPosted) {
                continue;
            }

            $allLines = $inventoryDoc->lines;

            // Reconcile against FULL unfiltered source aggregate (absolute value)
            $fullAggregateCost = '0.0000';
            $fullSourceHasNull = false;
            foreach ($allLines as $line) {
                if ($line->total_cost === null || $line->unit_cost === null) {
                    $fullSourceHasNull = true;
                }
                $fullAggregateCost = bcadd($fullAggregateCost, $this->toDecimal($line->total_cost, 4), 4);
            }

            $quarantineJournal = $return->quarantineJournalEntry;
            $journalReconciled = ! $fullSourceHasNull
                && $this->reconcileReturnJournal($quarantineJournal, $cogsAccountId, $fullAggregateCost, $return, $inventoryDoc);

            // Filter emitted lines to selected product (if any)
            $emittedLines = $productId
                ? $allLines->where('product_id', $productId)
                : $allLines;

            if ($emittedLines->isEmpty()) {
                continue;
            }

            foreach ($emittedLines as $line) {
                // Exact return line via source_line_type / source_line_id only
                $returnLine = $this->resolveExactReturnLine($return, $line);
                $hasExactLineage = $returnLine !== null;

                $unitCost = $hasExactLineage
                    ? $this->toNullableDecimal($returnLine->original_unit_cost, 8)
                    : $this->toNullableDecimal($line->unit_cost, 8);

                $quantity = $this->toDecimal($line->quantity, 8);

                // Negative COGS
                $signedTotalCost = $line->total_cost !== null
                    ? bcmul($this->toDecimal($line->total_cost, 4), '-1', 4)
                    : null;

                $rows->push([
                    'movement_kind' => 'return',
                    'document' => $return->doc_num,
                    'return_document' => $inventoryDoc->doc_num,
                    'order' => $return->order?->doc_num,
                    'invoice' => $return->invoice?->doc_num,
                    'customer' => $return->customer?->name,
                    'product' => $line->product?->name,
                    'unit' => $line->unit?->name,
                    'quantity' => bcmul($quantity, '-1', 8),
                    'unit_cost' => $unitCost,
                    'signed_total_cost' => $signedTotalCost,
                    'posting_date' => $inventoryDoc->document_date,
                    'branch_id' => $inventoryDoc->branch_id,
                    'financial_period_id' => $inventoryDoc->financial_period_id,
                    'journal_entry' => $quarantineJournal?->doc_num,
                    'reconciliation_status' => ($unitCost !== null && $signedTotalCost !== null && $journalReconciled && $hasExactLineage)
                        ? 'reconciled'
                        : 'unreconciled',
                ]);

                if ($signedTotalCost !== null) {
                    $returnCost = bcadd($returnCost, $signedTotalCost, 4);
                }
            }

            $returnCount++;
        }

        // Compute unreconciled_count once from final rows
        $unreconciledCount = $rows->where('reconciliation_status', 'unreconciled')->count();

        $summary = [
            'delivery_count' => $deliveryCount,
            'return_count' => $returnCount,
            'delivery_cost' => $deliveryCost,
            'return_cost' => $returnCost,
            'net_cost' => bcadd($deliveryCost, $returnCost, 4),
            'unreconciled_count' => $unreconciledCount,
        ];

        return new SalesCostReportResult($rows, $summary);
    }

    private function deliveryQuery(
        int $companyId,
        int $branchId,
        ?int $periodId,
        ?int $customerId,
        ?int $productId,
        ?int $orderId,
        ?int $invoiceId,
        ?string $from,
        ?string $to,
    ): Builder {
        $query = InventoryDocument::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('document_type', InventoryDocument::TypeSalesDelivery)
            ->where('status', InventoryDocument::StatusPosted);

        if ($periodId) {
            $query->where('financial_period_id', $periodId);
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($orderId) {
            $query->where(function (Builder $q) use ($orderId): void {
                // Typed: only when source_document_type is SalesOrder
                $q->where(function (Builder $sq) use ($orderId): void {
                    $sq->where('source_document_type', SalesOrder::class)
                        ->where('source_document_id', $orderId);
                })->orWhereHas('customerInvoices', function (Builder $ci) use ($orderId): void {
                    $ci->where('customer_invoices.sales_order_id', $orderId);
                });
            });
        }

        if ($invoiceId) {
            $query->whereHas('customerInvoices', function (Builder $ci) use ($invoiceId): void {
                $ci->where('customer_invoices.id', $invoiceId);
            });
        }

        if ($productId) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('product_id', $productId));
        }

        if ($from) {
            $query->whereDate('document_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('document_date', '<=', $to);
        }

        return $query->orderByDesc('document_date')->orderByDesc('id');
    }

    private function returnQuery(
        int $companyId,
        int $branchId,
        ?int $periodId,
        ?int $customerId,
        ?int $productId,
        ?int $orderId,
        ?int $invoiceId,
        ?string $from,
        ?string $to,
    ): Builder {
        $query = SalesReturn::query()
            ->where('sales_returns.company_id', $companyId)
            ->where('sales_returns.branch_id', $branchId)
            ->where('sales_returns.status', '!=', SalesReturn::StatusCancelled)
            ->whereNotNull('sales_returns.return_inventory_document_id');

        // Period/date filters use the posted return InventoryDocument fields
        if ($periodId) {
            $query->whereHas('returnInventoryDocument', function (Builder $doc) use ($periodId): void {
                $doc->where('financial_period_id', $periodId);
            });
        }

        if ($customerId) {
            $query->where('sales_returns.customer_id', $customerId);
        }

        if ($orderId) {
            $query->where('sales_returns.sales_order_id', $orderId);
        }

        if ($invoiceId) {
            $query->where('sales_returns.customer_invoice_id', $invoiceId);
        }

        if ($productId) {
            $query->whereHas('lines', function (Builder $lines) use ($productId): void {
                $lines->where('sales_return_lines.product_id', $productId);
            });
        }

        if ($from) {
            $query->whereHas('returnInventoryDocument', function (Builder $doc) use ($from): void {
                $doc->whereDate('document_date', '>=', $from);
            });
        }

        if ($to) {
            $query->whereHas('returnInventoryDocument', function (Builder $doc) use ($to): void {
                $doc->whereDate('document_date', '<=', $to);
            });
        }

        return $query->orderByDesc('sales_returns.return_date')->orderByDesc('sales_returns.id');
    }

    /**
     * Resolve the exact SalesReturnLine for an InventoryDocumentLine using
     * source_line_type/source_line_id only. Returns null when lineage is
     * absent or mismatched; callers must NOT fall back to product_id.
     */
    private function resolveExactReturnLine(SalesReturn $return, InventoryDocumentLine $line): ?SalesReturnLine
    {
        if ($line->source_line_type === SalesReturnLine::class && $line->source_line_id) {
            return $return->lines->firstWhere('id', $line->source_line_id);
        }

        return null;
    }

    /**
     * Reconcile delivery journal: COGS-account net debit minus credit must equal
     * the FULL delivery document aggregate total cost at scale 4.
     * Requires is_posted true and source_type/source identity match.
     */
    private function reconcileDeliveryJournal(
        ?JournalEntry $journal,
        int $cogsAccountId,
        string $fullAggregateCost,
        InventoryDocument $document,
    ): bool {
        if (! $journal || ! $journal->is_posted || $journal->status !== JournalEntry::StatusPosted) {
            return false;
        }

        if ($journal->source_type !== 'sales_delivery_cogs') {
            return false;
        }

        if ((int) $journal->source_id !== (int) $document->getKey()
            || $journal->source_doc_num !== $document->doc_num) {
            return false;
        }

        if ((int) $journal->company_id !== (int) $document->company_id
            || (int) $journal->branch_id !== (int) $document->branch_id
            || (int) $journal->financial_period_id !== (int) $document->financial_period_id
            || $journal->entry_date?->toDateString() !== $document->document_date?->toDateString()) {
            return false;
        }

        return bccomp($this->journalCogsNet($journal, $cogsAccountId), $fullAggregateCost, 4) === 0;
    }

    /**
     * Reconcile return quarantine journal: COGS-account net credit minus debit
     * must equal the FULL return document aggregate total cost at scale 4.
     * Requires is_posted true, source identity, and entry_date must match the
     * posted return inventory document's document_date.
     */
    private function reconcileReturnJournal(
        ?JournalEntry $journal,
        int $cogsAccountId,
        string $fullAggregateCost,
        SalesReturn $return,
        InventoryDocument $inventoryDoc,
    ): bool {
        if (! $journal || ! $journal->is_posted || $journal->status !== JournalEntry::StatusPosted) {
            return false;
        }

        if ($journal->source_type !== 'sales_return_quarantine_receipt') {
            return false;
        }

        if ((int) $journal->source_id !== (int) $return->getKey()
            || $journal->source_doc_num !== $return->doc_num) {
            return false;
        }

        // entry_date must match the posted return inventory document date
        if ((int) $journal->company_id !== (int) $return->company_id
            || (int) $journal->branch_id !== (int) $return->branch_id
            || (int) $journal->financial_period_id !== (int) $inventoryDoc->financial_period_id
            || $journal->entry_date?->toDateString() !== $inventoryDoc->document_date?->toDateString()) {
            return false;
        }

        return bccomp($this->journalCogsCreditMinusDebit($journal, $cogsAccountId), $fullAggregateCost, 4) === 0;
    }

    /** For delivery journals: debit_amount minus credit_amount on the COGS account. */
    private function journalCogsNet(JournalEntry $journal, int $cogsAccountId): string
    {
        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($journal->lines as $line) {
            if ((int) $line->account_id === $cogsAccountId) {
                $debit = bcadd($debit, (string) $line->debit_amount, 4);
                $credit = bcadd($credit, (string) $line->credit_amount, 4);
            }
        }

        return bcsub($debit, $credit, 4);
    }

    /** For return journals: credit_amount minus debit_amount on the COGS account. */
    private function journalCogsCreditMinusDebit(JournalEntry $journal, int $cogsAccountId): string
    {
        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($journal->lines as $line) {
            if ((int) $line->account_id === $cogsAccountId) {
                $debit = bcadd($debit, (string) $line->debit_amount, 4);
                $credit = bcadd($credit, (string) $line->credit_amount, 4);
            }
        }

        return bcsub($credit, $debit, 4);
    }

    /** Resolve invoice doc numbers for a delivery from the pivot table. */
    private function resolveDeliveryInvoiceReferences(InventoryDocument $document): string
    {
        return $document->customerInvoices
            ->pluck('doc_num')
            ->implode(', ');
    }

    private function toDecimal(?string $value, int $scale): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }

    private function toNullableDecimal(?string $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return bcadd($value, '0', $scale);
    }
}

/**
 * Immutable result of the Cost of Sales report.
 */
class SalesCostReportResult
{
    /** @param Collection<int, array<string, mixed>> $rows @param array<string, mixed> $summary */
    public function __construct(
        public readonly Collection $rows,
        public readonly array $summary,
    ) {}
}
