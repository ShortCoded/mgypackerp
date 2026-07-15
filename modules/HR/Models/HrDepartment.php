<?php

namespace Modules\HR\Models;

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
}
