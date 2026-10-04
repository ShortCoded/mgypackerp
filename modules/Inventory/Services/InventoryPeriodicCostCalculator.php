<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryPeriodicCostCalculator
{
    /** @param array<string, mixed> $window @return array{targets: array<int, string>, inputs: array<int, array<string, mixed>>} */
    public function calculate(Collection $transactions, Collection $costs, array $window, Collection $provisionalCosts): array
    {
        $groups = $transactions->filter(fn ($row): bool => in_array((int) $row->branch_store_id, $window['store_ids'], true)
            && $row->transaction_date->toDateString() <= $window['to_date'])
            ->groupBy(fn ($row): string => implode(':', [$row->branch_store_id, $row->product_id, $row->stock_status,
                $row->warehouse_location_id ?? 'none', $row->batch_lot ?? 'none',
                $row->stock_status === InventoryTransaction::StatusProductionStaging ? ($row->production_run_id ?? 'none') : 'none']));
        $byId = $transactions->keyBy('id');
        $targets = [];
        $inputs = [];
        foreach ($groups as $key => $rows) {
            $openingQuantity = $openingValue = $openingUnknown = $receiptQuantity = $receiptValue = $closingQuantity = '0.00000000';
            $outflows = collect();
            $receiptIds = $openingIds = [];
            foreach ($rows as $row) {
                $quantity = bcsub((string) $row->quantity_in, (string) $row->quantity_out, 8);
                $cost = $costs->get($row->id);
                $signedCost = $quantity[0] === '-' ? bcmul((string) ($cost ?? '0'), '-1', 8) : (string) ($cost ?? '0');
                $closingQuantity = bcadd($closingQuantity, $quantity, 8);
                if ($row->transaction_date->toDateString() < $window['from_date']) {
                    $openingQuantity = bcadd($openingQuantity, $quantity, 8);
                    $openingValue = bcadd($openingValue, $signedCost, 8);
                    $openingUnknown = bcadd($openingUnknown, $cost === null ? $quantity : '0', 8);
                    $openingIds[] = (int) $row->id;

                    continue;
                }
                $receiptReversal = $row->is_reversal && bccomp((string) ($byId->get($row->reversal_of_id)?->quantity_in ?? '0'), '0', 8) > 0;
                if (bccomp((string) $row->quantity_in, '0', 8) > 0 || $receiptReversal) {
                    if ($cost === null) {
                        throw new DomainException(__('inventory_periodic_cost.errors.unpriced'));
                    }
                    $receiptQuantity = bcadd($receiptQuantity, $quantity, 8);
                    $receiptValue = bcadd($receiptValue, $signedCost, 8);
                    $receiptIds[] = (int) $row->id;
                } elseif (! $row->is_reversal && bccomp((string) $row->quantity_out, '0', 8) > 0) {
                    if ($row->cost_method !== InventoryCostPolicy::PeriodicWeightedAverage
                        || (int) $row->cost_policy_id !== (int) $window['policy_ids'][$row->branch_store_id]) {
                        throw new DomainException(__('inventory_periodic_cost.errors.policy'));
                    }
                    $outflows->push($row);
                }
            }
            if (bccomp($openingUnknown, '0', 8) !== 0 || bccomp($openingQuantity, '0', 8) < 0
                || bccomp($openingValue, '0', 8) < 0 || bccomp($closingQuantity, '0', 8) < 0) {
                throw new DomainException(__('inventory_periodic_cost.errors.unpriced'));
            }
            $availableQuantity = bcadd($openingQuantity, $receiptQuantity, 8);
            $availableValue = bcadd($openingValue, $receiptValue, 8);
            if (bccomp($availableQuantity, '0', 8) <= 0) {
                if ($outflows->isNotEmpty() || bccomp($availableValue, '0', 8) !== 0) {
                    throw new DomainException(__('inventory_periodic_cost.errors.unpriced'));
                }

                continue;
            }
            $average = bcadd(bcdiv($availableValue, $availableQuantity, 16), '0.000000005', 8);
            $allocated = '0.00000000';
            $targetRows = [];
            foreach ($outflows->values() as $index => $row) {
                $target = bcmul($average, (string) $row->quantity_out, 8);
                if ($index === $outflows->count() - 1 && bccomp($closingQuantity, '0', 8) === 0) {
                    $target = bcsub($availableValue, $allocated, 8);
                }
                if (bccomp($target, '0', 8) < 0) {
                    throw new DomainException(__('inventory_periodic_cost.errors.unpriced'));
                }
                $targets[$row->id] = $target;
                $allocated = bcadd($allocated, $target, 8);
                $targetRows[] = ['transaction_id' => (int) $row->id, 'document' => $row->source_doc_num,
                    'quantity' => (string) $row->quantity_out, 'provisional_cost' => $provisionalCosts->get($row->id),
                    'final_cost' => $target, 'difference' => bcsub($target, (string) ($provisionalCosts->get($row->id) ?? '0'), 8)];
            }
            $first = $rows->first();
            $inputs[] = ['position' => $key, 'store_id' => (int) $first->branch_store_id, 'product_id' => (int) $first->product_id,
                'product_label' => $first->product?->name, 'product_code' => $first->product?->doc_num,
                'stock_status' => $first->stock_status, 'policy_id' => $window['policy_ids'][$first->branch_store_id],
                'opening_quantity' => $openingQuantity, 'opening_value' => $openingValue,
                'receipt_quantity' => $receiptQuantity, 'receipt_value' => $receiptValue,
                'available_quantity' => $availableQuantity, 'available_value' => $availableValue,
                'average' => $average, 'closing_quantity' => $closingQuantity,
                'opening_transaction_ids' => $openingIds, 'receipt_transaction_ids' => $receiptIds, 'outflows' => $targetRows];
        }
        ksort($targets);

        return ['targets' => $targets, 'inputs' => $inputs];
    }
}
