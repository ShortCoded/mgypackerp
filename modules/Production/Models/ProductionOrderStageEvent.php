<?php

namespace Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductionOrderStageEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function stageSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderStageSnapshot::class, 'production_order_stage_snapshot_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
