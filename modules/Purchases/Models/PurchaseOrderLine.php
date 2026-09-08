<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class PurchaseOrderLine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'purchase_order_id',
        'company_id',
        'financial_period_id',
        'line_number',
        'product_id',
        'unit_id',
        'purchase_requisition_line_id',
        'request_for_quotation_line_id',
        'supplier_quotation_line_id',
        'supplier_selection_line_id',
        'cost_center_id',
        'ordered_quantity',
        'received_quantity',
        'remaining_quantity',
        'unit_price',
        'line_total',
        'description',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'subtotal_amount',
        'total_before_tax',
        'total_after_tax',
        'required_delivery_date',
        'specification',
        'product_snapshot',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderLine $line): void {
            if (! is_string($line->public_id) || trim($line->public_id) === '') {
                $line->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:8',
            'received_quantity' => 'decimal:8',
            'remaining_quantity' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'total_before_tax' => 'decimal:4',
            'total_after_tax' => 'decimal:4',
            'required_delivery_date' => 'date',
            'product_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function receivedQuantity(?int $exceptReceiptId = null): float
    {
        return (float) UnpricedInventoryReceiptLine::query()
            ->where('purchase_order_line_id', $this->getKey())
            ->when($exceptReceiptId !== null, fn ($query) => $query->where('receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
            ->sum('accepted_quantity');
    }

    public function returnedQuantity(): float
    {
        return (float) PurchaseReturnLine::query()->where('purchase_order_line_id', $this->getKey())
            ->where('from_quarantine', false)
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))->sum('quantity');
    }

    public function netReceivedQuantity(?int $exceptReceiptId = null): float
    {
        return max(0, $this->receivedQuantity($exceptReceiptId) - $this->returnedQuantity());
    }

    /** @return array{ordered: float, received: float, accepted: float, returned: float, net_received: float, invoiced: float, remaining: float, remaining_to_invoice: float} */
    public function scopeWithQuantityProgress(Builder $query, ?string $asOf = null): void
    {
        $query->addSelect(['purchase_order_lines.*']);
        $receipts = UnpricedInventoryReceiptLine::query()->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed'])->when($asOf, fn ($query) => $query->whereDate('document_date', '<=', $asOf)));
        $returns = PurchaseReturnLine::query()->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted)->when($asOf, fn ($query) => $query->whereDate('return_date', '<=', $asOf)));
        $query->selectSub((clone $receipts)->selectRaw('coalesce(sum(accepted_quantity), 0)'), 'progress_received')
            ->selectSub((clone $receipts)->selectRaw('coalesce(sum(accepted_quantity), 0)'), 'progress_accepted')
            ->selectSub((clone $returns)->selectRaw('coalesce(sum(quantity), 0)'), 'progress_returned')
            ->selectSub((clone $returns)->where('from_quarantine', false)->selectRaw('coalesce(sum(quantity), 0)'), 'progress_accepted_returned')
            ->selectSub((clone $returns)->whereNotNull('purchase_invoice_line_id')->selectRaw('coalesce(sum(quantity), 0)'), 'progress_credited')
            ->selectSub(PurchaseInvoiceLine::query()->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
                ->whereHas('purchaseInvoice', fn ($query) => $query->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])->when($asOf, fn ($query) => $query->whereDate('invoice_date', '<=', $asOf)))
                ->selectRaw('coalesce(sum(quantity), 0)'), 'progress_invoiced');
    }

    public function quantityProgress(): array
    {
        $progress = array_key_exists('progress_received', $this->getAttributes())
            ? $this : static::query()->withQuantityProgress()->findOrFail($this->getKey());
        $received = (float) $progress->progress_received;
        $accepted = (float) $progress->progress_accepted;
        $acceptedReturned = (float) $progress->progress_accepted_returned;
        $invoiced = (float) $progress->progress_invoiced;
        $credited = (float) $progress->progress_credited;
        $returned = (float) $progress->progress_returned;
        $net = max(0, $received - $acceptedReturned);
        $netAccepted = max(0, $accepted - $acceptedReturned);

        return ['ordered' => (float) $this->ordered_quantity, 'received' => $received, 'accepted' => $accepted,
            'returned' => $returned, 'net_received' => $net, 'net_accepted' => $netAccepted, 'invoiced' => $invoiced, 'credited' => $credited, 'net_invoiced' => max(0, $invoiced - $credited),
            'remaining' => max(0, (float) $this->ordered_quantity - $net),
            'remaining_to_invoice' => max(0, ($this->product?->isService() ? (float) $this->ordered_quantity : $netAccepted) - ($invoiced - $credited))];
    }

    public function stockConversionFactor(): string
    {
        $factor = $this->product_snapshot['stock_conversion_factor'] ?? app(ProductComponentUnitConversionService::class)
            ->convert('1', $this->product, $this->unit, $this->product, $this->product->unit, 8);
        if ($factor === null || bccomp((string) $factor, '0', 8) <= 0) {
            throw new \DomainException(__('The selected purchase unit has no valid conversion to the stock unit.'));
        }

        return (string) $factor;
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotationLine::class, 'request_for_quotation_line_id');
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationLine::class, 'supplier_quotation_line_id');
    }

    public function selectionLine(): BelongsTo
    {
        return $this->belongsTo(SupplierSelectionLine::class, 'supplier_selection_line_id');
    }

    public function deliverySchedules(): HasMany
    {
        return $this->hasMany(PurchaseOrderDeliverySchedule::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceiptLine::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }
}
