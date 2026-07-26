<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOrgUnit extends HrFoundationModel
{
    protected $table = 'hr_org_units';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'parent_id',
        'org_unit_type_id',
        'company_id',
        'branch_id',
        'code',
        'name',
        'headcount_budget',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'headcount_budget' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HrOrgUnitType, $this>
     */
    public function orgUnitType(): BelongsTo
    {
        return $this->belongsTo(HrOrgUnitType::class, 'org_unit_type_id');
    }

    /**
     * @return BelongsTo<HrOrgUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(HrOrgUnit::class, 'parent_id');
    }
}
