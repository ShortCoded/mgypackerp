<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrEmployee;

class SalesRequest extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'priority' => 'normal', 'exchange_rate' => 1];

    protected function casts(): array
    {
        return ['request_date' => 'date', 'required_delivery_date' => 'date', 'exchange_rate' => 'decimal:6', 'status_history' => 'array', 'print_identity_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function scopeOperationallyOpen(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), ['draft', 'submitted', 'approved', 'partially_converted'])
            ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->whereColumn('converted_quantity', '<', 'quantity'));
    }

    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'business_employee_id')->withTrashed();
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->newQuery()->where('company_id', app(OperatingCompanyContextService::class)->currentCompanyId())->where($field ?? 'doc_num', $value)->first();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesRequestLine::class)->orderBy('line_number');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }
}
