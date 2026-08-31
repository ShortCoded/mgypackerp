<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\CostCenter;

class HrDepartment extends HrFoundationModel
{
    protected $table = 'hr_departments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'code',
        'name',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    public function costCenterDefaults(): HasMany
    {
        return $this->hasMany(HrDepartmentCostCenterDefault::class, 'department_id');
    }

    public function defaultCostCenterForCompany(int $companyId): ?CostCenter
    {
        return $this->costCenterDefaults()
            ->where('company_id', $companyId)
            ->with('costCenter')
            ->first()
            ?->costCenter;
    }
}
