<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrderLine;

class SalesIssueOrderService
{
    public function __construct(private readonly SalesFulfillmentService $fulfillment) {}

    public function ensureForPostedInvoice(CustomerInvoice $invoice): ?SalesIssueOrder
    {
        $invoice->loadMissing(['lines', 'order', 'deliveries.lines']);
        if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== CustomerInvoice::StatusPosted) {
            throw new DomainException(__('sales_issue.messages.posted_invoice_required'));
        }
        if (! $invoice->lines->contains(fn (CustomerInvoiceLine $line): bool => ! $line->is_service)) {
            return null;
        }

        $remaining = $this->remainingLines($invoice);
        $status = $remaining === [] ? SalesIssueOrder::StatusIssued : SalesIssueOrder::StatusPending;

        return SalesIssueOrder::query()->firstOrCreate(
            ['customer_invoice_id' => $invoice->getKey()],
            [
                'doc_num' => 'SIO-'.str_pad((string) $invoice->getKey(), 6, '0', STR_PAD_LEFT),
                'company_id' => $invoice->company_id,
                'financial_period_id' => $invoice->financial_period_id,
                'branch_id' => $invoice->branch_id,
                'branch_store_id' => $invoice->order?->branch_store_id,
                'status' => $status,
                'created_by' => auth()->id(),
            ],
        );
    }

    /** @return list<array{line: CustomerInvoiceLine, remaining: string}> */
    public function remainingLines(CustomerInvoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'deliveries.lines']);
        $postedLines = $invoice->deliveries
            ->filter(fn (InventoryDocument $document): bool => $document->status === InventoryDocument::StatusPosted)
            ->flatMap->lines;
        $deliveredBySource = [];
        foreach ($postedLines as $documentLine) {
            $key = $documentLine->source_line_type.':'.$documentLine->source_line_id;
            $deliveredBySource[$key] = bcadd($deliveredBySource[$key] ?? '0', (string) $documentLine->transaction_quantity, 8);
        }
        $remaining = [];

        foreach ($invoice->lines as $line) {
            if ($line->is_service || $line->product_id === null) {
                continue;
            }
            $sourceType = $line->sales_order_line_id ? SalesOrderLine::class : CustomerInvoiceLine::class;
            $sourceId = $line->sales_order_line_id ?: $line->getKey();
            $key = $sourceType.':'.$sourceId;
            $issued = $deliveredBySource[$key] ?? '0';
            $consumed = bccomp($issued, (string) $line->quantity, 8) > 0 ? (string) $line->quantity : $issued;
            $quantity = bcsub((string) $line->quantity, $consumed, 8);
            $deliveredBySource[$key] = bcsub($issued, $consumed, 8);
            if (bccomp($quantity, '0', 8) > 0) {
                $remaining[] = ['line' => $line, 'remaining' => $quantity];
            }
        }

        return $remaining;
    }

    public function issue(SalesIssueOrder $order, BranchStore $store, string $documentDate): InventoryDocument
    {
        return DB::transaction(function () use ($order, $store, $documentDate): InventoryDocument {
            $locked = SalesIssueOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $invoice = CustomerInvoice::query()->with(['lines', 'order', 'deliveries.lines'])->lockForUpdate()->findOrFail($locked->customer_invoice_id);
            $store = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($store->getKey());

            if ($locked->status !== SalesIssueOrder::StatusPending
                || $invoice->posting_status !== CustomerInvoice::StatusPosted
                || $invoice->document_type !== CustomerInvoice::TypeInvoice
                || (int) $invoice->company_id !== (int) $locked->company_id
                || (int) $invoice->branch_id !== (int) $locked->branch_id
                || (int) $store->branch?->company_id !== (int) $locked->company_id
                || ($locked->branch_store_id !== null && (int) $locked->branch_store_id !== (int) $store->getKey())) {
                throw new DomainException(__('sales_issue.messages.order_not_eligible'));
            }

            $remaining = $this->remainingLines($invoice);
            if ($remaining === []) {
                throw new DomainException(__('sales_issue.messages.order_already_issued'));
            }

            $issue = $this->fulfillment->deliverInvoice(
                $invoice,
                array_map(fn (array $row): array => [
                    'customer_invoice_line_id' => $row['line']->getKey(),
                    'quantity' => $row['remaining'],
                ], $remaining),
                ['branch_store_uuid' => $store->public_uuid, 'document_date' => $documentDate],
                allowCompanyWarehouse: true,
            );

            $issue->update(['sales_issue_order_id' => $locked->getKey()]);
            $locked->update([
                'branch_store_id' => $store->getKey(),
                'status' => SalesIssueOrder::StatusIssued,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
            ]);

            return $issue->refresh()->load(['lines.product', 'branchStore', 'salesIssueOrder']);
        });
    }
}
