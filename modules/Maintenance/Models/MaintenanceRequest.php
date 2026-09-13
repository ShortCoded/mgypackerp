<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Production\Models\ProductionRun;

class MaintenanceRequest extends Model
{
    use SoftDeletes;

    public const StatusOpen = 'open';

    public const StatusConverted = 'converted';

    public const StatusClosed = 'closed';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusOpen, 'request_type' => 'breakdown', 'priority' => 'normal'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'converted_at' => 'datetime',
            'closed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class)->withTrashed();
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(MaintenanceWorkOrder::class);
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}
