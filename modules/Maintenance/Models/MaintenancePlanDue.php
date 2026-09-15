<?php

namespace Modules\Maintenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Core\Services\OperatingContextService;

class MaintenancePlanDue extends Model
{
    public const StatusOpen = 'open';

    public const StatusConverted = 'converted';

    public const StatusCompleted = 'completed';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusOpen];

    protected static function booted(): void
    {
        static::creating(fn (self $due) => $due->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'meter_target' => 'decimal:4',
            'generated_at' => 'datetime',
            'converted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $context = app(OperatingContextService::class)->snapshot(request());

        return $context['company_id'] && $context['financial_period_id'] && $context['branch_id']
            ? $this->newQuery()
                ->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id'])
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first()
            : null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(MaintenanceWorkOrder::class, 'maintenance_plan_due_id');
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}
