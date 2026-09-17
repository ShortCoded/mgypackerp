<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;

class OverheadAllocationRule extends Model
{
    public const BasisMachineHours = 'machine_hours';

    public const BasisLaborHours = 'labor_hours';

    public const BasisDirectMaterialCost = 'direct_material_cost';

    public const BehaviorVariable = 'variable';

    public const BehaviorFixed = 'fixed';

    protected $table = 'cost_overhead_allocation_rules';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'cost_behavior' => self::BehaviorVariable];

    protected static function booted(): void
    {
        static::creating(fn (self $rule) => $rule->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'source_account_ids' => 'array',
            'target_cost_center_ids' => 'array',
            'normal_capacity_hours' => 'decimal:8',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'source_cost_center_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(OverheadAllocationRun::class, 'rule_id');
    }
}
