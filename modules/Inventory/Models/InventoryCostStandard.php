<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;

class InventoryCostStandard extends Model
{
    public const StatusPrepared = 'prepared';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime',
            'materials_unit_cost' => 'decimal:8', 'labor_unit_cost' => 'decimal:8', 'overhead_unit_cost' => 'decimal:8', 'basis_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $standard): void {
            if ($standard->getOriginal('status') !== self::StatusPrepared
                || $standard->isDirty(array_diff(array_keys($standard->getAttributes()), ['status', 'approved_by', 'approved_at', 'approval_reference', 'rejection_reason', 'updated_at']))) {
                throw new DomainException(__('inventory_standard_cost.errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory_standard_cost.errors.immutable')));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }
}
