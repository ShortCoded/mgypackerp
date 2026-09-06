<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;

class PurchaseRequisitionLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'purchase_requisition_id', 'company_id', 'financial_period_id', 'line_number',
        'product_id', 'unit_id', 'requested_quantity', 'approved_quantity', 'required_date',
        'source_type', 'source_doc_num', 'source_line_reference', 'production_order_id',
        'production_order_line_id', 'specification', 'notes',
        'created_by', 'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:8', 'approved_quantity' => 'decimal:8', 'required_date' => 'date',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function rfqLines(): HasMany
    {
        return $this->hasMany(RequestForQuotationLine::class);
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function orderedQuantity(bool $includeDrafts = false): float
    {
        return (float) $this->purchaseOrderLines()
            ->whereHas('purchaseOrder', fn ($query) => $query->whereIn('status', $includeDrafts ? [PurchaseOrder::StatusDraft, PurchaseOrder::StatusSubmitted, PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed] : [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed]))
            ->sum('ordered_quantity');
    }

    public function remainingToOrder(): float
    {
        return max(0, (float) $this->approved_quantity - $this->orderedQuantity());
    }

    public function availableToOrder(): float
    {
        return max(0, (float) $this->approved_quantity - $this->orderedQuantity(true));
    }

    /** @return array<string, float> */
    public function quantityProgress(): array
    {
        $progress = ['requested' => (float) $this->requested_quantity, 'approved' => (float) $this->approved_quantity,
            'ordered' => 0.0, 'received' => 0.0, 'accepted' => 0.0, 'returned' => 0.0, 'net_received' => 0.0, 'invoiced' => 0.0, 'remaining' => 0.0, 'remaining_to_invoice' => 0.0];
        foreach ($this->purchaseOrderLines()->whereHas('purchaseOrder', fn ($query) => $query->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed]))->get() as $line) {
            foreach ($line->quantityProgress() as $key => $quantity) {
                if (array_key_exists($key, $progress)) {
                    $progress[$key] += $quantity;
                }
            }
        }
        $progress['draft_order_quantity'] = $this->orderedQuantity(true) - $this->orderedQuantity();
        $progress['remaining_to_order'] = max(0, $progress['approved'] - $progress['ordered']);

        return $progress;
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function productionOrderLine(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderLine::class);
    }
}
