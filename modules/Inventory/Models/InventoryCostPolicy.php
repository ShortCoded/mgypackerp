<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;

class InventoryCostPolicy extends Model
{
    public const MovingAverage = 'moving_average';

    public const PeriodicWeightedAverage = 'periodic_weighted_average';

    public const Fifo = 'fifo';

    public const SpecificIdentification = 'specific_identification';

    public static function usesReceiptLayers(?string $method): bool
    {
        return in_array($method, [self::Fifo, self::SpecificIdentification], true);
    }

    protected $fillable = [
        'company_id', 'branch_id', 'branch_store_id', 'scope_key', 'method',
        'effective_from', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function branchStore(): BelongsTo
    {
        return $this->belongsTo(BranchStore::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
