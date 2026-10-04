<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;

class InventoryPeriodicCostClose extends Model
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
        return ['from_date' => 'date', 'to_date' => 'date', 'posting_date' => 'date', 'scope_snapshot' => 'array',
            'impact_snapshot' => 'array', 'prepared_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $close): void {
            if ($close->getOriginal('impact_sha256') !== null && ($close->isDirty(['public_uuid', 'company_id', 'branch_id',
                'scope_branch_id', 'scope_store_id', 'financial_period_id', 'posting_period_id', 'counterpart_account_id',
                'doc_num', 'from_date', 'to_date', 'posting_date', 'reason', 'scope_snapshot', 'impact_snapshot', 'impact_sha256',
                'prepared_by', 'prepared_at']) || $close->getOriginal('status') !== self::StatusPrepared)) {
                throw new DomainException(__('inventory_periodic_cost.errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory_periodic_cost.errors.immutable')));
    }

    public function scopeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'scope_branch_id')->withTrashed();
    }

    public function scopeStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class, 'scope_store_id')->withTrashed();
    }

    public function valueAdjustment(): HasOne
    {
        return $this->hasOne(InventoryValueAdjustment::class, 'source_id')->where('source_type', self::class);
    }
}
