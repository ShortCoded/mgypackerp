<?php

namespace Modules\Sales\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class SalesOrderRemainderClosure extends Model
{
    public const StatusApplying = 'applying';

    public const StatusApplied = 'applied';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $closure) => $closure->public_id ??= (string) Str::uuid());
        static::updating(function (self $closure): void {
            $allowed = ['status', 'effect_snapshot', 'updated_at'];
            if (array_diff(array_keys($closure->getDirty()), $allowed) !== []
                || [$closure->getOriginal('status'), $closure->status] !== [self::StatusApplying, self::StatusApplied]) {
                throw new DomainException(__('sales_ui.remainder.messages.closure_immutable'));
            }
        });
        static::deleting(fn () => throw new DomainException(__('sales_ui.remainder.messages.closure_not_deletable')));
    }

    protected function casts(): array
    {
        return [
            'closure_date' => 'date',
            'before_snapshot' => 'array',
            'effect_snapshot' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderRemainderClosureLine::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
