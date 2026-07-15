<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationRevision extends Model
{
    public const StatusDraft = 'draft';

    public const StatusSent = 'sent';

    public const StatusAccepted = 'accepted';

    public const StatusRejected = 'rejected';

    public const StatusCancelled = 'cancelled';

    public const StatusSuperseded = 'superseded';

    public const Statuses = [
        self::StatusDraft,
        self::StatusSent,
        self::StatusAccepted,
        self::StatusRejected,
        self::StatusCancelled,
        self::StatusSuperseded,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quotation_id',
        'revision_number',
        'revision_code',
        'revision_date',
        'status',
        'change_reason',
        'customer_feedback',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'total',
        'notes_snapshot',
        'terms_snapshot',
        'payment_terms_snapshot',
        'execution_terms_snapshot',
        'warranty_terms_snapshot',
        'technical_notes_snapshot',
        'delivery_terms_snapshot',
        'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::StatusDraft,
        'subtotal' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'revision_code';
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationRevisionLine::class)->orderBy('line_number');
    }

    public function paymentMilestones(): HasMany
    {
        return $this->hasMany(QuotationPaymentMilestone::class)->orderBy('line_number');
    }

    public function executionScheduleLines(): HasMany
    {
        return $this->hasMany(QuotationExecutionScheduleLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::StatusDraft;
    }
}
