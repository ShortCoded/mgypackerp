<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryPeriodicCostClose;

class InventoryPeriodicCostCloseService
{
    public function __construct(
        private readonly InventoryCostPolicyService $policies,
        private readonly OperatingContextService $context,
        private readonly FinancialPeriodService $periods,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
        private readonly InventoryReceiptCostProposalService $receiptProposals,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function prepare(int $companyId, array $data, int $actorId): InventoryPeriodicCostClose
    {
        return DB::transaction(function () use ($companyId, $data, $actorId): InventoryPeriodicCostClose {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $store = isset($data['scope_store_id']) ? BranchStore::query()->with('branch')->findOrFail($data['scope_store_id']) : null;
            $branchId = $store?->branch_id ?? ($data['scope_branch_id'] ?? null);
            if ($store && ((int) $store->branch?->company_id !== $companyId
                || (isset($data['scope_branch_id']) && (int) $data['scope_branch_id'] !== (int) $store->branch_id))) {
                throw new AuthorizationException(__('inventory_periodic_cost.errors.scope'));
            }
            $this->policies->authorizeScopeOperation($companyId, $branchId, $store?->id, $actorId, 'inventory.cost_policies.periodic.prepare');
            $snapshot = $this->context->snapshot(request());
            if (empty($data['reason']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['from_date'])
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['to_date'])
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['posting_date']) || $data['from_date'] > $data['to_date']
                || $data['to_date'] > $data['posting_date']) {
                throw new DomainException(__('inventory_periodic_cost.errors.dates'));
            }
            $sourcePeriod = FinancialPeriod::query()->where('company_id', $companyId)
                ->whereDate('from_date', '<=', $data['from_date'])->whereDate('to_date', '>=', $data['to_date'])->first();
            if ($sourcePeriod === null) {
                throw new DomainException(__('inventory_periodic_cost.errors.dates'));
            }
            $close = InventoryPeriodicCostClose::query()->create([
                'public_uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $snapshot['branch_id'],
                'scope_branch_id' => $branchId, 'scope_store_id' => $store?->id,
                'financial_period_id' => $sourcePeriod->id, 'posting_period_id' => $snapshot['financial_period_id'],
                'counterpart_account_id' => $data['counterpart_account_id'],
                'doc_num' => 'PWA-'.str_pad((string) (InventoryPeriodicCostClose::query()->where('company_id', $companyId)->count() + 1), 5, '0', STR_PAD_LEFT),
                'from_date' => $data['from_date'], 'to_date' => $data['to_date'], 'posting_date' => $data['posting_date'],
                'status' => InventoryPeriodicCostClose::StatusPrepared, 'reason' => trim($data['reason']),
                'scope_snapshot' => [], 'prepared_by' => $actorId, 'prepared_at' => now(),
            ]);
            $window = $this->capture($close, $actorId, 'inventory.cost_policies.periodic.prepare');
            $plan = $this->completion->planPeriodic($close, $window);
            $this->receiptProposals->assertImpactBranchAccess(request(), $plan);
            if ($plan['effects'] === [] && InventoryPeriodicCostClose::query()->where('company_id', $companyId)
                ->where('status', InventoryPeriodicCostClose::StatusFinalized)->whereDate('from_date', $close->from_date)
                ->whereDate('to_date', $close->to_date)->get()->contains(fn ($row): bool => array_intersect($window['store_ids'], $row->scope_snapshot['store_ids']) !== [])) {
                throw new DomainException(__('inventory_periodic_cost.errors.duplicate'));
            }
            $close->forceFill(['scope_snapshot' => $window, 'impact_snapshot' => $plan,
                'impact_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))])->save();
            $this->log($close, 'periodic_cost_prepared');

            return $close->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function capture(InventoryPeriodicCostClose $close, int $actorId, string $permission): array
    {
        $this->policies->authorizeScopeOperation((int) $close->company_id, $close->scope_branch_id,
            $close->scope_store_id, $actorId, $permission);
        $snapshot = $this->context->snapshot(request());
        if ((int) $snapshot['financial_period_id'] !== (int) $close->posting_period_id) {
            throw new AuthorizationException(__('inventory_periodic_cost.errors.scope'));
        }
        $this->periods->resolveOpenForPostingDate((int) $close->company_id, $close->posting_date,
            expectedPeriodId: (int) $close->posting_period_id, lockForUpdate: true);
        $stores = BranchStore::query()->whereHas('branch', fn ($query) => $query->where('company_id', $close->company_id))
            ->when($close->scope_branch_id !== null, fn ($query) => $query->where('branch_id', $close->scope_branch_id))
            ->when($close->scope_store_id !== null, fn ($query) => $query->whereKey($close->scope_store_id))
            ->orderBy('id')->get();
        $policyIds = [];
        foreach ($stores as $store) {
            $this->policies->authorizeScopeOperation((int) $close->company_id, (int) $store->branch_id, (int) $store->id, $actorId, $permission);
            $start = $this->policies->resolve((int) $close->company_id, (int) $store->id, $close->from_date->toDateString());
            $end = $this->policies->resolve((int) $close->company_id, (int) $store->id, $close->to_date->toDateString());
            if ($start['method'] !== InventoryCostPolicy::PeriodicWeightedAverage && $end['method'] !== InventoryCostPolicy::PeriodicWeightedAverage
                && $close->scope_store_id === null) {
                continue;
            }
            if ($start['method'] !== InventoryCostPolicy::PeriodicWeightedAverage || $start !== $end) {
                throw new DomainException(__('inventory_periodic_cost.errors.policy'));
            }
            $policyIds[$store->id] = $start['policy_id'];
        }
        $stores = $stores->whereIn('id', array_keys($policyIds))->values();
        if ($stores->isEmpty()) {
            throw new DomainException(__('inventory_periodic_cost.errors.scope'));
        }
        $storeIds = array_map('intval', $stores->modelKeys());
        $other = InventoryPeriodicCostClose::query()->where('company_id', $close->company_id)->whereKeyNot($close->id)
            ->whereIn('status', [InventoryPeriodicCostClose::StatusPrepared, InventoryPeriodicCostClose::StatusFinalized])
            ->whereDate('to_date', '>=', $close->from_date)->orderBy('id')->lockForUpdate()->get();
        foreach ($other as $previous) {
            if (array_intersect($storeIds, $previous->scope_snapshot['store_ids'] ?? []) === []) {
                continue;
            }
            if ($previous->status === InventoryPeriodicCostClose::StatusFinalized
                && $previous->from_date->equalTo($close->from_date) && $previous->to_date->equalTo($close->to_date)) {
                continue;
            }
            throw new DomainException(__('inventory_periodic_cost.errors.overlap'));
        }

        return ['from_date' => $close->from_date->toDateString(), 'to_date' => $close->to_date->toDateString(),
            'store_ids' => $storeIds, 'policy_ids' => $policyIds,
            'store_labels' => $stores->mapWithKeys(fn ($store): array => [$store->id => $store->name])->all()];
    }

    public function approve(InventoryPeriodicCostClose $close, int $actorId, string $reference): InventoryPeriodicCostClose
    {
        return DB::transaction(function () use ($close, $actorId, $reference): InventoryPeriodicCostClose {
            Company::query()->whereKey($close->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryPeriodicCostClose::query()->lockForUpdate()->findOrFail($close->id);
            if ($locked->status !== InventoryPeriodicCostClose::StatusPrepared || (int) $locked->prepared_by === $actorId || trim($reference) === '') {
                throw new DomainException(__('inventory_periodic_cost.errors.approval'));
            }
            $window = $this->capture($locked, $actorId, 'inventory.cost_policies.periodic.approve');
            $plan = $this->completion->planPeriodic($locked, $window);
            $this->receiptProposals->assertImpactBranchAccess(request(), $plan);
            if ($locked->impact_sha256 !== hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR))) {
                throw new DomainException(__('inventory_periodic_cost.errors.stale'));
            }
            $locked->forceFill(['approval_reference' => trim($reference)])->save();
            $adjustment = $this->adjustments->postPeriodicClose($locked, $plan, request());
            $this->completion->persistBases($adjustment, $plan);
            $locked->forceFill(['status' => InventoryPeriodicCostClose::StatusFinalized, 'approved_by' => $actorId, 'approved_at' => now()])->save();
            $this->log($locked, 'periodic_cost_finalized');

            return $locked->refresh();
        });
    }

    public function reject(InventoryPeriodicCostClose $close, int $actorId, string $reason): InventoryPeriodicCostClose
    {
        return DB::transaction(function () use ($close, $actorId, $reason): InventoryPeriodicCostClose {
            Company::query()->whereKey($close->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryPeriodicCostClose::query()->lockForUpdate()->findOrFail($close->id);
            $this->policies->authorizeScopeOperation((int) $locked->company_id, $locked->scope_branch_id,
                $locked->scope_store_id, $actorId, 'inventory.cost_policies.periodic.approve');
            if ($locked->status !== InventoryPeriodicCostClose::StatusPrepared || trim($reason) === '') {
                throw new DomainException(__('inventory_periodic_cost.errors.approval'));
            }
            $locked->forceFill(['status' => InventoryPeriodicCostClose::StatusRejected, 'rejected_by' => $actorId,
                'rejected_at' => now(), 'rejection_reason' => trim($reason)])->save();
            $this->log($locked, 'periodic_cost_rejected');

            return $locked->refresh();
        });
    }

    private function log(InventoryPeriodicCostClose $close, string $action): void
    {
        $this->activity->log(request(), 'inventory', $action, 'success', ['subject' => $close,
            'company_id' => $close->company_id, 'properties_only' => true,
            'properties' => $close->only(['doc_num', 'status', 'from_date', 'to_date', 'posting_date', 'impact_sha256', 'approval_reference'])]);
    }
}
