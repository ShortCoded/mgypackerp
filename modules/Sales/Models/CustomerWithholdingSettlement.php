<?php

namespace Modules\Sales\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\ArchiveFile;

class CustomerWithholdingSettlement extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            $allowed = match ($settlement->getOriginal('status').'->'.$settlement->status) {
                'prepared->approved' => ['status', 'approved_by', 'approved_at', 'approval_reason', 'journal_entry_id', 'execution_snapshot', 'execution_fingerprint', 'updated_at'],
                'approved->reversed' => ['status', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_journal_entry_id', 'recovery_reference', 'recovery_file_id', 'reversal_reason', 'reversal_snapshot', 'reversal_fingerprint', 'updated_at'],
                default => [],
            };
            if ($allowed === [] || array_diff(array_keys($settlement->getDirty()), $allowed) !== []) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
        });
        static::deleting(fn () => throw new DomainException(__('sales_ui.wht.source_invalid')));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'expected_amount' => 'decimal:4', 'exchange_rate' => 'decimal:6',
            'certificate_date' => 'date', 'posting_date' => 'date', 'reversal_date' => 'date',
            'approved_at' => 'datetime', 'reversed_at' => 'datetime',
            'source_snapshot' => 'array', 'execution_snapshot' => 'array', 'reversal_snapshot' => 'array'];
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ArchiveFile::class, 'certificate_file_id')->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
