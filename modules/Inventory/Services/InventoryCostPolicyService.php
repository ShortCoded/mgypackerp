<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryPeriodicCostClose;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryCostPolicyService
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /** @return array{method: string, policy_id: int|null} */
    public function resolve(int $companyId, int $branchStoreId, string $transactionDate): array
    {
        $store = BranchStore::query()->with('branch')->findOrFail($branchStoreId);
        if ((int) $store->branch?->company_id !== $companyId) {
            throw new DomainException(__('inventory_cost_policy.errors.store_company'));
        }

        $policy = InventoryCostPolicy::query()
            ->where('company_id', $companyId)
            ->whereDate('effective_from', '<=', $transactionDate)
            ->where(function ($query) use ($store): void {
                $query->where('branch_store_id', $store->getKey())
                    ->orWhere(fn ($branch) => $branch->whereNull('branch_store_id')->where('branch_id', $store->branch_id))
                    ->orWhere(fn ($company) => $company->whereNull('branch_store_id')->whereNull('branch_id'));
            })
            ->orderByRaw('case when branch_store_id is not null then 2 when branch_id is not null then 1 else 0 end desc')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return [
            'method' => $policy?->method ?? InventoryCostPolicy::MovingAverage,
            'policy_id' => $policy?->getKey(),
        ];
    }

    public function assertPostingDateAllowed(int $companyId, int $branchStoreId, string $transactionDate): void
    {
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $store = BranchStore::query()->with('branch')->findOrFail($branchStoreId);
        if ((int) $store->branch?->company_id !== $companyId) {
            throw new DomainException(__('inventory_cost_policy.errors.store_company'));
        }

        if (InventoryTransaction::query()->where('company_id', $companyId)->where('branch_store_id', $branchStoreId)
            ->where('transaction_type', InventoryTransaction::TypeValueAdjustment)->whereDate('transaction_date', '>', $transactionDate)->exists()) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_posting_locked'));
        }

        if (InventoryPeriodicCostClose::query()->where('company_id', $companyId)
            ->where('status', InventoryPeriodicCostClose::StatusFinalized)->whereDate('to_date', '>=', $transactionDate)
            ->get()->contains(fn ($close): bool => in_array($branchStoreId, $close->scope_snapshot['store_ids'], true))) {
            throw new DomainException(__('inventory_periodic_cost.errors.finalized'));
        }

        if (InventoryCostPolicy::query()->where('company_id', $companyId)
            ->whereIn('method', [InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification])
            ->whereDate('effective_from', '>', $transactionDate)
            ->where(fn ($query) => $query->where('branch_store_id', $branchStoreId)
                ->orWhere(fn ($branch) => $branch->whereNull('branch_store_id')->where('branch_id', $store->branch_id))
                ->orWhere(fn ($company) => $company->whereNull('branch_store_id')->whereNull('branch_id')))
            ->exists()) {
            throw new DomainException(__('inventory_cost_policy.errors.backdated'));
        }
    }

    /** @param array{branch_id?: int|null, branch_store_id?: int|null, method: string, effective_from: string, reason?: string|null} $data */
    public function createVersion(
        int $companyId,
        array $data,
        ?int $userId,
        ?InventoryCostPolicyTransition $approvedTransition = null,
    ): InventoryCostPolicy {
        return DB::transaction(function () use ($companyId, $data, $userId, $approvedTransition): InventoryCostPolicy {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $approvedTransition = $approvedTransition === null
                ? null
                : InventoryCostPolicyTransition::query()->lockForUpdate()->findOrFail($approvedTransition->getKey());
            $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
            $storeId = isset($data['branch_store_id']) ? (int) $data['branch_store_id'] : null;
            $method = (string) $data['method'];
            $effectiveFrom = (string) $data['effective_from'];
            $this->authorizeOperation(
                $companyId,
                $branchId,
                $storeId,
                $effectiveFrom,
                (int) $userId,
                $approvedTransition === null
                    ? 'inventory.cost_policies.manage'
                    : 'inventory.cost_policies.transition.activate',
            );

            if (! in_array($method, [InventoryCostPolicy::MovingAverage, InventoryCostPolicy::PeriodicWeightedAverage, InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification], true)) {
                throw new DomainException(__('inventory_cost_policy.errors.unsupported_method'));
            }

            if ($branchId !== null) {
                $branch = Branch::query()->where('company_id', $companyId)->findOrFail($branchId);
                if ($branch->status !== 'active') {
                    throw new DomainException(__('inventory_cost_policy.errors.branch_inactive'));
                }
            }
            if ($storeId !== null) {
                $store = BranchStore::query()->with('branch')->findOrFail($storeId);
                if ((int) $store->branch?->company_id !== $companyId
                    || ($branchId !== null && (int) $store->branch_id !== $branchId)) {
                    throw new DomainException(__('inventory_cost_policy.errors.store_scope'));
                }
                $branchId = (int) $store->branch_id;
            }

            if (! FinancialPeriod::query()
                ->where('company_id', $companyId)
                ->where('is_closed', false)
                ->whereDate('from_date', '<=', $effectiveFrom)
                ->whereDate('to_date', '>=', $effectiveFrom)
                ->exists()) {
                throw new DomainException(__('inventory_cost_policy.errors.period'));
            }

            $scopeKey = $storeId !== null ? 'store:'.$storeId : ($branchId !== null ? 'branch:'.$branchId : 'company');
            if (InventoryCostPolicy::query()->where('company_id', $companyId)
                ->where('scope_key', $scopeKey)
                ->whereDate('effective_from', '>=', $effectiveFrom)->exists()) {
                throw new DomainException(__('inventory_cost_policy.errors.version_order'));
            }

            $transactions = InventoryTransaction::query()->where('company_id', $companyId)
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->when($storeId !== null, fn ($query) => $query->where('branch_store_id', $storeId));
            if ((clone $transactions)->whereDate('transaction_date', '>=', $effectiveFrom)->exists()) {
                throw new DomainException(__('inventory_cost_policy.errors.posted_movement'));
            }

            $hasApprovedTransition = $approvedTransition !== null
                && (int) $approvedTransition->company_id === $companyId
                && $approvedTransition->scope_key === $scopeKey
                && $approvedTransition->effective_from?->toDateString() === $effectiveFrom
                && $approvedTransition->status === InventoryCostPolicyTransition::StatusApproved
                && $approvedTransition->inventory_cost_policy_id === null;

            if (InventoryCostPolicy::usesReceiptLayers($method)
                && ! ($hasApprovedTransition && $method === $approvedTransition->target_method)
                && (clone $transactions)
                    ->selectRaw('1')
                    ->groupBy('branch_store_id', 'product_id', 'stock_status', 'warehouse_location_id', 'batch_lot', 'production_run_id')
                    ->havingRaw('sum(quantity_in - quantity_out) <> 0')
                    ->exists()) {
                throw new DomainException(__('inventory_cost_policy.errors.fifo_balance'));
            }

            return InventoryCostPolicy::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'branch_store_id' => $storeId,
                'scope_key' => $scopeKey,
                'method' => $method,
                'effective_from' => $effectiveFrom,
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
                'created_by' => $userId,
            ]);
        });
    }

    public function authorizeOperation(
        int $companyId,
        ?int $branchId,
        ?int $storeId,
        string $effectiveFrom,
        int $actorId,
        string $permission,
    ): FinancialPeriod {
        $user = $this->authorizeScopeOperation($companyId, $branchId, $storeId, $actorId, $permission);
        $snapshot = $this->context->snapshot(request());
        $period = isset($snapshot['financial_period_id'])
            ? FinancialPeriod::query()->lockForUpdate()->find($snapshot['financial_period_id'])
            : null;
        $company = $this->companies->currentCompany(request());
        if (! $period instanceof FinancialPeriod
            || ! $company instanceof Company
            || ! $this->scope->canAccessFinancialPeriod($user, $period, $company)
            || (int) $period->company_id !== $companyId
            || $period->is_closed
            || $period->from_date->toDateString() > $effectiveFrom
            || $period->to_date->toDateString() < $effectiveFrom) {
            throw new AuthorizationException(__('inventory_cost_policy.errors.period_scope'));
        }

        return $period;
    }

    public function authorizeScopeOperation(
        int $companyId,
        ?int $branchId,
        ?int $storeId,
        int $actorId,
        string $permission,
    ): User {
        $user = request()->user();
        $company = $this->companies->currentCompany(request());
        if (! $user instanceof User
            || (int) $user->getKey() !== $actorId
            || ! $user->can($permission)
            || ! $company instanceof Company
            || (int) $company->getKey() !== $companyId
            || $company->status !== 'active'
            || ! $this->scope->canAccessCompany($user, $company)) {
            throw new AuthorizationException(__('inventory_cost_policy.errors.unauthorized'));
        }

        if ($storeId !== null) {
            $store = BranchStore::query()->with('branch')->find($storeId);
            if (! $store instanceof BranchStore || (int) $store->branch?->company_id !== $companyId) {
                throw new AuthorizationException(__('inventory_cost_policy.errors.unauthorized'));
            }

            $branchId = (int) $store->branch_id;
        }

        if ($branchId === null) {
            if (! $this->scope->hasUnrestrictedBranchAccess($user)) {
                throw new AuthorizationException(__('inventory_cost_policy.errors.unauthorized'));
            }

            return $user;
        }

        $branch = Branch::query()->where('company_id', $companyId)->find($branchId);
        if (! $branch instanceof Branch || ! $this->scope->canAccessBranch($user, $branch, $company)) {
            throw new AuthorizationException(__('inventory_cost_policy.errors.unauthorized'));
        }

        return $user;
    }
}
