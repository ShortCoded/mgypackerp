<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;

class SupplyOrderLine extends Model
{
    protected $fillable = [
        'public_id', 'supply_order_id', 'company_id', 'financial_period_id', 'line_number',
        'purchase_order_line_id', 'purchase_invoice_line_id', 'product_id', 'unit_id', 'ordered_quantity', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplyOrderLine $line): void {
            $line->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:8'];
    }

    public function receivedQuantity(?int $exceptReceiptId = null, bool $includeDrafts = false): float
    {
        $query = $this->receiptLines()
            ->when($exceptReceiptId !== null, fn ($query) => $query->where('receipt_id', '<>', $exceptReceiptId))
            ->whereHas('receipt', fn ($query) => $query
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->when(! $includeDrafts, fn ($receipts) => $receipts->where('approved', true)->where('posting_status', 'posted')));

        return (float) $query->sum($includeDrafts ? 'delivered_quantity' : 'accepted_quantity');
    }

    public function remainingQuantity(?int $exceptReceiptId = null, bool $includeDrafts = false): float
    {
        return max(0, (float) $this->ordered_quantity - $this->receivedQuantity($exceptReceiptId, $includeDrafts));
    }

    public function supplyOrder(): BelongsTo
    {
        return $this->belongsTo(SupplyOrder::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function purchaseInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class)->withTrashed();
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(UnpricedInventoryReceiptLine::class);
    }
}
