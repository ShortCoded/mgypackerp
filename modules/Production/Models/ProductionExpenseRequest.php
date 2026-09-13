<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Maintenance\Models\MaintenanceWorkOrder;

class ProductionExpenseRequest extends Model
{
    use SoftDeletes;

    public const StatusDraft = 'draft';

    public const StatusSubmitted = 'submitted';

    public const StatusApproved = 'approved';

    public const StatusPaid = 'paid';

    public const StatusRejected = 'rejected';

    public const StatusReversed = 'reversed';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft, 'payment_channel' => 'cashbox'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'amount' => 'decimal:4',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function maintenanceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withTrashed();
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id')->withTrashed();
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function scopeForContext(Builder $query, int $companyId, int $periodId, int $branchId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId)
            ->where($this->getTable().'.financial_period_id', $periodId)
            ->where($this->getTable().'.branch_id', $branchId);
    }
}
