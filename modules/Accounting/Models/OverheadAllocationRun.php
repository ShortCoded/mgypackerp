<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class OverheadAllocationRun extends Model
{
    public const StatusDraft = 'draft';

    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    public const StatusSuperseded = 'superseded';

    protected $table = 'cost_overhead_allocation_runs';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft];

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'eligible_cost' => 'decimal:4',
            'allocatable_cost' => 'decimal:4',
            'allocated_cost' => 'decimal:4',
            'unallocated_cost' => 'decimal:4',
            'actual_capacity' => 'decimal:8',
            'utilization_percent' => 'decimal:4',
            'policy_snapshot' => 'array',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(OverheadAllocationRule::class, 'rule_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(OverheadAllocationSource::class, 'allocation_run_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OverheadAllocationLine::class, 'allocation_run_id');
    }

    public function unusedCapacityCost(): string
    {
        return (string) data_get($this->policy_snapshot, 'unused_capacity_cost', '0.0000');
    }

    public function unusedCapacityReason(): ?string
    {
        $reason = data_get($this->policy_snapshot, 'unused_capacity_reason');

        return filled($reason) ? (string) $reason : null;
    }
}
