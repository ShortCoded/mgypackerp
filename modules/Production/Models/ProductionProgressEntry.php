<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductionProgressEntry extends Model
{
    protected $fillable = [
        'public_id', 'production_run_id', 'recorded_at', 'good_base_quantity',
        'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity',
        'good_weight_kg', 'production_scrap_weight_kg',
        'notes', 'recorded_by', 'production_run_correction_id',
        'material_evidence', 'material_documents', 'production_shift_entry_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime', 'good_base_quantity' => 'decimal:8',
            'rejected_base_quantity' => 'decimal:8', 'rework_base_quantity' => 'decimal:8',
            'scrap_base_quantity' => 'decimal:8',
            'good_weight_kg' => 'decimal:8', 'production_scrap_weight_kg' => 'decimal:8',
            'material_evidence' => 'array', 'material_documents' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }
}
