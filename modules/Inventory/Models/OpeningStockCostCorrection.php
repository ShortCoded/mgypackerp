<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockCostCorrection extends Model
{
    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    protected $table = 'inventory_opening_stock_cost_corrections';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $correction): void {
            if ($correction->isDirty([
                'public_uuid', 'company_id', 'opening_stock_id', 'financial_period_id', 'branch_id',
                'posting_period_id', 'posting_date', 'counterpart_account_id', 'reason', 'source_reference',
                'unit_costs', 'source_snapshot', 'plan', 'fingerprint', 'prepared_by',
            ])
                || ($correction->getOriginal('inventory_value_adjustment_id') !== null && $correction->isDirty('inventory_value_adjustment_id'))
                || (in_array($correction->getOriginal('status'), [self::StatusApproved, self::StatusRejected], true)
                    && $correction->isDirty([
                        'status', 'approval_reference', 'rejection_reason', 'approved_by', 'approved_at',
                        'rejected_by', 'rejected_at', 'inventory_value_adjustment_id',
                    ]))) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable'));
            }
        });
        static::deleting(fn (): never => throw new DomainException(__('inventory.movements.messages.receipt_completion_immutable')));
    }

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'unit_costs' => 'array',
            'source_snapshot' => 'array',
            'plan' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryValueAdjustment::class, 'inventory_value_adjustment_id');
    }

    public function valueAdjustment(): BelongsTo
    {
        return $this->adjustment();
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
