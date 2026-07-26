<?php

namespace Modules\HR\Models;

class HrWorkLocation extends HrFoundationModel
{
    protected $table = 'hr_work_locations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
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
