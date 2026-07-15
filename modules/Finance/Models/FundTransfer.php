<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class FundTransfer extends Model
{
    use SoftDeletes;

    public const HolderCashbox = 'cashbox';

    public const HolderBankAccount = 'bank_account';

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'transfer_date',
        'source_type',
        'source_cashbox_id',
        'source_bank_account_id',
        'target_type',
        'target_cashbox_id',
        'target_bank_account_id',
        'source_currency_id',
        'target_currency_id',
        'source_amount',
        'exchange_rate',
        'target_amount',
        'source_amount_base',
        'target_amount_base',
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
        'source_type' => self::HolderCashbox,
        'target_type' => self::HolderBankAccount,
        'exchange_rate' => 1,
        'status' => self::StatusDraft,
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'source_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'target_amount' => 'decimal:4',
            'source_amount_base' => 'decimal:4',
            'target_amount_base' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public static function holderTypes(): array
    {
        return [self::HolderCashbox, self::HolderBankAccount];
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
        return $this->isDraft();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'source_cashbox_id')->withTrashed();
    }

    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id')->withTrashed();
    }

    public function targetCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'target_cashbox_id')->withTrashed();
    }

    public function targetBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'target_bank_account_id')->withTrashed();
    }

    public function sourceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'source_currency_id')->withTrashed();
    }

    public function targetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'target_currency_id')->withTrashed();
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

    private function companyScopedRouteQuery(mixed $value, ?string $field): Builder
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where($this->getTable().'.company_id', $companyId);
    }
}
