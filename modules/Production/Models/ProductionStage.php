<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Services\OperatingCompanyContextService;

class ProductionStage extends Model
{
    use SoftDeletes;

    public const StatusActive = 'active';

    public const StatusInactive = 'inactive';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusActive, 'display_order' => 1];

    protected static function booted(): void
    {
        static::creating(fn (self $stage) => $stage->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'standard_duration_value' => 'decimal:4',
            'display_order' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('company_id', $companyId)
            ->first();
    }

    public function productStages(): HasMany
    {
        return $this->hasMany(ProductProductionStage::class)->orderBy('sequence');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }
}
