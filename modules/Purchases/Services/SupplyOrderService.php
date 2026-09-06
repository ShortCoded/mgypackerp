<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Models\SupplyOrderLine;

class SupplyOrderService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly ProcurementAttachmentService $attachments,
        private readonly ProcurementAuditService $audit,
    ) {}

    public function create(array $data): SupplyOrder
    {
        return $this->save($data);
    }

    public function update(SupplyOrder $supplyOrder, array $data): SupplyOrder
    {
        return $this->save($data, $supplyOrder);
    }

    public function issue(SupplyOrder $supplyOrder): SupplyOrder
    {
        return DB::transaction(function () use ($supplyOrder): SupplyOrder {
            $record = $this->locked($supplyOrder, true);
            if ($record->status === SupplyOrder::StatusIssued) {
                return $record;
            }
            if ($record->status !== SupplyOrder::StatusDraft || $record->lines->isEmpty()) {
                throw new DomainException(__('Only a complete draft supply order can be issued.'));
            }
            $record->forceFill([
                'status' => SupplyOrder::StatusIssued,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();
            $this->audit->record($record, 'supply_order.issued');

            return $record->refresh()->load($this->relations());
        }, 3);
    }

    public function cancel(SupplyOrder $supplyOrder, string $reason): SupplyOrder
    {
        return DB::transaction(function () use ($supplyOrder, $reason): SupplyOrder {
            $record = $this->locked($supplyOrder, true);
            if ($record->status === SupplyOrder::StatusCancelled) {
                return $record;
            }
            if (blank($reason)) {
                throw new DomainException(__('Cancellation reason is required.'));
            }
            if ($record->receipts()->where('approved', true)->whereNotIn('status', ['cancelled', 'reversed'])->exists()) {
                throw new DomainException(__('Reverse or cancel dependent receipts before cancelling this supply order.'));
            }
            if (! in_array($record->status, [SupplyOrder::StatusDraft, SupplyOrder::StatusIssued], true)) {
                throw new DomainException(__('This supply order cannot be cancelled in its current status.'));
            }
            $record->forceFill([
                'status' => SupplyOrder::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
                'updated_by' => auth()->id(),
            ])->save();
            $this->audit->record($record, 'supply_order.cancelled', ['reason' => trim($reason)]);

            return $record->refresh()->load($this->relations());
        }, 3);
    }

    public function deleteDraft(SupplyOrder $supplyOrder): void
    {
        DB::transaction(function () use ($supplyOrder): void {
            $record = $this->locked($supplyOrder);
            if ($record->status !== SupplyOrder::StatusDraft || $record->receipts()->exists()) {
                throw new DomainException(__('Only an unused draft supply order can be deleted.'));
            }
            $record->delete();
            $this->audit->record($record, 'supply_order.deleted');
        }, 3);
    }

    public function syncFulfillmentStatus(SupplyOrder $supplyOrder): SupplyOrder
    {
        $record = SupplyOrder::query()->with('lines')->lockForUpdate()->findOrFail($supplyOrder->getKey());
        if (in_array($record->status, [SupplyOrder::StatusDraft, SupplyOrder::StatusCancelled, SupplyOrder::StatusClosed], true)) {
            return $record;
        }
        $status = $record->fulfillmentStatus();
        if ($record->status !== $status) {
            $record->forceFill(['status' => $status, 'updated_by' => auth()->id()])->save();
        }

        return $record->refresh();
    }

    public function remainingToSupply(PurchaseOrderLine $line, ?SupplyOrder $except = null): float
    {
        $allocated = (float) SupplyOrderLine::query()
            ->where('purchase_order_line_id', $line->getKey())
            ->when($except, fn ($query) => $query->where('supply_order_id', '<>', $except->getKey()))
            ->whereHas('supplyOrder', fn ($query) => $query->whereNotIn('status', [SupplyOrder::StatusCancelled]))
            ->sum('ordered_quantity');

        return max(0, min(
            (float) $line->ordered_quantity - $allocated,
            $line->quantityProgress()['remaining'],
        ));
    }

    /** @return Collection<int, PurchaseOrderLine> */
    public function sourceLines(PurchaseOrder|PurchaseInvoice $source, ?SupplyOrder $editing = null): Collection
    {
        $order = $source instanceof PurchaseOrder ? $source : $source->purchaseOrder;
        if (! $order instanceof PurchaseOrder) {
            throw new DomainException(__('A stock supply order requires a linked purchase order.'));
        }
        $order->setRelation('lines', $order->lines()->withQuantityProgress()->with(['product', 'unit'])->get());
        $editing?->loadMissing('lines');
        $lineIds = $order->lines->modelKeys();
        $allocatedByOrderLine = $this->allocatedQuantities($lineIds, $editing);
        $invoiceQuantities = collect();
        $invoiceAllocations = collect();
        if ($source instanceof PurchaseInvoice) {
            $source->loadMissing('lines');
            $invoiceQuantities = $source->lines->whereNotNull('purchase_order_line_id')
                ->groupBy('purchase_order_line_id')
                ->map(fn (Collection $lines): float => (float) $lines->sum('quantity'));
            $invoiceAllocations = $this->allocatedQuantities($lineIds, $editing, $source);
        }

        foreach ($order->lines as $line) {
            $available = max(0, min(
                (float) $line->ordered_quantity - (float) $allocatedByOrderLine->get($line->getKey(), 0),
                $line->quantityProgress()['remaining'],
            ));
            if ($source instanceof PurchaseInvoice) {
                $available = max(0, min(
                    $available,
                    (float) $invoiceQuantities->get($line->getKey(), 0) - (float) $invoiceAllocations->get($line->getKey(), 0),
                ));
            }
            $line->setAttribute('supply_available_quantity', $available);
        }

        return $order->lines
            ->reject(fn (PurchaseOrderLine $line): bool => $line->product?->isPurchasable() !== true)
            ->filter(fn (PurchaseOrderLine $line): bool => (float) $line->getAttribute('supply_available_quantity') > 0
                || $editing?->lines->contains('purchase_order_line_id', $line->getKey()));
    }

    private function save(array $data, ?SupplyOrder $editing = null): SupplyOrder
    {
        return DB::transaction(function () use ($data, $editing): SupplyOrder {
            $context = $this->context();
            app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                $context['company_id'],
                $data['issue_date'],
                $context['financial_period_id'],
                lockForUpdate: true,
            );
            $record = $editing ? $this->locked($editing, true) : new SupplyOrder;
            if ($editing && $record->status !== SupplyOrder::StatusDraft) {
                throw new DomainException(__('Only a draft supply order can be edited.'));
            }
            $source = $this->source($data['source_type'], $data['source_doc_num'], $context);
            $order = $source instanceof PurchaseOrder ? $source : $source->purchaseOrder;
            if (! $order instanceof PurchaseOrder) {
                throw new DomainException(__('A stock supply order requires a linked purchase order.'));
            }
            $sourceLines = $this->sourceLines($source, $record->exists ? $record : null)->keyBy('public_id');
            $record->fill([
                ...($record->exists ? [] : $this->documents->nextForCompany('supply_orders', SupplyOrder::class, $context['company_id'])),
                ...$context,
                'branch_store_id' => $order->branch_store_id,
                'supplier_id' => $order->supplier_id,
                'source_type' => $data['source_type'],
                'source_id' => $source->getKey(),
                'source_doc_num' => $source->doc_num,
                'purchase_order_id' => $order->getKey(),
                'purchase_invoice_id' => $source instanceof PurchaseInvoice ? $source->getKey() : null,
                'issue_date' => $data['issue_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $record->created_by ?? auth()->id(),
            ]);
            if ($record->isDirty() || ! $record->exists) {
                $record->updated_by = $record->exists ? auth()->id() : null;
                $record->save();
            }

            $kept = [];
            $total = 0.0;
            foreach (array_values($data['lines']) as $index => $input) {
                $orderLine = $sourceLines->get($input['purchase_order_line_public_id'] ?? '');
                if (! $orderLine instanceof PurchaseOrderLine) {
                    throw new DomainException(__('The selected supply order line is outside the source document.'));
                }
                $quantity = (float) $input['ordered_quantity'];
                $existingLine = $record->lines->firstWhere('purchase_order_line_id', $orderLine->getKey());
                $available = (float) $orderLine->getAttribute('supply_available_quantity');
                if ($quantity <= 0 || $quantity > $available + 0.00000001) {
                    throw new DomainException(__('Supply order quantity exceeds the remaining source quantity.'));
                }
                $invoiceLine = $source instanceof PurchaseInvoice
                    ? $source->lines->firstWhere('purchase_order_line_id', $orderLine->getKey())
                    : null;
                $line = $existingLine ?? new SupplyOrderLine;
                $line->fill([
                    'supply_order_id' => $record->getKey(),
                    'company_id' => $context['company_id'],
                    'financial_period_id' => $context['financial_period_id'],
                    'line_number' => $index + 1,
                    'purchase_order_line_id' => $orderLine->getKey(),
                    'purchase_invoice_line_id' => $invoiceLine?->getKey(),
                    'product_id' => $orderLine->product_id,
                    'unit_id' => $orderLine->unit_id,
                    'ordered_quantity' => $this->quantity($quantity),
                    'notes' => $input['notes'] ?? null,
                ]);
                $line->save();
                $this->attachments->attachLine(
                    $line,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                );
                $kept[] = $line->getKey();
                $total += $quantity;
            }
            if ($kept === []) {
                throw new DomainException(__('A supply order requires at least one line.'));
            }
            $record->lines()->whereNotIn('id', $kept)->delete();
            $record->forceFill(['total_ordered_quantity' => $this->quantity($total)])->save();
            $this->attachments->attach($record, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
            $this->audit->record($record, $editing ? 'supply_order.updated' : 'supply_order.created', ['source' => $source->doc_num]);

            return $record->refresh()->load($this->relations());
        }, 3);
    }

    private function source(string $type, string $docNum, array $context): PurchaseOrder|PurchaseInvoice
    {
        if ($type === SupplyOrder::SourcePurchaseOrder) {
            $source = PurchaseOrder::query()->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $context['branch_id'])
                ->where('doc_num', $docNum)->with('lines.product')->lockForUpdate()->firstOrFail();
            if (! $source->isApproved() || in_array($source->status, [PurchaseOrder::StatusClosed, PurchaseOrder::StatusCancelled], true)) {
                throw new DomainException(__('Only an approved open purchase order can create a supply order.'));
            }

            return $source;
        }
        if ($type !== SupplyOrder::SourcePurchaseInvoice) {
            throw new DomainException(__('The selected supply order source is invalid.'));
        }
        $source = PurchaseInvoice::query()->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])->where('branch_id', $context['branch_id'])
            ->where('doc_num', $docNum)->with(['purchaseOrder', 'lines'])->lockForUpdate()->firstOrFail();
        if (! in_array($source->status, [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed], true)) {
            throw new DomainException(__('Only an approved supplier invoice can create a supply order.'));
        }

        return $source;
    }

    /**
     * @param  list<int>  $purchaseOrderLineIds
     * @return Collection<int, float>
     */
    private function allocatedQuantities(array $purchaseOrderLineIds, ?SupplyOrder $except = null, ?PurchaseInvoice $invoice = null): Collection
    {
        return SupplyOrderLine::query()
            ->selectRaw('purchase_order_line_id, SUM(ordered_quantity) as allocated_quantity')
            ->whereIn('purchase_order_line_id', $purchaseOrderLineIds)
            ->when($except, fn ($query) => $query->where('supply_order_id', '<>', $except->getKey()))
            ->whereHas('supplyOrder', fn ($query) => $query
                ->whereNotIn('status', [SupplyOrder::StatusCancelled])
                ->when($invoice, fn ($orders) => $orders->where('purchase_invoice_id', $invoice->getKey())))
            ->groupBy('purchase_order_line_id')
            ->pluck('allocated_quantity', 'purchase_order_line_id')
            ->map(fn (mixed $quantity): float => (float) $quantity);
    }

    private function locked(SupplyOrder $supplyOrder, bool $withLines = false): SupplyOrder
    {
        $query = SupplyOrder::query()->when($withLines, fn ($query) => $query->with('lines'));
        $record = $query->lockForUpdate()->findOrFail($supplyOrder->getKey());
        $context = $this->context();
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id'] || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('The supply order is outside the active operating context.'));
        }

        return $record;
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function context(): array
    {
        $snapshot = $this->operatingContext->snapshot(request());
        if (! $snapshot['company_id'] || ! $snapshot['financial_period_id'] || ! $snapshot['branch_id']) {
            throw new DomainException(__('An operating company, branch, and financial period are required.'));
        }
        if (FinancialPeriod::query()->whereKey($snapshot['financial_period_id'])->value('is_closed')) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }

        return [
            'company_id' => (int) $snapshot['company_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
            'branch_id' => (int) $snapshot['branch_id'],
        ];
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['supplier', 'branchStore', 'purchaseOrder', 'purchaseInvoice', 'lines.product', 'lines.unit', 'lines.purchaseOrderLine'];
    }

    private function quantity(float $quantity): string
    {
        return number_format($quantity, 8, '.', '');
    }
}
