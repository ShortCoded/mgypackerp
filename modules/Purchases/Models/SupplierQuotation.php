<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class SupplierQuotation extends Model
{
    use SoftDeletes;

    public const AttachmentCollection = 'supplier_quotation_attachments';

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'request_for_quotation_id',
        'supplier_id', 'currency_id', 'exchange_rate', 'supplier_reference', 'quotation_date', 'valid_until', 'lead_time_days',
        'payment_terms', 'freight_amount', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount',
        'status', 'commercial_notes', 'submitted_by', 'submitted_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date', 'valid_until' => 'date', 'submitted_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
            'freight_amount' => 'decimal:4', 'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()->where('company_id', $companyId)
            ->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function requestForQuotation(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuotationLine::class)->orderBy('line_number');
    }

    public function archiveFileUsages(): MorphMany
    {
        return $this->morphMany(ArchiveFileUsage::class, 'usable');
    }

    public function attachmentUsages(): MorphMany
    {
        return $this->archiveFileUsages()
            ->where('collection', self::AttachmentCollection)
            ->whereNull('role')
            ->with('file');
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query->where('company_id', $companyId)->where('financial_period_id', $financialPeriodId);
    }
}
