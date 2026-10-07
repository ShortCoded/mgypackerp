<?php

namespace Modules\Purchases\Services;

use App\Services\DocumentOwnerEffectProofService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\HR\Models\HrEmployee;
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
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['request_date'], $context['financial_period_id'], lockForUpdate: true);
            $requisition = PurchaseRequisition::query()->create([
                ...$this->number('purchase_requisitions', PurchaseRequisition::class, $context),
                ...$context,
                ...$this->requisitionValues($data, $context),
                'status' => PurchaseRequisition::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $this->syncRequisitionLines($requisition, $data, $context);
            $this->attachments->attach(
                $requisition,
                $data['attachment_file_doc_nums'] ?? [],
                ProcurementAttachmentService::OperationalCollection,
                $context['company_id'],
            );

            $requisition = $requisition->refresh()->load(['lines.product', 'lines.unit', 'branch', 'branchStore']);
            $this->audit->record($requisition, 'purchase_requisition.created', ['line_count' => $requisition->lines->count()]);

            return $requisition;
        }, 3);
    }

    public function updateRequisition(PurchaseRequisition $requisition, array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $data): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition);
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusDraft]);
            if ($locked->hasDownstreamDocuments()) {
                throw new DomainException(__('procurement.messages.line_correction_execution_blocked'));
            }
            $context = $this->context();
            $beforeLines = $locked->lines()->get()->map->attributesToArray()->all();
            $locked->fill($this->requisitionValues($data, $context, $locked));
            $changed = $this->syncRequisitionLines($locked, $data, $context);
            $changed = $this->attachments->attach(
                $locked,
                $data['attachment_file_doc_nums'] ?? [],
                ProcurementAttachmentService::OperationalCollection,
                $context['company_id'],
            ) || $changed;
            if ($locked->isDirty() || $changed) {
                $locked->forceFill(['updated_by' => auth()->id()])->save();
                $this->audit->record($locked, 'purchase_requisition.updated', [
                    'before_lines' => $beforeLines,
                    'after_lines' => $locked->lines()->get()->map->attributesToArray()->all(),
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    public function rejectRequisition(PurchaseRequisition $requisition, string $reason): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $reason): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition, allowAdministrativeAccess: true);
            if ($locked->status === PurchaseRequisition::StatusRejected) {
                return $locked;
            }
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusSubmitted]);
            if (blank($reason)) {
                throw new DomainException(__('A rejection reason is required.'));
            }
            $locked->forceFill(['status' => PurchaseRequisition::StatusRejected, 'rejected_by' => auth()->id(),
                'rejected_at' => now(), 'rejection_reason' => trim($reason), 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'purchase_requisition.rejected', ['reason' => trim($reason)]);

            return $locked;
        }, 3);
    }

    public function finishRequisition(PurchaseRequisition $requisition, string $status, ?string $reason = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $status, $reason): PurchaseRequisition {
            Company::query()->whereKey($this->context()['company_id'])->lockForUpdate()->firstOrFail();
            $locked = $this->lockRequisition($requisition, true, allowAdministrativeAccess: true);
            $this->requireStatus($status, [PurchaseRequisition::StatusCancelled, PurchaseRequisition::StatusClosed]);
            if ($locked->status === $status) {
                return $locked;
            }
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusDraft, PurchaseRequisition::StatusSubmitted,
                PurchaseRequisition::StatusRejected, PurchaseRequisition::StatusClosed, PurchaseRequisition::StatusApproved,
                PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted]);
            if ($status === PurchaseRequisition::StatusCancelled) {
                if (blank($reason)) {
                    throw new DomainException(__('Cancellation reason is required.'));
                }
                if (! $locked->canCancelSafely()) {
                    throw new DomainException(__('A closed, converted, or reopened purchase request cannot be cancelled.'));
                }
                $values = ['cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => trim($reason)];
            } else {
                $this->requireStatus($locked->status, [PurchaseRequisition::StatusApproved,
                    PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted]);
                if ($locked->lines->contains(fn (PurchaseRequisitionLine $line): bool => $line->orderedQuantity(true) > $line->orderedQuantity())) {
                    throw new DomainException(__('Cancel or approve dependent draft purchase orders before closing the request.'));
                }
                $values = ['closed_by' => auth()->id(), 'closed_at' => now()];
            }
            $locked->forceFill([...$values, 'status' => $status, 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'purchase_requisition.'.$status);

            return $locked;
        }, 3);
    }

    public function deleteRequisition(PurchaseRequisition $requisition): void
    {
        DB::transaction(function () use ($requisition): void {
            $locked = $this->lockRequisition($requisition);
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusDraft]);
            if ((($locked->approved_at !== null || $locked->closed_at !== null) && ! $locked->hasReopenEvidence()) || $locked->hasDownstreamDocuments()) {
                throw new DomainException(__('Only unused drafts can be deleted.'));
            }
            $locked->forceFill(['deleted_by' => auth()->id()])->save();
            $locked->delete();
            $this->audit->record($locked, 'purchase_requisition.deleted');
        }, 3);
    }

    public function requisitionStore(array $context, ?string $storeUuid = null): ?BranchStore
    {
        $branch = Branch::query()->where('company_id', $context['company_id'])->find($context['branch_id']);
        if (! $branch || ! in_array($branch->type, [Branch::TypeFactory, Branch::TypeWarehouse], true)) {
            throw new DomainException(__('procurement.ui.inventory_context_required'));
        }
        $query = $branch->stores()->purchasingEligible();
        if (filled($storeUuid)) {
            return $query->where('public_uuid', $storeUuid)->firstOrFail();
        }
        $stores = $query->limit(2)->get();

        return $stores->count() === 1 ? $stores->first() : null;
    }

    private function requesterEmployeeId(mixed $employeeId, array $context): ?int
    {
        if (blank($employeeId)) {
            return null;
        }
        $employee = HrEmployee::query()->where('company_id', $context['company_id'])->find($employeeId);
        if (! $employee) {
            throw new DomainException(__('procurement.ui.employee_unavailable'));
        }

        return $employee->getKey();
    }

    private function requisitionValues(array $data, array $context, ?PurchaseRequisition $existing = null): array
    {
        return [
            'branch_store_id' => $this->requisitionStore($context, $data['branch_store_uuid'] ?? $existing?->branchStore?->public_uuid)?->getKey(),
            ...array_key_exists('requester_employee_id', $data) ? ['requester_employee_id' => $this->requesterEmployeeId($data['requester_employee_id'], $context)] : [],
            'request_date' => $data['request_date'], 'required_by_date' => $data['required_by_date'] ?? null,
            'department' => $data['department'] ?? $existing?->department, 'priority' => $data['priority'] ?? $existing?->priority ?? 'normal',
            'notes' => $data['notes'] ?? null, 'lead_time_days' => $data['lead_time_days'] ?? $existing?->lead_time_days,
            'suggested_supplier_id' => filled($data['suggested_supplier_doc_num'] ?? null)
                ? $this->supplier($context['company_id'], $data['suggested_supplier_doc_num'])->getKey() : $existing?->suggested_supplier_id,
        ];
    }

    private function syncRequisitionLines(PurchaseRequisition $requisition, array $data, array $context): bool
    {
        if (empty($data['lines'])) {
            throw new DomainException(__('A purchase request requires at least one line.'));
        }
        $existing = $requisition->lines()->get()->keyBy('public_id');
        $inputs = array_values($data['lines']);
        $seen = [];
        $renumber = false;
        foreach ($inputs as $index => $input) {
            $publicId = $input['public_id'] ?? null;
            if ($publicId && (! $existing->has($publicId) || isset($seen[$publicId]))) {
                throw new DomainException(__('The selected purchase request line is invalid.'));
            }
            if ($publicId) {
                $seen[$publicId] = true;
            }
            $renumber = $renumber || ($existing->isNotEmpty() && (! $publicId || (int) $existing->get($publicId)?->line_number !== $index + 1));
        }
        if ($renumber) {
            $offset = (int) $existing->max('line_number') + count($inputs) + 1;
            foreach ($existing as $line) {
                $line->forceFill(['line_number' => $line->line_number + $offset])->save();
            }
        }
        $kept = [];
        $changed = false;
        foreach (array_values($data['lines']) as $index => $input) {
            $requestedQuantity = $this->quantity($input['requested_quantity']);
            if (bccomp($requestedQuantity, '0', 8) <= 0) {
                throw new DomainException(__('Requested quantity must be positive.'));
            }
            $product = $this->product($context['company_id'], $input['product_doc_num']);
            $unit = $this->unitOptions->unitForProduct($product, $input['unit_doc_num'] ?? null, $context['company_id']);
            $productionSource = $this->productionSource($input, $context);
            $this->assertDemandSourceIsAvailable($product, $input, $context, $existing->get($input['public_id'] ?? '')?->getKey());

            if ($unit === null) {
                throw new DomainException(__('The selected unit is not available for this item.'));
            }

            $values = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'line_number' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'requested_quantity' => $requestedQuantity,
                'approved_quantity' => 0,
                'required_date' => $input['required_date'] ?? $data['required_by_date'] ?? null,
                'source_type' => $input['source_type'] ?? 'manual',
                'source_doc_num' => $input['source_doc_num'] ?? null,
                'source_line_reference' => $input['source_line_reference'] ?? null,
                'production_order_id' => $productionSource['production_order_id'],
                'production_order_line_id' => $productionSource['production_order_line_id'],
                'specification' => $input['specification'] ?? null,
                'notes' => $input['notes'] ?? null,
            ];
            $line = filled($input['public_id'] ?? null) ? $existing->get($input['public_id']) : null;
            if (filled($input['public_id'] ?? null) && ! $line instanceof PurchaseRequisitionLine) {
                throw new DomainException(__('The selected purchase request line is invalid.'));
            }
            if ($line instanceof PurchaseRequisitionLine) {
                $line->fill($values);
                if ($line->isDirty()) {
                    $line->forceFill(['updated_by' => auth()->id()])->save();
                    $changed = true;
                }
            } else {
                $line = $requisition->lines()->create([...$values, 'created_by' => auth()->id()]);
                $changed = true;
            }
            $changed = $this->attachments->attachLine(
                $line,
                $input['attachment_file_doc_nums'] ?? [],
                $context['company_id'],
            ) || $changed;
            $kept[] = $line->getKey();
        }

        foreach ($existing as $line) {
            if (! in_array($line->getKey(), $kept, true)) {
                $line->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    public function submitRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition);
            if ($locked->status === PurchaseRequisition::StatusSubmitted) {
                return $locked;
            }
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusDraft]);
            if (! $locked->lines()->exists()) {
                throw new DomainException(__('A purchase request requires at least one line.'));
            }
            $locked->forceFill(['status' => PurchaseRequisition::StatusSubmitted, 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'purchase_requisition.submitted');

            return $locked->refresh();
        }, 3);
    }

    public function approveRequisition(PurchaseRequisition $requisition, array $approvedQuantities = []): PurchaseRequisition
    {
        return DB::transaction(function () use ($approvedQuantities, $requisition): PurchaseRequisition {
            $locked = $this->lockRequisition($requisition, true, allowAdministrativeAccess: true);
            if (in_array($locked->status, [PurchaseRequisition::StatusApproved, PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted], true)) {
                return $locked;
            }
            $this->requireStatus($locked->status, [PurchaseRequisition::StatusSubmitted]);

            $hasApprovedQuantity = false;
            foreach ($locked->lines as $line) {
                $approved = $this->quantity($approvedQuantities[$line->public_id] ?? $line->requested_quantity);

                if (bccomp($approved, '0', 8) < 0 || bccomp($approved, (string) $line->requested_quantity, 8) > 0) {
                    throw new DomainException(__('Approved quantity must be between zero and the requested quantity.'));
                }

                $hasApprovedQuantity = $hasApprovedQuantity || bccomp($approved, '0', 8) > 0;
                $line->forceFill(['approved_quantity' => $approved, 'updated_by' => auth()->id()])->save();
            }

            if (! $hasApprovedQuantity) {
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

    public function reopenRequisition(PurchaseRequisition $requisition, string $reason): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $reason): PurchaseRequisition {
            $context = $this->context();
            $locked = $this->lockRequisition($requisition, true);

            if ((int) $locked->branch_id !== $context['branch_id'] || blank($reason)) {
                throw new DomainException(__('open_documents.validation.reopen_context_or_reason'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                $context['company_id'],
                $locked->request_date,
                $context['financial_period_id'],
                lockForUpdate: true,
            );
            if ($locked->trashed() || ! $locked->canReopenSafely()) {
                throw new DomainException(__('open_documents.messages.skipped_blocked', ['count' => 1]));
            }

            $previousStatus = $locked->status;
            $approvedSnapshot = [
                'header' => Arr::only($locked->attributesToArray(), [
                    'doc_num', 'request_date', 'required_by_date', 'branch_store_id',
                    'department', 'priority', 'requested_by', 'submitted_by', 'submitted_at',
                    'approved_by', 'approved_at', 'closed_by', 'closed_at',
                ]),
                'lines' => $locked->lines->map(fn (PurchaseRequisitionLine $line): array => Arr::only(
                    $line->attributesToArray(),
                    ['public_id', 'line_number', 'product_id', 'unit_id', 'requested_quantity',
                        'approved_quantity', 'source_type', 'source_doc_num', 'source_line_reference'],
                ))->all(),
            ];
            $locked->lines->each(fn (PurchaseRequisitionLine $line) => $line->forceFill([
                'approved_quantity' => 0,
                'updated_by' => auth()->id(),
            ])->save());
            $locked->forceFill([
                'status' => PurchaseRequisition::StatusDraft,
                'submitted_by' => null,
                'submitted_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by' => auth()->id(),
            ])->save();
            $this->audit->record($locked, 'purchase_requisition.reopened', [
                'previous_status' => $previousStatus,
                'reason' => trim($reason),
                'approved_snapshot' => $approvedSnapshot,
            ]);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function createRequestForQuotation(PurchaseRequisition $requisition, array $data): RequestForQuotation
    {
        return $this->saveRequestForQuotation($requisition, $data);
    }

    public function updateRequestForQuotation(RequestForQuotation $draft, array $data): RequestForQuotation
    {
        return $this->saveRequestForQuotation($draft->requisition, $data, $draft);
    }

    private function saveRequestForQuotation(PurchaseRequisition $requisition, array $data, ?RequestForQuotation $draft = null): RequestForQuotation
    {
        return DB::transaction(function () use ($data, $requisition, $draft): RequestForQuotation {
            $context = $this->context();
            if ($draft) {
                $draft = RequestForQuotation::query()->lockForUpdate()->findOrFail($draft->getKey());
                $this->assertContext($draft, $context);
                $this->requireStatus($draft->status, ['draft']);
            }
            $changed = ! $draft;
            $keptLineIds = [];
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['issue_date'], $context['financial_period_id'], lockForUpdate: true);
            $locked = $this->lockRequisition($requisition, true, true, true);
            $this->assertContext($locked, $context, true, true);
            $this->requireStatus($locked->status, [
                PurchaseRequisition::StatusApproved,
                PurchaseRequisition::StatusPartiallyConverted,
            ]);

            $rfq = $draft ?? new RequestForQuotation;
            $rfq->fill([
                ...($draft ? [] : $this->number('request_for_quotations', RequestForQuotation::class, $context)),
                ...$context,
                'purchase_requisition_id' => $locked->getKey(),
                'issue_date' => $data['issue_date'],
                'quotation_due_date' => $data['quotation_due_date'] ?? null,
                'required_delivery_date' => $data['required_delivery_date'] ?? null,
                'status' => 'draft',
                'commercial_notes' => $data['commercial_notes'] ?? null,
                'created_by' => $draft?->created_by ?? auth()->id(),
            ]);

            if ($rfq->isDirty()) {
                $rfq->save();
                $changed = true;
            }

            foreach (array_values($data['lines']) as $index => $input) {
                $line = $locked->lines->firstWhere('public_id', $input['requisition_line_public_id'] ?? null);

                if (! $line instanceof PurchaseRequisitionLine) {
                    throw new DomainException(__('The selected purchase requirement line is invalid.'));
                }

                $alreadyRequested = app(NumericFormatService::class)->normalizeScientificNotation((string) RequestForQuotationLine::query()
                    ->where('purchase_requisition_line_id', $line->getKey())
                    ->where('request_for_quotation_id', '<>', $rfq->getKey())
                    ->whereHas('requestForQuotation', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
                    ->sum('quantity')) ?? '0';
                $quantity = $this->quantity($input['quantity']);

                if (bccomp($quantity, '0', 8) <= 0 || bccomp(bcadd($alreadyRequested, $quantity, 8), (string) $line->approved_quantity, 8) > 0) {
                    throw new DomainException(__('RFQ quantity exceeds the remaining approved requirement.'));
                }

                $savedLine = $rfq->lines()->firstOrNew(['purchase_requisition_line_id' => $line->getKey()]);
                $savedLine->fill([
                    'purchase_requisition_line_id' => $line->getKey(),
                    'line_number' => $index + 1,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => $quantity,
                    'specification' => $line->specification,
                    'notes' => $input['notes'] ?? null,
                ]);
                if ($savedLine->isDirty()) {
                    $savedLine->save();
                    $changed = true;
                }
                $changed = $this->attachments->attachLine(
                    $savedLine,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                ) || $changed;
                $keptLineIds[] = $savedLine->getKey();
            }

            $supplierIds = collect($data['supplier_doc_nums'])->map(function (string $docNum) use ($context): int {
                return $this->supplier($context['company_id'], $docNum)->getKey();
            })->unique()->values()->all();
            $existingSupplierIds = $rfq->suppliers()->pluck('suppliers.id')->sort()->values()->all();
            sort($supplierIds);
            if ($existingSupplierIds !== $supplierIds) {
                $rfq->suppliers()->sync($supplierIds);
                $changed = true;
            }

            $changed = $rfq->lines()->whereNotIn('id', $keptLineIds)->delete() > 0 || $changed;
            $changed = $this->attachments->attach(
                $rfq,
                $data['attachment_file_doc_nums'] ?? [],
                ProcurementAttachmentService::OperationalCollection,
                $context['company_id'],
            ) || $changed;
            if ($draft && $changed) {
                $rfq->forceFill(['updated_by' => auth()->id()])->save();
            }
            $rfq = $rfq->refresh()->load(['requisition', 'lines.product', 'lines.unit', 'suppliers']);
            if ($changed) {
                $this->audit->record($rfq, $draft ? 'request_for_quotation.updated' : 'request_for_quotation.created', ['line_count' => $rfq->lines->count(), 'supplier_count' => $rfq->suppliers->count()]);
            }

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

    public function createSupplierQuotation(RequestForQuotation|PurchaseRequisition|PurchaseOrder $source, array $data): SupplierQuotation
    {
        return $this->saveSupplierQuotation($source, $data);
    }

    public function updateSupplierQuotation(SupplierQuotation $draft, array $data): SupplierQuotation
    {
        $draft->loadMissing(['requestForQuotation', 'purchaseRequisition', 'purchaseOrder']);
        $source = $draft->sourceDocument();
        if (! $source) {
            throw new DomainException(__('The supplier quotation source document is missing.'));
        }

        return $this->saveSupplierQuotation($source, $data, $draft);
    }

    private function saveSupplierQuotation(RequestForQuotation|PurchaseRequisition|PurchaseOrder $source, array $data, ?SupplierQuotation $draft = null): SupplierQuotation
    {
        return DB::transaction(function () use ($data, $source, $draft): SupplierQuotation {
            $context = $this->context();
            if ($draft) {
                $draft = SupplierQuotation::query()->lockForUpdate()->findOrFail($draft->getKey());
                $this->assertContext($draft, $context);
                $this->requireStatus($draft->status, ['draft']);
            }
            $changed = ! $draft;
            $keptLineIds = [];
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['quotation_date'], $context['financial_period_id'], lockForUpdate: true);
            $locked = $source->newQuery()->with($source instanceof RequestForQuotation ? ['lines.product', 'suppliers'] : ['lines.product'])->lockForUpdate()->findOrFail($source->getKey());
            $this->assertContext($locked, $context, true, $locked instanceof PurchaseRequisition);
            $this->assertSupplierQuotationSourceStatus($locked);
            $supplier = $this->supplier($context['company_id'], $data['supplier_doc_num']);

            if ($locked instanceof RequestForQuotation && ! $locked->suppliers->contains(fn (Supplier $candidate): bool => $candidate->is($supplier))) {
                throw new DomainException(__('The supplier was not invited to this RFQ.'));
            }

            $sourceType = $this->supplierQuotationSourceType($locked);
            if (SupplierQuotation::query()->where('source_type', $sourceType)->where('source_id', $locked->getKey())
                ->when($draft, fn ($query) => $query->whereKeyNot($draft->getKey()))
                ->where('supplier_id', $supplier->getKey())->whereNotIn('status', ['cancelled', 'rejected'])->exists()) {
                throw new DomainException(__('An active quotation for this supplier and source document already exists.'));
            }

            $quotation = $draft ?? new SupplierQuotation;
            $quotation->fill([
                ...($draft ? [] : $this->number('supplier_quotations', SupplierQuotation::class, $context)),
                ...$context,
                'request_for_quotation_id' => $locked instanceof RequestForQuotation ? $locked->getKey() : null,
                'purchase_requisition_id' => $locked instanceof PurchaseRequisition ? $locked->getKey() : null,
                'purchase_order_id' => $locked instanceof PurchaseOrder ? $locked->getKey() : null,
                'source_type' => $sourceType,
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
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
                'created_by' => $draft?->created_by ?? auth()->id(),
            ]);

            if ($quotation->isDirty()) {
                $quotation->save();
                $changed = true;
            }

            $commercial = app(SupplierQuotationDiscountService::class)->calculate($data, $draft);

            foreach (array_values($data['lines']) as $index => $input) {
                $sourceLine = $locked->lines->firstWhere('public_id', $input['source_line_public_id'] ?? $input['rfq_line_public_id'] ?? null);

                if (! $sourceLine || ! $sourceLine->product?->isPurchasable()) {
                    throw new DomainException(__('The selected supplier quotation source line is invalid.'));
                }

                $numbers = app(NumericFormatService::class);
                $quantity = $numbers->normalizeToScale($input['offered_quantity'], 8);
                $sourceQuantity = $this->supplierQuotationSourceQuantity($sourceLine);
                $unitPrice = $numbers->normalizeToScale($input['unit_price'], 8);
                $calculated = $commercial['lines'][$index];
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $sourceQuantity, 8) > 0 || bccomp($unitPrice, '0', 8) < 0) {
                    throw new DomainException(__('Supplier quotation line values are invalid.'));
                }

                $sourceLineKey = match (true) {
                    $locked instanceof PurchaseRequisition => 'purchase_requisition_line_id',
                    $locked instanceof PurchaseOrder => 'purchase_order_line_id',
                    default => 'request_for_quotation_line_id',
                };
                $savedLine = $quotation->lines()->firstOrNew([$sourceLineKey => $sourceLine->getKey()]);
                $savedLine->fill([
                    'request_for_quotation_line_id' => $locked instanceof RequestForQuotation ? $sourceLine->getKey() : null,
                    'purchase_requisition_line_id' => $locked instanceof PurchaseRequisition ? $sourceLine->getKey() : null,
                    'purchase_order_line_id' => $locked instanceof PurchaseOrder ? $sourceLine->getKey() : null,
                    'line_number' => $index + 1,
                    'product_id' => $sourceLine->product_id,
                    'unit_id' => $sourceLine->unit_id,
                    'offered_quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_type' => $calculated['discount_type'], 'discount_value' => $calculated['discount_value'],
                    'subtotal_amount' => $calculated['subtotal_amount'], 'discount_amount' => $calculated['discount_amount'],
                    'header_discount_amount' => $calculated['header_discount_amount'], 'tax_rate' => $calculated['tax_rate'],
                    'tax_amount' => $calculated['tax_amount'], 'line_total' => $calculated['total_after_tax'],
                    'delivery_date' => $input['delivery_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                ]);
                if ($savedLine->isDirty()) {
                    $savedLine->save();
                    $changed = true;
                }
                $changed = $this->attachments->attachLine(
                    $savedLine,
                    $input['attachment_file_doc_nums'] ?? [],
                    $context['company_id'],
                ) || $changed;
                $keptLineIds[] = $savedLine->getKey();

            }

            $quotation->forceFill([
                'subtotal_amount' => $commercial['order']['subtotal_amount'],
                'discount_amount' => array_reduce($commercial['lines'], fn (string $sum, array $line): string => bcadd($sum, $line['discount_amount'], 4), '0.0000'),
                'tax_amount' => array_reduce($commercial['lines'], fn (string $sum, array $line): string => bcadd($sum, $line['tax_amount'], 4), '0.0000'),
                'total_amount' => $commercial['order']['total_amount'],
                'header_discount_type' => $commercial['order']['header_discount_type'],
                'header_discount_value' => $commercial['order']['header_discount_value'],
                'header_discount_amount' => $commercial['order']['header_discount_amount'],
            ]);
            if ($quotation->isDirty()) {
                $quotation->save();
                $changed = true;
            }
            $this->attachments->attach(
                $quotation,
                $data['attachment_file_doc_nums'] ?? [],
                SupplierQuotation::AttachmentCollection,
                $context['company_id'],
            );

            $changed = $quotation->lines()->whereNotIn('id', $keptLineIds)->delete() > 0 || $changed;
            if ($draft && $changed) {
                $quotation->forceFill(['updated_by' => auth()->id()])->save();
            }
            $quotation = $quotation->refresh()->load(['supplier', 'currency', 'lines.product', 'requestForQuotation', 'purchaseRequisition', 'purchaseOrder', 'attachmentUsages.file']);
            if ($changed) {
                $this->audit->record($quotation, $draft ? 'supplier_quotation.updated' : 'supplier_quotation.created', ['line_count' => $quotation->lines->count()]);
            }

            return $quotation;
        }, 3);
    }

    private function assertSupplierQuotationSourceStatus(RequestForQuotation|PurchaseRequisition|PurchaseOrder $source): void
    {
        $allowed = match (true) {
            $source instanceof RequestForQuotation => ['issued'],
            $source instanceof PurchaseRequisition => [PurchaseRequisition::StatusApproved, PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted],
            default => [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed],
        };

        $this->requireStatus($source->status, $allowed);
    }

    private function supplierQuotationSourceType(RequestForQuotation|PurchaseRequisition|PurchaseOrder $source): string
    {
        return match (true) {
            $source instanceof PurchaseRequisition => SupplierQuotation::SourcePurchaseRequisition,
            $source instanceof PurchaseOrder => SupplierQuotation::SourcePurchaseOrder,
            default => SupplierQuotation::SourceRequestForQuotation,
        };
    }

    private function supplierQuotationSourceQuantity(Model $line): string
    {
        return (string) match (true) {
            $line instanceof PurchaseRequisitionLine => $line->approved_quantity,
            $line instanceof PurchaseOrderLine => $line->ordered_quantity,
            default => $line->quantity,
        };
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
        return $this->saveSupplierSelection($rfq, $data);
    }

    public function updateSupplierSelection(SupplierSelection $selection, array $data): SupplierSelection
    {
        return $this->saveSupplierSelection($selection->requestForQuotation, $data, $selection);
    }

    private function saveSupplierSelection(RequestForQuotation $rfq, array $data, ?SupplierSelection $draft = null): SupplierSelection
    {
        return DB::transaction(function () use ($data, $rfq, $draft): SupplierSelection {
            $context = $this->context();
            if ($draft) {
                $draft = SupplierSelection::query()->with('lines')->lockForUpdate()->findOrFail($draft->id);
                $this->assertContext($draft, $context);
                $this->requireStatus($draft->status, ['draft']);
                if ($draft->lines->contains(fn ($line) => $line->purchase_order_id !== null)) {
                    throw new DomainException(__('purchase_orders.messages.document_locked'));
                }
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['selection_date'], $context['financial_period_id'], lockForUpdate: true);
            $locked = RequestForQuotation::query()->with('lines.requisitionLine')->lockForUpdate()->findOrFail($rfq->id);
            $this->assertContext($locked, $context, true);
            $this->requireStatus($locked->status, ['issued']);
            $selection = $draft ?? new SupplierSelection;
            $selection->fill([...($draft ? [] : $this->number('supplier_selections', SupplierSelection::class, $context)), ...$context,
                'request_for_quotation_id' => $locked->id, 'selection_date' => $data['selection_date'], 'status' => 'draft',
                'selection_reason' => $data['selection_reason'] ?? null, 'selected_by' => $draft?->selected_by ?? auth()->id(),
                'created_by' => $draft?->created_by ?? auth()->id(), 'updated_by' => $draft ? auth()->id() : null])->save();
            $previousLines = $draft?->lines->keyBy('supplier_quotation_line_id') ?? collect();
            $keptIds = [];
            $seen = [];
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

                if (isset($seen[$quotationLine->id])) {
                    throw new DomainException(__('procurement.messages.commercial_discount_invalid'));
                }
                $seen[$quotationLine->id] = true;
                $quantityDecimal = $this->quantity($input['selected_quantity']);
                if (bccomp($quantityDecimal, '0', 8) <= 0 || bccomp($quantityDecimal, (string) $quotationLine->offered_quantity, 8) > 0) {
                    throw new DomainException(__('Selected quantity exceeds the supplier offer.'));
                }

                $requirementLine = $quotationLine->rfqLine->requisitionLine;
                $pendingByRequirement[$requirementLine->getKey()] = bcadd(
                    $pendingByRequirement[$requirementLine->getKey()] ?? '0',
                    $quantityDecimal,
                    8,
                );
                $this->assertSelectionCapacity($requirementLine, $selection, $pendingByRequirement[$requirementLine->getKey()]);
                $allocation = app(SupplierQuotationDiscountService::class)->selectionAllocation($quotationLine, $quantityDecimal, $selection, $previousLines->get($quotationLine->id));
                $type = $quotationLine->discount_type ?: 'fixed';
                $value = $type === 'percentage' ? $quotationLine->discount_value : $allocation['discount'];
                if (empty($input['inherit_source_discount']) && filled($input['discount_type'] ?? null)) {
                    $type = $input['discount_type'];
                    $value = $input['discount_value'] ?? 0;
                }
                $headerType = $quotationLine->quotation->header_discount_type;
                $headerValue = $headerType === 'percentage' ? $quotationLine->quotation->header_discount_value : $allocation['header'];
                $inherited = $type === ($quotationLine->discount_type ?: 'fixed') && bccomp((string) $value, (string) (($quotationLine->discount_type === 'percentage') ? $quotationLine->discount_value : $allocation['discount']), 4) === 0;
                $native = $inherited ? null : app(SupplierQuotationDiscountService::class)->calculate(['header_discount_type' => $headerType,
                    'header_discount_value' => $headerValue, 'lines' => [['offered_quantity' => $quantityDecimal, 'unit_price' => $quotationLine->unit_price,
                        'discount_type' => $type, 'discount_value' => $value, 'tax_rate' => $quotationLine->tax_rate]]]);
                $calculated = $inherited ? ['subtotal_amount' => $allocation['gross'], 'discount_amount' => $allocation['discount'],
                    'header_discount_amount' => $allocation['header'], 'tax_amount' => $allocation['tax'],
                    'line_total' => bcadd(bcsub(bcsub($allocation['gross'], $allocation['discount'], 4), $allocation['header'], 4), $allocation['tax'], 4)]
                    : [...$native['lines'][0], 'line_total' => $native['lines'][0]['total_after_tax']];
                $savedLine = $previousLines->get($quotationLine->id) ?? $selection->lines()->make();
                $savedLine->fill([
                    'supplier_quotation_line_id' => $quotationLine->getKey(),
                    'purchase_requisition_line_id' => $requirementLine->getKey(),
                    'supplier_id' => $quotationLine->quotation->supplier_id,
                    'product_id' => $quotationLine->product_id,
                    'unit_id' => $quotationLine->unit_id,
                    'selected_quantity' => $quantityDecimal,
                    'unit_price' => $quotationLine->unit_price,
                    'discount_type' => $type, 'discount_value' => $value, 'subtotal_amount' => $calculated['subtotal_amount'],
                    'discount_amount' => $calculated['discount_amount'], 'header_discount_type' => $headerType,
                    'header_discount_value' => $headerValue, 'header_discount_amount' => $calculated['header_discount_amount'],
                    'source_discount_snapshot' => [...$allocation, 'inherited' => $inherited],
                    'tax_rate' => $quotationLine->tax_rate, 'tax_amount' => $calculated['tax_amount'], 'line_total' => $calculated['line_total'],
                    'reason' => $input['reason'] ?? null,
                ])->save();
                $keptIds[] = $savedLine->id;
            }

            $selection->lines()->whereNotIn('id', $keptIds)->delete();
            $selection = $selection->refresh()->load(['lines.supplier', 'lines.product', 'requestForQuotation']);
            $this->audit->record($selection, $draft ? 'supplier_selection.updated' : 'supplier_selection.created', ['line_count' => $selection->lines->count()]);

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
                    'header_discount_type' => $quotation->header_discount_type,
                    'header_discount_value' => $quotation->header_discount_type === 'percentage' ? $quotation->header_discount_value : $lines->reduce(fn (string $sum, SupplierSelectionLine $line): string => bcadd($sum, $line->header_discount_amount ?? '0', 4), '0.0000'),
                    'notes' => $locked->selection_reason,
                    'lines' => $lines->values()->map(function (SupplierSelectionLine $line): array {
                        return [
                            'product_doc_num' => $line->product->doc_num,
                            'unit_doc_num' => $line->unit->doc_num,
                            'ordered_quantity' => $line->selected_quantity,
                            'unit_price' => $line->unit_price,
                            'discount_type' => $line->discount_type ?: 'fixed',
                            'discount_value' => $line->discount_value ?? $line->discount_amount,
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
    public function deleteSourcingDraft(RequestForQuotation|SupplierQuotation $record): void
    {
        DB::transaction(function () use ($record): void {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertContext($locked, $this->context());
            $this->requireStatus($locked->status, ['draft']);
            if ($locked instanceof RequestForQuotation && $locked->quotations()->exists()) {
                throw new DomainException(__('The document has downstream dependencies.'));
            }
            $this->audit->record($locked, 'sourcing_draft.deleted');
            $locked->delete();
        }, 3);
    }

    public function cancelSourcingDocument(RequestForQuotation|SupplierQuotation|SupplierSelection $record, string $reason): RequestForQuotation|SupplierQuotation|SupplierSelection
    {
        Gate::authorize($this->sourcingCancellationPermission($record));
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new DomainException(__('cancellation_review.reason_required'));
        }

        return DB::transaction(function () use ($record, $reason): RequestForQuotation|SupplierQuotation|SupplierSelection {
            $context = $this->context();
            $company = Company::query()->lockForUpdate()->findOrFail($context['company_id']);
            $scope = app(OperatingScopeAccessService::class);
            if (! Branch::query()->where('company_id', $context['company_id'])->whereKey($context['branch_id'])->where('type', Branch::TypeAdministrative)->exists()
                || ! $scope->canAccessCompany(auth()->user(), $company)
                || ! $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->whereKey($record->branch_id)->exists()
                || ! $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->whereKey($record->financial_period_id)->exists()
                || ! $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num], openOnly: true)->whereKey($context['financial_period_id'])->exists()) {
                throw new DomainException(__('The document is outside the active operating context.'));
            }
            $rfqId = $record instanceof RequestForQuotation ? $record->getKey() : $record->request_for_quotation_id;
            if ($rfqId !== null) {
                RequestForQuotation::query()->where('company_id', $context['company_id'])->lockForUpdate()->findOrFail($rfqId);
            }
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertContext($locked, $context, source: true);
            $postingPeriod = app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                $context['company_id'], now()->toDateString(), $context['financial_period_id'], lockForUpdate: true,
            );
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            $blockers = $this->sourcingCancellationBlockers($locked);
            if ($blockers !== []) {
                throw new DomainException(implode(' ', $blockers));
            }
            $originalStatus = $locked->status;
            $locked->forceFill(['status' => 'cancelled', 'updated_by' => auth()->id()])->save();
            $this->audit->record($locked, 'sourcing_document.cancelled', [
                'reason' => trim($reason), 'original_status' => $originalStatus,
                'source_financial_period_id' => $locked->financial_period_id,
                'cancellation_financial_period_id' => $postingPeriod->getKey(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function sourcingCancellationPermission(RequestForQuotation|SupplierQuotation|SupplierSelection $record): string
    {
        return match (true) {
            $record instanceof RequestForQuotation => 'purchases.request_for_quotations.cancel',
            $record instanceof SupplierQuotation => 'purchases.supplier_quotation_entry.cancel',
            default => 'purchases.supplier_selection.cancel',
        };
    }

    /** @return list<string> */
    public function sourcingCancellationBlockers(RequestForQuotation|SupplierQuotation|SupplierSelection $record): array
    {
        $allowed = match (true) {
            $record instanceof RequestForQuotation => ['draft', 'issued'],
            $record instanceof SupplierQuotation => ['draft', 'submitted'],
            default => ['draft', 'approved'],
        };
        if (! in_array($record->status, $allowed, true)) {
            return [__('cancellation_review.owner_workflow')];
        }
        $hasDependencies = app(DocumentOwnerEffectProofService::class)->sourcingHasUnsettledEffects($record);

        return $hasDependencies ? [__('cancellation_review.sourcing_dependencies')] : [];
    }

    /** @param Builder<Model> $query
     * @param  class-string<Model>  $model
     */
    private function whereSourcingCancellationUnproven(Builder $query, string $model): void
    {
        $table = $query->getModel()->getTable();
        $query->where($table.'.status', '<>', 'cancelled')->orWhereNotNull($table.'.deleted_at')
            ->orWhereNotExists(fn ($audit) => $audit->selectRaw('1')->from('activity_log')
                ->whereColumn('activity_log.subject_id', $table.'.id')->where('activity_log.subject_type', $model)
                ->where('activity_log.event', 'sourcing_document.cancelled'));
    }

    public function comparison(RequestForQuotation $rfq): Collection
    {
        $this->assertContext($rfq, $this->context(), true);

        return SupplierQuotationLine::query()
            ->with(['quotation.supplier', 'quotation.currency', 'rfqLine.product', 'rfqLine.unit'])
            ->whereHas('quotation', fn ($query) => $query
                ->where('request_for_quotation_id', $rfq->getKey())
                ->where('status', 'submitted'))
            ->orderBy('request_for_quotation_line_id')
            ->orderBy('line_total')
            ->get();
    }

    private function assertSelectionCapacity(PurchaseRequisitionLine $line, SupplierSelection $selection, string $pendingQuantity): void
    {
        PurchaseRequisitionLine::query()->lockForUpdate()->findOrFail($line->getKey());
        $committed = app(NumericFormatService::class)->normalizeScientificNotation((string) SupplierSelectionLine::query()
            ->where('purchase_requisition_line_id', $line->getKey())
            ->where('supplier_selection_id', '<>', $selection->getKey())
            ->whereHas('selection', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
            ->sum('selected_quantity')) ?? '0';

        if (bccomp(bcadd($committed, $pendingQuantity, 8), (string) $line->approved_quantity, 8) > 0) {
            throw new DomainException(__('Selected quantities exceed the approved purchase requirement.'));
        }
    }

    private function refreshRequisitionConversionStatus(PurchaseRequisition $requisition): void
    {
        $requisition->refreshOrderingStatus();
    }

    private function lockRequisition(PurchaseRequisition $requisition, bool $withLines = false, bool $source = false, bool $allowAdministrativeAccess = false): PurchaseRequisition
    {
        $query = PurchaseRequisition::query()->lockForUpdate();
        if ($withLines) {
            $query->with('lines');
        }
        $locked = $query->findOrFail($requisition->getKey());
        $this->assertContext($locked, $this->context(), $source, $allowAdministrativeAccess);
        if (! $source && $locked->financialPeriod?->is_closed) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }

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
    private function assertDemandSourceIsAvailable(Product $product, array $input, array $context, ?int $exceptLineId = null): void
    {
        $sourceType = (string) ($input['source_type'] ?? 'manual');
        if ($sourceType === 'manual') {
            return;
        }

        $duplicateExists = PurchaseRequisitionLine::query()
            ->when($exceptLineId !== null, fn ($query) => $query->whereKeyNot($exceptLineId))
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

    private function assertContext(object $record, array $context, bool $source = false, bool $allowAdministrativeAccess = false): void
    {
        if (! $source && FinancialPeriod::query()->whereKey($record->financial_period_id)->value('is_closed')) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }
        $hasBranchAccess = $record->branch_id === null
            || (int) $record->branch_id === $context['branch_id']
            || ($allowAdministrativeAccess && Branch::query()
                ->whereKey($context['branch_id'])
                ->where('company_id', $context['company_id'])
                ->where('type', Branch::TypeAdministrative)
                ->exists());

        if ((int) $record->company_id !== $context['company_id']
            || (! $source && (int) $record->financial_period_id !== $context['financial_period_id'])
            || ! $hasBranchAccess) {
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
        );
    }

    private function quantity(mixed $value): string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 8)
            ?? throw new DomainException(__('Requested quantity must be positive.'));
    }

    private function amount(mixed $value): string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 4)
            ?? throw new DomainException(__('Supplier quotation line values are invalid.'));
    }
}
