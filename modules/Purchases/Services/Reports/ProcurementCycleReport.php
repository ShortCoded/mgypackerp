<?php

namespace Modules\Purchases\Services\Reports;

use Illuminate\Support\Collection;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseRequisitionLine;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\RequestForQuotation;

class ProcurementCycleReport
{
    public const OpenRequirements = 'open_requirements';

    public const PurchaseOrderStatus = 'purchase_order_status';

    public const RequestedVsOrdered = 'requested_vs_ordered';

    public const RfqQuotationStatus = 'rfq_quotation_status';

    public const OrderedVsReceived = 'ordered_vs_received';

    public const OverduePoDeliveries = 'overdue_po_deliveries';

    public const DeliverySchedule = 'delivery_schedule';

    public const ReceiptQualityStatus = 'receipt_quality_status';

    public const IncomingQcPending = 'incoming_qc_pending';

    public const QcRejection = 'qc_rejection';

    public const PurchasesBySupplier = 'purchases_by_supplier';

    public const PurchasesByProduct = 'purchases_by_product';

    public const PurchasesByPeriod = 'purchases_by_period';

    public const OutstandingSupplierInvoices = 'outstanding_supplier_invoices';

    public const SupplierPayables = self::OutstandingSupplierInvoices;

    public const DueSupplierInstallments = 'due_supplier_installments';

    public const SupplierAging = 'supplier_aging';

    public const UpcomingSupplierPayments = 'upcoming_supplier_payments';

    public const Returns = 'returns';

    public const ProductionAnalysis = 'production_analysis';

    public const GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';

    public static function types(): array
    {
        return [
            self::OpenRequirements,
            self::RequestedVsOrdered,
            self::RfqQuotationStatus,
            self::PurchaseOrderStatus,
            self::OrderedVsReceived,
            self::OverduePoDeliveries,
            self::DeliverySchedule,
            self::IncomingQcPending,
            self::GoodsReceivedNotInvoiced,
            self::QcRejection,
            self::PurchasesBySupplier,
            self::PurchasesByProduct,
            self::PurchasesByPeriod,
            self::OutstandingSupplierInvoices,
            self::DueSupplierInstallments,
            self::SupplierAging,
            self::UpcomingSupplierPayments,
            self::Returns,
            self::ProductionAnalysis,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $type, array $filters, int $companyId, int $financialPeriodId): Collection
    {
        $rows = match ($type) {
            self::OpenRequirements => $this->openRequirements($companyId, $financialPeriodId),
            self::RequestedVsOrdered => $this->requestedVsOrdered($companyId, $financialPeriodId),
            self::RfqQuotationStatus => $this->rfqQuotationStatus($companyId, $financialPeriodId),
            self::PurchaseOrderStatus, self::OrderedVsReceived, self::PurchasesBySupplier,
            self::PurchasesByProduct, self::PurchasesByPeriod => $this->purchaseOrderStatus($companyId, $financialPeriodId),
            self::OverduePoDeliveries => $this->purchaseOrderStatus($companyId, $financialPeriodId)->where('overdue', true)->values(),
            self::DeliverySchedule => $this->deliverySchedule($companyId, $financialPeriodId),
            self::IncomingQcPending => $this->receiptQualityStatus($companyId, $financialPeriodId)->where('qc_status', 'pending_inspection')->values(),
            self::QcRejection => $this->receiptQualityStatus($companyId, $financialPeriodId)->filter(fn (array $row): bool => (float) $row['outstanding'] > 0)->values(),
            self::GoodsReceivedNotInvoiced => $this->goodsReceivedNotInvoiced($companyId, $financialPeriodId),
            self::OutstandingSupplierInvoices, self::SupplierAging => $this->supplierPayables($companyId, $financialPeriodId)->filter(fn (array $row): bool => (float) $row['outstanding'] > 0)->values(),
            self::DueSupplierInstallments => $this->supplierInstallments($companyId, $financialPeriodId, false),
            self::UpcomingSupplierPayments => $this->supplierInstallments($companyId, $financialPeriodId, true),
            self::Returns => $this->returns($companyId, $financialPeriodId),
            self::ProductionAnalysis => $this->productionAnalysis($companyId, $financialPeriodId),
            default => collect(),
        };

        return $this->applyFilters($rows, $filters);
    }

    /** @return list<string> */
    public function headings(bool $showPrices = true, ?string $type = null): array
    {
        if ($type === self::GoodsReceivedNotInvoiced) {
            return ['Receipt Date', 'GRN', 'Supplier', 'PO', 'Product', 'Store', 'Received Qty', 'Invoiced Qty', 'Returned Qty', 'Remaining Qty', 'Provisional Unit Value', 'Remaining GRNI Value', 'Currency', 'Days Outstanding', 'Status'];
        }
        $headings = [
            'Date', 'Document', 'Status', 'Supplier', 'Product', 'Purchase Requisition', 'Purchase Order',
            'Branch', 'Warehouse', 'QC Status', 'Production Order', 'Work Order', 'Quantity',
        ];

        return [
            ...$headings,
            ...($showPrices ? ['Amount'] : []),
            'Outstanding',
            'Overdue',
        ];
    }

    /** @return array{subledger: string, gl: string, difference: string, status: string, account: string|null} */
    public function grniReconciliation(int $companyId, int $financialPeriodId): array
    {
        $mapping = InventoryAccountingMapping::query()->where('company_id', $companyId)->with('grniAccount')->first();
        if (! $mapping?->grni_account_id) {
            return ['subledger' => '0.0000', 'gl' => '0.0000', 'difference' => '0.0000', 'status' => 'not_configured', 'account' => null];
        }

        $subledger = $this->goodsReceivedNotInvoiced($companyId, $financialPeriodId)
            ->reduce(fn (string $total, array $row): string => bcadd($total, (string) $row['remaining_grni_value'], 4), '0.0000');
        $gl = bcadd((string) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.financial_period_id', $financialPeriodId)
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $mapping->grni_account_id)
            ->selectRaw('coalesce(sum((journal_entry_lines.credit_amount - journal_entry_lines.debit_amount) * journal_entries.exchange_rate), 0) as balance')
            ->value('balance'), '0', 4);
        $difference = bcsub($subledger, $gl, 4);

        return [
            'subledger' => $subledger,
            'gl' => $gl,
            'difference' => $difference,
            'status' => bccomp($difference, '0', 4) === 0 ? 'reconciled' : 'difference',
            'account' => $mapping->grniAccount?->doc_num,
        ];
    }

    /** @param array<string, mixed> $row
     * @return list<mixed>
     */
    public function exportMap(array $row, bool $showPrices = true, ?string $type = null): array
    {
        if ($type === self::GoodsReceivedNotInvoiced) {
            return [
                $row['date'], $row['document'], $row['supplier'], $row['purchase_order'], $row['product'], $row['warehouse'],
                $row['received_quantity'], $row['invoiced_quantity'], $row['returned_quantity'], $row['remaining_quantity'],
                $row['provisional_unit_value'], $row['remaining_grni_value'], $row['currency'], $row['age_days'], $row['status'],
            ];
        }
        $values = [
            $row['date'], $row['document'], $row['status'], $row['supplier'], $row['product'],
            $row['requisition'], $row['purchase_order'], $row['branch'], $row['warehouse'], $row['qc_status'],
            $row['production_order'], $row['work_order'], $row['quantity'],
        ];

        return [
            ...$values,
            ...($showPrices ? [$row['amount']] : []),
            $row['outstanding'],
            $row['overdue'] ? 'Yes' : 'No',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function openRequirements(int $companyId, int $periodId): Collection
    {
        return PurchaseRequisitionLine::query()
            ->with(['requisition.branch', 'requisition.branchStore', 'product', 'purchaseOrderLines'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereHas('requisition', fn ($query) => $query->whereIn('status', ['approved', 'partially_converted']))
            ->get()->map(function (PurchaseRequisitionLine $line): array {
                $ordered = (float) $line->purchaseOrderLines->sum('ordered_quantity');
                $outstanding = max(0, (float) $line->approved_quantity - $ordered);

                return $this->row([
                    'date' => $line->requisition?->request_date?->toDateString(),
                    'document' => $line->requisition?->doc_num,
                    'status' => $line->requisition?->status,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'requisition' => $line->requisition?->doc_num,
                    'branch_id' => $line->requisition?->branch_id,
                    'branch' => $line->requisition?->branch?->name,
                    'warehouse_uuid' => $line->requisition?->branchStore?->public_uuid,
                    'warehouse' => $line->requisition?->branchStore?->name,
                    'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                    'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                    'quantity' => $line->approved_quantity,
                    'outstanding' => $outstanding,
                    'overdue' => $outstanding > 0 && $line->required_date?->isPast(),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function requestedVsOrdered(int $companyId, int $periodId): Collection
    {
        return PurchaseRequisitionLine::query()
            ->with(['requisition.branch', 'requisition.branchStore', 'product', 'purchaseOrderLines'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(function (PurchaseRequisitionLine $line): array {
                $ordered = (float) $line->purchaseOrderLines->sum('ordered_quantity');

                return $this->row([
                    'date' => $line->requisition?->request_date?->toDateString(),
                    'document' => $line->requisition?->doc_num,
                    'status' => $line->requisition?->status,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'requisition' => $line->requisition?->doc_num,
                    'branch_id' => $line->requisition?->branch_id,
                    'branch' => $line->requisition?->branch?->name,
                    'warehouse_uuid' => $line->requisition?->branchStore?->public_uuid,
                    'warehouse' => $line->requisition?->branchStore?->name,
                    'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                    'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                    'quantity' => $line->approved_quantity ?: $line->requested_quantity,
                    'outstanding' => max(0, (float) ($line->approved_quantity ?: $line->requested_quantity) - $ordered),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rfqQuotationStatus(int $companyId, int $periodId): Collection
    {
        return RequestForQuotation::query()->with(['requisition.branch', 'suppliers', 'quotations', 'lines'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(fn (RequestForQuotation $rfq): array => $this->row([
                'date' => $rfq->issue_date?->toDateString(),
                'document' => $rfq->doc_num,
                'status' => $rfq->status,
                'supplier' => $rfq->suppliers->pluck('name')->join(', '),
                'requisition' => $rfq->requisition?->doc_num,
                'branch_id' => $rfq->branch_id,
                'branch' => $rfq->requisition?->branch?->name,
                'quantity' => $rfq->lines->sum('quantity'),
                'outstanding' => max(0, $rfq->suppliers->count() - $rfq->quotations->count()),
                'overdue' => $rfq->quotation_due_date?->isPast() && $rfq->quotations->count() < $rfq->suppliers->count(),
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function purchaseOrderStatus(int $companyId, int $periodId): Collection
    {
        return PurchaseOrderLine::query()->with(['purchaseOrder.supplier', 'purchaseOrder.branch', 'purchaseOrder.branchStore', 'product', 'requisitionLine.requisition'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(fn (PurchaseOrderLine $line): array => $this->row([
                'date' => $line->purchaseOrder?->document_date?->toDateString(),
                'document' => $line->purchaseOrder?->doc_num,
                'status' => $line->purchaseOrder?->status,
                'supplier_doc_num' => $line->purchaseOrder?->supplier?->doc_num,
                'supplier' => $line->purchaseOrder?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'requisition' => $line->requisitionLine?->requisition?->doc_num,
                'purchase_order' => $line->purchaseOrder?->doc_num,
                'branch_id' => $line->purchaseOrder?->branch_id,
                'branch' => $line->purchaseOrder?->branch?->name,
                'warehouse_uuid' => $line->purchaseOrder?->branchStore?->public_uuid,
                'warehouse' => $line->purchaseOrder?->branchStore?->name,
                'production_order' => $line->requisitionLine?->source_type === 'production_order' ? $line->requisitionLine?->source_doc_num : null,
                'work_order' => $line->requisitionLine?->source_type === 'work_order' ? $line->requisitionLine?->source_doc_num : null,
                'quantity' => $line->ordered_quantity,
                'amount' => $line->total_after_tax,
                'outstanding' => $line->remaining_quantity,
                'overdue' => (float) $line->remaining_quantity > 0 && $line->required_delivery_date?->isPast(),
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function deliverySchedule(int $companyId, int $periodId): Collection
    {
        return PurchaseOrderDeliverySchedule::query()
            ->with(['purchaseOrder.supplier', 'purchaseOrder.branch', 'purchaseOrder.branchStore', 'purchaseOrderLine.product'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(fn (PurchaseOrderDeliverySchedule $schedule): array => $this->row([
                'date' => $schedule->scheduled_date?->toDateString(),
                'document' => $schedule->purchaseOrder?->doc_num.' / '.$schedule->sequence,
                'status' => $schedule->status,
                'supplier_doc_num' => $schedule->purchaseOrder?->supplier?->doc_num,
                'supplier' => $schedule->purchaseOrder?->supplier?->name,
                'product_doc_num' => $schedule->purchaseOrderLine?->product?->doc_num,
                'product' => $schedule->purchaseOrderLine?->product?->name,
                'purchase_order' => $schedule->purchaseOrder?->doc_num,
                'branch_id' => $schedule->purchaseOrder?->branch_id,
                'branch' => $schedule->purchaseOrder?->branch?->name,
                'warehouse_uuid' => $schedule->purchaseOrder?->branchStore?->public_uuid,
                'warehouse' => $schedule->purchaseOrder?->branchStore?->name,
                'quantity' => $schedule->scheduled_quantity,
                'outstanding' => max(0, (float) $schedule->scheduled_quantity - (float) $schedule->received_quantity),
                'overdue' => $schedule->scheduled_date?->isPast() && $schedule->status !== 'received',
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function receiptQualityStatus(int $companyId, int $periodId): Collection
    {
        return UnpricedInventoryReceiptLine::query()->with(['receipt.supplier', 'receipt.branch', 'receipt.branchStore', 'product', 'purchaseOrderLine.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->whereNotNull('purchase_order_line_id')->get()
            ->map(fn (UnpricedInventoryReceiptLine $line): array => $this->row([
                'date' => $line->receipt?->document_date?->toDateString(),
                'document' => $line->receipt?->doc_num,
                'status' => $line->receipt?->status,
                'supplier_doc_num' => $line->receipt?->supplier?->doc_num,
                'supplier' => $line->receipt?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'purchase_order' => $line->purchaseOrderLine?->purchaseOrder?->doc_num,
                'branch_id' => $line->receipt?->branch_id,
                'branch' => $line->receipt?->branch?->name,
                'warehouse_uuid' => $line->receipt?->branchStore?->public_uuid,
                'warehouse' => $line->receipt?->branchStore?->name,
                'qc_status' => $line->receipt?->qc_status,
                'quantity' => $line->delivered_quantity,
                'outstanding' => $line->rejected_quantity,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function goodsReceivedNotInvoiced(int $companyId, int $periodId): Collection
    {
        return UnpricedInventoryReceiptLine::query()
            ->with(['receipt.supplier', 'receipt.branch', 'receipt.branchStore', 'product', 'purchaseOrderLine.purchaseOrder.currency'])
            ->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)
            ->where('accepted_quantity', '>', 0)
            ->whereNotNull('grni_journal_entry_id')
            ->get()
            ->map(function (UnpricedInventoryReceiptLine $line): array {
                $eligibleQuantity = bcsub((string) $line->accepted_quantity, (string) $line->grni_returned_quantity, 8);
                $remainingQuantity = bcsub($eligibleQuantity, (string) $line->grni_cleared_quantity, 8);
                $remainingValue = bcsub(
                    bcsub((string) $line->provisional_total_value, (string) $line->grni_returned_value, 4),
                    (string) $line->grni_cleared_value,
                    4,
                );
                $receipt = $line->receipt;
                $order = $line->purchaseOrderLine?->purchaseOrder;

                return $this->row([
                    'date' => $receipt?->document_date?->toDateString(),
                    'document' => $receipt?->doc_num,
                    'status' => bccomp($remainingQuantity, '0', 8) > 0 ? 'open' : 'cleared',
                    'supplier_doc_num' => $receipt?->supplier?->doc_num,
                    'supplier' => $receipt?->supplier?->name,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'purchase_order' => $order?->doc_num,
                    'branch_id' => $receipt?->branch_id,
                    'branch' => $receipt?->branch?->name,
                    'warehouse_uuid' => $receipt?->branchStore?->public_uuid,
                    'warehouse' => $receipt?->branchStore?->name,
                    'quantity' => $line->accepted_quantity,
                    'amount' => $line->provisional_unit_value,
                    'outstanding' => $remainingValue,
                    'received_quantity' => $line->accepted_quantity,
                    'invoiced_quantity' => $line->grni_cleared_quantity,
                    'returned_quantity' => $line->grni_returned_quantity,
                    'remaining_quantity' => $remainingQuantity,
                    'provisional_unit_value' => $line->provisional_unit_value,
                    'remaining_grni_value' => $remainingValue,
                    'currency' => $order?->currency?->doc_num,
                    'age_days' => $receipt?->document_date?->diffInDays(today()) ?? 0,
                    'overdue' => bccomp($remainingQuantity, '0', 8) > 0 && $receipt?->document_date?->lt(today()->subDays(30)),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function supplierPayables(int $companyId, int $periodId): Collection
    {
        return PurchaseInvoice::query()->with(['supplier', 'branch', 'purchaseOrder', 'paymentSchedules'])->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
            ->get()->map(fn (PurchaseInvoice $invoice): array => $this->row([
                'date' => $invoice->invoice_date?->toDateString(),
                'document' => $invoice->doc_num,
                'status' => $invoice->status,
                'supplier_doc_num' => $invoice->supplier?->doc_num,
                'supplier' => $invoice->supplier?->name,
                'purchase_order' => $invoice->purchaseOrder?->doc_num,
                'branch_id' => $invoice->branch_id,
                'branch' => $invoice->branch?->name,
                'amount' => $invoice->total_amount,
                'outstanding' => $invoice->remaining_amount,
                'overdue' => (float) $invoice->remaining_amount > 0 && $invoice->paymentSchedules
                    ->where('due_date', '<', today())
                    ->whereNotIn('status', ['paid', 'settled', 'cancelled'])
                    ->isNotEmpty(),
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function supplierInstallments(int $companyId, int $periodId, bool $upcomingOnly): Collection
    {
        return PurchaseInvoicePaymentSchedule::query()->with(['purchaseInvoice.supplier', 'purchaseInvoice.branch', 'purchaseInvoice.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed]))
            ->get()
            ->map(function (PurchaseInvoicePaymentSchedule $schedule): array {
                $invoice = $schedule->purchaseInvoice;
                $outstanding = $schedule->outstanding_amount;

                return $this->row([
                    'date' => $schedule->due_date?->toDateString(),
                    'document' => $invoice?->doc_num.' / '.$schedule->line_number,
                    'status' => $schedule->status,
                    'supplier_doc_num' => $invoice?->supplier?->doc_num,
                    'supplier' => $invoice?->supplier?->name,
                    'purchase_order' => $invoice?->purchaseOrder?->doc_num,
                    'branch_id' => $invoice?->branch_id,
                    'branch' => $invoice?->branch?->name,
                    'amount' => $schedule->amount,
                    'outstanding' => $outstanding,
                    'overdue' => (float) $outstanding > 0 && $schedule->due_date?->isPast(),
                ]);
            })
            ->when($upcomingOnly, fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => (float) $row['outstanding'] > 0 && filled($row['date']) && $row['date'] >= today()->toDateString()))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function returns(int $companyId, int $periodId): Collection
    {
        return PurchaseReturnLine::query()->with(['purchaseReturn.supplier', 'purchaseReturn.purchaseOrder', 'purchaseReturn.receipt.branchStore', 'product'])
            ->whereHas('purchaseReturn', fn ($query) => $query->where('company_id', $companyId)->where('financial_period_id', $periodId))
            ->get()->map(fn (PurchaseReturnLine $line): array => $this->row([
                'date' => $line->purchaseReturn?->return_date?->toDateString(),
                'document' => $line->purchaseReturn?->doc_num,
                'status' => $line->purchaseReturn?->status,
                'supplier_doc_num' => $line->purchaseReturn?->supplier?->doc_num,
                'supplier' => $line->purchaseReturn?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'purchase_order' => $line->purchaseReturn?->purchaseOrder?->doc_num,
                'warehouse_uuid' => $line->purchaseReturn?->receipt?->branchStore?->public_uuid,
                'warehouse' => $line->purchaseReturn?->receipt?->branchStore?->name,
                'qc_status' => $line->from_quarantine ? 'quarantine_return' : 'usable_stock_return',
                'quantity' => $line->quantity,
                'amount' => $line->line_total,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function productionAnalysis(int $companyId, int $periodId): Collection
    {
        return PurchaseRequisitionLine::query()->with(['requisition.branch', 'product', 'purchaseOrderLines.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereIn('source_type', ['production_order', 'work_order'])->get()
            ->map(fn (PurchaseRequisitionLine $line): array => $this->row([
                'date' => $line->requisition?->request_date?->toDateString(),
                'document' => $line->requisition?->doc_num,
                'status' => $line->requisition?->status,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'requisition' => $line->requisition?->doc_num,
                'purchase_order' => $line->purchaseOrderLines->first()?->purchaseOrder?->doc_num,
                'branch_id' => $line->requisition?->branch_id,
                'branch' => $line->requisition?->branch?->name,
                'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                'quantity' => $line->approved_quantity,
                'outstanding' => max(0, (float) $line->approved_quantity - (float) $line->purchaseOrderLines->sum('ordered_quantity')),
            ]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $exact = [
            'supplier_doc_num' => 'supplier_doc_num', 'product_doc_num' => 'product_doc_num',
            'purchase_requisition_doc_num' => 'requisition', 'purchase_order_doc_num' => 'purchase_order',
            'status' => 'status', 'branch_id' => 'branch_id', 'warehouse_uuid' => 'warehouse_uuid',
            'qc_status' => 'qc_status', 'production_order_doc_num' => 'production_order',
            'work_order_reference' => 'work_order',
        ];
        foreach ($exact as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $rows = $rows->where($column, $filters[$filter]);
            }
        }
        if (filled($filters['date_from'] ?? null)) {
            $rows = $rows->filter(fn (array $row): bool => filled($row['date']) && $row['date'] >= $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $rows = $rows->filter(fn (array $row): bool => filled($row['date']) && $row['date'] <= $filters['date_to']);
        }
        if (($filters['overdue'] ?? null) === '1') {
            $rows = $rows->where('overdue', true);
        }
        if (($filters['outstanding'] ?? null) === '1') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['outstanding'] > 0);
        }

        return $rows->values();
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return [
            'date' => null, 'document' => null, 'status' => null, 'supplier_doc_num' => null, 'supplier' => null,
            'product_doc_num' => null, 'product' => null, 'requisition' => null, 'purchase_order' => null,
            'branch_id' => null, 'branch' => null, 'warehouse_uuid' => null, 'warehouse' => null,
            'qc_status' => null, 'production_order' => null, 'work_order' => null, 'quantity' => 0,
            'amount' => 0, 'outstanding' => 0, 'overdue' => false,
            'received_quantity' => 0, 'invoiced_quantity' => 0, 'returned_quantity' => 0,
            'remaining_quantity' => 0, 'provisional_unit_value' => 0,
            'remaining_grni_value' => 0, 'currency' => null, 'age_days' => 0,
            ...$values,
        ];
    }
}
