<?php

namespace Modules\HR\Models;

class HrShift extends HrFoundationModel
{
    protected $table = 'hr_shifts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doc_number',
        'doc_num',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'crosses_midnight',
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
            'break_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
        ];
    }
}
