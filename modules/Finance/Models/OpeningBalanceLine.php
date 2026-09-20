<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\Account;

class OpeningBalanceLine extends Model
{
    protected $fillable = [
        'opening_balance_id',
        'line_no',
        'account_id',
        'debit_amount',
        'credit_amount',
        'description',
        'customer_id',
        'supplier_id',
        'employee_id',
        'bank_account_id',
        'cost_center_id',
        'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:4',
            'credit_amount' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(OpeningBalance::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
