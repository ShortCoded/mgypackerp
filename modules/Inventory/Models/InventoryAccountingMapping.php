<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Company;

class InventoryAccountingMapping extends Model
{
    public const ValuationMovingAverage = 'moving_average';

    protected $fillable = [
        'company_id',
        'valuation_method',
        'raw_material_inventory_account_id',
        'packaging_inventory_account_id',
        'semi_finished_inventory_account_id',
        'finished_goods_inventory_account_id',
        'wip_account_id',
        'production_waste_account_id',
        'recoverable_scrap_inventory_account_id',
        'warehouse_damage_loss_account_id',
        'inventory_adjustment_gain_account_id',
        'inventory_adjustment_loss_account_id',
        'production_variance_account_id',
        'production_cost_center_id',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'valuation_method' => self::ValuationMovingAverage,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rawMaterialInventoryAccount(): BelongsTo
    {
        return $this->account('raw_material_inventory_account_id');
    }

    public function packagingInventoryAccount(): BelongsTo
    {
        return $this->account('packaging_inventory_account_id');
    }

    public function semiFinishedInventoryAccount(): BelongsTo
    {
        return $this->account('semi_finished_inventory_account_id');
    }

    public function finishedGoodsInventoryAccount(): BelongsTo
    {
        return $this->account('finished_goods_inventory_account_id');
    }

    public function wipAccount(): BelongsTo
    {
        return $this->account('wip_account_id');
    }

    public function productionWasteAccount(): BelongsTo
    {
        return $this->account('production_waste_account_id');
    }

    public function recoverableScrapInventoryAccount(): BelongsTo
    {
        return $this->account('recoverable_scrap_inventory_account_id');
    }

    public function warehouseDamageLossAccount(): BelongsTo
    {
        return $this->account('warehouse_damage_loss_account_id');
    }

    public function inventoryAdjustmentGainAccount(): BelongsTo
    {
        return $this->account('inventory_adjustment_gain_account_id');
    }

    public function inventoryAdjustmentLossAccount(): BelongsTo
    {
        return $this->account('inventory_adjustment_loss_account_id');
    }

    public function productionVarianceAccount(): BelongsTo
    {
        return $this->account('production_variance_account_id');
    }

    public function productionCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'production_cost_center_id')->withTrashed();
    }

    private function account(string $foreignKey): BelongsTo
    {
        return $this->belongsTo(Account::class, $foreignKey)->withTrashed();
    }
}
