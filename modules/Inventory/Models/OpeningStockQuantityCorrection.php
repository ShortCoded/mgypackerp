<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockQuantityCorrection extends Model
{
    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    protected $table = 'inventory_opening_stock_quantity_corrections';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $correction): void {
            $originalStatus = $correction->getOriginal('status');
            $newStatus = $correction->status;
            $invalidPendingTransition = $originalStatus === self::StatusPending && (
                ($correction->isDirty('status') && ! in_array($newStatus, [self::StatusApproved, self::StatusRejected], true))
                || ($correction->isDirty('document_links') && $newStatus !== self::StatusApproved)
                || ($newStatus === self::StatusApproved && (
                    blank($correction->approval_reference) || $correction->approved_by === null
                    || $correction->approved_at === null || blank($correction->document_links)
                    || filled($correction->rejection_reason) || $correction->rejected_by !== null || $correction->rejected_at !== null
                ))
                || ($newStatus === self::StatusRejected && (
                    blank($correction->rejection_reason) || $correction->rejected_by === null
                    || $correction->rejected_at === null || filled($correction->document_links)
                    || filled($correction->approval_reference) || $correction->approved_by !== null || $correction->approved_at !== null
                ))
            );
            if ($invalidPendingTransition || $correction->isDirty([
                'public_uuid', 'company_id', 'opening_stock_id', 'financial_period_id', 'branch_id',
                'posting_period_id', 'posting_date', 'reason', 'source_reference', 'targets',
                'source_snapshot', 'plan', 'fingerprint', 'prepared_by',
            ]) || (in_array($originalStatus, [self::StatusApproved, self::StatusRejected], true)
                && $correction->isDirty([
                    'status', 'approval_reference', 'rejection_reason', 'document_links', 'approved_by',
                    'approved_at', 'rejected_by', 'rejected_at',
                ]))) {
                throw new DomainException(__('opening_stock_quantity_correction.errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('opening_stock_quantity_correction.errors.immutable')));
    }

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'targets' => 'array',
            'source_snapshot' => 'array',
            'plan' => 'array',
            'document_links' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
