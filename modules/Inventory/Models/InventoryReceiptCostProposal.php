<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryReceiptCostProposal extends Model
{
    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusRejected = 'rejected';

    public const BasisDocumented = 'documented';

    public const BasisEstimate = 'estimate';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_snapshot' => 'array',
            'impact_snapshot' => 'array',
            'posting_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function valueAdjustment(): HasOne
    {
        return $this->hasOne(InventoryValueAdjustment::class, 'source_id')->where('source_type', self::class);
    }
}
