<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionStageOutputCostOwner extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date', 'base_quantity' => 'decimal:8', 'total_cost' => 'decimal:8', 'booked_loss_amount' => 'decimal:4',
            'source_snapshot' => 'array', 'posting_snapshot' => 'array', 'approved_at' => 'datetime', 'reversed_at' => 'datetime',
            'execution_snapshot' => 'array', 'reversal_execution_snapshot' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function parentOwner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_owner_id');
    }
}
