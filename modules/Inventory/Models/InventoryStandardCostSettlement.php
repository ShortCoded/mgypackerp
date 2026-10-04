<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Production\Models\ProductionRun;

class InventoryStandardCostSettlement extends Model
{
    public const StatusPrepared = 'prepared';

    public const StatusFinalized = 'finalized';

    public const StatusRejected = 'rejected';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'approved_at' => 'datetime', 'impact_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            if ($settlement->getOriginal('impact_sha256') !== null
                && ($settlement->getOriginal('status') !== self::StatusPrepared
                    || $settlement->isDirty(array_diff(array_keys($settlement->getAttributes()), ['status', 'approved_by', 'approved_at', 'approval_reference', 'rejection_reason', 'updated_at'])))) {
                throw new DomainException(__('inventory_standard_cost.errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory_standard_cost.errors.immutable')));
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(InventoryCostStandard::class, 'inventory_cost_standard_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id')->withTrashed();
    }

    public function valueAdjustment(): HasOne
    {
        return $this->hasOne(InventoryValueAdjustment::class, 'source_id')->where('source_type', self::class);
    }
}
