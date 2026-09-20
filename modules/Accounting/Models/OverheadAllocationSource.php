<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OverheadAllocationSource extends Model
{
    protected $table = 'cost_overhead_allocation_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_amount' => 'decimal:4'];
    }

    public function allocationRun(): BelongsTo
    {
        return $this->belongsTo(OverheadAllocationRun::class, 'allocation_run_id');
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
