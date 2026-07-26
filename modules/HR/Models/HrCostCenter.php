<?php

namespace Modules\HR\Models;

class HrCostCenter extends HrFoundationModel
{
    protected $table = 'hr_cost_centers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'branch_id',
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
}
