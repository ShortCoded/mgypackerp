<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MaintenanceMeterReading extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['reading_type' => 'reading', 'is_triggered' => false];

    protected static function booted(): void
    {
        static::creating(fn (self $reading) => $reading->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'reading_value' => 'decimal:4',
            'is_triggered' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }
}
