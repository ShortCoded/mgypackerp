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
        'notes', 'recorded_by',
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
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }
}
