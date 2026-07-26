<?php

namespace Modules\HR\Models;

class HrContractType extends HrFoundationModel
{
    protected $table = 'hr_contract_types';

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
