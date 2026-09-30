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

    public const StatusApproved = 'approved';

    public const StatusDraft = 'draft';

    public const StatusRejected = 'rejected';

    public const StatusReopened = 'reopened';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'priority' => 'normal', 'exchange_rate' => 1];

    protected function casts(): array
    {
        return ['request_date' => 'date', 'required_delivery_date' => 'date', 'exchange_rate' => 'decimal:6', 'status_history' => 'array', 'print_identity_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function scopeOperationallyOpen(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [self::StatusDraft, 'submitted', self::StatusApproved, 'partially_converted', self::StatusReopened])
            ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->whereColumn('converted_quantity', '<', 'quantity'));
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::StatusDraft, self::StatusRejected, self::StatusReopened], true);
    }

    public function canReopenSafely(): bool
    {
        if ($this->status !== self::StatusApproved || $this->lines()->where('converted_quantity', '>', 0)->exists()) {
            return false;
        }

        return ! $this->quotations()->withTrashed()->exists()
            && ! $this->orders()->withTrashed()->exists()
            && ! CustomerInvoice::query()->withTrashed()
                ->where('source_type', 'sales_request')
                ->where('source_id', $this->getKey())
                ->exists();
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

    public function directInvoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class, 'source_id')
            ->where('source_type', 'sales_request');
    }
}
