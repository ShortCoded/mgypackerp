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
            'quantity' => 'decimal:8', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4',
            'conversion_factor' => 'decimal:8', 'base_quantity' => 'decimal:8',
            'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'reserved_quantity' => 'decimal:8',
            'reserved_base_quantity' => 'decimal:8',
            'production_requested_quantity' => 'decimal:8', 'produced_quantity' => 'decimal:8',
            'production_requested_base_quantity' => 'decimal:8', 'produced_base_quantity' => 'decimal:8',
            'delivered_quantity' => 'decimal:8', 'invoiced_quantity' => 'decimal:8',
            'delivered_base_quantity' => 'decimal:8', 'invoiced_base_quantity' => 'decimal:8',
            'returned_quantity' => 'decimal:8', 'requested_date' => 'date', 'specifications' => 'array',
            'returned_base_quantity' => 'decimal:8',
        ];
    }

    public function isService(): bool
    {
        return $this->product_classification_snapshot === Product::ClassificationService;
    }

    public function remainingDeliveryQuantity(): string
    {
        $remaining = bcsub((string) $this->quantity, (string) $this->delivered_quantity, 8);

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
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
        $remaining = bcsub((string) $this->quantity, (string) $this->invoiced_quantity, 8);

        return bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function quotationRevisionLine(): BelongsTo
    {
        return $this->belongsTo(QuotationRevisionLine::class);
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
