<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseRequisitionLine;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\RequestForQuotationLine;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierQuotationLine;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplierSelectionLine;

class ProcurementSourcingService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly ProcurementAuditService $audit,
        private readonly ProcurementAttachmentService $attachments,
    ) {}

    public function createRequisition(array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($data): PurchaseRequisition {
            $context = $this->context();
            $store = $this->store($context['branch_id'], $data['branch_store_uuid'] ?? null, true);
            $requisition = PurchaseRequisition::query()->create([
                ...$this->number('purchase_requisitions', PurchaseRequisition::class, $context),
                ...$context,
                'branch_store_id' => $store?->getKey(),
                'request_date' => $data['request_date'],
                'required_by_date' => $data['required_by_date'] ?? null,
                'department' => $data['department'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => PurchaseRequisition::StatusDraft,
                'notes' => $data['notes'] ?? null,
                'requested_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($data['lines']) as $index => $input) {
                $product = $this->product($context['company_id'], $input['product_doc_num']);
                $unit = $this->unitOptions->unitForProduct($product, $input['unit_doc_num'] ?? null, $context['company_id']);
                $productionSource = $this->productionSource($input, $context);
                $this->assertDemandSourceIsAvailable($product, $input, $context);

                if ($unit === null) {
                    throw new DomainException(__('The selected unit is not available for this item.'));
                }

                $requisition->lines()->create([
                    'company_id' => $context['company_id'],
                    'financial_period_id' => $context['financial_period_id'],
                    'line_number' => $index + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $unit->getKey(),
                    'requested_quantity' => $this->quantity($input['requested_quantity']),
                    'approved_quantity' => 0,
                    'required_date' => $input['required_date'] ?? $data['required_by_date'] ?? null,
                    'source_type' => $input['source_type'] ?? 'manual',
                    'source_doc_num' => $input['source_doc_num'] ?? null,
                    'source_line_reference' => $input['source_line_reference'] ?? null,
                    'production_order_id' => $productionSource['production_order_id'],
                    'production_order_line_id' => $productionSource['production_order_line_id'],
                    'specification' => $input['specification'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }

            $requisition = $requisition->refresh()->load(['lines.product', 'lines.unit', 'branch', 'branchStore']);
            $this->audit->record($requisition, 'purchase_requisition.created', ['line_count' => $requisition->lines->count()]);

            return $requisition;
        }, 3);
    }

    public function submitRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition);
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusDraft]);
            $locked->forceFill(['status' => 'pending_approval', 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'purchase_requisition.submitted');

            return $locked->refresh();
        }, 3);
    }

    public function approveRequisition(PurchaseRequisition $requisition, array $approvedQuantities = []): PurchaseRequisition
    {
        return DB::transaction(function () use ($approvedQuantities, $requisition): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition, true);
            $this->requireStatus($locked->status, ['pending_approval']);

            foreach ($locked->lines as $line) {
                $approved = $approvedQuantities[$line->public_id] ?? $line->requested_quantity;
                $approved = (float) $approved;

                if ($approved < 0 || $approved > (float) $line->requested_quantity) {
                    throw new DomainException(__('Approved quantity must be between zero and the requested quantity.'));
                }

                $line->forceFill(['approved_quantity' => $this->quantity($approved), 'updated_by' => auth()->id()])->save();
            }

            if ($locked->lines->sum(fn (PurchaseRequisitionLine $line): float => (float) $line->approved_quantity) <= 0) {
                throw new DomainException(__('At least one line must have an approved quantity.'));
            }

            $locked->forceFill([
                'status' => PurchaseRequisition::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();
            $this->audit->record($locked, 'purchase_requisition.approved');

            return $locked->refresh()->load(['lines.product', 'lines.unit']);
        }, 3);
    }

    public function createRequestForQuotation(PurchaseRequisition $requisition, array $data): RequestForQuotation
    {
        return DB::transaction(function () use ($data, $requisition): RequestForQuotation {
            $context = $this->context();
            $locked = $this->lockRequisition($requisition, true);
            $this->assertContext($locked, $context);
            $this->requireStatus($locked->status, [
                PurchaseRequisition::StatusApproved,
                PurchaseRequisition::StatusPartiallyConverted,
            ]);

            $rfq = RequestForQuotation::query()->create([
                ...$this->number('request_for_quotations', RequestForQuotation::class, $context),
                ...$context,
                'purchase_requisition_id' => $locked->getKey(),
                'issue_date' => $data['issue_date'],
                'quotation_due_date' => $data['quotation_due_date'] ?? null,
                'required_delivery_date' => $data['required_delivery_date'] ?? null,
                'status' => 'draft',
                'commercial_notes' => $data['commercial_notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($data['lines']) as $index => $input) {
                $line = $locked->lines->firstWhere('public_id', $input['requisition_line_public_id'] ?? null);

                if (! $line instanceof PurchaseRequisitionLine) {
                    throw new DomainException(__('The selected purchase requirement line is invalid.'));
                }

                $alreadyRequested = (float) RequestForQuotationLine::query()
                    ->where('purchase_requisition_line_id', $line->getKey())
                    ->whereHas('requestForQuotation', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
                    ->sum('quantity');
                $quantity = (float) $input['quantity'];

                if ($quantity <= 0 || $alreadyRequested + $quantity > (float) $line->approved_quantity + 0.00000001) {
                    throw new DomainException(__('RFQ quantity exceeds the remaining approved requirement.'));
                }

                $rfq->lines()->create([
                    'purchase_requisition_line_id' => $line->getKey(),
                    'line_number' => $index + 1,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => $this->quantity($quantity),
                    'specification' => $line->specification,
                    'notes' => $input['notes'] ?? null,
                ]);
            }

            $supplierIds = collect($data['supplier_doc_nums'])->map(function (string $docNum) use ($context): int {
                return $this->supplier($context['company_id'], $docNum)->getKey();
            })->unique()->values()->all();
            $rfq->suppliers()->sync($supplierIds);

            $rfq = $rfq->refresh()->load(['requisition', 'lines.product', 'lines.unit', 'suppliers']);
            $this->audit->record($rfq, 'request_for_quotation.created', ['line_count' => $rfq->lines->count(), 'supplier_count' => $rfq->suppliers->count()]);

            return $rfq;
        }, 3);
    }

    public function issueRequestForQuotation(RequestForQuotation $rfq): RequestForQuotation
    {
        return DB::transaction(function () use ($rfq): RequestForQuotation {
            $locked = RequestForQuotation::query()->with(['lines', 'suppliers'])->lockForUpdate()->findOrFail($rfq->getKey());
            $this->assertContext($locked, $this->context());
            $this->requireStatus($locked->status, ['draft']);

            if ($locked->lines->isEmpty() || $locked->suppliers->isEmpty()) {
                throw new DomainException(__('An RFQ requires at least one line and one supplier.'));
            }

            $locked->forceFill([
                'status' => 'issued', 'issued_by' => auth()->id(), 'issued_at' => now(), 'updated_by' => auth()->id(),
            ])->save();
            foreach ($locked->suppliers as $supplier) {
                $locked->suppliers()->updateExistingPivot($supplier->getKey(), ['status' => 'sent', 'sent_at' => now()]);
            }
            $this->audit->record($locked, 'request_for_quotation.issued');

            return $locked->refresh()->load(['lines.product', 'suppliers']);
        }, 3);
    }

    public function createSupplierQuotation(RequestForQuotation $rfq, array $data): SupplierQuotation
    {
        return DB::transaction(function () use ($data, $rfq): SupplierQuotation {
            $context = $this->context();
            $locked = RequestForQuotation::query()->with(['lines', 'suppliers'])->lockForUpdate()->findOrFail($rfq->getKey());
            $this->assertContext($locked, $context);
            $this->requireStatus($locked->status, ['issued']);
            $supplier = $this->supplier($context['company_id'], $data['supplier_doc_num']);

            if (! $locked->suppliers->contains(fn (Supplier $candidate): bool => $candidate->is($supplier))) {
                throw new DomainException(__('The supplier was not invited to this RFQ.'));
            }

            if ($locked->quotations()->where('supplier_id', $supplier->getKey())->whereNotIn('status', ['cancelled', 'rejected'])->exists()) {
                throw new DomainException(__('An active quotation for this supplier and RFQ already exists.'));
            }

            $quotation = SupplierQuotation::query()->create([
                ...$this->number('supplier_quotations', SupplierQuotation::class, $context),
                ...$context,
                'request_for_quotation_id' => $locked->getKey(),
                'supplier_id' => $supplier->getKey(),
                'currency_id' => $this->currency($context['company_id'], $data['currency_doc_num'] ?? null)?->getKey(),
                'exchange_rate' => number_format((float) $data['exchange_rate'], 6, '.', ''),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'quotation_date' => $data['quotation_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'freight_amount' => $this->amount($data['freight_amount'] ?? 0),
                'status' => 'draft',
                'commercial_notes' => $data['commercial_notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $subtotal = 0.0;
            $discount = 0.0;
            $tax = 0.0;

            foreach (array_values($data['lines']) as $index => $input) {
                $rfqLine = $locked->lines->firstWhere('public_id', $input['rfq_line_public_id'] ?? null);

                if (! $rfqLine instanceof RequestForQuotationLine) {
                    throw new DomainException(__('The selected RFQ line is invalid.'));
                }

                $quantity = (float) $input['offered_quantity'];
                $unitPrice = (float) $input['unit_price'];
                $lineDiscount = (float) ($input['discount_amount'] ?? 0);
                $lineSubtotal = $quantity * $unitPrice;
                $taxable = max(0, $lineSubtotal - $lineDiscount);
                $taxRate = (float) ($input['tax_rate'] ?? 0);
                $lineTax = $taxable * $taxRate / 100;
                $lineTotal = $taxable + $lineTax;

                if ($quantity <= 0 || $unitPrice < 0 || $lineDiscount > $lineSubtotal) {
                    throw new DomainException(__('Supplier quotation line values are invalid.'));
                }

                $quotation->lines()->create([
                    'request_for_quotation_line_id' => $rfqLine->getKey(),
                    'line_number' => $index + 1,
                    'product_id' => $rfqLine->product_id,
                    'unit_id' => $rfqLine->unit_id,
                    'offered_quantity' => $this->quantity($quantity),
                    'unit_price' => $this->amount($unitPrice),
                    'discount_amount' => $this->amount($lineDiscount),
                    'tax_rate' => $this->amount($taxRate),
                    'tax_amount' => $this->amount($lineTax),
                    'line_total' => $this->amount($lineTotal),
                    'delivery_date' => $input['delivery_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                ]);

                $subtotal += $lineSubtotal;
                $discount += $lineDiscount;
                $tax += $lineTax;
            }

            $freight = (float) $quotation->freight_amount;
            $quotation->forceFill([
                'subtotal_amount' => $this->amount($subtotal),
                'discount_amount' => $this->amount($discount),
                'tax_amount' => $this->amount($tax),
                'total_amount' => $this->amount($subtotal - $discount + $tax + $freight),
            ])->save();
            $this->attachments->attach(
                $quotation,
                $data['attachment_file_doc_nums'] ?? [],
                SupplierQuotation::AttachmentCollection,
                $context['company_id'],
            );

            $quotation = $quotation->refresh()->load(['supplier', 'currency', 'lines.product', 'requestForQuotation', 'attachmentUsages.file']);
            $this->audit->record($quotation, 'supplier_quotation.created', ['line_count' => $quotation->lines->count()]);

            return $quotation;
        }, 3);
    }

    public function submitSupplierQuotation(SupplierQuotation $quotation): SupplierQuotation
    {
        return DB::transaction(function () use ($quotation): SupplierQuotation {
            $locked = SupplierQuotation::query()->with('lines')->lockForUpdate()->findOrFail($quotation->getKey());
            $this->assertContext($locked, $this->context());
            $this->requireStatus($locked->status, ['draft']);

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('A supplier quotation requires at least one line.'));
            }

            $locked->forceFill([
                'status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'updated_by' => auth()->id(),
            ])->save();
            $this->audit->record($locked, 'supplier_quotation.submitted');

            return $locked->refresh()->load(['supplier', 'lines.product']);
        }, 3);
    }

    public function createSupplierSelection(RequestForQuotation $rfq, array $data): SupplierSelection
    {
        return DB::transaction(function () use ($data, $rfq): SupplierSelection {
            $context = $this->context();
            $locked = RequestForQuotation::query()->with('lines.requisitionLine')->lockForUpdate()->findOrFail($rfq->getKey());
            $this->assertContext($locked, $context);
            $this->requireStatus($locked->status, ['issued']);

            $selection = SupplierSelection::query()->create([
                ...$this->number('supplier_selections', SupplierSelection::class, $context),
                ...$context,
                'request_for_quotation_id' => $locked->getKey(),
                'selection_date' => $data['selection_date'],
                'status' => 'draft',
                'selection_reason' => $data['selection_reason'] ?? null,
                'selected_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $pendingByRequirement = [];
            foreach (array_values($data['lines']) as $input) {
                $quotationLine = SupplierQuotationLine::query()
                    ->with(['quotation', 'rfqLine.requisitionLine'])
                    ->lockForUpdate()
                    ->where('public_id', $input['quotation_line_public_id'])
                    ->first();

                if (! $quotationLine instanceof SupplierQuotationLine
                    || (int) $quotationLine->quotation->request_for_quotation_id !== (int) $locked->getKey()
                    || $quotationLine->quotation->status !== 'submitted') {
                    throw new DomainException(__('Only submitted quotation lines from this RFQ may be selected.'));
                }

                $quantity = (float) $input['selected_quantity'];
                if ($quantity <= 0 || $quantity > (float) $quotationLine->offered_quantity + 0.00000001) {
                    throw new DomainException(__('Selected quantity exceeds the supplier offer.'));
                }

                $requirementLine = $quotationLine->rfqLine->requisitionLine;
                $pendingByRequirement[$requirementLine->getKey()] = ($pendingByRequirement[$requirementLine->getKey()] ?? 0) + $quantity;
                $this->assertSelectionCapacity($requirementLine, $selection, $pendingByRequirement[$requirementLine->getKey()]);
                $discountPerUnit = (float) $quotationLine->discount_amount / (float) $quotationLine->offered_quantity;
                $discount = $discountPerUnit * $quantity;
                $subtotal = $quantity * (float) $quotationLine->unit_price;
                $tax = max(0, $subtotal - $discount) * (float) $quotationLine->tax_rate / 100;

                $selection->lines()->create([
                    'supplier_quotation_line_id' => $quotationLine->getKey(),
                    'purchase_requisition_line_id' => $requirementLine->getKey(),
                    'supplier_id' => $quotationLine->quotation->supplier_id,
                    'product_id' => $quotationLine->product_id,
                    'unit_id' => $quotationLine->unit_id,
                    'selected_quantity' => $this->quantity($quantity),
                    'unit_price' => $quotationLine->unit_price,
                    'discount_amount' => $this->amount($discount),
                    'tax_rate' => $quotationLine->tax_rate,
                    'tax_amount' => $this->amount($tax),
                    'line_total' => $this->amount($subtotal - $discount + $tax),
                    'reason' => $input['reason'] ?? null,
                ]);
            }

            $selection = $selection->refresh()->load(['lines.supplier', 'lines.product', 'requestForQuotation']);
            $this->audit->record($selection, 'supplier_selection.created', ['line_count' => $selection->lines->count()]);

            return $selection;
        }, 3);
    }

    /**
     * @return Collection<int, PurchaseOrder>
     */
    public function approveSelection(SupplierSelection $selection): Collection
    {
        return DB::transaction(function () use ($selection): Collection {
            $context = $this->context();
            $locked = SupplierSelection::query()
                ->with(['requestForQuotation.requisition.branchStore', 'lines.quotationLine.quotation.currency', 'lines.product', 'lines.unit'])
                ->lockForUpdate()
                ->findOrFail($selection->getKey());
            $this->assertContext($locked, $context);
            $this->requireStatus($locked->status, ['draft']);

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('A supplier selection requires at least one line.'));
            }

            $requisition = $locked->requestForQuotation->requisition;
            if (! $requisition->branchStore instanceof BranchStore) {
                throw new DomainException(__('A destination store is required before generating purchase orders.'));
            }

            $orders = collect();
            foreach ($locked->lines->groupBy(fn (SupplierSelectionLine $line): int => $line->quotationLine->supplier_quotation_id) as $lines) {
                /** @var SupplierSelectionLine $first */
                $first = $lines->first();
                $quotation = $first->quotationLine->quotation;
                $result = $this->purchaseOrders->create([
                    'supplier_doc_num' => $first->supplier->doc_num,
                    'currency_doc_num' => $quotation->currency?->doc_num,
                    'branch_store_uuid' => $requisition->branchStore->public_uuid,
                    'document_date' => $locked->selection_date->format('Y-m-d'),
                    'exchange_rate' => $quotation->exchange_rate,
                    'expected_delivery_date' => $quotation->lines()->min('delivery_date'),
                    'supplier_reference' => $quotation->supplier_reference,
                    'purchase_requisition_id' => $requisition->getKey(),
                    'request_for_quotation_id' => $locked->request_for_quotation_id,
                    'supplier_quotation_id' => $quotation->getKey(),
                    'supplier_selection_id' => $locked->getKey(),
                    'purchase_type' => 'standard',
                    'payment_terms' => $quotation->payment_terms,
                    'freight_amount' => $quotation->freight_amount,
                    'notes' => $locked->selection_reason,
                    'lines' => $lines->values()->map(function (SupplierSelectionLine $line): array {
                        return [
                            'product_doc_num' => $line->product->doc_num,
                            'unit_doc_num' => $line->unit->doc_num,
                            'ordered_quantity' => $line->selected_quantity,
                            'unit_price' => $line->unit_price,
                            'discount_type' => 'fixed',
                            'discount_value' => $line->discount_amount,
                            'tax_rate' => $line->tax_rate,
                            'purchase_requisition_line_id' => $line->purchase_requisition_line_id,
                            'request_for_quotation_line_id' => $line->quotationLine->request_for_quotation_line_id,
                            'supplier_quotation_line_id' => $line->supplier_quotation_line_id,
                            'supplier_selection_line_id' => $line->getKey(),
                            'required_delivery_date' => $line->quotationLine->delivery_date,
                            'specification' => $line->quotationLine->rfqLine?->specification,
                            'notes' => $line->reason,
                        ];
                    })->all(),
                ]);
                /** @var PurchaseOrder $order */
                $order = $result['record'];
                $lines->each(fn (SupplierSelectionLine $line) => $line->forceFill(['purchase_order_id' => $order->getKey()])->save());
                $orders->push($order);
            }

            $locked->forceFill([
                'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'updated_by' => auth()->id(),
            ])->save();
            $this->refreshRequisitionConversionStatus($requisition->refresh());
            $this->audit->record($locked, 'supplier_selection.approved', ['purchase_orders' => $orders->pluck('doc_num')->all()]);

            return $orders;
        }, 3);
    }

    /**
     * @return Collection<int, SupplierQuotationLine>
     */
    public function comparison(RequestForQuotation $rfq): Collection
    {
        $this->assertContext($rfq, $this->context());

        return SupplierQuotationLine::query()
            ->with(['quotation.supplier', 'quotation.currency', 'rfqLine.product', 'rfqLine.unit'])
            ->whereHas('quotation', fn ($query) => $query
                ->where('request_for_quotation_id', $rfq->getKey())
                ->where('status', 'submitted'))
            ->orderBy('request_for_quotation_line_id')
            ->orderBy('line_total')
            ->get();
    }

    private function assertSelectionCapacity(PurchaseRequisitionLine $line, SupplierSelection $selection, float $pendingQuantity): void
    {
        PurchaseRequisitionLine::query()->lockForUpdate()->findOrFail($line->getKey());
        $committed = (float) SupplierSelectionLine::query()
            ->where('purchase_requisition_line_id', $line->getKey())
            ->where('supplier_selection_id', '<>', $selection->getKey())
            ->whereHas('selection', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
            ->sum('selected_quantity');

        if ($committed + $pendingQuantity > (float) $line->approved_quantity + 0.00000001) {
            throw new DomainException(__('Selected quantities exceed the approved purchase requirement.'));
        }
    }

    private function refreshRequisitionConversionStatus(PurchaseRequisition $requisition): void
    {
        $approved = (float) $requisition->lines()->sum('approved_quantity');
        $ordered = (float) PurchaseOrderLine::query()
            ->whereIn('purchase_requisition_line_id', $requisition->lines()->pluck('id'))
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', [PurchaseOrder::StatusCancelled]))
            ->sum('ordered_quantity');
        $status = $ordered >= $approved - 0.00000001
            ? PurchaseRequisition::StatusFullyConverted
            : PurchaseRequisition::StatusPartiallyConverted;
        $requisition->forceFill(['status' => $status, 'updated_by' => auth()->id()])->save();
    }

    private function lockRequisition(PurchaseRequisition $requisition, bool $withLines = false): PurchaseRequisition
    {
        $query = PurchaseRequisition::query()->lockForUpdate();
        if ($withLines) {
            $query->with('lines');
        }
        $locked = $query->findOrFail($requisition->getKey());
        $this->assertContext($locked, $this->context());

        return $locked;
    }

    private function product(int $companyId, string $docNum): Product
    {
        $product = Product::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();

        if ($product instanceof Product && ! $product->isPurchasable()) {
            throw new DomainException(__('procurement.messages.purchase_product_type_invalid'));
        }

        if (! $product instanceof Product) {
            throw new DomainException(__('The selected item is not available for purchasing.'));
        }

        return $product;
    }

    private function supplier(int $companyId, string $docNum): Supplier
    {
        $supplier = Supplier::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
        if (! $supplier instanceof Supplier) {
            throw new DomainException(__('The selected supplier is unavailable.'));
        }

        return $supplier;
    }

    private function currency(int $companyId, ?string $docNum): ?Currency
    {
        if (blank($docNum)) {
            return null;
        }
        $currency = Currency::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
        if (! $currency instanceof Currency) {
            throw new DomainException(__('The selected currency is unavailable.'));
        }

        return $currency;
    }

    private function store(int $branchId, ?string $publicUuid, bool $nullable = false): ?BranchStore
    {
        if ($nullable && blank($publicUuid)) {
            return null;
        }
        $store = BranchStore::query()->where('branch_id', $branchId)->where('public_uuid', $publicUuid)->whereNull('deleted_at')->first();
        if (! $store instanceof BranchStore) {
            throw new DomainException(__('The selected destination store is unavailable.'));
        }

        return $store;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array{production_order_id: int|null, production_order_line_id: int|null}
     */
    private function productionSource(array $input, array $context): array
    {
        if (($input['source_type'] ?? 'manual') === 'work_order') {
            throw new DomainException(__('New Work Order references are not accepted because no canonical Work Order domain can resolve and validate them. Use Manual or a linked Production Order.'));
        }

        if (($input['source_type'] ?? 'manual') !== 'production_order') {
            return ['production_order_id' => null, 'production_order_line_id' => null];
        }

        $order = ProductionOrder::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('doc_num', $input['source_doc_num'] ?? null)
            ->where('status', '<>', ProductionOrder::StatusCancelled)
            ->lockForUpdate()
            ->first();
        if (! $order instanceof ProductionOrder) {
            throw new DomainException(__('The production demand source is unavailable in the active operating context.'));
        }

        $line = ProductionOrderLine::query()
            ->where('production_order_id', $order->getKey())
            ->where('public_id', $input['source_line_reference'] ?? null)
            ->lockForUpdate()
            ->first();
        if (! $line instanceof ProductionOrderLine) {
            throw new DomainException(__('The production demand source line is unavailable.'));
        }

        return [
            'production_order_id' => $order->getKey(),
            'production_order_line_id' => $line->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function assertDemandSourceIsAvailable(Product $product, array $input, array $context): void
    {
        $sourceType = (string) ($input['source_type'] ?? 'manual');
        if ($sourceType === 'manual') {
            return;
        }

        $duplicateExists = PurchaseRequisitionLine::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('product_id', $product->getKey())
            ->where('source_type', $sourceType)
            ->where('source_doc_num', $input['source_doc_num'] ?? null)
            ->when(filled($input['source_line_reference'] ?? null), fn ($query) => $query->where('source_line_reference', $input['source_line_reference']))
            ->whereHas('requisition', fn ($query) => $query->whereNotIn('status', ['rejected', 'cancelled', 'closed']))
            ->exists();

        if ($duplicateExists) {
            throw new DomainException(__('This operational demand and item already has an active purchase requirement.'));
        }
    }

    private function assertContext(object $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || ($record->branch_id !== null && (int) $record->branch_id !== $context['branch_id'])) {
            throw new DomainException(__('The document is outside the active operating context.'));
        }
    }

    /** @param list<string> $allowed */
    private function requireStatus(string $status, array $allowed): void
    {
        if (! in_array($status, $allowed, true)) {
            throw new DomainException(__('This document is locked in its current status.'));
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
