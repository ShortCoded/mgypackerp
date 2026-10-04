<?php

namespace Modules\Inventory\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;

class InventoryValueAdjustment extends Model
{
    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            if ($adjustment->isDirty(['company_id', 'financial_period_id', 'branch_id', 'source_type', 'source_id',
                'source_doc_num', 'posting_date', 'source_snapshot', 'approved_by', 'approved_at', 'status'])
                || ($adjustment->getOriginal('journal_entry_id') !== null && $adjustment->isDirty('journal_entry_id'))) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
    }

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'reversal_date' => 'date', 'source_snapshot' => 'array',
            'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryValueAdjustmentLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
