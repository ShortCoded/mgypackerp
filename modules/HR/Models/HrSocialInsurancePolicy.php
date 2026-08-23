<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSocialInsurancePolicy extends HrCompanyFoundationModel
{
    protected $table = 'hr_social_insurance_policies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'name',
        'effective_from',
        'effective_to',
        'employee_contribution_rate',
        'employer_contribution_rate',
        'minimum_contribution_wage',
        'maximum_contribution_wage',
        'rounding_rule',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'employee_contribution_rate' => '0.0000',
        'employer_contribution_rate' => '0.0000',
        'rounding_rule' => 'nearest',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'effective_from' => 'date',
            'effective_to' => 'date',
            'employee_contribution_rate' => 'decimal:4',
            'employer_contribution_rate' => 'decimal:4',
            'minimum_contribution_wage' => 'decimal:2',
            'maximum_contribution_wage' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<HrSocialInsurancePolicyComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(HrSocialInsurancePolicyComponent::class, 'social_insurance_policy_id')->orderBy('sort_order');
    }
}
