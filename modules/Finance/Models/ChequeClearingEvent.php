<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;

class ChequeClearingEvent extends Model
{
    public const StatusCleared = 'cleared';

    public const StatusReversed = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['clearing_date' => 'date', 'cleared_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function clearingJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'clearing_journal_entry_id');
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }
}
