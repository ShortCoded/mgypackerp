<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;

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
        $context = app(OperatingContextService::class)->snapshot(request());

        if (! $context['company_id'] || ! $context['branch_id'] || ! Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeFactory)
            ->exists()) {
            return null;
        }

        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('company_id', $context['company_id'])
            ->visibleInBranch($context['branch_id'])
            ->first();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function productStages(): HasMany
    {
        return $this->hasMany(ProductProductionStage::class)->orderBy('sequence');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }

    public function scopeVisibleInBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(fn (Builder $branchQuery): Builder => $branchQuery
            ->whereNull($this->qualifyColumn('branch_id'))
            ->orWhere($this->qualifyColumn('branch_id'), $branchId));
    }
}
