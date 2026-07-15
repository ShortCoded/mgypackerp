<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationExecutionScheduleLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'quotation_revision_id',
        'line_number',
        'phase_name',
        'description',
        'start_date',
        'end_date',
        'duration_days',
        'responsibility',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'duration_days' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'quotation_revision_id');
    }
}
