<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

final class CashboxCount extends Model
{
    public const StatusSaved = 'saved';

    public const StatusReopened = 'reopened';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'branch_id', 'cashbox_id', 'currency_id',
        'count_date', 'book_balance', 'actual_amount', 'variance', 'status', 'notes',
        'created_by', 'updated_by', 'reopened_by', 'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'book_balance' => 'decimal:4',
            'actual_amount' => 'decimal:4',
            'variance' => 'decimal:4',
            'reopened_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value)->where('company_id', $companyId)->first();
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function cashbox(): BelongsTo { return $this->belongsTo(Cashbox::class)->withTrashed(); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class)->withTrashed(); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reopenedBy(): BelongsTo { return $this->belongsTo(User::class, 'reopened_by'); }
}
