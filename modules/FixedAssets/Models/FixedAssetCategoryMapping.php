<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;

class FixedAssetCategoryMapping extends Model
{
    protected $fillable = [
        'company_id',
        'asset_group_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'disposal_clearing_account_id',
        'created_by',
        'updated_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assetGroupAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_group_account_id')->withTrashed();
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id')->withTrashed();
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id')->withTrashed();
    }

    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id')->withTrashed();
    }

    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id')->withTrashed();
    }

    public function disposalClearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_clearing_account_id')->withTrashed();
    }
}
