<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Concerns\SnapshotsCompanyPrintIdentity;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\HR\Models\HrEmployee;

class CustomerReceipt extends Model
{
    use SnapshotsCompanyPrintIdentity, SoftDeletes;

    public const TypeAdvance = 'advance';

    public const TypeCollection = 'collection';

    public const StatusDraft = 'draft';

    public const StatusApproved = 'approved';

    public const StatusReopened = 'reopened';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::StatusDraft];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'cheque_due_date' => 'date', 'exchange_rate' => 'decimal:6', 'amount' => 'decimal:4', 'unallocated_amount' => 'decimal:4', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'reopened_at' => 'datetime', 'is_closed' => 'boolean', 'print_identity_snapshot' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null ? null : $this->newQuery()->where($field ?? 'doc_num', $value)->where('company_id', $companyId)->first();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function receivedByEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'received_by_employee_id')->withTrashed();
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class);
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }
}
