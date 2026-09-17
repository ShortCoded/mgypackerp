<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Branch;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
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
        'department_id',
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class);
    }
}
