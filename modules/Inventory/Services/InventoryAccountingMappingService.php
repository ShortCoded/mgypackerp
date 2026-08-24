<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryAccountingMapping;

class InventoryAccountingMappingService
{
    /** @param array<string, mixed> $data */
    public function save(int $companyId, array $data): InventoryAccountingMapping
    {
        $accountFields = $this->accountFields();
        $attributes = [
            'valuation_method' => InventoryAccountingMapping::ValuationMovingAverage,
            'updated_by' => auth()->id(),
        ];

        foreach ($accountFields as $input => $column) {
            $docNum = trim((string) ($data[$input] ?? ''));
            $attributes[$column] = $docNum === '' ? null : $this->accountId($companyId, $docNum);
        }

        $costCenterDocNum = trim((string) ($data['production_cost_center_doc_num'] ?? ''));
        $attributes['production_cost_center_id'] = $costCenterDocNum === ''
            ? null
            : (int) CostCenter::query()
                ->forCompany($companyId)
                ->active()
                ->where('is_group', false)
                ->where('doc_num', $costCenterDocNum)
                ->valueOrFail('id');

        $mapping = InventoryAccountingMapping::query()->firstOrNew(['company_id' => $companyId]);

        if (! $mapping->exists) {
            $mapping->created_by = auth()->id();
        }

        $mapping->fill($attributes)->save();

        return $mapping->refresh();
    }

    public function forCompany(int $companyId): ?InventoryAccountingMapping
    {
        return InventoryAccountingMapping::query()
            ->where('company_id', $companyId)
            ->with($this->relationshipNames())
            ->first();
    }

    public function requireForCompany(int $companyId): InventoryAccountingMapping
    {
        return $this->forCompany($companyId)
            ?? throw new DomainException(__('inventory_accounting.errors.configuration_required'));
    }

    public function inventoryAccount(InventoryAccountingMapping $mapping, Product $product, string $event): Account
    {
        $relationship = match ($product->item_classification) {
            Product::ClassificationRawMaterial => 'rawMaterialInventoryAccount',
            Product::ClassificationPackaging, Product::ClassificationOther => 'packagingInventoryAccount',
            Product::ClassificationSemiFinished => 'semiFinishedInventoryAccount',
            Product::ClassificationFinishedProduct => 'finishedGoodsInventoryAccount',
            default => throw new DomainException(__('inventory_accounting.errors.non_inventory_product', ['event' => $event])),
        };

        return $this->requirePostableAccount($mapping, $relationship, $event);
    }

    public function requirePostableAccount(
        InventoryAccountingMapping $mapping,
        string $relationship,
        string $event,
    ): Account {
        $account = $mapping->getRelationValue($relationship);

        if (! $account instanceof Account
            || (int) $account->company_id !== (int) $mapping->company_id
            || $account->status !== 'active'
            || ! $account->is_postable
            || $account->is_group
            || $account->trashed()) {
            throw new DomainException(__('inventory_accounting.errors.account_unavailable', [
                'event' => $event,
                'account' => __('inventory_accounting.fields.'.$relationship),
            ]));
        }

        return $account;
    }

    public function productionCostCenterId(InventoryAccountingMapping $mapping, string $event): ?int
    {
        if ($mapping->production_cost_center_id === null) {
            return null;
        }

        $costCenter = $mapping->productionCostCenter;

        if (! $costCenter instanceof CostCenter
            || (int) $costCenter->company_id !== (int) $mapping->company_id
            || $costCenter->status !== 'active'
            || $costCenter->is_group
            || $costCenter->trashed()) {
            throw new DomainException(__('inventory_accounting.errors.cost_center_unavailable', ['event' => $event]));
        }

        return (int) $costCenter->getKey();
    }

    /** @return array<string, string> */
    private function accountFields(): array
    {
        return [
            'raw_material_inventory_account_doc_num' => 'raw_material_inventory_account_id',
            'packaging_inventory_account_doc_num' => 'packaging_inventory_account_id',
            'semi_finished_inventory_account_doc_num' => 'semi_finished_inventory_account_id',
            'finished_goods_inventory_account_doc_num' => 'finished_goods_inventory_account_id',
            'wip_account_doc_num' => 'wip_account_id',
            'production_waste_account_doc_num' => 'production_waste_account_id',
            'recoverable_scrap_inventory_account_doc_num' => 'recoverable_scrap_inventory_account_id',
            'warehouse_damage_loss_account_doc_num' => 'warehouse_damage_loss_account_id',
            'inventory_adjustment_gain_account_doc_num' => 'inventory_adjustment_gain_account_id',
            'inventory_adjustment_loss_account_doc_num' => 'inventory_adjustment_loss_account_id',
            'production_variance_account_doc_num' => 'production_variance_account_id',
        ];
    }

    /** @return list<string> */
    private function relationshipNames(): array
    {
        return [
            'rawMaterialInventoryAccount',
            'packagingInventoryAccount',
            'semiFinishedInventoryAccount',
            'finishedGoodsInventoryAccount',
            'wipAccount',
            'productionWasteAccount',
            'recoverableScrapInventoryAccount',
            'warehouseDamageLossAccount',
            'inventoryAdjustmentGainAccount',
            'inventoryAdjustmentLossAccount',
            'productionVarianceAccount',
            'productionCostCenter',
        ];
    }

    private function accountId(int $companyId, string $docNum): int
    {
        return (int) Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
    }
}
