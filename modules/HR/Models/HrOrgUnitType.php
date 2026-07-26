<?php

namespace Modules\HR\Models;

class HrOrgUnitType extends HrFoundationModel
{
    protected $table = 'hr_org_unit_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'code',
        'name',
        'category',
        'sort_order',
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
            'sort_order' => 'integer',
        ];
    }
}
