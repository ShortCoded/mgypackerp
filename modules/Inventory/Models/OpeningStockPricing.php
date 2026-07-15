<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;

class OpeningStockPricing extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusClosed = 'closed';

    protected $table = 'inventory_opening_stock_pricings';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'document_date',
        'company_id',
        'financial_period_id',
        'branch_id',
        'branch_hall_id',
        'opening_stock_id',
        'currency_id',
        'exchange_rate',
        'total_amount',
        'notes',
        'is_closed',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'exchange_rate' => 1,
        'total_amount' => 0,
        'is_closed' => true,
        'status' => self::StatusClosed,
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'total_amount' => 'decimal:4',
            'is_closed' => 'boolean',
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
        return $this->isClosed();
    }

    public function isClosed(): bool
    {
        return (bool) $this->is_closed || $this->status === self::StatusClosed;
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

    public function branchHall(): BelongsTo
    {
        return $this->belongsTo(BranchHall::class, 'branch_hall_id')->withTrashed();
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class, 'opening_stock_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningStockPricingLine::class, 'pricing_id')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query
            ->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $financialPeriodId);
    }
}
