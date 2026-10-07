<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionStageTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:8', 'total_cost' => 'decimal:8', 'booked_amount' => 'decimal:4',
            'source_snapshot' => 'array', 'posting_snapshot' => 'array', 'posting_date' => 'date',
            'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'source_run_id');
    }

    public function targetRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'target_run_id');
    }
}
