<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryReservationService;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequestLine;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementSourcingService;

class ProductionMaterialRequestService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryReservationService $reservations,
        private readonly ProductionCycleService $cycle,
        private readonly ProcurementSourcingService $procurement,
    ) {}

    /** @param array<int, string|int|float> $quantitiesByRequirementId */
    public function create(
        ProductionRun $run,
        int $branchStoreId,
        array $quantitiesByRequirementId = [],
        bool $additional = false,
        ?string $reason = null,
        ?string $requiredByDate = null,
    ): ProductionMaterialRequest {
        return DB::transaction(function () use ($run, $branchStoreId, $quantitiesByRequirementId, $additional, $reason, $requiredByDate): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionRun::query()->with(['requirements.product', 'requirements.unit', 'orderLine'])->lockForUpdate()->findOrFail($run->getKey());
            $this->assertContext($locked, $context);
            if (in_array($locked->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_run_closed'));
            }
            $store = BranchStore::query()->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($branchStoreId);

            if ($additional && blank($reason)) {
                throw new DomainException(__('production_execution.messages.additional_material_reason_required'));
            }

            $numbers = $this->documents->nextForCompany(
                'production_material_requests',
                ProductionMaterialRequest::class,
                $context['company_id'],
                fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
            );
            $request = ProductionMaterialRequest::query()->create([
                ...$numbers,
                ...$context,
                'branch_store_id' => $store->getKey(),
                'production_order_id' => $locked->production_order_id,
                'production_run_id' => $locked->getKey(),
                'request_date' => now()->toDateString(),
                'required_by_date' => $requiredByDate,
                'request_type' => $additional ? 'additional' : 'planned',
                'status' => ProductionMaterialRequest::StatusSubmitted,
                'reason' => $reason,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'created_by' => auth()->id(),
            ]);

            foreach ($locked->requirements as $index => $requirement) {
                $defaultQuantity = $additional
                    ? '0'
                    : $this->remainingRequestableFor($requirement);
                $quantity = bcadd((string) ($quantitiesByRequirementId[$requirement->getKey()] ?? $defaultQuantity), '0', 8);

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }
                if (! $additional && bccomp($quantity, $defaultQuantity, 8) > 0) {
                    throw new DomainException(__('production_execution.messages.material_request_exceeds_bom'));
                }

                $request->lines()->create([
                    'production_material_requirement_id' => $requirement->getKey(),
                    'line_number' => $index + 1,
                    'product_id' => $requirement->product_id,
                    'unit_id' => $requirement->unit_id,
                    'planned_quantity' => $requirement->planned_quantity,
                    'requested_quantity' => $quantity,
                ]);
            }

            if (! $request->lines()->exists()) {
                throw new DomainException(__('production_execution.messages.material_request_lines_required'));
            }

            return $request->load(['lines.product', 'lines.unit', 'run']);
        });
    }

    /** @param array<int, string|int|float> $quantitiesByRequirementId */
    public function update(
        ProductionMaterialRequest $request,
        int $branchStoreId,
        array $quantitiesByRequirementId,
        bool $additional = false,
        ?string $reason = null,
        ?string $requiredByDate = null,
    ): ProductionMaterialRequest {
        return DB::transaction(function () use ($request, $branchStoreId, $quantitiesByRequirementId, $additional, $reason, $requiredByDate): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()
                ->with(['run.requirements.product', 'run.requirements.unit'])
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if ($locked->status !== ProductionMaterialRequest::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.material_request_submitted_edit_only'));
            }
            if (in_array($locked->run->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_run_closed'));
            }
            if ($additional && blank($reason)) {
                throw new DomainException(__('production_execution.messages.additional_material_reason_required'));
            }

            $store = BranchStore::query()
                ->where('branch_id', $context['branch_id'])
                ->lockForUpdate()
                ->findOrFail($branchStoreId);
            $locked->lines()->delete();

            foreach ($locked->run->requirements as $index => $requirement) {
                $quantity = bcadd((string) ($quantitiesByRequirementId[$requirement->getKey()] ?? '0'), '0', 8);

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }
                if (! $additional) {
                    $remaining = $this->remainingRequestableFor($requirement, (int) $locked->getKey());
                    if (bccomp($quantity, $remaining, 8) > 0) {
                        throw new DomainException(__('production_execution.messages.material_request_exceeds_bom'));
                    }
                }

                $locked->lines()->create([
                    'production_material_requirement_id' => $requirement->getKey(),
                    'line_number' => $index + 1,
                    'product_id' => $requirement->product_id,
                    'unit_id' => $requirement->unit_id,
                    'planned_quantity' => $requirement->planned_quantity,
                    'requested_quantity' => $quantity,
                ]);
            }

            if (! $locked->lines()->exists()) {
                throw new DomainException(__('production_execution.messages.material_request_lines_required'));
            }

            $locked->update([
                'branch_store_id' => $store->getKey(),
                'required_by_date' => $requiredByDate,
                'request_type' => $additional ? 'additional' : 'planned',
                'reason' => $reason,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines.product', 'lines.unit', 'run']);
        });
    }

    public function delete(ProductionMaterialRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if ($locked->status !== ProductionMaterialRequest::StatusSubmitted || $locked->inventoryDocuments()->exists()) {
                throw new DomainException(__('production_execution.messages.material_request_submitted_edit_only'));
            }

            $locked->update(['deleted_by' => auth()->id()]);
            $locked->delete();
        });
    }

    public function restore(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::withTrashed()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! $locked->trashed() || $locked->status !== ProductionMaterialRequest::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.material_request_not_restorable'));
            }

            $run = ProductionRun::query()->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
                ->lockForUpdate()
                ->findOrFail($locked->production_run_id);
            $this->assertContext($run, $context);
            $locked->restore();
            $locked->update(['restored_by' => auth()->id(), 'restored_at' => now(), 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    public function approve(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()->with(['lines.requirement.run.orderLine', 'lines.product', 'lines.unit', 'store'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if ($locked->status !== ProductionMaterialRequest::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.material_request_submitted_only'));
            }

            $hasShortage = false;
            foreach ($locked->lines as $line) {
                $quantity = (string) $line->requested_quantity;
                $available = $this->availability->forProduct(
                    $context['company_id'],
                    (int) $locked->branch_store_id,
                    (int) $line->product_id,
                )['available'];
                $reserveQuantity = bccomp($available, $quantity, 8) >= 0
                    ? $quantity
                    : (bccomp($available, '0', 8) > 0 ? $available : '0.00000000');
                $shortage = bcsub($quantity, $reserveQuantity, 8);

                if (bccomp($reserveQuantity, '0', 8) > 0) {
                    $this->reservations->reserveForProduction(
                        $line->requirement,
                        (int) $locked->branch_store_id,
                        $reserveQuantity,
                        null,
                        $locked->request_type === 'additional',
                    );
                }

                $line->update([
                    'approved_quantity' => $quantity,
                    'reserved_quantity' => $reserveQuantity,
                    'shortage_quantity' => $shortage,
                ]);
                $hasShortage = $hasShortage || bccomp($shortage, '0', 8) > 0;
            }

            $purchaseRequisition = $hasShortage ? $this->createShortageRequisition($locked) : null;
            $locked->update([
                'status' => $hasShortage ? ProductionMaterialRequest::StatusShortage : ProductionMaterialRequest::StatusApproved,
                'purchase_requisition_id' => $purchaseRequisition?->getKey(),
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines.product', 'purchaseRequisition']);
        });
    }

    /** @param array<int, string|int|float> $quantitiesByRequestLineId */
    public function issue(ProductionMaterialRequest $request, array $quantitiesByRequestLineId = []): InventoryDocument
    {
        return DB::transaction(function () use ($request, $quantitiesByRequestLineId): InventoryDocument {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()->with(['lines', 'run.requirements'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! in_array($locked->status, [ProductionMaterialRequest::StatusApproved, ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true)) {
                throw new DomainException(__('production_execution.messages.material_request_not_issuable'));
            }
            if (array_diff(array_map('intval', array_keys($quantitiesByRequestLineId)), $locked->lines->modelKeys()) !== []) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }

            $quantities = $locked->run->requirements->mapWithKeys(fn ($requirement): array => [$requirement->getKey() => '0.00000000'])->all();
            $issuedByRequestLineId = [];
            foreach ($locked->lines as $line) {
                $remainingReserved = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8);
                $quantity = $quantitiesByRequestLineId === []
                    ? $remainingReserved
                    : bcadd((string) ($quantitiesByRequestLineId[$line->getKey()] ?? '0'), '0', 8);

                if (bccomp($quantity, '0', 8) < 0 || bccomp($quantity, $remainingReserved, 8) > 0) {
                    throw new DomainException(__('production_execution.messages.material_request_issue_exceeds_reserved'));
                }
                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                $quantities[$line->production_material_requirement_id] = $quantity;
                $issuedByRequestLineId[$line->getKey()] = $quantity;
            }

            if (! collect($quantities)->contains(fn (string $quantity): bool => bccomp($quantity, '0', 8) > 0)) {
                throw new DomainException(__('production_execution.messages.material_request_no_reserved_stock'));
            }

            $document = $this->cycle->issueMaterials(
                $locked->run,
                (int) $locked->branch_store_id,
                $quantities,
                $locked->request_type === 'additional',
            );
            $document->update(['production_material_request_id' => $locked->getKey()]);

            $requestLines = $locked->lines->keyBy('production_material_requirement_id');
            foreach ($document->load('lines')->lines as $documentLine) {
                $requestLine = $requestLines->get($documentLine->source_line_id);
                if ($requestLine !== null) {
                    $documentLine->update(['production_material_request_line_id' => $requestLine->getKey()]);
                }
            }

            foreach ($locked->lines as $line) {
                $issued = $issuedByRequestLineId[$line->getKey()] ?? '0.00000000';
                if (bccomp($issued, '0', 8) > 0) {
                    $line->increment('issued_quantity', $issued);
                }
            }

            $locked->update([
                'status' => $locked->lines()->whereColumn('issued_quantity', '<', 'approved_quantity')->exists()
                    ? ProductionMaterialRequest::StatusPartiallyIssued
                    : ProductionMaterialRequest::StatusIssued,
                'updated_by' => auth()->id(),
            ]);

            return $document;
        });
    }

    public function allocateShortage(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()
                ->with(['lines.requirement', 'store'])
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! in_array($locked->status, [ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true)
                || ! $locked->lines->contains(fn ($line): bool => bccomp((string) $line->shortage_quantity, '0', 8) > 0)) {
                throw new DomainException(__('production_execution.messages.material_request_has_no_shortage'));
            }

            foreach ($locked->lines as $line) {
                $shortage = (string) $line->shortage_quantity;
                if (bccomp($shortage, '0', 8) <= 0) {
                    continue;
                }

                $available = $this->availability->forProduct(
                    $context['company_id'],
                    (int) $locked->branch_store_id,
                    (int) $line->product_id,
                )['available'];
                $reserveQuantity = bccomp($available, $shortage, 8) >= 0
                    ? $shortage
                    : (bccomp($available, '0', 8) > 0 ? $available : '0.00000000');

                if (bccomp($reserveQuantity, '0', 8) <= 0) {
                    continue;
                }

                $this->reservations->reserveForProduction(
                    $line->requirement,
                    (int) $locked->branch_store_id,
                    $reserveQuantity,
                    null,
                    $locked->request_type === 'additional',
                );
                $line->update([
                    'reserved_quantity' => bcadd((string) $line->reserved_quantity, $reserveQuantity, 8),
                    'shortage_quantity' => bcsub($shortage, $reserveQuantity, 8),
                ]);
            }

            $hasShortage = $locked->lines()->where('shortage_quantity', '>', 0)->exists();
            $hasIssuedQuantity = $locked->lines()->where('issued_quantity', '>', 0)->exists();
            $locked->update([
                'status' => $hasShortage
                    ? ProductionMaterialRequest::StatusShortage
                    : ($hasIssuedQuantity ? ProductionMaterialRequest::StatusPartiallyIssued : ProductionMaterialRequest::StatusApproved),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines.product', 'purchaseRequisition']);
        });
    }

    private function createShortageRequisition(ProductionMaterialRequest $request): PurchaseRequisition
    {
        $request->loadMissing(['lines.product', 'lines.unit', 'run.order', 'run.orderLine', 'store']);
        $lines = $request->lines
            ->filter(fn ($line): bool => bccomp((string) $line->shortage_quantity, '0', 8) > 0)
            ->map(fn ($line): array => [
                'product_doc_num' => $line->product->doc_num,
                'unit_doc_num' => $line->unit?->doc_num,
                'requested_quantity' => (string) $line->shortage_quantity,
                'required_date' => $request->required_by_date?->toDateString(),
                'source_type' => 'production_order',
                'source_doc_num' => $request->run->order->doc_num,
                'source_line_reference' => $request->run->orderLine->public_id,
                'notes' => __('production_execution.messages.generated_from_material_request', ['number' => $request->doc_num]),
            ])->values()->all();

        return $this->procurement->createRequisition([
            'branch_store_uuid' => $request->store->public_uuid,
            'request_date' => now()->toDateString(),
            'required_by_date' => $request->required_by_date?->toDateString(),
            'department' => 'production',
            'priority' => 'urgent',
            'notes' => __('production_execution.messages.generated_from_material_request', ['number' => $request->doc_num]),
            'lines' => $lines,
        ]);
    }

    public function remainingRequestableFor(ProductionMaterialRequirement $requirement, ?int $excludeRequestId = null): string
    {
        $alreadyRequested = (string) ProductionMaterialRequestLine::query()
            ->where('production_material_requirement_id', $requirement->getKey())
            ->whereHas('request', fn ($query) => $query
                ->where('request_type', 'planned')
                ->whereNotIn('status', [ProductionMaterialRequest::StatusRejected, ProductionMaterialRequest::StatusCancelled])
                ->when($excludeRequestId !== null, fn ($requests) => $requests->whereKeyNot($excludeRequestId)))
            ->sum('requested_quantity');
        $remaining = bcsub((string) $requirement->planned_quantity, $alreadyRequested, 8);

        return bccomp($remaining, '0', 8) > 0 ? $remaining : '0.00000000';
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(): array
    {
        $context = $this->context->snapshot(request());
        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.operating_context_required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => (int) $context['branch_id'],
        ];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertContext(object $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.document_outside_context'));
        }
    }
}
