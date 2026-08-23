<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;

class CustomerCommercialAgreement extends Model
{
    use SoftDeletes;

    public const TypeCash = 'cash';

    public const TypeCredit = 'credit';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $agreement): void {
            $agreement->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4', 'include_open_orders' => 'boolean',
            'required_advance_percentage' => 'decimal:4', 'required_advance_minimum' => 'decimal:4',
            'blocking_enabled' => 'boolean', 'temporary_override_allowed' => 'boolean',
            'effective_from' => 'date', 'effective_to' => 'date', 'installment_terms' => 'array',
        ];
    }

    public function scopeEffective(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only([
            'public_id', 'customer_type', 'credit_limit', 'include_open_orders',
            'required_advance_percentage', 'required_advance_minimum', 'payment_terms_days',
            'blocking_enabled', 'temporary_override_allowed', 'installment_terms',
            'effective_from', 'effective_to',
        ]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
