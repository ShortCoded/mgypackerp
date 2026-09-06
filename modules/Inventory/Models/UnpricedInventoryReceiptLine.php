<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\SupplyOrderLine;

class UnpricedInventoryReceiptLine extends Model
{
    use SoftDeletes;

    protected $table = 'unpriced_inventory_receipt_lines';

    protected $fillable = [
        'public_id',
        'company_id',
        'financial_period_id',
        'branch_id',
        'receipt_id',
        'line_no',
        'product_id',
        'unit_id',
        'purchase_order_line_id',
        'supply_order_line_id',
        'delivery_schedule_id',
        'product_snapshot',
        'quantity',
        'delivered_quantity',
        'accepted_quantity',
        'rejected_quantity',
        'inventory_posted_quantity',
        'grni_journal_entry_id',
        'provisional_unit_value',
        'provisional_total_value',
        'grni_cleared_quantity',
        'grni_cleared_value',
        'grni_returned_quantity',
        'grni_returned_value',
        'supplier_lot_number',
        'manufacture_date',
        'expiry_date',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (UnpricedInventoryReceiptLine $line): void {
            if (! is_string($line->public_id) || trim($line->public_id) === '') {
                $line->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'delivered_quantity' => 'decimal:8',
            'accepted_quantity' => 'decimal:8',
            'rejected_quantity' => 'decimal:8',
            'inventory_posted_quantity' => 'decimal:8',
            'provisional_unit_value' => 'decimal:8',
            'provisional_total_value' => 'decimal:4',
            'grni_cleared_quantity' => 'decimal:8',
            'grni_cleared_value' => 'decimal:4',
            'grni_returned_quantity' => 'decimal:8',
            'grni_returned_value' => 'decimal:4',
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
            'product_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(UnpricedInventoryReceipt::class, 'receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'unit_id')->withTrashed();
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function supplyOrderLine(): BelongsTo
    {
        return $this->belongsTo(SupplyOrderLine::class);
    }

    public function deliverySchedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDeliverySchedule::class, 'delivery_schedule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
