<?php

namespace Modules\Maintenance\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenancePlan;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Maintenance\Models\MaintenanceWorkOrderEvent;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\Supplier;

class MaintenanceWorkflowService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly MaintenanceMaterialRequestService $materials,
    ) {}

    /** @param array<string, mixed> $data */
    public function reportBreakdown(array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($data): MaintenanceRequest {
            $context = $this->requiredContext();
            $inspection = filled($data['quality_inspection_id'] ?? null)
                ? ProductionQualityInspection::query()
                    ->with('run')
                    ->where('company_id', $context['company_id'])
                    ->where('financial_period_id', $context['financial_period_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->lockForUpdate()
                    ->findOrFail($data['quality_inspection_id'])
                : null;
            if ($inspection) {
                $existing = MaintenanceRequest::withTrashed()
                    ->where('quality_inspection_id', $inspection->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                        $existing->update([
                            'restored_by' => auth()->id(),
                            'restored_at' => now(),
                            'updated_by' => auth()->id(),
                        ]);
                    }

                    return $existing->refresh();
                }
            }
            $runId = $data['production_run_id'] ?? $inspection?->production_run_id;
            $run = filled($runId) ? $this->run($context, (int) $runId) : null;
            $assetId = $data['fixed_asset_id'] ?? $run?->fixed_asset_id;
            $moldId = $data['production_mold_id'] ?? $run?->production_mold_id;
            $asset = filled($assetId) ? $this->asset($context, (int) $assetId) : null;
            $mold = filled($moldId) ? $this->mold($context, (int) $moldId) : null;
            if (! $asset && ! $mold) {
                throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
            }
            $numbers = $this->document('maintenance_requests', MaintenanceRequest::class, $context);

            return MaintenanceRequest::query()->create([
                ...$numbers,
                ...$context,
                'fixed_asset_id' => $asset?->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'production_run_id' => $run?->getKey(),
                'quality_inspection_id' => $inspection?->getKey(),
                'reported_at' => $data['reported_at'] ?? now(),
                'is_machine_stopped' => (bool) ($data['is_machine_stopped'] ?? false),
                'request_type' => $data['request_type'] ?? 'breakdown',
                'discipline' => $data['discipline'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'symptoms' => trim($data['symptoms']),
                'notes' => $data['notes'] ?? null,
                'status' => MaintenanceRequest::StatusOpen,
                'reported_by' => auth()->id(),
                'created_by' => auth()->id(),
            ])->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateBreakdown(MaintenanceRequest $request, array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($request, $data): MaintenanceRequest {
            $context = $this->requiredContext();
            $locked = MaintenanceRequest::query()->with('workOrder')->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceRequest::StatusOpen || $locked->workOrder) {
                throw new DomainException(__('maintenance.messages.request_not_editable'));
            }

            $asset = filled($data['fixed_asset_id'] ?? null) ? $this->asset($context, (int) $data['fixed_asset_id']) : null;
            $mold = filled($data['production_mold_id'] ?? null) ? $this->mold($context, (int) $data['production_mold_id']) : null;
            if (! $asset && ! $mold) {
                throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
            }

            $locked->update([
                'fixed_asset_id' => $asset?->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'reported_at' => $data['reported_at'] ?? $locked->reported_at,
                'is_machine_stopped' => (bool) ($data['is_machine_stopped'] ?? false),
                'request_type' => $data['request_type'],
                'discipline' => $data['discipline'] ?? null,
                'priority' => $data['priority'],
                'symptoms' => trim($data['symptoms']),
                'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['asset', 'mold', 'workOrder']);
        });
    }

    /** @param array<string, mixed> $data */
    public function createWorkOrder(array $data, ?MaintenanceRequest $request = null): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($data, $request): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $lockedRequest = $request ? MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->getKey()) : null;
            if ($lockedRequest) {
                $this->assertContext($lockedRequest, $context);
                if ($lockedRequest->status !== MaintenanceRequest::StatusOpen || $lockedRequest->workOrder()->exists()) {
                    throw new DomainException(__('maintenance.messages.request_not_convertible'));
                }
            }

            $runId = $lockedRequest?->production_run_id ?? ($data['production_run_id'] ?? null);
            $run = filled($runId) ? $this->run($context, (int) $runId) : null;
            $assetId = $lockedRequest?->fixed_asset_id ?? ($data['fixed_asset_id'] ?? $run?->fixed_asset_id);
            $moldId = $lockedRequest?->production_mold_id ?? ($data['production_mold_id'] ?? $run?->production_mold_id);
            $asset = filled($assetId) ? $this->asset($context, (int) $assetId) : null;
            $mold = filled($moldId) ? $this->mold($context, (int) $moldId) : null;
            if (! $asset && ! $mold) {
                throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
            }
            $serviceMode = $data['service_mode'] ?? 'internal';
            $supplier = filled($data['supplier_id'] ?? null)
                ? Supplier::query()->where('company_id', $context['company_id'])->findOrFail($data['supplier_id'])
                : null;
            if (in_array($serviceMode, ['external', 'mixed'], true) && ! $supplier && blank($data['external_provider_name'] ?? null)) {
                throw new DomainException(__('maintenance.messages.external_provider_required'));
            }

            $numbers = $this->document('maintenance_work_orders', MaintenanceWorkOrder::class, $context);
            $order = MaintenanceWorkOrder::query()->create([
                ...$numbers,
                ...$context,
                'maintenance_request_id' => $lockedRequest?->getKey(),
                'fixed_asset_id' => $asset?->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'production_run_id' => $run?->getKey(),
                'maintenance_type' => $data['maintenance_type'],
                'discipline' => $data['discipline'] ?? $lockedRequest?->discipline,
                'priority' => $data['priority'] ?? $lockedRequest?->priority ?? 'normal',
                'service_mode' => $serviceMode,
                'supplier_id' => $supplier?->getKey(),
                'external_provider_name' => $data['external_provider_name'] ?? null,
                'external_provider_contact' => $data['external_provider_contact'] ?? null,
                'planned_start_at' => $data['planned_start_at'] ?? null,
                'planned_end_at' => $data['planned_end_at'] ?? null,
                'work_description' => trim($data['work_description']),
                'external_cost' => $data['external_cost'] ?? 0,
                'next_due_date' => $data['next_due_date'] ?? null,
                'status' => MaintenanceWorkOrder::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $lockedRequest?->update([
                'status' => MaintenanceRequest::StatusConverted,
                'converted_by' => auth()->id(),
                'converted_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $order->refresh()->load(['asset', 'mold', 'request', 'supplier']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateWorkOrder(MaintenanceWorkOrder $order, array $data): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order, $data): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()->with('request')->lockForUpdate()->findOrFail($order->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceWorkOrder::StatusDraft) {
                throw new DomainException(__('maintenance.messages.order_not_editable'));
            }

            $assetId = $locked->request?->fixed_asset_id ?? ($data['fixed_asset_id'] ?? null);
            $moldId = $locked->request?->production_mold_id ?? ($data['production_mold_id'] ?? null);
            $asset = filled($assetId) ? $this->asset($context, (int) $assetId) : null;
            $mold = filled($moldId) ? $this->mold($context, (int) $moldId) : null;
            if (! $asset && ! $mold) {
                throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
            }

            $serviceMode = $data['service_mode'];
            $supplier = filled($data['supplier_id'] ?? null)
                ? Supplier::query()->where('company_id', $context['company_id'])->findOrFail($data['supplier_id'])
                : null;
            if (in_array($serviceMode, ['external', 'mixed'], true) && ! $supplier && blank($data['external_provider_name'] ?? null)) {
                throw new DomainException(__('maintenance.messages.external_provider_required'));
            }

            $locked->update([
                'fixed_asset_id' => $asset?->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'maintenance_type' => $data['maintenance_type'],
                'discipline' => $data['discipline'] ?? null,
                'priority' => $data['priority'],
                'service_mode' => $serviceMode,
                'supplier_id' => $supplier?->getKey(),
                'external_provider_name' => $data['external_provider_name'] ?? null,
                'external_provider_contact' => $data['external_provider_contact'] ?? null,
                'planned_start_at' => $data['planned_start_at'] ?? null,
                'planned_end_at' => $data['planned_end_at'] ?? null,
                'work_description' => trim($data['work_description']),
                'external_cost' => $data['external_cost'] ?? 0,
                'next_due_date' => $data['next_due_date'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['asset', 'mold', 'request', 'supplier']);
        });
    }

    public function approve(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return $this->transition($order, [MaintenanceWorkOrder::StatusDraft], MaintenanceWorkOrder::StatusApproved, [
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function start(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()
                ->with(['request', 'productionRun'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceWorkOrder::StatusApproved) {
                throw new DomainException(__('maintenance.messages.invalid_transition'));
            }

            $this->markOperationalResourcesUnderMaintenance($locked);
            $locked->update([
                'status' => MaintenanceWorkOrder::StatusInProgress,
                'started_by' => auth()->id(),
                'actual_start_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['asset', 'mold', 'request', 'supplier', 'productionRun.machine']);
        });
    }

    /** @param array<string, mixed> $data */
    public function recordExecutionEvent(MaintenanceWorkOrder $order, string $action, array $data): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order, $action, $data): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceWorkOrder::StatusInProgress) {
                throw new DomainException(__('maintenance.messages.event_in_progress_only'));
            }

            $occurredAt = CarbonImmutable::parse($data['occurred_at']);
            if ($locked->actual_start_at && $occurredAt->isBefore($locked->actual_start_at)) {
                throw new DomainException(__('maintenance.messages.event_before_start'));
            }

            $eventType = match ($action) {
                'pause' => MaintenanceWorkOrderEvent::TypePaused,
                'resume' => MaintenanceWorkOrderEvent::TypeResumed,
                'external-dispatch' => MaintenanceWorkOrderEvent::TypeExternalDispatched,
                'external-receive' => MaintenanceWorkOrderEvent::TypeExternalReceived,
                default => throw new DomainException(__('maintenance.messages.invalid_event_action')),
            };

            if ($eventType === MaintenanceWorkOrderEvent::TypePaused) {
                if ($locked->paused_at !== null) {
                    throw new DomainException(__('maintenance.messages.order_already_paused'));
                }
                $locked->update([
                    'paused_at' => $occurredAt,
                    'current_pause_reason' => trim((string) $data['reason']),
                    'updated_by' => auth()->id(),
                ]);
            } elseif ($eventType === MaintenanceWorkOrderEvent::TypeResumed) {
                if ($locked->paused_at === null || $occurredAt->isBefore($locked->paused_at)) {
                    throw new DomainException(__('maintenance.messages.order_not_paused'));
                }
                $locked->update([
                    'total_paused_minutes' => $locked->total_paused_minutes + (int) round($locked->paused_at->diffInSeconds($occurredAt) / 60),
                    'paused_at' => null,
                    'current_pause_reason' => null,
                    'updated_by' => auth()->id(),
                ]);
            } elseif ($eventType === MaintenanceWorkOrderEvent::TypeExternalDispatched) {
                if (! in_array($locked->service_mode, ['external', 'mixed'], true)) {
                    throw new DomainException(__('maintenance.messages.external_event_requires_external_mode'));
                }
                if ($locked->external_in_transit) {
                    throw new DomainException(__('maintenance.messages.external_item_already_dispatched'));
                }
                $locked->update([
                    'external_in_transit' => true,
                    'external_dispatched_at' => $occurredAt,
                    'external_received_at' => null,
                    'updated_by' => auth()->id(),
                ]);
            } else {
                if (! $locked->external_in_transit || ($locked->external_dispatched_at && $occurredAt->isBefore($locked->external_dispatched_at))) {
                    throw new DomainException(__('maintenance.messages.external_item_not_dispatched'));
                }
                $locked->update([
                    'external_in_transit' => false,
                    'external_received_at' => $occurredAt,
                    'updated_by' => auth()->id(),
                ]);
            }

            $locked->events()->create([
                ...$context,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
                'details' => collect($data)->only(['recipient', 'item_condition', 'accessories', 'notes'])->filter(fn (mixed $value): bool => filled($value))->all(),
                'recorded_by' => auth()->id(),
            ]);

            return $locked->refresh()->load('events.recordedBy');
        });
    }

    /** @param array<string, mixed> $data */
    public function complete(MaintenanceWorkOrder $order, array $data): MaintenanceWorkOrder
    {
        if (blank($data['diagnosis'] ?? null) || blank($data['work_performed'] ?? null)) {
            throw new DomainException(__('maintenance.messages.completion_details_required'));
        }

        return DB::transaction(function () use ($order, $data): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()
                ->with(['request', 'materialRequests.lines', 'productionRun', 'maintenancePlanDue.plan'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceWorkOrder::StatusInProgress) {
                throw new DomainException(__('maintenance.messages.invalid_transition'));
            }
            if ($locked->paused_at !== null || $locked->external_in_transit) {
                throw new DomainException(__('maintenance.messages.order_has_open_execution_event'));
            }

            $this->materials->recordConsumption($locked, $data['material_usage'] ?? []);
            $common = [
                'diagnosis' => trim($data['diagnosis']),
                'root_cause' => $data['root_cause'] ?? null,
                'work_performed' => trim($data['work_performed']),
                'completion_notes' => $data['completion_notes'] ?? null,
                'test_result' => $data['test_result'],
                'repair_outcome' => $data['repair_outcome'] ?? null,
                'labor_details' => $data['labor_details'] ?? null,
                'follow_up_due_at' => $data['follow_up_due_at'] ?? null,
                'next_due_date' => $data['next_due_date'] ?? null,
                'updated_by' => auth()->id(),
            ];

            if ($data['test_result'] === 'failed') {
                $locked->update($common);

                return $locked->refresh()->load(['asset', 'mold', 'request', 'supplier']);
            }

            foreach ($locked->materialRequests as $materialRequest) {
                if (! in_array($materialRequest->status, [
                    MaintenanceMaterialRequest::StatusIssued,
                    MaintenanceMaterialRequest::StatusPartiallyReturned,
                ], true)) {
                    continue;
                }

                $hasUnused = $materialRequest->lines->contains(function ($line): bool {
                    $unused = bcsub(
                        bcsub((string) $line->issued_quantity, (string) $line->consumed_quantity, 8),
                        (string) $line->returned_quantity,
                        8,
                    );

                    return bccomp($unused, '0', 8) > 0;
                });
                if ($hasUnused) {
                    $this->materials->returnUnused($materialRequest);
                }
            }

            $locked->update([
                ...$common,
                'status' => MaintenanceWorkOrder::StatusCompleted,
                'actual_end_at' => now(),
                'machine_released_at' => $data['machine_released_at'] ?? now(),
                'completed_by' => auth()->id(),
            ]);
            $this->releaseOperationalResources($locked);
            $this->completePlanDue($locked);

            return $locked->refresh()->load(['asset', 'mold', 'request', 'supplier']);
        });
    }

    public function close(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $completed = MaintenanceWorkOrder::query()
                ->with(['request', 'materialRequests.lines', 'expenses'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $this->assertContext($completed, $context);
            if ($completed->status !== MaintenanceWorkOrder::StatusCompleted) {
                throw new DomainException(__('maintenance.messages.invalid_transition'));
            }
            $hasUnaccountedMaterials = $completed->materialRequests->flatMap->lines->contains(
                fn ($line): bool => bccomp(
                    bcadd((string) $line->consumed_quantity, (string) $line->returned_quantity, 8),
                    (string) $line->issued_quantity,
                    8,
                ) !== 0,
            );
            if ($hasUnaccountedMaterials) {
                throw new DomainException(__('maintenance.messages.materials_not_accounted'));
            }
            if ($completed->expenses->whereIn('status', ['submitted', 'approved'])->isNotEmpty()) {
                throw new DomainException(__('maintenance.messages.expenses_not_settled'));
            }
            $completed->update([
                'status' => MaintenanceWorkOrder::StatusClosed,
                'cost_closed_at' => now(),
                'closed_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            if ($completed->request) {
                $completed->request->update([
                    'status' => MaintenanceRequest::StatusClosed,
                    'closed_by' => auth()->id(),
                    'closed_at' => now(),
                    'updated_by' => auth()->id(),
                ]);
            }

            return $completed;
        });
    }

    /** @param list<string> $from @param array<string, mixed> $extra */
    private function transition(MaintenanceWorkOrder $order, array $from, string $status, array $extra = []): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order, $from, $status, $extra): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()->with('request')->lockForUpdate()->findOrFail($order->getKey());
            $this->assertContext($locked, $context);
            if (! in_array($locked->status, $from, true)) {
                throw new DomainException(__('maintenance.messages.invalid_transition'));
            }
            $locked->update([...$extra, 'status' => $status, 'updated_by' => auth()->id()]);

            return $locked->refresh()->load(['asset', 'mold', 'request', 'supplier']);
        });
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function asset(array $context, int $assetId): FixedAsset
    {
        return FixedAsset::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereNotIn('status', [FixedAsset::StatusDisposed, FixedAsset::StatusSold, FixedAsset::StatusWrittenOff])
            ->lockForUpdate()
            ->findOrFail($assetId);
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function mold(array $context, int $moldId): ProductionMold
    {
        return ProductionMold::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->lockForUpdate()
            ->findOrFail($moldId);
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function run(array $context, int $runId): ProductionRun
    {
        return ProductionRun::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->where('branch_id', $context['branch_id'])
            ->lockForUpdate()
            ->findOrFail($runId);
    }

    private function markOperationalResourcesUnderMaintenance(MaintenanceWorkOrder $order): void
    {
        if ($order->production_mold_id !== null) {
            $mold = ProductionMold::query()->lockForUpdate()->findOrFail($order->production_mold_id);
            $conflict = MaintenanceWorkOrder::query()
                ->whereKeyNot($order->getKey())
                ->where('production_mold_id', $mold->getKey())
                ->where('status', MaintenanceWorkOrder::StatusInProgress)
                ->exists();
            if ($conflict || $mold->status === ProductionMold::StatusUnavailable) {
                throw new DomainException(__('maintenance.messages.operational_resource_unavailable'));
            }
            $mold->update(['status' => ProductionMold::StatusMaintenance, 'updated_by' => auth()->id()]);
        }

        $machineId = $order->productionRun?->production_machine_id;
        if ($machineId === null) {
            return;
        }

        $machine = ProductionMachine::query()->lockForUpdate()->findOrFail($machineId);
        $conflict = MaintenanceWorkOrder::query()
            ->whereKeyNot($order->getKey())
            ->where('status', MaintenanceWorkOrder::StatusInProgress)
            ->whereHas('productionRun', fn ($query) => $query->where('production_machine_id', $machineId))
            ->exists();
        if ($conflict || $machine->status === ProductionMachine::StatusUnavailable) {
            throw new DomainException(__('maintenance.messages.operational_resource_unavailable'));
        }
        $machine->update(['status' => ProductionMachine::StatusMaintenance, 'updated_by' => auth()->id()]);
    }

    private function releaseOperationalResources(MaintenanceWorkOrder $order): void
    {
        if ($order->production_mold_id !== null) {
            $hasOtherBlocker = MaintenanceWorkOrder::query()
                ->whereKeyNot($order->getKey())
                ->where('production_mold_id', $order->production_mold_id)
                ->where('status', MaintenanceWorkOrder::StatusInProgress)
                ->exists();
            if (! $hasOtherBlocker) {
                ProductionMold::query()
                    ->whereKey($order->production_mold_id)
                    ->where('status', ProductionMold::StatusMaintenance)
                    ->update(['status' => ProductionMold::StatusAvailable, 'updated_by' => auth()->id()]);
            }
        }

        $machineId = $order->productionRun?->production_machine_id;
        if ($machineId === null) {
            return;
        }
        $hasOtherBlocker = MaintenanceWorkOrder::query()
            ->whereKeyNot($order->getKey())
            ->where('status', MaintenanceWorkOrder::StatusInProgress)
            ->whereHas('productionRun', fn ($query) => $query->where('production_machine_id', $machineId))
            ->exists();
        if (! $hasOtherBlocker) {
            ProductionMachine::query()
                ->whereKey($machineId)
                ->where('status', ProductionMachine::StatusMaintenance)
                ->update(['status' => ProductionMachine::StatusAvailable, 'updated_by' => auth()->id()]);
        }
    }

    private function completePlanDue(MaintenanceWorkOrder $order): void
    {
        $due = $order->maintenancePlanDue;
        if (! $due || $due->status === MaintenancePlanDue::StatusCompleted) {
            return;
        }

        $due->update([
            'status' => MaintenancePlanDue::StatusCompleted,
            'completed_by' => auth()->id(),
            'completed_at' => now(),
        ]);
        $plan = $due->plan;
        $updates = ['last_completed_at' => now(), 'updated_by' => auth()->id()];
        if ($plan->schedule_anchor === MaintenancePlan::AnchorActual) {
            if ($plan->frequency_basis === MaintenancePlan::FrequencyCalendar) {
                $updates['next_due_at'] = now()->addDays((int) $plan->interval_value);
            } elseif (in_array($plan->frequency_basis, [MaintenancePlan::FrequencyOperatingHours, MaintenancePlan::FrequencyCycles], true)) {
                $reading = $plan->readings()->where('basis', $plan->frequency_basis)->first();
                if ($reading) {
                    $updates['next_meter_value'] = bcadd((string) $reading->reading_value, (string) $plan->interval_value, 4);
                }
            }
        }
        $plan->update($updates);
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(): array
    {
        $context = $this->context->snapshot(request());
        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('maintenance.messages.operating_context_required'));
        }

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }

    /** @param class-string<Model> $model @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function document(string $key, string $model, array $context): array
    {
        return $this->documents->nextForCompany($key, $model, $context['company_id'], fn ($query) => $query->where('financial_period_id', $context['financial_period_id']));
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertContext(object $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('maintenance.messages.document_outside_context'));
        }
    }
}
