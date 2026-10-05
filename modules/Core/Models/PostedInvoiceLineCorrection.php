<?php

namespace Modules\Core\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostedInvoiceLineCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'replacement_input' => 'array', 'impact' => 'array',
            'execution_snapshot' => 'array', 'approved_at' => 'datetime', 'posting_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            if ($proposal->getOriginal('status') !== 'prepared'
                || ! in_array($proposal->status, ['approved', 'rejected'], true)
                || array_diff(array_keys($proposal->getDirty()), ['status', 'approved_by', 'approved_at',
                    'approval_reason', 'replacement_invoice_id', 'execution_snapshot', 'execution_fingerprint', 'updated_at']) !== []) {
                throw new DomainException(__('posted_invoice_correction.stale'));
            }
        });
        static::deleting(fn () => throw new DomainException(__('posted_invoice_correction.stale')));
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
