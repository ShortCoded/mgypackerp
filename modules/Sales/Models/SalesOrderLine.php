<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionOrderLine;

class SalesOrderLine extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8', 'unit_price' => 'decimal:8', 'discount_amount' => 'decimal:4', 'discount_value' => 'decimal:4', 'header_discount_amount' => 'decimal:4',
            'conversion_factor' => 'decimal:8', 'base_quantity' => 'decimal:8',
            'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'reserved_quantity' => 'decimal:8',
            'reserved_base_quantity' => 'decimal:8',
            'production_requested_quantity' => 'decimal:8', 'produced_quantity' => 'decimal:8',
            'production_requested_base_quantity' => 'decimal:8', 'produced_base_quantity' => 'decimal:8',
            'delivered_quantity' => 'decimal:8', 'declined_quantity' => 'decimal:8', 'invoiced_quantity' => 'decimal:8',
            'remainder_credited_quantity' => 'decimal:8',
            'delivered_base_quantity' => 'decimal:8', 'declined_base_quantity' => 'decimal:8', 'invoiced_base_quantity' => 'decimal:8',
            'remainder_credited_base_quantity' => 'decimal:8',
            'returned_quantity' => 'decimal:8', 'requested_date' => 'date', 'specifications' => 'array',
            'returned_base_quantity' => 'decimal:8',
            'allowed_discount_value' => 'decimal:4',
        ];
    }

    public function isService(): bool
    {
        return $this->product_classification_snapshot === Product::ClassificationService;
    }

    public function remainingDeliveryQuantity(): string
    {
        $remaining = bcsub($this->effectiveQuantity(), (string) $this->delivered_quantity, 8);

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
    }

    public function remainingDeliveryBaseQuantity(): string
    {
        $remaining = bcsub($this->effectiveBaseQuantity(), (string) $this->delivered_base_quantity, 8);

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
    }

    public function remainingProductionDemandBaseQuantity(): string
    {
        $remaining = bcsub(
            bcsub($this->effectiveBaseQuantity(), (string) $this->delivered_base_quantity, 8),
            (string) $this->production_requested_base_quantity,
            8,
        );

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
    }

    public function remainingProductionDemandQuantity(): string
    {
        if (bccomp((string) $this->conversion_factor, '0', 8) <= 0) {
            return '0.00000000';
        }

        return bcdiv($this->remainingProductionDemandBaseQuantity(), (string) $this->conversion_factor, 8);
    }

    public function activeReservedQuantity(): string
    {
        $reservations = $this->relationLoaded('reservations')
            ? $this->reservations
            : $this->reservations()->where('status', InventoryReservation::StatusActive)->get();

        $baseQuantity = $reservations->where('status', InventoryReservation::StatusActive)
            ->reduce(fn (string $total, InventoryReservation $reservation): string => bcadd($total, $reservation->remaining_quantity, 8), '0.00000000');

        return bcdiv($baseQuantity, (string) $this->conversion_factor, 8);
    }

    public function remainingInvoiceQuantity(): string
    {
        $remaining = bcsub($this->effectiveQuantity(), $this->netInvoicedQuantity(), 8);

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
    }

    public function effectiveQuantity(): string
    {
        $effective = bcsub((string) $this->quantity, (string) $this->declined_quantity, 8);

        return bccomp($effective, '0', 8) < 0 ? '0.00000000' : $effective;
    }

    public function effectiveBaseQuantity(): string
    {
        $effective = bcsub((string) $this->base_quantity, (string) $this->declined_base_quantity, 8);

        return bccomp($effective, '0', 8) < 0 ? '0.00000000' : $effective;
    }

    public function netInvoicedQuantity(): string
    {
        $net = bcsub((string) $this->invoiced_quantity, (string) $this->remainder_credited_quantity, 8);

        return bccomp($net, '0', 8) < 0 ? '0.00000000' : $net;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function quotationRevisionLine(): BelongsTo
    {
        return $this->belongsTo(QuotationRevisionLine::class);
    }

    public function salesRequestLine(): BelongsTo
    {
        return $this->belongsTo(SalesRequestLine::class);
    }

    public function priceListLine(): BelongsTo
    {
        return $this->belongsTo(PriceListLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'unit_id')->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class);
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class);
    }
}
