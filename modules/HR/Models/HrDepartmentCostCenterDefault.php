<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\Company;

class HrDepartmentCostCenterDefault extends Model
{
    protected $table = 'hr_department_cost_center_defaults';

    /** @var list<string> */
    protected $fillable = ['company_id', 'department_id', 'cost_center_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }
}
