<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;

class CustomerCreditRefund extends Model
{
    public const MethodCash = 'cash';

    public const MethodBank = 'bank';

    public const StatusPosted = 'posted';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'doc_num';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return $companyId === null
            ? null
            : $this->newQuery()->where($field ?? 'doc_num', $value)->where('company_id', $companyId)->first();
    }

    protected function casts(): array
    {
        return ['refund_date' => 'date', 'exchange_rate' => 'decimal:6', 'amount' => 'decimal:4', 'posted_at' => 'datetime'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'credit_note_id');
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
