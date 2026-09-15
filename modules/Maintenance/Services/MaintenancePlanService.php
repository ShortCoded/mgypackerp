<?php

namespace Modules\Maintenance\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Maintenance\Models\MaintenanceMeterReading;
use Modules\Maintenance\Models\MaintenancePlan;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionMold;
use Modules\Purchases\Models\Supplier;

class MaintenancePlanService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly MaintenanceWorkflowService $workflow,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): MaintenancePlan
    {
        return DB::transaction(function () use ($data): MaintenancePlan {
            $context = $this->requiredContext();
            $asset = filled($data['fixed_asset_id'] ?? null)
                ? FixedAsset::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($data['fixed_asset_id'])
                : null;
            $mold = filled($data['production_mold_id'] ?? null)
                ? ProductionMold::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($data['production_mold_id'])
                : null;
            if (! $asset && ! $mold) {
                throw new DomainException(__('maintenance.messages.asset_or_mold_required'));
            }
            $supplier = filled($data['supplier_id'] ?? null)
                ? Supplier::query()->where('company_id', $context['company_id'])->lockForUpdate()->findOrFail($data['supplier_id'])
                : null;

            return MaintenancePlan::query()->create([
                ...$this->documents->nextForCompany('maintenance_plans', MaintenancePlan::class, $context['company_id']),
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch_id'],
                'fixed_asset_id' => $asset?->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'supplier_id' => $supplier?->getKey(),
                ...collect($data)->only([
                    'name', 'maintenance_type', 'discipline', 'service_mode', 'external_provider_name',
                    'frequency_basis', 'interval_value', 'schedule_anchor', 'next_due_at', 'next_meter_value',
                    'task_template', 'expected_duration_minutes', 'estimated_cost',
                ])->all(),
                'status' => MaintenancePlan::StatusDraft,
                'created_by' => auth()->id(),
            ])->refresh()->load(['asset', 'mold', 'supplier']);
        });
    }

    public function approve(MaintenancePlan $plan): MaintenancePlan
    {
        return DB::transaction(function () use ($plan): MaintenancePlan {
            $locked = $this->lockedPlan($plan);
            if ($locked->status !== MaintenancePlan::StatusDraft) {
                throw new DomainException(__('maintenance.messages.plan_draft_only'));
            }
            $locked->update([
                'status' => MaintenancePlan::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function recordReading(MaintenancePlan $plan, array $data): MaintenanceMeterReading
    {
        return DB::transaction(function () use ($plan, $data): MaintenanceMeterReading {
            $locked = $this->lockedPlan($plan);
            if ($locked->frequency_basis !== $data['basis']) {
                throw new DomainException(__('maintenance.messages.reading_basis_mismatch'));
            }
            $existing = MaintenanceMeterReading::query()
                ->where('company_id', $locked->company_id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return $existing;
            }

            $previous = $locked->readings()->where('basis', $data['basis'])->lockForUpdate()->first();
            if ($data['basis'] !== MaintenancePlan::FrequencyCondition
                && $previous
                && $data['reading_type'] === 'reading'
                && bccomp((string) $data['reading_value'], (string) $previous->reading_value, 4) < 0) {
                throw new DomainException(__('maintenance.messages.meter_decrease_requires_correction'));
            }

            return $locked->readings()->create([
                'company_id' => $locked->company_id,
                'branch_id' => $locked->branch_id,
                'basis' => $data['basis'],
                'reading_value' => $data['reading_value'] ?? null,
                'is_triggered' => (bool) ($data['is_triggered'] ?? false),
                'reading_type' => $data['reading_type'],
                'recorded_at' => $data['recorded_at'],
                'idempotency_key' => $data['idempotency_key'],
                'notes' => $data['notes'] ?? null,
                'recorded_by' => auth()->id(),
            ])->refresh();
        });
    }

    public function generateDue(MaintenancePlan $plan, ?CarbonImmutable $asOf = null): MaintenancePlanDue
    {
        return DB::transaction(function () use ($plan, $asOf): MaintenancePlanDue {
            $context = $this->requiredContext();
            $locked = $this->lockedPlan($plan);
            if ($locked->status !== MaintenancePlan::StatusApproved) {
                throw new DomainException(__('maintenance.messages.plan_must_be_approved'));
            }
            $asOf ??= CarbonImmutable::now();
            [$dueKey, $dueAt, $meterTarget] = $this->dueDefinition($locked, $asOf);

            $due = MaintenancePlanDue::query()->firstOrCreate(
                ['maintenance_plan_id' => $locked->getKey(), 'due_key' => $dueKey],
                [
                    'company_id' => $context['company_id'],
                    'financial_period_id' => $context['financial_period_id'],
                    'branch_id' => $context['branch_id'],
                    'due_at' => $dueAt,
                    'meter_target' => $meterTarget,
                    'status' => MaintenancePlanDue::StatusOpen,
                    'generated_at' => now(),
                    'generated_by' => auth()->id(),
                ],
            );
            if ($due->wasRecentlyCreated && $locked->schedule_anchor === MaintenancePlan::AnchorPlanned) {
                $this->advancePlan($locked, $dueAt, $meterTarget);
            }

            return $due->refresh()->load(['plan.asset', 'plan.mold', 'workOrder']);
        });
    }

    public function convertDue(MaintenancePlanDue $due): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($due): MaintenanceWorkOrder {
            $context = $this->requiredContext();
            $locked = MaintenancePlanDue::query()->with(['plan.supplier', 'workOrder'])->lockForUpdate()->findOrFail($due->getKey());
            if ((int) $locked->company_id !== $context['company_id']
                || (int) $locked->financial_period_id !== $context['financial_period_id']
                || (int) $locked->branch_id !== $context['branch_id']) {
                throw new DomainException(__('maintenance.messages.document_outside_context'));
            }
            if ($locked->workOrder) {
                return $locked->workOrder;
            }
            if ($locked->status !== MaintenancePlanDue::StatusOpen || $locked->plan->status !== MaintenancePlan::StatusApproved) {
                throw new DomainException(__('maintenance.messages.plan_due_not_convertible'));
            }

            $plannedEnd = $locked->plan->expected_duration_minutes
                ? $locked->due_at->copy()->addMinutes($locked->plan->expected_duration_minutes)
                : null;
            $order = $this->workflow->createWorkOrder([
                'fixed_asset_id' => $locked->plan->fixed_asset_id,
                'production_mold_id' => $locked->plan->production_mold_id,
                'maintenance_type' => $locked->plan->maintenance_type,
                'discipline' => $locked->plan->discipline,
                'priority' => 'normal',
                'service_mode' => $locked->plan->service_mode,
                'supplier_id' => $locked->plan->supplier_id,
                'external_provider_name' => $locked->plan->external_provider_name,
                'planned_start_at' => $locked->due_at,
                'planned_end_at' => $plannedEnd,
                'work_description' => $locked->plan->task_template,
                'external_cost' => $locked->plan->estimated_cost,
            ]);
            $order->update([
                'maintenance_plan_due_id' => $locked->getKey(),
                'status' => MaintenanceWorkOrder::StatusApproved,
                'approved_by' => $locked->plan->approved_by,
                'approved_at' => $locked->plan->approved_at,
                'updated_by' => auth()->id(),
            ]);
            $locked->update([
                'status' => MaintenancePlanDue::StatusConverted,
                'converted_by' => auth()->id(),
                'converted_at' => now(),
            ]);

            return $order->refresh()->load(['asset', 'mold', 'maintenancePlanDue.plan']);
        });
    }

    /** @return array{0: string, 1: CarbonImmutable, 2: string|null} */
    private function dueDefinition(MaintenancePlan $plan, CarbonImmutable $asOf): array
    {
        if ($plan->frequency_basis === MaintenancePlan::FrequencyCalendar) {
            $dueAt = $plan->next_due_at?->toImmutable();
            if (! $dueAt || $dueAt->isAfter($asOf)) {
                throw new DomainException(__('maintenance.messages.plan_not_due'));
            }

            return ['calendar:'.$dueAt->format('YmdHis'), $dueAt, null];
        }

        $reading = $plan->readings()->where('basis', $plan->frequency_basis)->lockForUpdate()->first();
        if (! $reading) {
            throw new DomainException(__('maintenance.messages.plan_reading_required'));
        }
        if ($plan->frequency_basis === MaintenancePlan::FrequencyCondition) {
            if (! $reading->is_triggered) {
                throw new DomainException(__('maintenance.messages.plan_not_due'));
            }

            return ['condition:'.$reading->getKey(), $reading->recorded_at->toImmutable(), null];
        }
        if ($plan->next_meter_value === null || bccomp((string) $reading->reading_value, (string) $plan->next_meter_value, 4) < 0) {
            throw new DomainException(__('maintenance.messages.plan_not_due'));
        }

        return [$plan->frequency_basis.':'.$plan->next_meter_value, $reading->recorded_at->toImmutable(), (string) $plan->next_meter_value];
    }

    private function advancePlan(MaintenancePlan $plan, CarbonImmutable $dueAt, ?string $meterTarget): void
    {
        if ($plan->frequency_basis === MaintenancePlan::FrequencyCalendar) {
            $plan->update(['next_due_at' => $dueAt->addDays((int) $plan->interval_value), 'updated_by' => auth()->id()]);

            return;
        }
        if ($meterTarget !== null) {
            $plan->update(['next_meter_value' => bcadd($meterTarget, (string) $plan->interval_value, 4), 'updated_by' => auth()->id()]);
        }
    }

    private function lockedPlan(MaintenancePlan $plan): MaintenancePlan
    {
        $context = $this->requiredContext();
        $locked = MaintenancePlan::query()->lockForUpdate()->findOrFail($plan->getKey());
        if ((int) $locked->company_id !== $context['company_id'] || (int) $locked->branch_id !== $context['branch_id']) {
            throw new DomainException(__('maintenance.messages.document_outside_context'));
        }

        return $locked;
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
}
