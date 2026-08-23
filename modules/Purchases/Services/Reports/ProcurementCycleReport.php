<?php

namespace Modules\Purchases\Services\Reports;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseRequisitionLine;
use Modules\Purchases\Models\PurchaseReturnLine;

class ProcurementCycleReport
{
    public const OpenRequirements = 'open_requirements';

    public const PurchaseOrderStatus = 'purchase_order_status';

    public const ReceiptQualityStatus = 'receipt_quality_status';

    public const SupplierPayables = 'supplier_payables';

    public const Returns = 'returns';

    public const ProductionAnalysis = 'production_analysis';

    public static function types(): array
    {
        return [
            self::OpenRequirements,
            self::PurchaseOrderStatus,
            self::ReceiptQualityStatus,
            self::SupplierPayables,
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
            self::PurchaseOrderStatus => $this->purchaseOrderStatus($companyId, $financialPeriodId),
            self::ReceiptQualityStatus => $this->receiptQualityStatus($companyId, $financialPeriodId),
            self::SupplierPayables => $this->supplierPayables($companyId, $financialPeriodId),
            self::Returns => $this->returns($companyId, $financialPeriodId),
            self::ProductionAnalysis => $this->productionAnalysis($companyId, $financialPeriodId),
            default => collect(),
        };

        return $this->applyFilters($rows, $filters);
    }

    /** @return list<string> */
    public function headings(bool $showPrices = true): array
    {
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

    /** @param array<string, mixed> $row
     * @return list<mixed>
     */
    public function exportMap(array $row, bool $showPrices = true): array
    {
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
    private function supplierPayables(int $companyId, int $periodId): Collection
    {
        return PurchaseInvoice::query()->with(['supplier', 'branch', 'purchaseOrder', 'paymentSchedules'])->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)->get()->map(fn (PurchaseInvoice $invoice): array => $this->row([
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
            ...$values,
        ];
    }
}
