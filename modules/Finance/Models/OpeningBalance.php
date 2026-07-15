<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;

class OpeningBalance extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusCancelled = 'cancelled';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'document_date',
        'company_id',
        'financial_period_id',
        'currency_id',
        'exchange_rate',
        'description',
        'notes',
        'is_cancelled',
        'is_closed',
        'approved',
        'approved_at',
        'approved_by',
        'journal_entry_id',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'exchange_rate' => 1,
        'is_cancelled' => false,
        'is_closed' => false,
        'approved' => false,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'is_cancelled' => 'boolean',
            'is_closed' => 'boolean',
            'approved' => 'boolean',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function isLockedForEditing(): bool
    {
        return $this->isApproved() || $this->isClosed() || $this->is_cancelled || $this->journal_entry_id !== null;
    }

    public function isClosed(): bool
    {
        return (bool) $this->is_closed;
    }

    public function isApproved(): bool
    {
        return (bool) $this->approved || $this->status === self::StatusApproved;
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class)->orderBy('line_no');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', '!=', self::StatusCancelled);
    }
}
