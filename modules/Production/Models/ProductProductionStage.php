<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Product;

class ProductProductionStage extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => ProductionStage::StatusActive];

    protected static function booted(): void
    {
        static::creating(fn (self $stage) => $stage->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'standard_duration_value' => 'decimal:4',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'production_stage_id')->withTrashed();
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }
}
