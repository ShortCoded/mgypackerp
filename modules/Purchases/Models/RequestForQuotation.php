<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Services\OperatingCompanyContextService;

class RequestForQuotation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'purchase_requisition_id',
        'issue_date', 'quotation_due_date', 'required_delivery_date', 'status', 'commercial_notes',
        'issued_by', 'issued_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'quotation_due_date' => 'date', 'required_delivery_date' => 'date', 'issued_at' => 'datetime',
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

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequestForQuotationLine::class)->orderBy('line_number');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'request_for_quotation_suppliers')
            ->withPivot(['status', 'sent_at'])->withTimestamps();
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function supplierSelections(): HasMany
    {
        return $this->hasMany(SupplierSelection::class);
    }

    public function scopeForContext(Builder $query, int $companyId, int $financialPeriodId): Builder
    {
        return $query->where('company_id', $companyId)->where('financial_period_id', $financialPeriodId);
    }
}
