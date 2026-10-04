<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;

class InventoryCostPolicyTransition extends Model
{
    public const StatusPrepared = 'prepared';

    public const StatusApproved = 'approved';

    public const StatusActivated = 'activated';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'total_quantity' => 'decimal:8',
            'total_book_value' => 'decimal:8',
            'prepared_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transition): void {
            $immutable = [
                'company_id', 'branch_id', 'branch_store_id', 'scope_key', 'effective_from', 'target_method',
                'input_fingerprint', 'total_quantity', 'total_book_value', 'reason',
                'prepared_by', 'prepared_at',
            ];
            if ($transition->isDirty($immutable)) {
                throw new DomainException(__('inventory_cost_policy.transition_errors.immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory_cost_policy.transition_errors.immutable')));
    }

    public function bases(): HasMany
    {
        return $this->hasMany(InventoryCostPolicyTransitionBasis::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InventoryCostPolicy::class, 'inventory_cost_policy_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
