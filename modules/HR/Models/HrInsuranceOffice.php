<?php

namespace Modules\HR\Models;

class HrInsuranceOffice extends HrFoundationModel
{
    protected $table = 'hr_insurance_offices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'insurance_office_code',
        'address',
        'phone',
        'email',
        'contact_person',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];
}
