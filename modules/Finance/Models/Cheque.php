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

class Cheque extends Model
{
    use SoftDeletes;

    public const TypeReceived = 'received';

    public const TypeIssued = 'issued';

    public const StatusReceived = 'received';

    public const StatusDeposited = 'deposited';

    public const StatusCollected = 'collected';

    public const StatusDraft = 'draft';

    public const StatusIssued = 'issued';

    public const StatusDelivered = 'delivered';

    public const StatusCleared = 'cleared';

    public const StatusClearingReversed = 'clearing_reversed';

    public const StatusReturned = 'returned';

    public const StatusCancelled = 'cancelled';

    protected $fillable = [
        'doc_number',
        'doc_num',
        'company_id',
        'cheque_type',
        'cheque_number',
        'cheque_date',
        'due_date',
        'bank_account_id',
        'external_bank_name',
        'external_bank_branch',
        'party_type',
        'party_id',
        'party_name',
        'currency_id',
        'exchange_rate',
        'amount',
        'amount_base',
        'reason',
        'description',
        'status',
        'deposited_at',
        'collected_at',
        'returned_at',
        'issued_at',
        'delivered_at',
        'cleared_at',
        'clearing_revision',
        'clearing_reversed_at',
        'clearing_reversed_by',
        'clearing_reversal_reason',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'restored_at',
    ];

    protected $attributes = [
        'cheque_type' => self::TypeReceived,
        'exchange_rate' => 1,
        'status' => self::StatusReceived,
    ];

    protected function casts(): array
    {
        return [
            'cheque_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
            'deposited_at' => 'datetime',
            'collected_at' => 'datetime',
            'returned_at' => 'datetime',
            'issued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cleared_at' => 'datetime',
            'clearing_revision' => 'integer',
            'clearing_reversed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public static function types(): array
    {
        return [self::TypeReceived, self::TypeIssued];
    }

    public static function receivedStatuses(): array
    {
        return [self::StatusReceived, self::StatusDeposited, self::StatusCollected, self::StatusReturned, self::StatusCancelled];
    }

    public static function issuedStatuses(): array
    {
        return [self::StatusDraft, self::StatusIssued, self::StatusDelivered, self::StatusCleared, self::StatusClearingReversed, self::StatusReturned, self::StatusCancelled];
    }

    public static function documentNumberKeyForType(string $type): string
    {
        return $type === self::TypeIssued ? 'issued_cheques' : 'received_cheques';
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

    public function isReceived(): bool
    {
        return $this->cheque_type === self::TypeReceived;
    }

    public function isIssued(): bool
    {
        return $this->cheque_type === self::TypeIssued;
    }

    public function isLockedForEditing(): bool
    {
        return in_array($this->status, [self::StatusIssued, self::StatusDelivered, self::StatusCollected, self::StatusCleared, self::StatusClearingReversed, self::StatusReturned, self::StatusCancelled], true);
    }

    public function isDeletable(): bool
    {
        return ! $this->isLockedForEditing();
    }

    public function documentNumberKey(): string
    {
        return self::documentNumberKeyForType($this->cheque_type);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ChequeLine::class)->orderBy('line_number');
    }

    public function clearingEvents(): HasMany
    {
        return $this->hasMany(ChequeClearingEvent::class)->orderBy('sequence');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where($this->getTable().'.cheque_type', $type);
    }

    private function companyScopedRouteQuery(mixed $value, ?string $field): Builder
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where($this->getTable().'.company_id', $companyId);
    }
}
