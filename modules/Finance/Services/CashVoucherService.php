<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\HR\Services\PayrollPaymentService;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\SupplierPaymentPostingService;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Services\CustomerReceiptSettlementService;

class CashVoucherService
{
    public const SourceReceipt = 'cash_receipt_voucher';

    public const SourcePayment = 'cash_payment_voucher';

    public const SourceReceiptReversal = 'cash_receipt_voucher_reversal';

    public const SourcePaymentReversal = 'cash_payment_voucher_reversal';

    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
        private readonly SupplierPaymentPostingService $supplierPaymentPostings,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly JournalEntryService $journalEntries,
    ) {}

    public function create(string $voucherType, array $data, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($voucherType, $data, $companyId): array {
            $companyId ??= $this->companies->requireCompanyId();
            $record = CashVoucher::query()->create([
                ...$this->values($voucherType, $data, $companyId),
                ...$this->document($voucherType, $data, $companyId),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines'] ?? []);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['cashbox.account', 'currency', 'lines.account'])];
        });
    }

    public function update(string $voucherType, CashVoucher $record, array $data): array
    {
        return DB::transaction(function () use ($voucherType, $record, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwnedByCurrentScreen($record, $voucherType, $companyId);
            $this->assertEditable($record);
            $this->assertNotLinkedToClosedPurchaseInvoice($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($voucherType, $data, $companyId);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($voucherType, $data, $companyId)];
            }

            $changes = $this->changes($record, $values);
            $linesChanged = $this->linesChanged($record, $data['lines'] ?? []);

            if ($changes === [] && ! $linesChanged) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            if ($changes !== []) {
                $this->audit->saveUpdate($record, $values);
            } else {
                $this->audit->touchUpdateAudit($record);
            }

            $this->syncLines($record->refresh(), $data['lines'] ?? []);

            return [
                'record' => $record->refresh()->load(['cashbox.account', 'currency', 'lines.account']),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(string $voucherType, CashVoucher $record): void
    {
        DB::transaction(function () use ($voucherType, $record): void {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwnedByCurrentScreen($record, $voucherType, $companyId);
            $this->assertDeletable($record);
            $this->assertNotLinkedToClosedPurchaseInvoice($record);
            $this->audit->softDelete($record);
            $this->refreshLinkedPurchaseInvoices($record->refresh());
        });
    }

    public function bulkDelete(string $voucherType, array $docNums): int
    {
        return DB::transaction(function () use ($voucherType, $docNums): int {
            $companyId = $this->companies->requireCompanyId();
            $deleted = 0;

            foreach (CashVoucher::query()->forCompany($companyId)->ofType($voucherType)->whereIn('doc_num', $docNums)->get() as $record) {
                try {
                    $this->delete($voucherType, $record);
                    $deleted++;
                } catch (DomainException) {
                    continue;
                }
            }

            return $deleted;
        });
    }

    public function restore(string $voucherType, CashVoucher $record): CashVoucher
    {
        return DB::transaction(function () use ($voucherType, $record): CashVoucher {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwnedByCurrentScreen($record, $voucherType, $companyId);
            $this->assertNotLinkedToClosedPurchaseInvoice($record);

            if (! $record->isDeletable()) {
                throw new DomainException($this->message($voucherType, 'restore_status_forbidden'));
            }

            if (CashVoucher::query()
                ->forCompany($companyId)
                ->ofType($voucherType)
                ->whereKeyNot($record->getKey())
                ->where('doc_number', $record->doc_number)
                ->exists()) {
                throw new DomainException($this->message($voucherType, 'restore_conflict'));
            }

            if (CashVoucher::query()
                ->forCompany($companyId)
                ->whereKeyNot($record->getKey())
                ->where('doc_num', $record->doc_num)
                ->exists()) {
                throw new DomainException($this->message($voucherType, 'restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());
            $this->refreshLinkedPurchaseInvoices($record->refresh());

            return $record->refresh();
        });
    }

    public function approve(string $voucherType, CashVoucher $record, ?int $companyId = null): CashVoucher
    {
        return $this->approveVoucher($voucherType, $record, $companyId, false);
    }

    public function approveGeneric(string $voucherType, CashVoucher $record): CashVoucher
    {
        return $this->approveVoucher($voucherType, $record, null, true);
    }

    private function approveVoucher(string $voucherType, CashVoucher $record, ?int $companyId, bool $postGeneric): CashVoucher
    {
        return DB::transaction(function () use ($voucherType, $record, $companyId, $postGeneric): CashVoucher {
            $companyId ??= $this->companies->requireCompanyId();

            /** @var CashVoucher $locked */
            $locked = CashVoucher::query()
                ->with(['cashbox.account', 'cashbox.branch', 'currency', 'lines.account'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $this->assertOwnedByCurrentScreen($locked, $voucherType, $companyId);

            if ($locked->isApproved()) {
                if ($postGeneric && ! $this->hasSpecializedPostingOwner($locked)) {
                    $existingJournal = JournalEntry::query()
                        ->where('company_id', $companyId)
                        ->where('source_type', $this->sourceType($locked))
                        ->where('source_id', $locked->getKey())
                        ->where('status', JournalEntry::StatusPosted)
                        ->where('is_posted', true)
                        ->lockForUpdate()
                        ->first();
                    if (! $existingJournal instanceof JournalEntry) {
                        throw new DomainException(__('The approved cash voucher is missing its canonical journal entry and requires controlled reconciliation.'));
                    }
                }

                return $locked;
            }

            $this->assertApprovable($locked);
            $this->assertNotLinkedToClosedPurchaseInvoice($locked);
            $financialPeriod = $this->financialPeriods->resolveOpenForPostingDate(
                $companyId,
                $locked->voucher_date,
                lockForUpdate: true,
            );

            $locked->forceFill([
                'status' => CashVoucher::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();
            $this->postSupplierPayment($locked);
            app(PayrollPaymentService::class)->postApprovedVoucher($locked);
            if ($postGeneric && ! $this->hasSpecializedPostingOwner($locked)) {
                $this->postGenericVoucher($locked, (int) $financialPeriod->getKey());
            }
            $this->refreshLinkedPurchaseInvoices($locked->refresh());

            return $locked->refresh()->load(['cashbox.account', 'currency', 'lines.account']);
        }, attempts: 3);
    }

    public function cancel(string $voucherType, CashVoucher $record, string $reason): CashVoucher
    {
        return $this->cancelVoucher($voucherType, $record, $reason, false);
    }

    public function cancelGeneric(string $voucherType, CashVoucher $record, string $reason): CashVoucher
    {
        return $this->cancelVoucher($voucherType, $record, $reason, true);
    }

    private function cancelVoucher(string $voucherType, CashVoucher $record, string $reason, bool $reverseGeneric): CashVoucher
    {
        return DB::transaction(function () use ($voucherType, $record, $reason, $reverseGeneric): CashVoucher {
            $companyId = $this->companies->requireCompanyId();

            /** @var CashVoucher $locked */
            $locked = CashVoucher::query()
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $this->assertOwnedByCurrentScreen($locked, $voucherType, $companyId);
            $this->assertNotLinkedToClosedPurchaseInvoice($locked);

            if (! $locked->isApproved()) {
                throw new DomainException($this->message($voucherType, 'cancel_requires_approved'));
            }

            if ($locked->trashed()) {
                throw new DomainException($this->message($voucherType, 'deleted_not_cancellable'));
            }

            $locked->forceFill([
                'status' => CashVoucher::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();
            $this->reverseSupplierPayment($locked);
            $receipt = CustomerReceipt::query()->where('cash_voucher_id', $locked->id)->lockForUpdate()->first();
            if ($receipt) {
                app(CustomerReceiptSettlementService::class)->reverse($receipt, $reason);
            }
            app(PayrollPaymentService::class)->reverseCancelledVoucher($locked);
            if ($reverseGeneric && ! $this->hasSpecializedPostingOwner($locked)) {
                $this->reverseGenericVoucher($locked);
            }
            $this->refreshLinkedPurchaseInvoices($locked->refresh());

            return $locked->refresh()->load(['cashbox.account', 'currency', 'lines.account']);
        }, attempts: 3);
    }

    private function postGenericVoucher(CashVoucher $voucher, ?int $financialPeriodId = null): JournalEntry
    {
        $voucher->loadMissing(['cashbox.account', 'cashbox.branch', 'currency', 'lines.account']);
        $financialPeriodId ??= (int) $this->financialPeriods->resolveOpenForPostingDate(
            (int) $voucher->company_id,
            $voucher->voucher_date,
            lockForUpdate: true,
        )->getKey();

        $cashAccountId = (int) $voucher->cashbox->account->getKey();
        $branchId = $voucher->cashbox->branch_id === null ? null : (int) $voucher->cashbox->branch_id;
        $lines = $voucher->lines->map(function ($line) use ($voucher, $branchId): array {
            return [
                'account_id' => (int) $line->account_id,
                'debit_amount' => $voucher->isPayment() ? (string) $line->amount : '0.0000',
                'credit_amount' => $voucher->isReceipt() ? (string) $line->amount : '0.0000',
                'description' => (string) ($line->description ?: $voucher->reason),
                'branch_id' => $branchId,
            ];
        })->all();
        $lines[] = [
            'account_id' => $cashAccountId,
            'debit_amount' => $voucher->isReceipt() ? (string) $voucher->amount : '0.0000',
            'credit_amount' => $voucher->isPayment() ? (string) $voucher->amount : '0.0000',
            'description' => (string) $voucher->reason,
            'branch_id' => $branchId,
        ];

        return $this->journalEntries->createPostedFromSource([
            'entry_date' => $voucher->voucher_date,
            'company_id' => (int) $voucher->company_id,
            'financial_period_id' => $financialPeriodId,
            'branch_id' => $branchId,
            'currency_id' => (int) $voucher->currency_id,
            'exchange_rate' => (string) $voucher->exchange_rate,
            'description' => __($voucher->isReceipt() ? 'Cash receipt voucher :document' : 'Cash payment voucher :document', ['document' => $voucher->doc_num]),
            'notes' => $voucher->description,
            'source_type' => $this->sourceType($voucher),
            'source_id' => (int) $voucher->getKey(),
            'source_doc_num' => (string) $voucher->doc_num,
        ], $lines);
    }

    private function reverseGenericVoucher(CashVoucher $voucher): JournalEntry
    {
        $original = JournalEntry::query()
            ->where('company_id', $voucher->company_id)
            ->where('source_type', $this->sourceType($voucher))
            ->where('source_id', $voucher->getKey())
            ->where('status', JournalEntry::StatusPosted)
            ->lockForUpdate()
            ->first();
        if (! $original instanceof JournalEntry) {
            throw new DomainException(__('The approved cash voucher does not have a canonical journal entry to reverse.'));
        }

        $entryDate = $voucher->cancelled_at?->toDateString() ?? now()->toDateString();
        $financialPeriod = $this->financialPeriods->resolveOpenForPostingDate(
            (int) $voucher->company_id,
            $entryDate,
            lockForUpdate: true,
        );

        return $this->journalEntries->createPostedReversalFromSource($original, [
            'entry_date' => $entryDate,
            'company_id' => (int) $voucher->company_id,
            'financial_period_id' => (int) $financialPeriod->getKey(),
            'branch_id' => $voucher->cashbox?->branch_id,
            'currency_id' => (int) $voucher->currency_id,
            'exchange_rate' => (string) $voucher->exchange_rate,
            'description' => __('Cash voucher reversal :document', ['document' => $voucher->doc_num]),
            'notes' => $voucher->cancel_reason,
            'source_type' => $this->reversalSourceType($voucher),
            'source_id' => (int) $voucher->getKey(),
            'source_doc_num' => (string) $voucher->doc_num,
        ]);
    }

    private function sourceType(CashVoucher $voucher): string
    {
        return $voucher->isReceipt() ? self::SourceReceipt : self::SourcePayment;
    }

    private function reversalSourceType(CashVoucher $voucher): string
    {
        return $voucher->isReceipt() ? self::SourceReceiptReversal : self::SourcePaymentReversal;
    }

    private function hasSpecializedPostingOwner(CashVoucher $voucher): bool
    {
        return SupplierPaymentContext::query()->where('cash_voucher_id', $voucher->getKey())->exists()
            || PurchaseInvoicePaymentSchedule::query()->where('cash_voucher_id', $voucher->getKey())->exists()
            || CustomerReceipt::query()->where('cash_voucher_id', $voucher->getKey())->exists()
            || ProductionExpenseRequest::query()->where('cash_voucher_id', $voucher->getKey())->exists()
            || (Schema::hasTable('hr_payroll_payments')
                && DB::table('hr_payroll_payments')->where('cash_voucher_id', $voucher->getKey())->exists());
    }

    /**
     * @return array{amount: string, distributed_amount: string, remaining_amount: string, amount_units: int, distributed_units: int, remaining_units: int}
     */
    public function totals(CashVoucher $record): array
    {
        $record->loadMissing('lines');
        $amountUnits = $this->toUnits($record->amount);
        $distributedUnits = $record->lines->sum(fn ($line): int => $this->toUnits($line->amount));
        $remainingUnits = $amountUnits - $distributedUnits;

        return [
            'amount' => $this->fromUnits($amountUnits),
            'distributed_amount' => $this->fromUnits($distributedUnits),
            'remaining_amount' => $this->fromUnits($remainingUnits),
            'amount_units' => $amountUnits,
            'distributed_units' => $distributedUnits,
            'remaining_units' => $remainingUnits,
        ];
    }

    private function postSupplierPayment(CashVoucher $voucher): void
    {
        $payment = SupplierPaymentContext::query()
            ->with('journalEntry')
            ->where('cash_voucher_id', $voucher->getKey())
            ->lockForUpdate()
            ->first();

        if (! $payment instanceof SupplierPaymentContext || $payment->journal_entry_id !== null) {
            return;
        }

        $voucher->loadMissing('cashbox.account');
        $cashAccount = $voucher->cashbox?->account;
        if (! $cashAccount instanceof Account) {
            throw new DomainException(__('The cashbox posting account is required for Supplier payment approval.'));
        }

        $this->supplierPaymentPostings->post($payment, $cashAccount);
    }

    private function reverseSupplierPayment(CashVoucher $voucher): void
    {
        $payment = SupplierPaymentContext::query()
            ->with('journalEntry')
            ->where('cash_voucher_id', $voucher->getKey())
            ->lockForUpdate()
            ->first();
        if (! $payment instanceof SupplierPaymentContext) {
            return;
        }

        $this->supplierPaymentPostings->reverse(
            $payment,
            (string) $voucher->cancel_reason,
            $voucher->cancelled_at?->toDateString(),
        );
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(string $voucherType, array $data, int $companyId): array
    {
        $key = CashVoucher::documentNumberKeyForType($voucherType);

        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format($key, (int) $data['doc_number'])]
            : $this->nextScopedDocument($voucherType, $key, $companyId);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(string $voucherType, array $data, int $companyId): array
    {
        $cashbox = Cashbox::query()
            ->forCompany($companyId)
            ->where('doc_num', $data['cashbox_doc_num'])
            ->first();
        $currency = Currency::query()
            ->forCompany($companyId)
            ->where('doc_num', $data['currency_doc_num'])
            ->first();
        $exchangeRate = $currency?->is_main ? '1.000000' : $this->normalizeDecimal($data['exchange_rate'] ?? 1, 6);
        $amount = $this->normalizeDecimal($data['amount'] ?? 0, 4);

        return [
            'company_id' => $companyId,
            'voucher_type' => $voucherType,
            'voucher_date' => $data['voucher_date'],
            'cashbox_id' => $cashbox?->getKey(),
            'currency_id' => $currency?->getKey(),
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'amount_base' => $this->multiplyDecimal($amount, $exchangeRate, 4),
            'person_name' => $data['person_name'],
            'person_national_id' => $data['person_national_id'] ?? null,
            'person_phone' => $data['person_phone'] ?? null,
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'status' => CashVoucher::StatusDraft,
            'approved_by' => null,
            'approved_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(CashVoucher $record, array $lines): void
    {
        $record->lines()->delete();
        $exchangeRate = (string) $record->exchange_rate;

        foreach (array_values($lines) as $index => $line) {
            $accountId = Account::query()
                ->where('company_id', $record->company_id)
                ->where('doc_num', $line['account_doc_num'])
                ->value('id');
            $amount = $this->normalizeDecimal($line['amount'] ?? 0, 4);

            $record->lines()->create([
                'line_number' => $index + 1,
                'account_id' => $accountId,
                'amount' => $amount,
                'amount_base' => $this->multiplyDecimal($amount, $exchangeRate, 4),
                'description' => $line['description'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function linesChanged(CashVoucher $record, array $lines): bool
    {
        $record->loadMissing('lines.account');
        $existing = $record->lines->map(fn ($line): array => [
            'account_doc_num' => $line->account?->doc_num,
            'amount' => $this->normalizeDecimal($line->amount, 4),
            'description' => $line->description,
            'notes' => $line->notes,
        ])->values()->all();

        $incoming = collect($lines)->map(fn (array $line): array => [
            'account_doc_num' => $line['account_doc_num'] ?? null,
            'amount' => $this->normalizeDecimal($line['amount'] ?? 0, 4),
            'description' => $line['description'] ?? null,
            'notes' => $line['notes'] ?? null,
        ])->values()->all();

        return json_encode($existing) !== json_encode($incoming);
    }

    private function assertEditable(CashVoucher $record): void
    {
        if (! $record->isDraft()) {
            throw new DomainException($this->message($record->voucher_type, 'document_locked'));
        }
    }

    private function assertDeletable(CashVoucher $record): void
    {
        if (! $record->isDeletable()) {
            throw new DomainException($this->message($record->voucher_type, 'document_delete_blocked'));
        }
    }

    private function assertApprovable(CashVoucher $record): void
    {
        if ($record->trashed()) {
            throw new DomainException($this->message($record->voucher_type, 'deleted_not_approvable'));
        }

        if (! $record->isDraft()) {
            throw new DomainException($this->message($record->voucher_type, 'document_not_approvable'));
        }

        if ($record->lines->isEmpty()) {
            throw new DomainException($this->message($record->voucher_type, 'lines_required'));
        }

        $this->assertCashboxCanBeUsed($record);
        $this->assertCurrencyCanBeUsed($record);
        $this->assertLinesCanBeApproved($record);

        $totals = $this->totals($record);

        if ($totals['remaining_units'] !== 0) {
            throw new DomainException($this->message($record->voucher_type, 'approval_requires_full_distribution'));
        }
    }

    private function assertCashboxCanBeUsed(CashVoucher $record): void
    {
        $cashbox = $record->cashbox;

        if (! $cashbox instanceof Cashbox
            || $cashbox->trashed()
            || $cashbox->status !== 'active'
            || (int) $cashbox->company_id !== (int) $record->company_id
            || (int) $cashbox->branch?->company_id !== (int) $record->company_id) {
            throw new DomainException($this->message($record->voucher_type, 'cashbox_inactive'));
        }

        $account = $cashbox->account;

        if (! $account instanceof Account
            || ! $account->isEligibleForDirectPosting()
            || (int) $account->company_id !== (int) $record->company_id) {
            throw new DomainException($this->message($record->voucher_type, 'cashbox_account_required'));
        }
    }

    private function assertCurrencyCanBeUsed(CashVoucher $record): void
    {
        $currency = $record->currency;
        $cashbox = $record->cashbox;

        if (! $currency instanceof Currency
            || $currency->trashed()
            || $currency->status !== 'active'
            || (int) $currency->company_id !== (int) $record->company_id) {
            throw new DomainException($this->message($record->voucher_type, 'currency_inactive'));
        }

        if (! $cashbox instanceof Cashbox || ! $this->isCurrencyAllowedForCashbox($cashbox, $currency)) {
            throw new DomainException($this->message($record->voucher_type, 'currency_not_allowed'));
        }
    }

    private function assertLinesCanBeApproved(CashVoucher $record): void
    {
        $cashboxAccountId = (int) $record->cashbox?->account_id;

        foreach ($record->lines as $line) {
            $account = $line->account;

            if (! $account instanceof Account
                || ! $account->isEligibleForDirectPosting()
                || (int) $account->company_id !== (int) $record->company_id) {
                throw new DomainException($this->message($record->voucher_type, 'account_not_postable'));
            }

            if ((int) $account->getKey() === $cashboxAccountId) {
                throw new DomainException($this->message($record->voucher_type, 'cashbox_account_line_forbidden'));
            }

            if ($this->toUnits($line->amount) <= 0) {
                throw new DomainException($this->message($record->voucher_type, 'line_amount_positive'));
            }
        }
    }

    private function isCurrencyAllowedForCashbox(Cashbox $cashbox, Currency $currency): bool
    {
        $allowed = $cashbox->currencies()
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if (! $allowed->exists()) {
            return true;
        }

        return $cashbox->currencies()
            ->where('status', 'active')
            ->where('currency_id', $currency->getKey())
            ->whereNull('deleted_at')
            ->exists();
    }

    private function assertOwnedByCurrentScreen(CashVoucher $record, string $voucherType, int $companyId): void
    {
        if ((int) $record->company_id !== $companyId || $record->voucher_type !== $voucherType) {
            throw new DomainException($this->message($voucherType, 'not_found_in_context'));
        }
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(CashVoucher $record, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function nextScopedDocument(string $voucherType, string $key, int $companyId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('cash_vouchers')));
        }

        $nextNumber = ((int) CashVoucher::query()
            ->where('company_id', $companyId)
            ->where('voucher_type', $voucherType)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format($key, $nextNumber),
        ];
    }

    private function message(string $voucherType, string $key): string
    {
        return __(CashVoucher::translationKeyForType($voucherType).".messages.{$key}");
    }

    private function refreshLinkedPurchaseInvoices(CashVoucher $voucher): void
    {
        PurchaseInvoicePaymentSchedule::query()
            ->with('purchaseInvoice')
            ->where('cash_voucher_id', $voucher->getKey())
            ->get()
            ->each(function (PurchaseInvoicePaymentSchedule $schedule) use ($voucher): void {
                $status = match (true) {
                    $voucher->isApproved() && ! $voucher->trashed() => PurchaseInvoicePaymentSchedule::StatusPaid,
                    $voucher->isCancelled() && ! $voucher->trashed() => PurchaseInvoicePaymentSchedule::StatusCancelled,
                    $voucher->trashed() => PurchaseInvoicePaymentSchedule::StatusScheduled,
                    default => PurchaseInvoicePaymentSchedule::StatusVoucherDraft,
                };

                $schedule->forceFill([
                    'status' => $status,
                    'updated_by' => auth()->id(),
                ])->save();

                $schedule->purchaseInvoice?->refreshPaymentTotals();
            });

        $payment = SupplierPaymentContext::query()
            ->with(['allocations.paymentSchedule', 'allocations.purchaseInvoice'])
            ->where('cash_voucher_id', $voucher->getKey())
            ->first();

        if (! $payment instanceof SupplierPaymentContext) {
            return;
        }

        foreach ($payment->allocations as $allocation) {
            $schedule = $allocation->paymentSchedule;
            if ($schedule instanceof PurchaseInvoicePaymentSchedule) {
                $paid = (float) $schedule->allocations()
                    ->whereHas('paymentContext.cashVoucher', fn ($query) => $query
                        ->where('status', CashVoucher::StatusApproved)
                        ->whereNull('deleted_at'))
                    ->sum('amount');
                $schedule->forceFill([
                    'paid_amount' => $this->normalizeDecimal($paid, 4),
                    'status' => match (true) {
                        $paid >= (float) $schedule->amount - 0.0001 => PurchaseInvoicePaymentSchedule::StatusPaid,
                        $paid > 0 => 'partially_paid',
                        default => PurchaseInvoicePaymentSchedule::StatusScheduled,
                    },
                    'updated_by' => auth()->id(),
                ])->save();
            }

            $allocation->purchaseInvoice?->refreshPaymentTotals();
        }
    }

    private function assertNotLinkedToClosedPurchaseInvoice(CashVoucher $voucher): void
    {
        $hasClosedInvoice = PurchaseInvoicePaymentSchedule::query()
            ->where('cash_voucher_id', $voucher->getKey())
            ->whereHas('purchaseInvoice', fn ($query) => $query->where('status', PurchaseInvoice::StatusClosed))
            ->exists();

        if ($hasClosedInvoice) {
            throw new DomainException(__('purchase_invoices.messages.closed_payment_change_forbidden'));
        }
    }

    private function normalizeDecimal(mixed $value, int $scale): string
    {
        return $this->numbers->normalizeToScale($value, $scale)
            ?? $this->numbers->normalizeToScale(0, $scale);
    }

    private function multiplyDecimal(mixed $left, mixed $right, int $scale): string
    {
        if (function_exists('bcmul')) {
            return bcmul((string) $left, (string) $right, $scale);
        }

        return number_format(((float) $left) * ((float) $right), $scale, '.', '');
    }

    private function toUnits(mixed $value): int
    {
        $value = trim(str_replace(',', '', (string) $value));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?: '', 4, '0'), 0, 4);
        $units = ((int) $whole * 10000) + (int) $fraction;

        return $negative ? -$units : $units;
    }

    private function fromUnits(int $units): string
    {
        $negative = $units < 0;
        $units = abs($units);
        $whole = intdiv($units, 10000);
        $fraction = $units % 10000;
        $formatted = $whole.'.'.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').rtrim(rtrim($formatted, '0'), '.');
    }
}
