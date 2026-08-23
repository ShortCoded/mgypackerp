<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class HrEmploymentTaxPolicy extends HrCompanyFoundationModel
{
    protected $table = 'hr_employment_tax_policies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'name',
        'tax_year',
        'effective_from',
        'effective_to',
        'annual_exemption_amount',
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
        'annual_exemption_amount' => '0.00',
        'rounding_rule' => 'nearest',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'tax_year' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'annual_exemption_amount' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<HrEmploymentTaxBracket, $this>
     */
    public function brackets(): HasMany
    {
        return $this->hasMany(HrEmploymentTaxBracket::class, 'employment_tax_policy_id')->orderBy('sort_order');
    }
}
