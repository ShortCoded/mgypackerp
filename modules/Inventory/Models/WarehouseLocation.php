<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\OperatingCompanyContextService;

class WarehouseLocation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'branch_store_id', 'code', 'name', 'zone_code', 'position', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $attributes = ['position' => 0, 'is_active' => true];

    protected static function booted(): void
    {
        static::creating(fn (self $location) => $location->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->whereHas('branchStore.branch', fn ($query) => $query->where('company_id', $companyId))
                ->first();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }
}
