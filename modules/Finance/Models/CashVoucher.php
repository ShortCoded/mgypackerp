<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class CashVoucher extends Model
{
    use SoftDeletes;

    public const TypeReceipt = 'receipt';

    public const TypePayment = 'payment';

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'voucher_type',
        'voucher_date',
        'cashbox_id',
        'currency_id',
        'exchange_rate',
        'amount',
        'amount_base',
        'person_name',
        'person_national_id',
        'person_phone',
        'reason',
        'description',
        'status',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'voucher_type' => self::TypeReceipt,
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?self
    {
        return $this->companyScopedRouteQuery($value, $field)->withTrashed()->first();
    }

    public function isReceipt(): bool
    {
        return $this->voucher_type === self::TypeReceipt;
    }

    public function isPayment(): bool
    {
        return $this->voucher_type === self::TypePayment;
    }

    public function isDraft(): bool
    {
        return $this->status === self::StatusDraft;
    }

    public function isApproved(): bool
    {
        return $this->status === self::StatusApproved;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::StatusCancelled;
    }

    public function isLockedForEditing(): bool
    {
        return ! $this->isDraft();
    }

    public function isDeletable(): bool
    {
        return $this->isDraft() || $this->isCancelled();
    }

    public function documentNumberKey(): string
    {
        return self::documentNumberKeyForType($this->voucher_type);
    }

    public static function documentNumberKeyForType(string $voucherType): string
    {
        return $voucherType === self::TypePayment ? 'cash_payment_vouchers' : 'cash_receipt_vouchers';
    }

    public static function permissionPrefixForType(string $voucherType): string
    {
        return $voucherType === self::TypePayment ? 'cash_payment_vouchers' : 'cash_receipt_vouchers';
    }

    public static function translationKeyForType(string $voucherType): string
    {
        return $voucherType === self::TypePayment ? 'cash_payment_vouchers' : 'cash_receipt_vouchers';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashVoucherLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function scopeOfType(Builder $query, string $voucherType): Builder
    {
        return $query->where($this->getTable().'.voucher_type', $voucherType);
    }

    private function companyScopedRouteQuery(mixed $value, ?string $field): Builder
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where($this->getTable().'.company_id', $companyId);
    }
}
