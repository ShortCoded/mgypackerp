<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Production\Models\ProductionMold;
use Modules\Purchases\Models\Supplier;

class MaintenancePlan extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusInactive = 'inactive';

    public const FrequencyCalendar = 'calendar';

    public const FrequencyOperatingHours = 'operating_hours';

    public const FrequencyCycles = 'cycles';

    public const FrequencyCondition = 'condition';

    public const AnchorPlanned = 'planned';

    public const AnchorActual = 'actual';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::StatusDraft,
        'maintenance_type' => 'preventive',
        'service_mode' => 'internal',
        'schedule_anchor' => self::AnchorPlanned,
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $plan) => $plan->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'interval_value' => 'decimal:4',
            'next_due_at' => 'datetime',
            'next_meter_value' => 'decimal:4',
            'expected_duration_minutes' => 'integer',
            'estimated_cost' => 'decimal:4',
            'approved_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $context = app(OperatingContextService::class)->snapshot(request());

        return $context['company_id'] && $context['branch_id']
            ? $this->newQuery()
                ->forContext((int) $context['company_id'], (int) $context['branch_id'])
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first()
            : null;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(ProductionMold::class, 'production_mold_id')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function dues(): HasMany
    {
        return $this->hasMany(MaintenancePlanDue::class)->latest('due_at');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MaintenanceMeterReading::class)->latest('recorded_at');
    }

    public function scopeForContext(Builder $query, int $companyId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}
