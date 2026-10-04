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

    public const TypeValueAdjustment = 'value_adjustment';

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

    public function serialIdentity(): BelongsTo
    {
        return $this->belongsTo(InventorySerialIdentity::class, 'inventory_serial_identity_id');
    }

    /** @return list<string> */
    public function serialNumbers(): array
    {
        if ($this->inventory_serial_identity_id !== null) {
            return $this->serialIdentity === null ? [] : [$this->serialIdentity->serial_number];
        }

        return $this->serial_numbers ?? [];
    }

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'manufacture_date' => 'date', 'expiry_date' => 'date',
            'quantity_in' => 'decimal:8', 'quantity_out' => 'decimal:8',
            'unit_cost' => 'decimal:8', 'total_cost' => 'decimal:8', 'is_reversal' => 'boolean',
            'value_delta' => 'decimal:8', 'unvalued_quantity_delta' => 'decimal:8',
            'serial_numbers' => 'array',
        ];
    }

    public static function signedValueSql(string $prefix = ''): string
    {
        return "(case when {$prefix}unit_cost is not null and {$prefix}total_cost is not null then case when {$prefix}quantity_in > 0 then {$prefix}total_cost else -{$prefix}total_cost end else 0 end + coalesce({$prefix}value_delta, 0))";
    }

    public static function unvaluedQuantitySql(string $prefix = ''): string
    {
        return "(case when {$prefix}unit_cost is null or {$prefix}total_cost is null then {$prefix}quantity_in - {$prefix}quantity_out else 0 end + coalesce({$prefix}unvalued_quantity_delta, 0))";
    }

    public function signedValue(): string
    {
        $original = $this->unit_cost === null || $this->total_cost === null ? '0' : (string) $this->total_cost;
        if (bccomp((string) $this->quantity_in, '0', 8) <= 0) {
            $original = bcmul($original, '-1', 8);
        }

        return bcadd($original, (string) ($this->value_delta ?? '0'), 8);
    }

    public function completedTotalCost(): ?string
    {
        $corrections = InventoryValueAdjustmentLine::query()->where('source_transaction_id', $this->id)
            ->where('effect', InventoryValueAdjustmentLine::EffectStock)
            ->whereHas('adjustment', fn ($query) => $query->where('status', InventoryValueAdjustment::StatusPosted))->get();
        if ($this->total_cost === null && $corrections->isEmpty()) {
            return null;
        }
        $delta = $corrections->reduce(fn (string $sum, InventoryValueAdjustmentLine $line): string => bcadd($sum, (string) $line->amount, 8), '0');
        $sign = bccomp((string) $this->quantity_in, '0', 8) > 0 ? '1' : '-1';

        return bcadd((string) ($this->total_cost ?? '0'), bcmul($delta, $sign, 8), 8);
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
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class)->withTrashed();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }
}
