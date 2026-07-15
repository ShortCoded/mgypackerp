<?php

namespace Modules\Accounting\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;

class JournalEntry extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusPosted = 'posted';

    public const StatusCancelled = 'cancelled';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'entry_date',
        'company_id',
        'financial_period_id',
        'currency_id',
        'exchange_rate',
        'description',
        'notes',
        'source_type',
        'source_id',
        'source_doc_num',
        'status',
        'is_system_generated',
        'is_posted',
        'posted_at',
        'posted_by',
        'approved',
        'approved_at',
        'approved_by',
        'reversed_entry_id',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
        'is_system_generated' => false,
        'is_posted' => false,
        'approved' => false,
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'is_system_generated' => 'boolean',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
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

    public function assertManuallyEditable(): void
    {
        if ($this->is_system_generated || $this->source_type !== null || $this->source_id !== null) {
            throw new DomainException(__('opening_balances.messages.system_journal_entry_locked'));
        }
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_no');
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

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }
}
