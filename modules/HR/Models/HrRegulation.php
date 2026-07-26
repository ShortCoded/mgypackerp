<?php

namespace Modules\HR\Models;

class HrRegulation extends HrFoundationModel
{
    protected $table = 'hr_regulations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'code',
        'description',
        'effective_from',
        'effective_to',
        'applies_to',
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
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
