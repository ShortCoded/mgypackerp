<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductionShift extends Model
{
    protected $fillable = [
        'public_id', 'company_id', 'branch_id', 'code', 'name', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::creating(fn (self $shift) => $shift->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
