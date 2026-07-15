<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationPaymentMilestone extends Model
{
    public const DueOnContract = 'on_contract';

    public const DueBeforeDelivery = 'before_delivery';

    public const DueAfterDelivery = 'after_delivery';

    public const DueAfterInstallation = 'after_installation';

    public const DueCustomDate = 'custom_date';

    public const DueTypes = [
        self::DueOnContract,
        self::DueBeforeDelivery,
        self::DueAfterDelivery,
        self::DueAfterInstallation,
        self::DueCustomDate,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quotation_revision_id',
        'line_number',
        'title',
        'description',
        'percentage',
        'amount',
        'due_type',
        'due_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'amount' => 'decimal:4',
            'due_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'quotation_revision_id');
    }
}
