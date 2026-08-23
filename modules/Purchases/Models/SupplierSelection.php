<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Services\OperatingCompanyContextService;

class SupplierSelection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'branch_id', 'request_for_quotation_id',
        'selection_date', 'status', 'selection_reason', 'selected_by', 'approved_by', 'approved_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['selection_date' => 'date', 'approved_at' => 'datetime'];
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

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierSelectionLine::class);
    }
}
