<?php

namespace Modules\Production\Services;

use App\Services\DocumentOwnerEffectProofService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
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
        private readonly ActivityLogger $activityLogger,
        private readonly ProductionMaterialDemandService $demand,
    ) {}

    /** @param array<int, string|int|float> $quantitiesByRequirementId */
    public function create(
        ProductionRun $run,
        int $branchStoreId,
        array $quantitiesByRequirementId = [],
        bool $additional = false,
        ?string $reason = null,
        ?string $requiredByDate = null,
        array $quantitiesByComponentId = [],
    ): ProductionMaterialRequest {
        return DB::transaction(function () use ($run, $branchStoreId, $quantitiesByRequirementId, $additional, $reason, $requiredByDate, $quantitiesByComponentId): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionRun::query()->with(['requirements.product', 'requirements.unit', 'orderLine'])->lockForUpdate()->findOrFail($run->getKey());
            $this->assertContext($locked, $context);
            app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($locked);
            if (in_array($locked->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_run_closed'));
            }
            $store = BranchStore::query()->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($branchStoreId);

            if ($additional && blank($reason)) {
                throw new DomainException(__('production_execution.messages.additional_material_reason_required'));
            }
            $explicitQuantities = $quantitiesByRequirementId !== [] || $quantitiesByComponentId !== [];
            $resolved = $this->demand->materializeSelections($locked, $quantitiesByComponentId);
            if (array_intersect_key($quantitiesByRequirementId, $resolved) !== []) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }
            $quantitiesByRequirementId += $resolved;
            $locked->load(['requirements.product', 'requirements.unit']);
            if (array_diff(array_map('intval', array_keys($quantitiesByRequirementId)), $locked->requirements->modelKeys()) !== []) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
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
                $quantity = bcadd((string) ($quantitiesByRequirementId[$requirement->getKey()] ?? ($explicitQuantities ? '0' : $defaultQuantity)), '0', 8);

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
        array $quantitiesByComponentId = [],
    ): ProductionMaterialRequest {
        return DB::transaction(function () use ($request, $branchStoreId, $quantitiesByRequirementId, $additional, $reason, $requiredByDate, $quantitiesByComponentId): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
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
            $resolved = $this->demand->materializeSelections($locked->run, $quantitiesByComponentId);
            if (array_intersect_key($quantitiesByRequirementId, $resolved) !== []) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
            }
            $quantitiesByRequirementId += $resolved;
            $locked->run->load(['requirements.product', 'requirements.unit']);
            if (array_diff(array_map('intval', array_keys($quantitiesByRequirementId)), $locked->run->requirements->modelKeys()) !== []) {
                throw new DomainException(__('production_execution.messages.material_request_issue_line_invalid'));
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

            if ($locked->status !== ProductionMaterialRequest::StatusSubmitted
                || $locked->approved_at !== null || $locked->inventoryDocuments()->exists()) {
                throw new DomainException(__('production_execution.messages.material_request_submitted_edit_only'));
            }

            $locked->update(['deleted_by' => auth()->id()]);
            $locked->delete();
        });
    }

    public function cancelUnissued(ProductionMaterialRequest $request, string $reason): ProductionMaterialRequest
    {
        Gate::authorize('production.material_requests.cancel');

        return DB::transaction(function () use ($request, $reason): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionMaterialRequest::query()->with('lines')->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if (blank($reason)) {
                throw new DomainException(__('open_documents.validation.reason_required'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                $context['company_id'], $locked->request_date, $context['financial_period_id'], lockForUpdate: true,
            );
            if ($locked->status === ProductionMaterialRequest::StatusCancelled) {
                return $locked;
            }
            ProductionRun::query()->lockForUpdate()->findOrFail($locked->production_run_id);
            if (! $locked->canCancelUnissued()) {
                throw new DomainException(__('cancellation_review.unissued_only'));
            }
            $before = ['header' => $locked->getAttributes(), 'lines' => $locked->lines->toArray()];
            foreach ($locked->lines as $line) {
                $this->reservations->releaseForMaterialRequestLine($line, trim($reason));
                $line->forceFill(['reserved_quantity' => 0])->save();
            }
            $locked->forceFill([
                'status' => ProductionMaterialRequest::StatusCancelled,
                'notes' => trim(implode("\n", array_filter([$locked->notes, __('cancellation_review.reason').': '.trim($reason)]))),
                'updated_by' => auth()->id(),
            ])->save();
            $this->activityLogger->log(request(), 'production', 'production_material_request.cancelled', 'success', [
                'subject' => $locked, 'company_id' => $locked->company_id, 'properties_only' => true,
                'properties' => ['reason' => trim($reason), 'before' => $before],
            ]);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function restore(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionMaterialRequest::withTrashed()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! $locked->trashed() || $locked->status !== ProductionMaterialRequest::StatusSubmitted) {
                throw new DomainException(__('production_execution.messages.material_request_not_restorable'));
            }

            $run = ProductionRun::query()->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
                ->lockForUpdate()
                ->findOrFail($locked->production_run_id);
            $this->assertContext($run, $context);
            if ($locked->request_type === 'planned') {
                foreach ($locked->lines()->with('requirement.run.orderLine')->get() as $line) {
                    if (bccomp((string) $line->requested_quantity, $this->remainingRequestableFor($line->requirement), 8) > 0) {
                        throw new DomainException(__('production_execution.messages.material_request_exceeds_bom'));
                    }
                }
            }
            $locked->restore();
            $locked->update(['restored_by' => auth()->id(), 'restored_at' => now(), 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    public function approve(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
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
                    $this->reservations->reserveForProductionAcrossPositions(
                        $line->requirement,
                        (int) $locked->branch_store_id,
                        $reserveQuantity,
                        null,
                        $locked->request_type === 'additional',
                        $line->getKey(),
                    );
                }

                $line->update([
                    'approved_quantity' => $quantity,
                    'reserved_quantity' => $reserveQuantity,
                    'shortage_quantity' => $shortage,
                ]);
                $hasShortage = $hasShortage || bccomp($shortage, '0', 8) > 0;
            }

            $locked->update([
                'status' => $hasShortage ? ProductionMaterialRequest::StatusShortage : ProductionMaterialRequest::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines.product', 'purchaseRequisition']);
        });
    }

    public function reopen(ProductionMaterialRequest $request, string $reason): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request, $reason): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()->with('lines')->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if (blank($reason)) {
                throw new DomainException(__('open_documents.validation.reason_required'));
            }

            app(FinancialPeriodService::class)->resolveOpenForPostingDate(
                $context['company_id'],
                $locked->request_date,
                $context['financial_period_id'],
                lockForUpdate: true,
            );
            ProductionRun::query()->lockForUpdate()->findOrFail($locked->production_run_id);
            if ($locked->trashed() || ! $locked->canReopenSafely()) {
                throw new DomainException(__('open_documents.messages.skipped_blocked', ['count' => 1]));
            }

            $approvedSnapshot = [
                'header' => Arr::only($locked->attributesToArray(), [
                    'doc_num', 'request_date', 'required_by_date', 'production_run_id',
                    'branch_store_id', 'request_type', 'status', 'approved_by', 'approved_at',
                ]),
                'lines' => $locked->lines->map(fn (ProductionMaterialRequestLine $line): array => Arr::only(
                    $line->attributesToArray(),
                    ['public_id', 'line_number', 'production_material_requirement_id', 'product_id',
                        'requested_quantity', 'approved_quantity', 'reserved_quantity', 'shortage_quantity'],
                ))->all(),
            ];
            foreach ($locked->lines as $line) {
                $this->reservations->releaseForMaterialRequestLine($line, $reason);
                $line->forceFill([
                    'approved_quantity' => 0,
                    'reserved_quantity' => 0,
                    'shortage_quantity' => 0,
                ])->save();
            }
            $locked->forceFill([
                'status' => ProductionMaterialRequest::StatusSubmitted,
                'updated_by' => auth()->id(),
            ])->save();
            $this->activityLogger->log(request(), 'production', 'production_material_request.reopened', 'success', [
                'subject' => $locked,
                'company_id' => $locked->company_id,
                'properties_only' => true,
                'properties' => [
                    'doc_num' => $locked->doc_num,
                    'reason' => trim($reason),
                    'approved_snapshot' => $approvedSnapshot,
                ],
            ]);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<int, string|int|float> $quantitiesByRequestLineId */
    public function issue(ProductionMaterialRequest $request, array $quantitiesByRequestLineId = [], array $selectedLayersByRequestLineId = []): InventoryDocument
    {
        return DB::transaction(function () use ($request, $quantitiesByRequestLineId, $selectedLayersByRequestLineId): InventoryDocument {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionMaterialRequest::query()->with(['lines', 'run.requirements'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($locked->run);

            if (! in_array($locked->status, [ProductionMaterialRequest::StatusApproved, ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true)) {
                throw new DomainException(__('production_execution.messages.material_request_not_issuable'));
            }
            if (array_diff(array_map('intval', array_keys($quantitiesByRequestLineId + $selectedLayersByRequestLineId)), $locked->lines->modelKeys()) !== []) {
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
                    if (($selectedLayersByRequestLineId[$line->getKey()] ?? []) !== []) {
                        throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                    }

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
                materialRequestLineIdsByRequirementId: $locked->lines->mapWithKeys(
                    fn (ProductionMaterialRequestLine $line): array => [$line->production_material_requirement_id => $line->getKey()],
                )->all(),
                selectedLayersByRequirementId: $locked->lines->mapWithKeys(
                    fn (ProductionMaterialRequestLine $line): array => [$line->production_material_requirement_id => $selectedLayersByRequestLineId[$line->id] ?? []],
                )->all(),
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

    public function reconcileReservationStore(ProductionMaterialRequest $request, string $reason): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request, $reason): ProductionMaterialRequest {
            $context = $this->requiredContext();
            $locked = ProductionMaterialRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (trim($reason) === '' || ! in_array($locked->status, [
                ProductionMaterialRequest::StatusApproved,
                ProductionMaterialRequest::StatusShortage,
                ProductionMaterialRequest::StatusPartiallyIssued,
                ProductionMaterialRequest::StatusIssued,
            ], true)) {
                throw new DomainException(__('production_execution.messages.material_request_reservation_repair_invalid'));
            }

            $run = ProductionRun::query()->lockForUpdate()->findOrFail($locked->production_run_id);
            if (in_array($run->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_run_closed'));
            }

            $changes = [];
            $lines = ProductionMaterialRequestLine::query()
                ->where('production_material_request_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($lines as $line) {
                foreach ($this->reservations->linkUntouchedLegacyForMaterialRequestLine($line, $reason) as $change) {
                    $changes[] = ['request_line_id' => (int) $line->getKey(), 'kind' => 'legacy_link', ...$change];
                }
                foreach ($this->reservations->rebuildMissingForMaterialRequestLine($line, $reason) as $change) {
                    $changes[] = ['request_line_id' => (int) $line->getKey(), 'kind' => 'missing_reservation_rebuild', ...$change];
                }
                foreach ($this->reservations->relocateMisplacedForMaterialRequestLine($line, (int) $locked->branch_store_id, $reason) as $change) {
                    $changes[] = ['request_line_id' => (int) $line->getKey(), 'kind' => 'store_relocation', ...$change];
                }
            }

            if ($changes !== []) {
                $this->activityLogger->log(request(), 'production', 'production_material_request.reservation_store_reconciled', 'success', [
                    'subject' => $locked,
                    'company_id' => $locked->company_id,
                    'properties_only' => true,
                    'properties' => [
                        'doc_num' => $locked->doc_num,
                        'target_store_id' => $locked->branch_store_id,
                        'reason' => trim($reason),
                        'changes' => $changes,
                    ],
                ]);
            }

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function allocateShortage(ProductionMaterialRequest $request): ProductionMaterialRequest
    {
        return DB::transaction(function () use ($request): ProductionMaterialRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
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

                $this->reservations->reserveForProductionAcrossPositions(
                    $line->requirement,
                    (int) $locked->branch_store_id,
                    $reserveQuantity,
                    null,
                    $locked->request_type === 'additional',
                    $line->getKey(),
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

    public function createPurchaseRequisition(ProductionMaterialRequest $request): PurchaseRequisition
    {
        Gate::authorize('production.material_requests.view');
        Gate::authorize('purchases.purchase_requisitions.create');

        return DB::transaction(function () use ($request): PurchaseRequisition {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionMaterialRequest::query()->with(['lines.product', 'lines.unit', 'run.order', 'run.orderLine', 'store'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            $previous = null;
            if ($locked->purchase_requisition_id !== null) {
                $previous = PurchaseRequisition::withTrashed()->where('company_id', $context['company_id'])
                    ->lockForUpdate()->findOrFail($locked->purchase_requisition_id);
                $this->assertContext($previous, $context);
                if ($previous->trashed()) {
                    throw new DomainException(__('production_execution.manual_purchase_replacement_blocked'));
                }
                if ($previous->status !== PurchaseRequisition::StatusCancelled) {
                    return $previous;
                }
                if (! app(DocumentOwnerEffectProofService::class)->cancelledRequisitionIsSettled($previous)) {
                    throw new DomainException(__('production_execution.manual_purchase_replacement_blocked'));
                }
            }
            if (! in_array($locked->status, [ProductionMaterialRequest::StatusShortage, ProductionMaterialRequest::StatusPartiallyIssued], true)
                || ! $locked->lines->contains(fn ($line): bool => bccomp((string) $line->shortage_quantity, '0', 8) > 0)
                || in_array($locked->run->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_has_no_shortage'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], now()->toDateString(), $context['financial_period_id'], lockForUpdate: true);
            $requisition = $this->createShortageRequisition($locked);
            $locked->update(['purchase_requisition_id' => $requisition->getKey(), 'updated_by' => auth()->id()]);
            $this->activityLogger->log(request(), 'production', 'production_material_request.purchase_requisition_created', 'success', [
                'subject' => $locked, 'company_id' => $locked->company_id, 'branch_id' => $locked->branch_id,
                'financial_period_id' => $locked->financial_period_id,
                'properties' => ['purchase_requisition' => $requisition->doc_num],
            ]);
            if ($previous !== null) {
                $this->activityLogger->log(request(), 'production', 'production_material_request.purchase_requisition_replaced', 'success', [
                    'subject' => $locked, 'company_id' => $locked->company_id, 'branch_id' => $locked->branch_id,
                    'financial_period_id' => $locked->financial_period_id,
                    'properties' => [
                        'previous_id' => $previous->getKey(), 'previous_doc_num' => $previous->doc_num,
                        'replacement_id' => $requisition->getKey(), 'replacement_doc_num' => $requisition->doc_num,
                        'previous_owner_effects_settled' => true,
                        'shortage_lines' => $locked->lines->where('shortage_quantity', '>', 0)->map(fn ($line): array => [
                            'material_request_line_id' => $line->getKey(), 'product_id' => $line->product_id,
                            'unit_id' => $line->unit_id, 'shortage_quantity' => (string) $line->shortage_quantity,
                        ])->values()->all(),
                    ],
                ]);
            }

            return $requisition;
        }, 3);
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
        return $this->demand->remaining($requirement, $excludeRequestId);
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
