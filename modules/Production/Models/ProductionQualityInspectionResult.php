<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionQualityInspectionResult extends Model
{
    protected $table = 'quality_inspection_results';

    protected $fillable = [
        'quality_inspection_id', 'quality_checkpoint_id', 'sequence', 'result',
        'measured_value', 'notes', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'quality_inspection_id');
    }
}
