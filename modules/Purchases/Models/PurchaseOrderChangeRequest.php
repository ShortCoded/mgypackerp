<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Services\OperatingCompanyContextService;

class PurchaseOrderChangeRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'doc_number', 'doc_num', 'company_id', 'financial_period_id', 'purchase_order_id', 'request_date',
        'original_values', 'requested_values', 'reason', 'status', 'requested_by', 'approved_by',
        'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date', 'original_values' => 'array', 'requested_values' => 'array',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
