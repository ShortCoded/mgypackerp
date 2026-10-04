<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditApplicationEvidence extends Model
{
    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    protected $table = 'customer_credit_application_evidences';

    protected static function booted(): void
    {
        static::updating(function (self $evidence): void {
            $allowed = ['status', 'approved_by', 'approved_at', 'approval_reason', 'approval_fingerprint', 'updated_at'];
            if ($evidence->getOriginal('status') !== self::StatusPending || $evidence->status !== self::StatusApproved
                || array_diff(array_keys($evidence->getDirty()), $allowed) !== []) {
                throw new \DomainException(__('credit_application_evidence.stale'));
            }
        });
        static::deleting(function (): void {
            throw new \DomainException(__('credit_application_evidence.stale'));
        });
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'application_snapshot' => 'array', 'approved_at' => 'datetime'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'credit_note_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }
}
