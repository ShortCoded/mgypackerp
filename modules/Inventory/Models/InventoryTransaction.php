<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Production\Models\ProductionRun;

class InventoryTransaction extends Model
{
    public const TypePositionReconciliation = 'position_reconciliation';

    public const StatusAvailable = 'available';

    public const StatusReserved = 'reserved';

    public const StatusQcHold = 'qc_hold';

    public const StatusQuarantine = 'quarantine';

    public const StatusRework = 'rework';

    public const StatusProductionStaging = 'production_staging';

    public const StatusWip = 'wip';

    public const StatusRejected = 'rejected';

    public const StatusDamaged = 'damaged';

    public const StatusScrap = 'scrap';

    public const StatusInTransit = 'in_transit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'manufacture_date' => 'date', 'expiry_date' => 'date',
            'quantity_in' => 'decimal:8', 'quantity_out' => 'decimal:8',
            'unit_cost' => 'decimal:8', 'total_cost' => 'decimal:8', 'is_reversal' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function branchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }
}
