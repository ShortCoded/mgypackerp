<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\Supplier;

class MaintenanceWorkOrder extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusInProgress = 'in_progress';

    public const StatusCompleted = 'completed';

    public const StatusClosed = 'closed';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft, 'service_mode' => 'internal', 'priority' => 'normal'];

    protected static function booted(): void
    {
        static::creating(fn (self $order) => $order->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'external_cost' => 'decimal:4',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'approved_at' => 'datetime',
            'next_due_date' => 'date',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class)->withTrashed();
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(ProductionMold::class, 'production_mold_id')->withTrashed();
    }

    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaintenanceMaterialRequest::class)->latest('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProductionExpenseRequest::class)->latest('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}
