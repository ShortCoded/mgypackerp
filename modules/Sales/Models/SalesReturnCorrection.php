<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'execution_snapshot' => 'array', 'replacement_payload' => 'array', 'posting_date' => 'date',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            $allowed = ['status', 'approved_by', 'approved_at', 'approval_reason', 'approval_fingerprint',
                'replacement_return_id', 'execution_snapshot', 'execution_fingerprint', 'rejected_at', 'updated_at'];
            if (array_diff(array_keys($proposal->getDirty()), $allowed) !== []
                || ! in_array([$proposal->getOriginal('status'), $proposal->status],
                    [['prepared', 'applying'], ['applying', 'approved'], ['prepared', 'rejected']], true)) {
                throw new \DomainException(__('sales_return_plan.stale'));
            }
        });
        static::deleting(fn () => throw new \DomainException(__('sales_return_plan.stale')));
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function replacementReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'replacement_return_id');
    }
}
