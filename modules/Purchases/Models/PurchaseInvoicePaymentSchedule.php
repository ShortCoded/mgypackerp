<?php

namespace Modules\Purchases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cashbox;

class PurchaseInvoicePaymentSchedule extends Model
{
    use SoftDeletes;

    public const StatusScheduled = 'scheduled';

    public const StatusVoucherDraft = 'voucher_draft';

    public const StatusPaid = 'paid';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'public_id',
        'purchase_invoice_id',
        'company_id',
        'financial_period_id',
        'line_number',
        'due_date',
        'amount',
        'payment_source_type',
        'cashbox_id',
        'bank_account_id',
        'payment_date',
        'cash_voucher_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $attributes = [
        'payment_source_type' => PurchaseInvoice::SourceScheduled,
        'status' => self::StatusScheduled,
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseInvoicePaymentSchedule $schedule): void {
            if (! is_string($schedule->public_id) || trim($schedule->public_id) === '') {
                $schedule->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:4',
            'payment_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class);
    }
}
