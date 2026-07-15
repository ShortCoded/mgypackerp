<?php

namespace Modules\HR\Models;

class HrGrade extends HrFoundationModel
{
    protected $table = 'hr_grades';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'code',
        'name',
        'rank',
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
            'rank' => 'integer',
        ];
    }
}
