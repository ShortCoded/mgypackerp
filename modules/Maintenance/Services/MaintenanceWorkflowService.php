<?php

namespace Modules\Maintenance\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionMold;
use Modules\Purchases\Models\Supplier;

class MaintenanceWorkflowService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
    ) {}

    /** @param array<string, mixed> $data */
    public function reportBreakdown(array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($data): MaintenanceRequest {
            $context = $this->requiredContext();
            $asset = $this->asset($context, (int) $data['fixed_asset_id']);
            $numbers = $this->document('maintenance_requests', MaintenanceRequest::class, $context);

            return MaintenanceRequest::query()->create([
                ...$numbers,
                ...$context,
                'fixed_asset_id' => $asset->getKey(),
                'production_run_id' => $data['production_run_id'] ?? null,
                'reported_at' => $data['reported_at'] ?? now(),
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

            $asset = $this->asset($context, (int) ($lockedRequest?->fixed_asset_id ?? $data['fixed_asset_id']));
            $mold = filled($data['production_mold_id'] ?? null)
                ? ProductionMold::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($data['production_mold_id'])
                : null;
            $serviceMode = $data['service_mode'] ?? 'internal';
            $supplier = filled($data['supplier_id'] ?? null)
                ? Supplier::query()->where('company_id', $context['company_id'])->findOrFail($data['supplier_id'])
                : null;
            if ($serviceMode === 'external' && ! $supplier && blank($data['external_provider_name'] ?? null)) {
                throw new DomainException(__('maintenance.messages.external_provider_required'));
            }

            $numbers = $this->document('maintenance_work_orders', MaintenanceWorkOrder::class, $context);
            $order = MaintenanceWorkOrder::query()->create([
                ...$numbers,
                ...$context,
                'maintenance_request_id' => $lockedRequest?->getKey(),
                'fixed_asset_id' => $asset->getKey(),
                'production_mold_id' => $mold?->getKey(),
                'production_run_id' => $lockedRequest?->production_run_id ?? ($data['production_run_id'] ?? null),
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

    public function approve(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return $this->transition($order, [MaintenanceWorkOrder::StatusDraft], MaintenanceWorkOrder::StatusApproved, [
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function start(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return $this->transition($order, [MaintenanceWorkOrder::StatusApproved], MaintenanceWorkOrder::StatusInProgress, [
            'started_by' => auth()->id(),
            'actual_start_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function complete(MaintenanceWorkOrder $order, array $data): MaintenanceWorkOrder
    {
        if (blank($data['diagnosis'] ?? null) || blank($data['work_performed'] ?? null)) {
            throw new DomainException(__('maintenance.messages.completion_details_required'));
        }

        return $this->transition($order, [MaintenanceWorkOrder::StatusInProgress], MaintenanceWorkOrder::StatusCompleted, [
            'diagnosis' => trim($data['diagnosis']),
            'root_cause' => $data['root_cause'] ?? null,
            'work_performed' => trim($data['work_performed']),
            'completion_notes' => $data['completion_notes'] ?? null,
            'actual_end_at' => now(),
            'completed_by' => auth()->id(),
            'next_due_date' => $data['next_due_date'] ?? null,
        ]);
    }

    public function close(MaintenanceWorkOrder $order): MaintenanceWorkOrder
    {
        return DB::transaction(function () use ($order): MaintenanceWorkOrder {
            $completed = $this->transition($order, [MaintenanceWorkOrder::StatusCompleted], MaintenanceWorkOrder::StatusClosed, ['closed_by' => auth()->id()]);
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
