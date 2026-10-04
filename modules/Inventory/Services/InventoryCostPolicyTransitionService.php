<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryCostPolicyTransition;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryCostPolicyTransitionService
{
    public function __construct(private readonly InventoryCostPolicyService $policies) {}

    /**
     * @param  array{branch_id?: int|null, branch_store_id?: int|null, effective_from: string, target_method?: string, reason?: string|null}  $data
     */
    public function prepare(int $companyId, array $data, int $userId): InventoryCostPolicyTransition
    {
        return DB::transaction(function () use ($companyId, $data, $userId): InventoryCostPolicyTransition {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $scope = $this->validatedScope($companyId, $data['branch_id'] ?? null, $data['branch_store_id'] ?? null);
            $effectiveFrom = (string) $data['effective_from'];
            $targetMethod = $data['target_method'] ?? InventoryCostPolicy::Fifo;
            if (! in_array($targetMethod, [InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification], true)) {
                throw new DomainException(__('inventory_cost_policy.errors.unsupported_method'));
            }
            $this->policies->authorizeOperation(
                $companyId,
                $scope['branch_id'],
                $scope['branch_store_id'],
                $effectiveFrom,
                $userId,
                'inventory.cost_policies.transition.prepare',
            );

            if (InventoryCostPolicyTransition::query()
                ->where('company_id', $companyId)
                ->where('scope_key', $scope['scope_key'])
                ->whereIn('status', [InventoryCostPolicyTransition::StatusPrepared, InventoryCostPolicyTransition::StatusApproved])
                ->exists()) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.pending'));
            }

            $snapshot = $this->capture($companyId, $scope, $effectiveFrom);
            if ($snapshot['lines'] === []) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.empty'));
            }

            $transition = InventoryCostPolicyTransition::query()->create([
                'company_id' => $companyId,
                'branch_id' => $scope['branch_id'],
                'branch_store_id' => $scope['branch_store_id'],
                'scope_key' => $scope['scope_key'],
                'effective_from' => $effectiveFrom,
                'target_method' => $targetMethod,
                'status' => InventoryCostPolicyTransition::StatusPrepared,
                'input_fingerprint' => $snapshot['fingerprint'],
                'total_quantity' => $snapshot['total_quantity'],
                'total_book_value' => $snapshot['total_book_value'],
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
                'prepared_by' => $userId,
                'prepared_at' => now(),
            ]);
            foreach ($snapshot['lines'] as $line) {
                $transition->bases()->create($line);
            }

            return $transition->load(['bases.receiptLayer', 'preparedBy']);
        }, attempts: 3);
    }

    public function approve(InventoryCostPolicyTransition $transition, int $userId): InventoryCostPolicyTransition
    {
        return DB::transaction(function () use ($transition, $userId): InventoryCostPolicyTransition {
            $companyId = (int) InventoryCostPolicyTransition::query()
                ->whereKey($transition->getKey())
                ->firstOrFail(['company_id'])
                ->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $locked = InventoryCostPolicyTransition::query()->lockForUpdate()->findOrFail($transition->getKey());
            $this->policies->authorizeOperation(
                (int) $locked->company_id,
                $locked->branch_id === null ? null : (int) $locked->branch_id,
                $locked->branch_store_id === null ? null : (int) $locked->branch_store_id,
                $locked->effective_from->toDateString(),
                $userId,
                'inventory.cost_policies.transition.approve',
            );
            if ($locked->status !== InventoryCostPolicyTransition::StatusPrepared
                || (int) $locked->prepared_by === $userId) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.approval'));
            }

            $scope = $this->validatedScope((int) $locked->company_id, $locked->branch_id, $locked->branch_store_id);
            $snapshot = $this->capture((int) $locked->company_id, $scope, $locked->effective_from->toDateString());
            $this->assertFresh($locked, $snapshot);
            $locked->forceFill([
                'status' => InventoryCostPolicyTransition::StatusApproved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            return $locked->refresh()->load(['bases.receiptLayer', 'preparedBy', 'approvedBy']);
        }, attempts: 3);
    }

    public function activate(InventoryCostPolicyTransition $transition, int $userId): InventoryCostPolicyTransition
    {
        return DB::transaction(function () use ($transition, $userId): InventoryCostPolicyTransition {
            $companyId = (int) InventoryCostPolicyTransition::query()
                ->whereKey($transition->getKey())
                ->firstOrFail(['company_id'])
                ->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $locked = InventoryCostPolicyTransition::query()->lockForUpdate()->findOrFail($transition->getKey());
            $this->policies->authorizeOperation(
                (int) $locked->company_id,
                $locked->branch_id === null ? null : (int) $locked->branch_id,
                $locked->branch_store_id === null ? null : (int) $locked->branch_store_id,
                $locked->effective_from->toDateString(),
                $userId,
                'inventory.cost_policies.transition.activate',
            );
            if ($locked->status !== InventoryCostPolicyTransition::StatusApproved
                || $locked->inventory_cost_policy_id !== null) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.activation'));
            }

            $scope = $this->validatedScope((int) $locked->company_id, $locked->branch_id, $locked->branch_store_id);
            $this->lockScopeStores($scope);
            $snapshot = $this->capture((int) $locked->company_id, $scope, $locked->effective_from->toDateString());
            $this->assertFresh($locked, $snapshot);

            $policy = $this->policies->createVersion((int) $locked->company_id, [
                'branch_id' => $locked->branch_id,
                'branch_store_id' => $locked->branch_store_id,
                'method' => $locked->target_method,
                'effective_from' => $locked->effective_from->toDateString(),
                'reason' => $locked->reason,
            ], $userId, $locked);

            $locked->forceFill([
                'status' => InventoryCostPolicyTransition::StatusActivated,
                'inventory_cost_policy_id' => $policy->getKey(),
                'activated_by' => $userId,
                'activated_at' => now(),
            ])->save();

            return $locked->refresh()->load(['bases.receiptLayer', 'policy', 'preparedBy', 'approvedBy']);
        }, attempts: 3);
    }

    public function cancel(InventoryCostPolicyTransition $transition, int $userId, string $reason): InventoryCostPolicyTransition
    {
        return DB::transaction(function () use ($transition, $userId, $reason): InventoryCostPolicyTransition {
            $companyId = (int) InventoryCostPolicyTransition::query()
                ->whereKey($transition->getKey())
                ->firstOrFail(['company_id'])
                ->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $locked = InventoryCostPolicyTransition::query()->lockForUpdate()->findOrFail($transition->getKey());
            $this->policies->authorizeScopeOperation(
                (int) $locked->company_id,
                $locked->branch_id === null ? null : (int) $locked->branch_id,
                $locked->branch_store_id === null ? null : (int) $locked->branch_store_id,
                $userId,
                'inventory.cost_policies.transition.cancel',
            );
            if (! in_array($locked->status, [
                InventoryCostPolicyTransition::StatusPrepared,
                InventoryCostPolicyTransition::StatusApproved,
            ], true)) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.cancellation'));
            }

            $locked->forceFill([
                'status' => InventoryCostPolicyTransition::StatusCancelled,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
            ])->save();

            return $locked->refresh()->load(['bases.receiptLayer', 'preparedBy', 'approvedBy', 'cancelledBy']);
        }, attempts: 3);
    }

    /**
     * @param  array{branch_id: int|null, branch_store_id: int|null, scope_key: string, store_ids: list<int>}  $scope
     * @return array{fingerprint: string, total_quantity: string, total_book_value: string, lines: list<array<string, mixed>>}
     */
    private function capture(int $companyId, array $scope, string $effectiveFrom): array
    {
        $stores = BranchStore::query()->with('branch:id,company_id')->whereIn('id', $scope['store_ids'])->orderBy('id')->lockForUpdate()->get();
        if ($stores->count() !== count($scope['store_ids'])) {
            throw new DomainException(__('inventory_cost_policy.errors.store_scope'));
        }
        $stores = $stores->filter(fn (BranchStore $store): bool => $this->scopeAppliesToStore($scope, $store, $effectiveFrom))->values();
        foreach ($stores as $store) {
            if ($this->policies->resolve($companyId, (int) $store->getKey(), $effectiveFrom)['method'] !== InventoryCostPolicy::MovingAverage) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.source_method'));
            }
        }

        $transactionsQuery = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->whereIn('branch_store_id', $stores->modelKeys());
        if ((clone $transactionsQuery)->whereDate('transaction_date', '>=', $effectiveFrom)->exists()) {
            throw new DomainException(__('inventory_cost_policy.errors.posted_movement'));
        }
        Product::query()->whereIn('id', (clone $transactionsQuery)->select('product_id')->distinct()->pluck('product_id')->all())
            ->orderBy('id')->lockForUpdate()->get();
        $transactions = $transactionsQuery->orderBy('id')->lockForUpdate()->get();
        $positions = [];
        foreach ($transactions as $transaction) {
            $key = $this->positionKey($transaction);
            $positions[$key] ??= [
                'branch_store_id' => (int) $transaction->branch_store_id,
                'product_id' => (int) $transaction->product_id,
                'stock_status' => (string) $transaction->stock_status,
                'warehouse_location_id' => $transaction->warehouse_location_id === null ? null : (int) $transaction->warehouse_location_id,
                'batch_lot' => $transaction->batch_lot,
                'production_run_id' => $transaction->production_run_id === null ? null : (int) $transaction->production_run_id,
                'quantity' => '0.00000000',
                'book_value' => '0.00000000',
                'unvalued_quantity' => '0.00000000',
            ];
            $quantityIn = (string) $transaction->quantity_in;
            $quantityOut = (string) $transaction->quantity_out;
            $positions[$key]['quantity'] = bcadd($positions[$key]['quantity'], bcsub($quantityIn, $quantityOut, 8), 8);
            $positions[$key]['unvalued_quantity'] = bcadd($positions[$key]['unvalued_quantity'],
                bcadd($transaction->unit_cost === null || $transaction->total_cost === null ? bcsub($quantityIn, $quantityOut, 8) : '0',
                    (string) ($transaction->unvalued_quantity_delta ?? '0'), 8), 8);
            $positions[$key]['book_value'] = bcadd($positions[$key]['book_value'], $transaction->signedValue(), 8);
        }

        ksort($positions);
        $lines = [];
        $totalQuantity = '0.00000000';
        $totalBookValue = '0.00000000';
        foreach ($positions as $position) {
            if (bccomp($position['unvalued_quantity'], '0', 8) !== 0) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.unpriced'));
            }
            if (bccomp($position['quantity'], '0', 8) < 0 || bccomp($position['book_value'], '0', 8) < 0) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.reconciliation'));
            }
            if (bccomp($position['quantity'], '0', 8) === 0) {
                if (bccomp($position['book_value'], '0', 8) !== 0) {
                    throw new DomainException(__('inventory_cost_policy.transition_errors.reconciliation'));
                }

                continue;
            }

            $layers = InventoryReceiptLayer::query()
                ->with('receiptTransaction:id,production_run_id')
                ->where('company_id', $companyId)
                ->where('branch_store_id', $position['branch_store_id'])
                ->where('product_id', $position['product_id'])
                ->where('stock_status', $position['stock_status'])
                ->where('remaining_quantity', '>', 0)
                ->when($position['warehouse_location_id'] === null, fn ($query) => $query->whereNull('warehouse_location_id'), fn ($query) => $query->where('warehouse_location_id', $position['warehouse_location_id']))
                ->when($position['batch_lot'] === null, fn ($query) => $query->whereNull('batch_lot'), fn ($query) => $query->where('batch_lot', $position['batch_lot']))
                ->whereHas('receiptTransaction', fn ($query) => $position['production_run_id'] === null
                    ? $query->whereNull('production_run_id')
                    : $query->where('production_run_id', $position['production_run_id']))
                ->orderBy('original_receipt_date')->orderBy('id')->lockForUpdate()->get();
            $layerQuantity = $layers->reduce(
                fn (string $carry, InventoryReceiptLayer $layer): string => bcadd($carry, (string) $layer->remaining_quantity, 8),
                '0.00000000',
            );
            if (bccomp($layerQuantity, $position['quantity'], 8) !== 0) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.reconciliation'));
            }

            $average = bcdiv($position['book_value'], $position['quantity'], 8);
            $remainingValue = $position['book_value'];
            foreach ($layers->values() as $index => $layer) {
                $quantity = (string) $layer->remaining_quantity;
                $value = $index === $layers->count() - 1 ? $remainingValue : bcmul($quantity, $average, 8);
                $remainingValue = bcsub($remainingValue, $value, 8);
                $lines[] = [
                    'inventory_receipt_layer_id' => $layer->getKey(),
                    'branch_store_id' => $position['branch_store_id'],
                    'product_id' => $position['product_id'],
                    'warehouse_location_id' => $position['warehouse_location_id'],
                    'production_run_id' => $position['production_run_id'],
                    'stock_status' => $position['stock_status'],
                    'batch_lot' => $position['batch_lot'],
                    'original_quantity' => $quantity,
                    'original_book_value' => $value,
                    'basis_unit_cost' => bcdiv($value, $quantity, 8),
                    'remaining_quantity' => $quantity,
                    'remaining_book_value' => $value,
                ];
            }
            $totalQuantity = bcadd($totalQuantity, $position['quantity'], 8);
            $totalBookValue = bcadd($totalBookValue, $position['book_value'], 8);
        }

        $fingerprintLines = collect($lines)->map(fn (array $line): array => [
            'layer_id' => (int) $line['inventory_receipt_layer_id'],
            'quantity' => (string) $line['original_quantity'],
            'book_value' => (string) $line['original_book_value'],
        ])->all();

        return [
            'fingerprint' => hash('sha256', json_encode([
                'company_id' => $companyId,
                'scope_key' => $scope['scope_key'],
                'effective_from' => $effectiveFrom,
                'transactions' => $transactions->map(fn (InventoryTransaction $transaction): array => [
                    (int) $transaction->getKey(),
                    (string) $transaction->quantity_in,
                    (string) $transaction->quantity_out,
                    $transaction->unit_cost === null ? null : (string) $transaction->unit_cost,
                    $transaction->total_cost === null ? null : (string) $transaction->total_cost,
                ])->all(),
                'lines' => $fingerprintLines,
            ], JSON_THROW_ON_ERROR)),
            'total_quantity' => $totalQuantity,
            'total_book_value' => $totalBookValue,
            'lines' => $lines,
        ];
    }

    /** @param array{fingerprint: string, total_quantity: string, total_book_value: string, lines: list<array<string, mixed>>} $snapshot */
    private function assertFresh(InventoryCostPolicyTransition $transition, array $snapshot): void
    {
        if (! hash_equals((string) $transition->input_fingerprint, $snapshot['fingerprint'])
            || bccomp((string) $transition->total_quantity, $snapshot['total_quantity'], 8) !== 0
            || bccomp((string) $transition->total_book_value, $snapshot['total_book_value'], 8) !== 0) {
            throw new DomainException(__('inventory_cost_policy.transition_errors.stale'));
        }
    }

    /**
     * @return array{branch_id: int|null, branch_store_id: int|null, scope_key: string, store_ids: list<int>}
     */
    private function validatedScope(int $companyId, mixed $branchId, mixed $storeId): array
    {
        $branchId = $branchId === null ? null : (int) $branchId;
        $storeId = $storeId === null ? null : (int) $storeId;
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

        $storeIds = BranchStore::query()
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId)
                ->when($branchId !== null, fn ($branchQuery) => $branchQuery->whereKey($branchId)))
            ->when($storeId !== null, fn ($query) => $query->whereKey($storeId))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [
            'branch_id' => $branchId,
            'branch_store_id' => $storeId,
            'scope_key' => $storeId !== null ? 'store:'.$storeId : ($branchId !== null ? 'branch:'.$branchId : 'company'),
            'store_ids' => $storeIds,
        ];
    }

    /** @param array{store_ids: list<int>} $scope */
    private function lockScopeStores(array $scope): void
    {
        BranchStore::query()->whereIn('id', $scope['store_ids'])->orderBy('id')->lockForUpdate()->get();
    }

    private function positionKey(InventoryTransaction $transaction): string
    {
        return implode('|', [
            $transaction->branch_store_id,
            $transaction->product_id,
            $transaction->stock_status,
            $transaction->warehouse_location_id ?? 'null',
            $transaction->batch_lot ?? 'null',
            $transaction->production_run_id ?? 'null',
        ]);
    }

    /** @param array{branch_id: int|null, branch_store_id: int|null} $scope */
    private function scopeAppliesToStore(array $scope, BranchStore $store, string $effectiveFrom): bool
    {
        if ($scope['branch_store_id'] !== null) {
            return (int) $scope['branch_store_id'] === (int) $store->getKey();
        }

        $overrides = InventoryCostPolicy::query()
            ->where('company_id', $store->branch?->company_id)
            ->whereDate('effective_from', '<=', $effectiveFrom);
        if ($scope['branch_id'] !== null) {
            return ! $overrides->where('branch_store_id', $store->getKey())->exists();
        }

        return ! $overrides->where(fn ($query) => $query
            ->where('branch_store_id', $store->getKey())
            ->orWhere(fn ($branch) => $branch->whereNull('branch_store_id')->where('branch_id', $store->branch_id)))
            ->exists();
    }
}
