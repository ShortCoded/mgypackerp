<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryDocument;

class SalesDeliveryReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()->where($field ?? 'doc_num', $value)->where('company_id', $companyId)->first();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
    }
}
