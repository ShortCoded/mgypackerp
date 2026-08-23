<?php

namespace Modules\FixedAssets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingCompanyContextService;

class FixedAssetDepreciationRun extends Model
{
    public const StatusPosted = 'posted';

    public const StatusReversed = 'reversed';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'financial_period_id',
        'branch_id',
        'period_start',
        'period_end',
        'posting_date',
        'filters',
        'total_depreciation',
        'base_total_depreciation',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::StatusPosted,
        'total_depreciation' => '0',
        'base_total_depreciation' => '0',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'posting_date' => 'date',
            'filters' => 'array',
            'total_depreciation' => 'decimal:4',
            'base_total_depreciation' => 'decimal:4',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId ? $this->newQuery()->where('company_id', $companyId)->where($field ?? $this->getRouteKeyName(), $value)->first() : null;
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
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withTrashed();
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class, 'depreciation_run_id')->orderBy('id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
