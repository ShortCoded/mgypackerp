<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HrSocialInsurancePolicyComponent extends Model
{
    protected $table = 'hr_social_insurance_policy_components';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_uuid',
        'social_insurance_policy_id',
        'name',
        'employee_rate',
        'employer_rate',
        'calculation_basis',
        'is_active',
        'notes',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employee_rate' => '0.0000',
        'employer_rate' => '0.0000',
        'calculation_basis' => 'contribution_wage',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $component): void {
            $component->public_uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_rate' => 'decimal:4',
            'employer_rate' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HrSocialInsurancePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(HrSocialInsurancePolicy::class, 'social_insurance_policy_id');
    }
}
