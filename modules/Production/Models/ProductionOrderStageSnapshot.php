<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductionOrderStageSnapshot extends Model
{
    public const StatusPending = 'pending';

    public const StatusInProgress = 'in_progress';

    public const StatusCompleted = 'completed';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusPending, 'is_required' => true];

    protected static function booted(): void
    {
        static::creating(fn (self $stage) => $stage->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'standard_duration_value' => 'decimal:4',
            'is_required' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderLine::class, 'production_order_line_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'production_stage_id')->withTrashed();
    }

    public function productStage(): BelongsTo
    {
        return $this->belongsTo(ProductProductionStage::class, 'product_production_stage_id')->withTrashed();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProductionRun::class, 'production_order_stage_snapshot_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionOrderStageEvent::class, 'production_order_stage_snapshot_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }
}
