<?php

namespace Modules\HR\Models;

class HrJob extends HrFoundationModel
{
    protected $table = 'hr_jobs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'code',
        'name',
        'description',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];
}
